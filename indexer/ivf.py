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
import shutil
import sqlite3
import time
import zlib
from pathlib import Path

import numpy as np

from .embed import DIMS, DOC_MODEL, QUERY_MODEL, fill, input_keys, iter_inputs, open_cache, update_flat

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


def load_vectors(build_dir: Path, model: str, dims: int, fake: bool, save: bool = False):
    """(passage ids in id order, float16 rows, the row of each passage or None when row i is passage i).
    save (full builds): the rows are the flat store itself, brought up to date and read from disk as needed,
    so the vectors (1 GB per million passages) are never all in memory; one row may serve several passages."""
    if fake:
        rows = list(iter_inputs(build_dir))
        ids = np.array([pid for pid, _ in rows], dtype=np.int64)
        X = np.zeros((len(rows), dims), dtype=np.float16)
        for i, (_, text) in enumerate(rows):
            X[i] = fake_embed(text, dims)
        return ids, X, None
    pids, keys = input_keys(build_dir, model, dims)
    ids = np.array(pids, dtype=np.int64)
    if save:
        X, row, absent = update_flat(keys, dims, open_cache())
    else:
        (X, absent), row = fill(keys, dims, open_cache()), None
    if absent:
        raise SystemExit(f'P{pids[absent[0]]} has no cached embedding: run python -m indexer.embed {build_dir}')
    return ids, X, row


SWEEP = 16_384                  # rows read at a time
BUCKET_BYTES = 128 << 20        # vectors.bin is put in order this much at a time


def sweep(X, row):
    """Yields (float16 rows, the passages they belong to, where each passage's row is in them), going through
    X front to back once: a spinning disk reads that at full speed, and only one piece is in memory."""
    n = len(X) if row is None else len(row)
    by_row = None if row is None else np.argsort(row, kind='stable')
    for a in range(0, len(X), SWEEP):
        b = min(a + SWEEP, len(X))
        if row is None:
            who = np.arange(a, min(b, n))
        else:
            who = by_row[np.searchsorted(row, a, sorter=by_row):np.searchsorted(row, b, sorter=by_row)]
        if len(who):
            yield np.asarray(X[a:b]), who, (who if row is None else row[who]) - a


def pick(X, row, idx: np.ndarray) -> np.ndarray:
    """The float16 rows of passages idx, in that order (a passage may be asked for more than once)."""
    want, back = np.unique(idx, return_inverse=True)
    out = np.empty((len(want), X.shape[1]), dtype=np.float16)
    slot = np.full(len(X) if row is None else len(row), -1, dtype=np.int64)
    slot[want] = np.arange(len(want))
    for block, who, at in sweep(X, row):
        m = slot[who] >= 0
        out[slot[who[m]]] = block[at[m]]
    return out[back.reshape(-1)]


def kmeans(S: np.ndarray, k: int, iters: int, rng) -> np.ndarray:
    """Spherical k-means on the sample S (float32 rows, made unit length in place); returns unit-length
    float32 centroids (k x d)."""
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
          model: str = DOC_MODEL, dims: int = DIMS, save_flat: bool = False):
    t0 = time.time()
    ids, X, row = load_vectors(build_dir, model, dims, fake, save=save_flat and not fake)
    n = len(ids)
    if n and ids.max() >= 2**32:
        raise SystemExit('passage id does not fit in uint32')
    k = max(1, min(k, n // 40))
    print(f'{n} vectors ready in {time.time() - t0:.0f}s; clustering into {k} lists', flush=True)

    # Pass 1 over the vectors: the k-means sample and the sample for the quantization scale (one global scale
    # keeps dot products comparable).
    rng = np.random.default_rng(7)
    idx = rng.choice(n, size=min(sample, n), replace=False)
    idx2 = np.random.default_rng(11).choice(n, size=min(n, 100_000), replace=False)
    both = pick(X, row, np.concatenate([idx, idx2]))
    scale = float(127.0 / max(np.percentile(np.abs(both[len(idx):].astype(np.float32)), 99.999), 1e-6))
    S = both[:len(idx)].astype(np.float32)
    del both
    C = kmeans(S, k, iters, rng)
    del S

    # Pass 2: the list of every passage, and its int8 vector appended to a scratch file per run of lists.
    per = max(1, -(-k // max(1, -(-n * dims // BUCKET_BYTES))))      # lists per scratch file
    tmp = build_dir / 'ivf-tmp'
    shutil.rmtree(tmp, ignore_errors=True)
    tmp.mkdir()
    files = [open(tmp / f'{b}.bin', 'wb') for b in range(-(-k // per))]
    held: list[list[np.ndarray]] = [[] for _ in files]
    assign = np.empty(n, dtype=np.int32)
    for block, who, at in sweep(X, row):
        block = block.astype(np.float32)
        lists = np.argmax(block @ C.T, axis=1).astype(np.int32)[at]
        assign[who] = lists
        q = np.clip(np.rint(block * scale), -127, 127).astype(np.int8)[at]
        for b in np.unique(lists // per):
            m = lists // per == b
            files[b].write(q[m].tobytes())
            held[b].append(who[m])
    for f in files:
        f.close()
    del X, row
    order = np.lexsort((ids, assign))
    counts = np.bincount(assign, minlength=k)
    starts = np.concatenate([[0], np.cumsum(counts)[:-1]])
    pos = np.empty(n, dtype=np.int64)
    pos[order] = np.arange(n)

    done = 0
    with open(build_dir / 'vectors.bin', 'wb') as vf:
        for b, f in enumerate(files):
            who = np.concatenate(held[b]) if held[b] else np.empty(0, dtype=np.int64)
            q = np.fromfile(f.name, dtype=np.int8).reshape(len(who), dims)
            out = np.empty_like(q)
            out[pos[who] - done] = q
            vf.write(out.tobytes())
            done += len(who)
    assert done == n
    shutil.rmtree(tmp)
    ids[order].astype('<u4').tofile(build_dir / 'ids.bin')

    db = sqlite3.connect(build_dir / 'library.sqlite')
    db.execute('PRAGMA journal_mode=OFF')
    db.execute('DELETE FROM ivf')
    db.executemany('INSERT INTO ivf(cluster, centroid, start, count) VALUES (?,?,?,?)',
                   [(c, C[c].astype('<f4').tobytes(), int(starts[c]), int(counts[c])) for c in range(k)])
    db.execute('UPDATE passages SET vec_pos = NULL')
    db.executemany('UPDATE passages SET vec_pos=? WHERE id=?',
                   ((int(pos[i]), int(ids[i])) for i in range(n)))
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
