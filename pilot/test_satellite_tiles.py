import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import numpy as np
from rasterio.warp import transform_geom, transform_bounds
from shapely.geometry import box, mapping

from build_pilibhit_satellite import build, sha, save_cache, valid_pixels
from satellite_tiles import cached_window, halo, windows, worker_count


class TileTest(unittest.TestCase):
    def test_halo_matches_whole_array_at_cloud_and_tile_edges(self):
        a=np.full((5,75,83),.2,dtype='float32');a[4]=4
        a[4,31:34,30:35]=9;a[4,63,63]=3;a[0,12,12]=np.nan
        full=valid_pixels(a);actual=np.zeros(full.shape,dtype=bool)
        for w in windows(83,75,32):
            h,c=halo(w,83,75)
            tile=a[:,int(h.row_off):int(h.row_off+h.height),int(h.col_off):int(h.col_off+h.width)]
            actual[int(w.row_off):int(w.row_off+w.height),int(w.col_off):int(w.col_off+w.width)]=valid_pixels(tile)[c]
        np.testing.assert_array_equal(full,actual)

    def test_memory_gate(self):
        self.assertEqual(worker_count(500_000_000,2),1)
        self.assertEqual(worker_count(3_000_000_000,2),2)
        self.assertEqual(worker_count(3_000_000_000,1),1)

    def test_full_and_tiled_outputs_and_reuse(self):
        from rasterio.windows import Window
        with tempfile.TemporaryDirectory() as root:
            base=Path(root);geometry=base/'geometry.json'
            shapes=[box(500020,3000020,501940,3001940),box(500640,3000640,501280,3001280)]
            geo={'type':'FeatureCollection','features':[dict(type='Feature',properties={'id':i},geometry=transform_geom('EPSG:32644','EPSG:4326',mapping(g))) for i,g in enumerate(shapes)]}
            geometry.write_text(json.dumps(geo));dates=['2023-11-12','2024-11-16']
            bounds=box(*[0,0,1,1])
            from shapely.geometry import shape
            from shapely.ops import unary_union
            b=unary_union([shape(f['geometry']) for f in geo['features']]).bounds
            import math
            l,bot,r,t=transform_bounds('EPSG:4326','EPSG:32644',*b)
            l,bot,r,t=math.floor(l/20)*20,math.floor(bot/20)*20,math.ceil(r/20)*20,math.ceil(t/20)*20
            width,height=int((r-l)/20),int((t-bot)/20)
            config=dict(dates=dates,geometry_sha256=sha(geometry),crs='EPSG:32644',platform='sentinel-2a',orbit='R062',layer_id='test')
            for year,date in enumerate(dates):
                product='S2A_MSIL2A_'+date.replace('-','')+'T050000_N0509_R062_T44RLS_20250101T000000'
                item=dict(id='scene'+str(year),geometry=mapping(box(*b)),properties={'s2:product_uri':product+'.SAFE','platform':'sentinel-2a','datetime':date+'T05:00:00Z','grid:code':'44RLS','eo:cloud_cover':0},assets={n:{} for n in ['red','green','blue','nir','scl']},links=[{'rel':'self','href':'https://example.org/scene'}])
                (base/f'c1-{date}.json').write_text(json.dumps({'features':[item]}))
                (base/f'official-{date}.json').write_text(json.dumps({'features':[{'id':product,'links':[{'rel':'self','href':'https://example.org/official'}]}]}))
                a=np.full((5,height,width),.2,dtype='float32');a[3]=.4+year*.03;a[4]=4
                a[4,31:34,31:34]=9;a[4,65:67,60:70]=9
                save_cache(base/(item['id']+'.npz'),bands=a)
                np.testing.assert_array_equal(cached_window(base/(item['id']+'.npz'),'bands',3,Window(3,4,10,11),a.shape),a[3,4:15,3:13])
            (base/'existing-metrics.json').write_text(json.dumps({'geometry_sha256':config['geometry_sha256'],'records':[{'feature_index':i,'lgd_code':str(i)} for i in range(2)]}))
            build(base,geometry,base/'legacy',config)
            tiled=dict(config,tiled=True,tile_size=32,tile_workers=2)
            with patch('satellite_tiles.available_memory',return_value=3_000_000_000):build(base,geometry,base/'tiled',tiled)
            old=json.loads((base/'legacy/comparison.json').read_text());new=json.loads((base/'tiled/comparison.json').read_text())
            self.assertEqual(old['summary'],new['summary']);self.assertEqual(old['villages'],new['villages'])
            self.assertEqual((base/'legacy/index.json').read_text(),(base/'tiled/index.json').read_text())
            from PIL import Image
            for date in dates:np.testing.assert_array_equal(np.asarray(Image.open(base/'legacy'/f'{date}.webp')),np.asarray(Image.open(base/'tiled'/f'{date}.webp')))
            with patch('satellite_tiles.cached_window',side_effect=AssertionError('must reuse completed year tiles')):
                build(base,geometry,base/'reused',tiled)
            self.assertEqual(new['villages'],json.loads((base/'reused/comparison.json').read_text())['villages'])
            tile=next(base.glob('year-tile-v1-*.npz'))
            original=tile.read_bytes();tile.write_bytes(original+b'changed')
            with self.assertRaisesRegex(AssertionError,'checksum changed'):
                build(base,geometry,base/'corrupt',tiled)
            tile.write_bytes(original);tile.unlink()
            with self.assertRaisesRegex(RuntimeError,'missing without recovery receipt'):
                build(base,geometry,base/'missing',tiled)
            tile.write_bytes(original)
            # Failed-cache ledger restores both manifested tiles and legacy caches.
            legacy_cache=base/'scene0.npz'
            archived={tile.name:original,legacy_cache.name:legacy_cache.read_bytes()}
            failed=base/'failed-r2-archive-receipts.jsonl'
            import hashlib
            failed.write_text(''.join(json.dumps(dict(path=str(base/name),layer_id='test',
                bytes=len(body),sha256=hashlib.sha256(body).hexdigest()))+'\n' for name,body in archived.items()))
            tile.unlink();legacy_cache.unlink()
            restored=[]
            def restore(path,receipt,digest):
                self.assertEqual(digest,receipt['sha256'])
                path.write_bytes(archived[path.name]);restored.append(path.name)
            recover=dict(tiled,archive_receipts=str(base/'local-r2-archive-receipts.jsonl'))
            with patch('satellite_tiles.restore_year_tile',side_effect=restore):
                build(base,geometry,base/'recovered',recover)
            self.assertEqual(set(restored),set(archived))
            self.assertEqual(new['summary'],json.loads((base/'recovered/comparison.json').read_text())['summary'])
            # Exercise actual GeoTIFF reads, 10 m to 20 m average resampling,
            # calibrated scale/offset, and cloud halos across worker boundaries.
            import rasterio
            from rasterio.transform import from_origin
            remote=base/'source-case';remote.mkdir()
            for date in dates:
                item=json.loads((base/f'c1-{date}.json').read_text())['features'][0]
                with np.load(base/(item['id']+'.npz')) as saved:bands=saved['bands']
                for j,name in enumerate(['red','green','blue','nir','scl']):
                    path=remote/f'{date}-{name}.tif'
                    data=np.repeat(np.repeat(bands[j],2,axis=0),2,axis=1)
                    scale,offset=(1.,0.) if name=='scl' else (.0001,-.1)
                    raw=np.rint((data-offset)/scale).astype('uint16')
                    with rasterio.open(path,'w',driver='GTiff',width=width*2,height=height*2,count=1,dtype='uint16',crs='EPSG:32644',transform=from_origin(l,t,10,10)) as dst:
                        dst.write(raw,1);dst.scales=[scale];dst.offsets=[offset]
                    item['assets'][name]={'href':str(path),'raster:bands':[{'scale':scale,'offset':offset}]}
                (remote/f'c1-{date}.json').write_text(json.dumps({'features':[item]}))
                (remote/f'official-{date}.json').write_bytes((base/f'official-{date}.json').read_bytes())
            (remote/'existing-metrics.json').write_bytes((base/'existing-metrics.json').read_bytes())
            with patch('satellite_tiles.available_memory',return_value=3_000_000_000):build(remote,geometry,remote/'tiled',tiled)
            build(remote,geometry,remote/'legacy',config)
            r=json.loads((remote/'tiled/comparison.json').read_text());v=json.loads((remote/'legacy/comparison.json').read_text())
            self.assertEqual(r['summary'],v['summary']);self.assertEqual(r['villages'],v['villages'])
            for date in dates:np.testing.assert_array_equal(np.asarray(Image.open(remote/'legacy'/f'{date}.webp')),np.asarray(Image.open(remote/'tiled'/f'{date}.webp')))


if __name__=='__main__':unittest.main()
