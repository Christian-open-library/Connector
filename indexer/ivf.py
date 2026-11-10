"""Vector index for a build: k-means clusters + packed int8 vectors (DESIGN.md sections 5-6).

    python -m indexer.ivf BUILD_DIR            # uses cached Voyage embeddings (indexer.embed)
    python -m indexer.ivf BUILD_DIR --fake     # deterministic hashed-word vectors (tests only)

Writes vectors.bin (int8, rows grouped by cluster), ids.bin (uint32 LE passage ids), fills the
ivf table and passages.vec_pos in library.sqlite, records meta, and writes manifest.json.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sqlite3
import time
import zlib
from pathlib import Path

import numpy as np

from .embed import DIMS, DOC_MODEL, QUERY_MODEL, cache_key, iter_inputs, open_cache

RERANK_MODEL = 'rerank-3'
FAKE_TOKEN_RE = re.compile(r'[a-z]+')


def fake_embed(text: str, dims: int) -> np.ndarray:
    """Hashed bag-of-words vector; PHP's Voyage fake mode must compute the same thing."""
    v = np.zeros(dims, dtype=np.float32)
    for tok in FAKE_TOKEN_RE.findall(text.lower()):
        h = zlib.crc32(tok.encode('utf-8')) & 0xFFFFFFFF
        v[h % dims] += 1.0 if (h >> 16) & 1 else -1.0
    n = np.linalg.norm(v)
    return v / n if n else v


def load_vectors(build_dir: Path, model: str, dims: int, fake: bool):
    src = sqlite3.connect(build_dir / 'embed_inputs.sqlite')
    n = src.execute('SELECT count(*) FROM inputs').fetchone()[0]
    src.close()
    ids = np.zeros(n, dtype=np.int64)
    X = np.zeros((n, dims), dtype=np.float16)
    cache = None if fake else open_cache()
    for i, (pid, text) in enumerate(iter_inputs(build_dir)):
        ids[i] = pid
        if fake:
            X[i] = fake_embed(text, dims)
        else:
            row = cache.execute('SELECT v FROM vec WHERE key=?', (cache_key(model, dims, text),)).fetchone()
            if row is None:
                raise SystemExit(f'P{pid} has no cached embedding: run python -m indexer.embed {build_dir}')
            X[i] = np.frombuffer(row[0], dtype=np.float16)
    return ids, X


def kmeans(X: np.ndarray, k: int, sample: int, iters: int, seed: int = 7) -> np.ndarray:
    """Spherical k-means on a sample; returns unit-length float32 centroids (k x d)."""
    rng = np.random.default_rng(seed)
    idx = rng.choice(len(X), size=min(sample, len(X)), replace=False)
    S = X[idx].astype(np.float32)
    S /= np.maximum(np.linalg.norm(S, axis=1, keepdims=True), 1e-9)
    C = S[rng.choice(len(S), size=k, replace=False)].copy()
    for it in range(iters):
        assign = np.empty(len(S), dtype=np.int32)
        for a in range(0, len(S), 20000):
            assign[a:a + 20000] = np.argmax(S[a:a + 20000] @ C.T, axis=1)
        newC = np.zeros_like(C)
        np.add.at(newC, assign, S)
        counts = np.bincount(assign, minlength=k)
        empty = np.where(counts == 0)[0]
        if len(empty):
            newC[empty] = S[rng.choice(len(S), size=len(empty), replace=False)]
        newC /= np.maximum(np.linalg.norm(newC, axis=1, keepdims=True), 1e-9)
        shift = float(np.mean(np.sum(newC * C, axis=1)))
        C = newC
        print(f'  k-means iter {it + 1}/{iters}: mean centroid similarity to previous {shift:.4f}, '
              f'{len(empty)} empty', flush=True)
        if shift > 0.9995:
            break
    return C.astype(np.float32)


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def write_manifest(build_dir: Path) -> dict:
    db = sqlite3.connect(build_dir / 'library.sqlite')
    meta = dict(db.execute('SELECT key, value FROM meta'))
    db.close()
    files = {}
    for name in ('library.sqlite', 'vectors.bin', 'ids.bin'):
        p = build_dir / name
        if p.exists():
            files[name] = {'bytes': p.stat().st_size, 'sha256': sha256(p)}
    manifest = {'build_id': meta.get('build_id', build_dir.name), 'meta': meta, 'files': files}
    (build_dir / 'manifest.json').write_text(json.dumps(manifest, indent=1), encoding='utf-8')
    return manifest


def build(build_dir: Path, fake: bool, k: int, sample: int, iters: int,
          model: str = DOC_MODEL, dims: int = DIMS):
    t0 = time.time()
    ids, X = load_vectors(build_dir, model, dims, fake)
    n = len(ids)
    k = max(1, min(k, n // 40))
    print(f'{n} vectors loaded in {time.time() - t0:.0f}s; clustering into {k} lists', flush=True)
    C = kmeans(X, k, sample, iters)

    # quantization scale from a sample (one global scale keeps dot products comparable)
    rng = np.random.default_rng(11)
    samp = X[rng.choice(n, size=min(n, 100_000), replace=False)].astype(np.float32)
    scale = float(127.0 / max(np.percentile(np.abs(samp), 99.999), 1e-6))

    assign = np.empty(n, dtype=np.int32)
    for a in range(0, n, 50_000):
        block = X[a:a + 50_000].astype(np.float32)
        assign[a:a + 50_000] = np.argmax(block @ C.T, axis=1)
    order = np.lexsort((ids, assign))
    counts = np.bincount(assign, minlength=k)
    starts = np.concatenate([[0], np.cumsum(counts)[:-1]])

    with open(build_dir / 'vectors.bin', 'wb') as vf:
        for a in range(0, n, 50_000):
            rows = order[a:a + 50_000]
            q = np.clip(np.rint(X[rows].astype(np.float32) * scale), -127, 127).astype(np.int8)
            vf.write(q.tobytes())
    if ids.max() >= 2**32:
        raise SystemExit('passage id does not fit in uint32')
    ids[order].astype('<u4').tofile(build_dir / 'ids.bin')

    db = sqlite3.connect(build_dir / 'library.sqlite')
    db.execute('PRAGMA journal_mode=OFF')
    db.execute('DELETE FROM ivf')
    db.executemany('INSERT INTO ivf(cluster, centroid, start, count) VALUES (?,?,?,?)',
                   [(c, C[c].astype('<f4').tobytes(), int(starts[c]), int(counts[c])) for c in range(k)])
    db.execute('UPDATE passages SET vec_pos = NULL')
    pos = np.empty(n, dtype=np.int64)
    pos[order] = np.arange(n)
    db.executemany('UPDATE passages SET vec_pos=? WHERE id=?',
                   [(int(pos[i]), int(ids[i])) for i in range(n)])
    meta = {'embed_model': model, 'query_model': QUERY_MODEL, 'embed_dims': str(dims),
            'embed_dtype': 'int8', 'embed_scale': repr(scale), 'ivf_k': str(k),
            'rerank_model': RERANK_MODEL, 'embed_fake': '1' if fake else '0',
            'n_vectors': str(n)}
    db.executemany('INSERT OR REPLACE INTO meta(key, value) VALUES (?,?)', meta.items())
    db.commit()
    db.execute('PRAGMA journal_mode=DELETE')
    db.execute('VACUUM') if n < 200_000 else None
    db.close()
    write_manifest(build_dir)
    sizes = counts[counts > 0]
    print(f'ivf done in {time.time() - t0:.0f}s: k={k}, list sizes min/median/max '
          f'{sizes.min()}/{int(np.median(sizes))}/{sizes.max()}, scale {scale:.1f}')


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument('build_dir', type=Path)
    ap.add_argument('--fake', action='store_true', help='hashed-word vectors, for tests only')
    ap.add_argument('--k', type=int, default=2048)
    ap.add_argument('--sample', type=int, default=200_000)
    ap.add_argument('--iters', type=int, default=12)
    args = ap.parse_args(argv)
    build(args.build_dir, args.fake, args.k, args.sample, args.iters)


if __name__ == '__main__':
    main()
