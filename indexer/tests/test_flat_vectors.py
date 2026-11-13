import hashlib
import os
import tempfile
import unittest
from pathlib import Path

import numpy as np

from indexer import embed


class FlatStore(unittest.TestCase):
    """The flat store must give exactly the cache's bytes, and fall back to the cache for anything else."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        os.environ['LIBRARY_BUILD_CACHE_DIR'] = self.tmp.name
        self.cache = embed.open_cache(Path(self.tmp.name) / 'emb.sqlite')
        rng = np.random.default_rng(1)
        self.keys = [hashlib.sha1(str(i).encode()).hexdigest() for i in range(300)]
        self.vecs = {k: rng.standard_normal(8).astype(np.float16).tobytes() for k in self.keys}
        self.cache.executemany('INSERT INTO vec VALUES (?,?)', self.vecs.items())
        self.cache.commit()

    def tearDown(self):
        del os.environ['LIBRARY_BUILD_CACHE_DIR']
        self.cache.close()
        self.tmp.cleanup()

    def test_same_bytes_with_and_without_the_flat_store(self):
        want = self.keys[::-1] + ['0' * 40]                  # one key nobody has
        X0, absent0 = embed.fill(want, 8, self.cache)        # no flat store yet: all from the cache
        half = self.keys[:150] + self.keys[:10]              # last build: half of them, with duplicates
        embed.save_flat(half, np.stack([np.frombuffer(self.vecs[k], np.float16) for k in half]), 8)
        X1, absent1 = embed.fill(want, 8, self.cache)
        self.assertEqual(X0.tobytes(), X1.tobytes())
        self.assertEqual(absent0, [300])
        self.assertEqual(absent1, [300])
        for i, k in enumerate(want[:-1]):
            self.assertEqual(X1[i].tobytes(), self.vecs[k])

    def test_a_damaged_flat_store_is_ignored(self):
        embed.save_flat(self.keys, np.stack([np.frombuffer(self.vecs[k], np.float16) for k in self.keys]), 8)
        p = embed.flat_path(8)
        p.write_bytes(p.read_bytes()[:-5])
        self.assertIsNone(embed.open_flat(8))
        X, absent = embed.fill(self.keys, 8, self.cache)
        self.assertEqual(absent, [])
        self.assertEqual(X[7].tobytes(), self.vecs[self.keys[7]])


if __name__ == '__main__':
    unittest.main()
