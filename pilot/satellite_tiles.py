"""Bounded spatial workers; one coordinator owns statistics, images and manifests.

Year tiles retain calibrated RGB/NIR and the scene-eroded clear mask. Pair masks
are constructed afresh: independently averaged annual NDVI is never subtracted.
"""
import hashlib
import json
import os
import shutil
import subprocess
import zipfile
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import numpy as np
import rasterio
from PIL import Image
from rasterio.enums import Resampling
from rasterio.features import geometry_mask
from rasterio.transform import from_bounds
from rasterio.vrt import WarpedVRT
from rasterio.warp import transform_bounds, transform_geom, reproject
from rasterio.windows import Window, transform as window_transform
from shapely.geometry import shape, box
from shapely.strtree import STRtree

VERSION = 'year-tile-v1'


def available_memory():
    if os.name == 'nt':
        import ctypes
        class Memory(ctypes.Structure):
            _fields_ = [('length', ctypes.c_ulong), ('load', ctypes.c_ulong)] + [(n, ctypes.c_ulonglong) for n in ['total', 'available', 'page', 'free_page', 'virtual', 'free_virtual', 'extended']]
        m = Memory(); m.length = ctypes.sizeof(m)
        if ctypes.windll.kernel32.GlobalMemoryStatusEx(ctypes.byref(m)):
            return m.available
        return 0
    try:
        return int(next(x.split()[1] for x in Path('/proc/meminfo').read_text().splitlines() if x.startswith('MemAvailable:'))) * 1024
    except (OSError, StopIteration):
        return 0


def worker_count(free_memory, requested=2):
    # At most two tiles in flight, with a conservative 1.5 GB system reserve.
    return 2 if requested >= 2 and free_memory >= 2_500_000_000 else 1


def windows(width, height, size):
    for y in range(0, height, size):
        for x in range(0, width, size):
            yield Window(x, y, min(size, width-x), min(size, height-y))


def halo(window, width, height):
    x, y, w, h = map(int, (window.col_off, window.row_off, window.width, window.height))
    a, b = max(0, x-1), max(0, y-1)
    return Window(a, b, min(width, x+w+1)-a, min(height, y+h+1)-b), (slice(y-b, y-b+h), slice(x-a, x-a+w))


def cached_window(path, member, band, window, expected):
    """Read an existing NPZ without expanding a district-sized array in RAM."""
    with zipfile.ZipFile(path) as archive, archive.open(member+'.npy') as stream:
        version = np.lib.format.read_magic(stream)
        shape_, fortran, dtype = np.lib.format._read_array_header(stream, version)
        assert not fortran and not dtype.hasobject and shape_ == expected
        offset = stream.tell(); h, w = expected[-2:]
        x, y, cols, rows = map(int, (window.col_off, window.row_off, window.width, window.height))
        out = np.empty((rows, cols), dtype=dtype)
        for row in range(rows):
            stream.seek(offset + (((band or 0)*h + y+row)*w+x)*dtype.itemsize)
            data = stream.read(cols*dtype.itemsize)
            assert len(data) == cols*dtype.itemsize
            out[row] = np.frombuffer(data, dtype=dtype)
        return out


def totals_result(total, count, first, last):
    coverage = count/total if total else 0
    result = dict(paired_pixels=int(count), polygon_pixels=int(total), paired_coverage_pct=round(coverage*100, 2))
    if count < 25 or coverage < .8:
        return dict(result, before_ndvi=None, after_ndvi=None, ndvi_change=None)
    a, b = first/count, last/count
    return dict(result, before_ndvi=round(a, 4), after_ndvi=round(b, 4), ndvi_change=round(b-a, 4))


def restore_year_tile(path, receipt, expected_sha):
    """Stream an archived year tile back locally, never staging raw data on server."""
    assert receipt['sha256']==expected_sha and receipt['layer_id']==path.parent.name
    assert not path.is_symlink() and not path.parent.is_symlink()
    if shutil.disk_usage(path.parent).free < 5_000_000_000+receipt['bytes']:
        raise RuntimeError('Insufficient reserve to restore year cache')
    temporary=path.with_suffix('.restore-pending')
    with temporary.open('wb') as stream:
        result=subprocess.run(['ssh','-T','-i','C:/Users/menha/.ssh/pollmedia_ed25519','-o','BatchMode=yes','-o','ConnectTimeout=15',
            'root@5.183.35.118','runuser -u pollmedia -- php /home/pollmedia/tmp/read-satellite-year-cache.php'],
            input=json.dumps(receipt).encode(),stdout=stream,stderr=subprocess.PIPE,timeout=300)
        stream.flush();os.fsync(stream.fileno())
    from build_pilibhit_satellite import sha
    if result.returncode or temporary.stat().st_size!=receipt['bytes'] or sha(temporary)!=expected_sha:
        raise RuntimeError('Archived year tile recovery failed verification; source retained')
    os.replace(temporary,path)


def process_tiles(base, output, config, geo, bounds, transform, width, height):
    from build_pilibhit_satellite import BANDS, confirmed_product, valid_pixels, save_cache, sha
    dates, crs = config['dates'], config['crs']
    size = min(1024, max(32, int(config.get('tile_size', 1024))))
    requested = min(2, max(1, int(config.get('tile_workers', 2))))
    receipts={}
    receipt_path=Path(config['archive_receipts']) if config.get('archive_receipts') else None
    receipt_paths = [receipt_path, receipt_path.with_name('failed-r2-archive-receipts.jsonl')] if receipt_path else []
    for ledger in receipt_paths:
        if not ledger.exists():continue
        for line in ledger.read_text().splitlines():
            receipt=json.loads(line)
            if receipt['layer_id']==config['layer_id']:
                name=Path(receipt['path']).name
                if name in receipts and receipts[name]['sha256']!=receipt['sha256']:
                    raise RuntimeError('Conflicting cache recovery receipts')
                receipts[name]=receipt
    # Restore legacy caches serially before tile threads can read them.
    for name, receipt in receipts.items():
        if not name.startswith(VERSION+'-'):
            path=base/name
            if not path.exists():restore_year_tile(path,receipt,receipt['sha256'])
    projected = [transform_geom('EPSG:4326', crs, f['geometry']) for f in geo['features']]
    geometries = [shape(g) for g in projected]; tree = STRtree(geometries)
    year_scenes, keys, provenance, manifests, expected_hashes = [], [], [], [], []
    for date in dates:
        selected = base/f'c1-{date}.json'; official_path = base/f'official-{date}.json'
        scenes = sorted(json.loads(selected.read_bytes())['features'], key=lambda i: i['id'])
        official = json.loads(official_path.read_bytes())['features']
        assert scenes and len({i['properties']['grid:code'] for i in scenes}) == len(scenes)
        identity = dict(version=VERSION, geometry=config['geometry_sha256'], date=date, crs=crs,
                        grid=[width,height,*transform], tile_size=size, selected_sha256=sha(selected))
        key = hashlib.sha256(json.dumps(identity, sort_keys=True).encode()).hexdigest()[:24]
        manifest = dict(identity, tiles={}); manifests.append(manifest); keys.append(key)
        manifest_path=base/f'{VERSION}-{date}-{key}.json'
        previous=json.loads(manifest_path.read_bytes()) if manifest_path.exists() else {}
        expected_hashes.append(previous.get('tiles',{}))
        year_scenes.append(scenes)
        for item in scenes:
            p = item['properties']; product = p['s2:product_uri']
            assert p['platform'] == config['platform'] and '_'+config['orbit']+'_' in product and p['datetime'].startswith(date)
            matches = sorted(confirmed_product(official, product), key=lambda f:f['id'], reverse=True)
            assert len({tuple(f['id'].split('_')[i] for i in [0,2,4,5]) for f in matches}) == 1
            provenance.append(dict(id=item['id'], product_id=product, acquired_at=p['datetime'], tile=product.split('_')[5],
                official_catalogue_editions=[f['id'] for f in matches], official_catalogue_id=matches[0]['id'],
                official_catalogue_url=next(l['href'] for l in matches[0]['links'] if l['rel']=='self'),
                download_catalogue_url=next(l['href'] for l in item['links'] if l['rel']=='self'),
                cloud_cover_scene_pct=p['eo:cloud_cover'], assets={n:item['assets'][n] for n in BANDS},
                year_cache_manifest=f'{VERSION}-{date}-{key}.json'))

    def year_tile(year, win):
        name=f'{VERSION}-{dates[year]}-{keys[year]}-{int(win.row_off)}-{int(win.col_off)}.npz'
        path=base/name; expected=(4,int(win.height),int(win.width))
        if not path.exists() and (name in expected_hashes[year] or name in receipts):
            if name not in receipts:
                raise RuntimeError('Completed year tile missing without recovery receipt; refusing recomputation')
            restore_year_tile(path,receipts[name],expected_hashes[year].get(name,receipts[name]['sha256']))
        if path.exists():
            digest=sha(path)
            if name in expected_hashes[year]:
                assert digest==expected_hashes[year][name], 'Year tile checksum changed'
            with np.load(path, allow_pickle=False) as data:
                values=data['values']; assert values.shape==expected
                assert str(data['identity'])==keys[year]
            return values, name, digest
        expanded, crop=halo(win,width,height)
        local_transform=window_transform(expanded,transform)
        result=np.full(expected,np.nan,dtype='float32')
        tile_bounds=rasterio.windows.bounds(expanded,transform)
        for item in year_scenes[year]:
            footprint=shape(transform_geom('EPSG:4326',crs,item['geometry']))
            if not footprint.intersects(box(*tile_bounds)):continue
            bands=[]; legacy=base/(item['id']+'.npz')
            for idx,name_ in enumerate(BANDS):
                band_cache=base/(item['id']+'-'+name_+'.npz')
                if legacy.exists():
                    values=cached_window(legacy,'bands',idx,expanded,(5,height,width))
                elif band_cache.exists():
                    values=cached_window(band_cache,'values',None,expanded,(height,width))
                else:
                    asset=item['assets'][name_]
                    with rasterio.Env(GDAL_DISABLE_READDIR_ON_OPEN='EMPTY_DIR',GDAL_HTTP_TIMEOUT='120',GDAL_HTTP_MAX_RETRY='3',GDAL_CACHEMAX=32*1024*1024):
                        with rasterio.open(asset['href']) as src:
                            with WarpedVRT(src,crs=crs,transform=local_transform,width=int(expanded.width),height=int(expanded.height),dtype='float32',nodata=float('nan'),resampling=Resampling.nearest if name_=='scl' else Resampling.average) as vrt:
                                values=vrt.read(1,masked=True).astype('float32').filled(np.nan)
                            if name_!='scl':
                                metadata=asset['raster:bands'][0]
                                assert src.scales[0]==metadata['scale'] and src.offsets[0]==metadata['offset']
                                values=values*src.scales[0]+src.offsets[0]
                bands.append(values)
            bands=np.stack(bands); valid=valid_pixels(bands)[crop]
            take=valid & ~np.isfinite(result[0]); rgbn=bands[:4, crop[0], crop[1]]
            result[:,take]=rgbn[:,take]
        # Reserve includes this uncompressed tile and concurrent tile allowance.
        if shutil.disk_usage(base).free < 5_000_000_000+2*result.nbytes:
            raise RuntimeError('Processing reserve reached before saving year tile')
        save_cache(path, values=result, identity=np.array(keys[year]))
        return result, path.name, sha(path)

    def calculate(win):
        return win, [year_tile(i,win) for i in range(2)]

    # Disk-backed display intermediates never enter the publication bundle.
    temporary=[base/f'{VERSION}-display-{date}.tif' for date in dates]
    required=width*height*8+200_000_000
    if shutil.disk_usage(base).free < 5_000_000_000+required:
        raise RuntimeError('Insufficient processing reserve for tiled display intermediates')
    writers=[]; sums=np.zeros((len(projected)+1,4),dtype='float64')
    try:
        for path in temporary:
            writers.append(rasterio.open(path,'w',driver='GTiff',width=width,height=height,count=4,dtype='uint8',crs=crs,transform=transform,tiled=True,compress='deflate',BIGTIFF='IF_SAFER'))
        pending=iter(windows(width,height,size)); count=0
        with ThreadPoolExecutor(max_workers=requested) as pool:
            while True:
                # Re-evaluate memory before every bounded batch; never submit the entire district.
                batch=[]
                for _ in range(worker_count(available_memory(),requested)):
                    win=next(pending,None)
                    if win is not None:batch.append(win)
                if not batch:break
                for win, years in pool.map(calculate,batch):
                    count+=1; local=window_transform(win,transform)
                    arrays=[v[0] for v in years]
                    ndvi=[(a[3]-a[0])/np.where(a[3]+a[0]>0,a[3]+a[0],np.nan) for a in arrays]
                    paired=np.isfinite(ndvi[0]) & np.isfinite(ndvi[1])
                    indices=tree.query(box(*rasterio.windows.bounds(win,transform)))
                    district=np.zeros(paired.shape,dtype=bool)
                    for idx in indices:
                        inside=geometry_mask([projected[idx]],out_shape=paired.shape,transform=local,invert=True)
                        district|=inside; valid=inside&paired
                        sums[idx]+=[inside.sum(),valid.sum(),ndvi[0][valid].sum(dtype='float64'),ndvi[1][valid].sum(dtype='float64')]
                    valid=district&paired
                    sums[-1]+=[district.sum(),valid.sum(),ndvi[0][valid].sum(dtype='float64'),ndvi[1][valid].sum(dtype='float64')]
                    for i,(a,name,digest) in enumerate(years):
                        manifests[i]['tiles'][name]=digest
                        rgb=np.nan_to_num(np.clip(a[:3]/.3,0,1))**(1/2.2)
                        rgba=np.concatenate([(rgb*255).astype('uint8'),(valid*255).astype('uint8')[None]])
                        writers[i].write(rgba,window=win)
                    print(f'Tiled comparison {count}: row {int(win.row_off)} col {int(win.col_off)}; workers {len(batch)}',flush=True)
        for writer in writers:writer.close()
        mercator=transform_bounds('EPSG:4326','EPSG:3857',*bounds)
        dw=min(4800,width); dh=max(1,round(dw*(mercator[3]-mercator[1])/(mercator[2]-mercator[0])))
        if dh>4800:dw=max(1,round(dw*4800/dh));dh=4800
        display_transform=from_bounds(*mercator,dw,dh)
        for i,path in enumerate(temporary):
            display=np.zeros((4,dh,dw),dtype='uint8')
            with rasterio.open(path) as src:
                for band in range(4):
                    reproject(rasterio.band(src,band+1),display[band],dst_transform=display_transform,dst_crs='EPSG:3857',resampling=Resampling.nearest,warp_mem_limit=32)
            display[:3,display[3]==0]=0
            Image.fromarray(display.transpose(1,2,0),'RGBA').save(output/(dates[i]+'.webp'),quality=90,method=4)
            manifest_path=base/f'{VERSION}-{dates[i]}-{keys[i]}.json'
            staged=manifest_path.with_suffix('.pending');staged.write_text(json.dumps(manifests[i],separators=(',',':')));os.replace(staged,manifest_path)
        for scene in provenance:
            scene['year_cache_manifest_sha256']=sha(base/scene['year_cache_manifest'])
    finally:
        for writer in writers:writer.close()
        for path in temporary:
            # Only these two regenerable files, in this validated district directory.
            if path.exists() and not path.is_symlink() and path.resolve().parent==base.resolve():path.unlink()
    rows=[dict(feature_index=i,original_properties=f['properties'],**totals_result(*sums[i])) for i,f in enumerate(geo['features'])]
    return rows,totals_result(*sums[-1]),provenance
