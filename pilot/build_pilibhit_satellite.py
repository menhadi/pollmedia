"""Reproducible same-season comparison; existing SOI geometries remain read-only."""
import hashlib
import json
import math
import sys
import os
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
import rasterio
from rasterio.enums import Resampling
from rasterio.features import geometry_mask
from rasterio.vrt import WarpedVRT
from rasterio.warp import transform_bounds, transform_geom, reproject
from rasterio.transform import from_origin, from_bounds
from rasterio.windows import from_bounds as window_from_bounds, transform as window_transform
from shapely.geometry import shape
from PIL import Image

DATES = ['2023-11-22', '2024-11-26']
GEOMETRY_SHA = '5711c0233a8ba1320f6e38e7c60c33cd5dc8462ab0d5457997ec8ccb64df0633'
BANDS = ['red', 'green', 'blue', 'nir', 'scl']

def sha(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()

def confirmed_product(official, product):
    # Confirm the selected product, not other satellites observing this tile that day.
    identity = tuple(product.removesuffix('.SAFE').split('_')[i] for i in [0, 1, 2, 4, 5])
    matches = [feature for feature in official if tuple(feature['id'].removesuffix('.SAFE').split('_')[i] for i in [0, 1, 2, 4, 5]) == identity]
    assert matches, 'Selected product not confirmed by Copernicus'
    return matches

def save_cache(path, **arrays):
    temporary = path.with_suffix(path.suffix + '.pending')
    with temporary.open('wb') as stream:
        np.savez_compressed(stream, **arrays)
    os.replace(temporary, path)

def band_workers(pixels):
    # Bound additional raster memory while overlapping remote reads.
    if pixels > 8_000_000:
        return 1
    if os.name == 'nt':
        import ctypes
        class MemoryStatus(ctypes.Structure):
            _fields_ = [('length', ctypes.c_ulong), ('load', ctypes.c_ulong)] + [(name, ctypes.c_ulonglong) for name in ['total_phys', 'avail_phys', 'total_page', 'avail_page', 'total_virtual', 'avail_virtual', 'extended']]
        status = MemoryStatus(); status.length = ctypes.sizeof(status)
        if not ctypes.windll.kernel32.GlobalMemoryStatusEx(ctypes.byref(status)) or status.avail_phys < 1_500_000_000:
            return 1
    return 2

def valid_pixels(bands):
    valid = np.isin(bands[4], [4, 5, 6]) & np.all(np.isfinite(bands[:4]), axis=0)
    valid &= np.all((bands[:4] >= 0) & (bands[:4] <= 1), axis=0)
    # Exclude one analysis pixel around cloud/shadow/no-data edges.
    padded = np.pad(valid, 1, constant_values=False)
    return np.logical_and.reduce([padded[y:y+valid.shape[0], x:x+valid.shape[1]] for y in range(3) for x in range(3)])

def paired_statistics(before, after, mask, total):
    count = int(mask.sum()); coverage = count / total if total else 0
    result = {'paired_pixels': count, 'polygon_pixels': total, 'paired_coverage_pct': round(coverage*100, 2)}
    if count < 25 or coverage < .8:
        return {**result, 'before_ndvi': None, 'after_ndvi': None, 'ndvi_change': None}
    first, last = float(before[mask].mean()), float(after[mask].mean())
    return {**result, 'before_ndvi': round(first, 4), 'after_ndvi': round(last, 4), 'ndvi_change': round(last-first, 4)}

def build(base, geometry, output, config=None):
    base, geometry, output = map(Path, [base, geometry, output]); output.mkdir(parents=True, exist_ok=True)
    config=config or {}; dates=config.get('dates',DATES); geometry_sha=config.get('geometry_sha256',GEOMETRY_SHA); crs=config.get('crs','EPSG:32644')
    assert sha(geometry) == geometry_sha, 'Existing geometry changed; review before rebuilding'
    geo = json.loads(geometry.read_bytes()); shapes = [shape(f['geometry']) for f in geo['features']]
    bounds = [min(g.bounds[0] for g in shapes), min(g.bounds[1] for g in shapes), max(g.bounds[2] for g in shapes), max(g.bounds[3] for g in shapes)]
    left, bottom, right, top = transform_bounds('EPSG:4326', crs, *bounds)
    left, bottom, right, top = math.floor(left/20)*20, math.floor(bottom/20)*20, math.ceil(right/20)*20, math.ceil(top/20)*20
    width, height = int((right-left)/20), int((top-bottom)/20); transform = from_origin(left, top, 20, 20)
    if config.get('tiled', False):
        from satellite_tiles import process_tiles
        rows, summary, provenance = process_tiles(base, output, config, geo, bounds, transform, width, height)
    else:
        if width*height>config.get('max_pixels',25000000):raise ValueError('Processing limit: area requires tiled analysis; no values inferred')
        provenance = []; mosaics = []
        for date in dates:
            items = json.loads((base/f'c1-{date}.json').read_bytes())['features']
            official = json.loads((base/f'official-{date}.json').read_bytes())['features']
            assert items and len({i['properties']['grid:code'] for i in items}) == len(items)
            mosaic = np.full((5, height, width), np.nan, dtype='float32')
            for item in sorted(items, key=lambda i:i['id']):
                properties = item['properties']; product = properties['s2:product_uri']
                assert properties['platform'] == config.get('platform','sentinel-2a') and ('_'+config.get('orbit','R062')+'_') in product and properties['datetime'].startswith(date)
                tile = product.split('_')[5]
                matches = confirmed_product(official, product)
                assert matches, 'Acquisition not confirmed by Copernicus'
                acquisitions={tuple(f['id'].split('_')[i] for i in [0,2,4,5]) for f in matches}
                assert len(acquisitions)==1, 'Acquisition not uniquely confirmed by Copernicus'
                matches.sort(key=lambda f:f['id'],reverse=True)
                cache = base/(item['id']+'.npz')
                if not cache.exists():
                    def read_band(name):
                        asset = item['assets'][name]
                        band_cache = base/(item['id']+'-'+name+'.npz')
                        if band_cache.exists():

                            with np.load(band_cache) as saved:
                                return saved['values']
                        with rasterio.Env(GDAL_DISABLE_READDIR_ON_OPEN='EMPTY_DIR', GDAL_HTTP_TIMEOUT='120', GDAL_HTTP_MAX_RETRY='3'):
                            with rasterio.open(asset['href']) as src:
                                if str(src.crs) != crs:
                                    with WarpedVRT(src,crs=crs,transform=transform,width=width,height=height,dtype='float32',nodata=float('nan'),resampling=Resampling.nearest if name=='scl' else Resampling.average) as vrt:
                                        values=vrt.read(1,masked=True).astype('float32').filled(np.nan)
                                else:
                                    west,south,east,north=max(left,src.bounds.left),max(bottom,src.bounds.bottom),min(right,src.bounds.right),min(top,src.bounds.top)
                                    window=window_from_bounds(west,south,east,north,src.transform)
                                    cols,rows=round((east-west)/20),round((north-south)/20)
                                    part=src.read(1,window=window,out_shape=(rows,cols),masked=True,resampling=Resampling.nearest if name=='scl' else Resampling.average).astype('float32').filled(np.nan)
                                    values=np.full((height,width),np.nan,dtype='float32')
                                    x,y=round((west-left)/20),round((top-north)/20)
                                    values[y:y+rows,x:x+cols]=part
                                if name != 'scl':
                                    metadata = asset['raster:bands'][0]
                                    assert src.scales[0] == metadata['scale'] and src.offsets[0] == metadata['offset']
                                    values = values * src.scales[0] + src.offsets[0]
                                save_cache(band_cache, values=values)
                        print(date, tile, name, flush=True)
                        return values
                    workers = band_workers(width * height)
                    with ThreadPoolExecutor(max_workers=workers) as pool:
                        bands = list(pool.map(read_band, BANDS))
                    save_cache(cache, bands=np.stack(bands))
                bands = np.load(cache)['bands']; assert bands.shape == mosaic.shape
                valid = valid_pixels(bands); vacant = ~np.isfinite(mosaic[0]); take = valid & vacant
                mosaic[:, take] = bands[:, take]
                provenance.append({'id':item['id'], 'product_id':product, 'acquired_at':properties['datetime'], 'tile':tile,
                    'official_catalogue_editions':[f['id'] for f in matches], 'official_catalogue_id': matches[0]['id'], 'official_catalogue_url':next(l['href'] for l in matches[0]['links'] if l['rel']=='self'),
                    'download_catalogue_url':next(l['href'] for l in item['links'] if l['rel']=='self'),
                    'cloud_cover_scene_pct':properties['eo:cloud_cover'], 'assets':{name:item['assets'][name] for name in BANDS},
                    'extracted_grid_file':cache.name, 'extracted_grid_sha256':sha(cache)})
            mosaics.append(mosaic)
        ndvis = [(m[3]-m[0])/np.where(m[3]+m[0]>0,m[3]+m[0],np.nan) for m in mosaics]
        paired = np.isfinite(ndvis[0]) & np.isfinite(ndvis[1])
        projected = [transform_geom('EPSG:4326',crs,f['geometry']) for f in geo['features']]
        district_mask = geometry_mask(projected, out_shape=(height,width), transform=transform, invert=True)
        rows = []
        for index, (feature, geom) in enumerate(zip(geo['features'], projected)):
            west,south,east,north = shape(geom).bounds
            col0=max(0,int((west-left)//20)); col1=min(width,math.ceil((east-left)/20))
            row0=max(0,int((top-north)//20)); row1=min(height,math.ceil((top-south)/20))
            if col1<=col0 or row1<=row0:continue
            local_transform=from_origin(left+col0*20,top-row0*20,20,20)
            inside=geometry_mask([geom],out_shape=(row1-row0,col1-col0),transform=local_transform,invert=True)
            sl=np.s_[row0:row1,col0:col1]; valid=paired[sl]&inside
            rows.append({'feature_index':index,'original_properties':feature['properties'],**paired_statistics(ndvis[0][sl],ndvis[1][sl],valid,int(inside.sum()))})
        # Display rasters use identical Web Mercator grid and fixed reflectance stretch.
        mercator=transform_bounds('EPSG:4326','EPSG:3857',*bounds); display_width=min(4800,width) if config else 4800
        display_height=round(display_width*(mercator[3]-mercator[1])/(mercator[2]-mercator[0]))
        display_transform=from_bounds(*mercator,display_width,display_height)
        for date,mosaic in zip(dates,mosaics):
            rgb=np.nan_to_num(np.clip(mosaic[:3]/.3,0,1))**(1/2.2)
            rgba=np.concatenate([(rgb*255).astype('uint8'),((paired&district_mask)*255).astype('uint8')[None]],axis=0)
            display=np.zeros((4,display_height,display_width),dtype='uint8')
            reproject(rgba,display,src_transform=transform,src_crs=crs,dst_transform=display_transform,dst_crs='EPSG:3857',resampling=Resampling.nearest)
            display[:3,display[3]==0]=0
            Image.fromarray(display.transpose(1,2,0),'RGBA').save(output/(date+'.webp'),quality=90,method=4)
        summary=paired_statistics(ndvis[0],ndvis[1],paired&district_mask,int(district_mask.sum()))
    metrics=json.loads((base/'existing-metrics.json').read_bytes())
    assert metrics['geometry_sha256']==geometry_sha
    identities={row['feature_index']:row for row in metrics['records']}
    assert len(identities)==len(metrics['records'])
    index={'lgd':{},'census':{}}
    if not config:
        dashboard_path=base.parent/'soil-preview-pilibhit-polygons.json'
        dashboard=json.loads(dashboard_path.read_bytes())
        dashboard_rows=[row for row in dashboard['rows'] if row.get('geometry')]
        geometry_key=lambda g:hashlib.sha256(json.dumps(g,sort_keys=True,separators=(',',':')).encode()).hexdigest()
        original_by_geometry={geometry_key(f['geometry']):i for i,f in enumerate(geo['features'])}
        assert len(original_by_geometry)==len(geo['features'])
        dashboard_indices=[]
        for row in dashboard_rows:
            mapped=original_by_geometry[geometry_key(row['geometry'])]
            assert str(geo['features'][mapped]['properties']['source_code'])==str(row['code'])
            dashboard_indices.append(mapped)
        index['dashboard']={'file':'maps/pilibhit-district-pilot.json','sha256':sha(dashboard_path),'indices':dashboard_indices}
    for row in rows:
        identity=identities.get(row['feature_index'],{})
        row['identity']={key:identity[key] for key in ['lgd_code','census_code','state_lgd_code','record_id','record_key','place_id','soi_source_id','url','census_url'] if key in identity}
        for kind,key in [('lgd','lgd_code'),('census','census_code')]:
            if identity.get(key):index[kind].setdefault(str(identity[key]),[]).append(row['feature_index'])
    (output/'index.json').write_text(json.dumps(index,separators=(',',':')),encoding='utf-8')
    result={'version':1,'dates':dates,'season':'November matched calendar window','sensor':config.get('platform','sentinel-2a')+' MSI Level-2A','orbit':config.get('orbit','R062'),
        'retrieved_at':datetime.now(timezone.utc).isoformat(),'source':'https://dataspace.copernicus.eu/explore-data/data-collections/sentinel-data/sentinel-2',
        'download_provider':'Element 84 Earth Search, public Collection 1 copies of Copernicus Sentinel-2 data',
        'attribution':'Contains modified Copernicus Sentinel data (2023, 2024). Processed by Pollmedia.',
        'geometry_sha256':geometry_sha,'geometry_url':'/api/india-map/layers/'+config.get('layer_id','aa42595d08e74cd4d9a2'),'layer_id':config.get('layer_id','aa42595d08e74cd4d9a2'),
        'identity_snapshot_sha256':sha(base/'existing-metrics.json'),
        'catalogue_snapshots':{p.name:sha(p) for date in dates for p in [base/f'c1-{date}.json',base/f'official-{date}.json']},
        'bounds':bounds,'analysis_resolution_metres':20,'minimum_paired_coverage_pct':80,'minimum_pixels':25,
        'display_note':'Fixed colour stretch in both years; WebP compression is used only for display, never for NDVI calculations.',
        'method':'Same-sensor, same-orbit acquisitions in November across years, with calendar dates at most seven days apart. C1 raster scale/offset applied once. Red/NIR/RGB averaged to a common 20 m UTM grid; SCL nearest-neighbour. Only SCL 4/5/6 retained, eroded by one pixel. Both years use the same valid pixels. NDVI=(NIR-red)/(NIR+red). Polygon centres rasterized independently; at least 80% paired coverage and 25 pixels required. Fixed RGB stretch 0–0.3 reflectance, gamma 2.2. Display images resampled to Web Mercator, not used for statistics.',
        'limitations':'Two dated observations, not annual averages or proof of permanent land-use change. Crop stage, haze, moisture and processing editions can affect differences. No soil fertility, water quality, yield, built-up area or causal claims are inferred. Transparent areas have no paired clear observation. Boundaries retain their original source edition; they are not historical boundary reconstructions.',
        'processing':{'mode':'tiled' if config.get('tiled') else 'whole-grid','tile_size':config.get('tile_size'),'maximum_tile_workers':config.get('tile_workers'), 'year_cache_reuse':bool(config.get('tiled'))},
        'summary':summary,'villages':rows,'scenes':provenance,'images':{date:date+'.webp' for date in dates},
        'files':{date+'.webp':sha(output/(date+'.webp')) for date in dates}}
    (output/'comparison.json').write_text(json.dumps(result,separators=(',',':')),encoding='utf-8')
    assert sha(geometry)==geometry_sha
    print(json.dumps({'summary':summary,'polygons':len(rows),'eligible':sum(r['ndvi_change'] is not None for r in rows)}),flush=True)

if __name__=='__main__':build(*sys.argv[1:])
