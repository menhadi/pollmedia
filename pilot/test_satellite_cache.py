import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
import numpy as np
from build_pilibhit_satellite import save_cache, band_workers

class SatelliteCacheTest(unittest.TestCase):
    def test_interrupted_write_preserves_completed_cache(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'band.npz'
            values = np.array([[1, 2]], dtype='float32')
            save_cache(path, values=values)
            original = path.read_bytes()
            with patch('build_pilibhit_satellite.np.savez_compressed', side_effect=OSError('interrupted')):
                with self.assertRaises(OSError):
                    save_cache(path, values=values + 1)
            self.assertEqual(path.read_bytes(), original)
            with np.load(path) as saved:
                np.testing.assert_array_equal(saved['values'], values)

    def test_large_rasters_remain_sequential(self):
        self.assertEqual(band_workers(8_000_001), 1)
