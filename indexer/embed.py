"""Embed every passage of a build with Voyage, through a persistent cache (DESIGN.md section 6).

    python -m indexer.embed BUILD_DIR --dry-run          # token/cost estimate, no API calls
    python -m indexer.embed BUILD_DIR                    # embed what is missing from the cache

Only inputs missing from data/emb_cache.sqlite are sent, so reruns and nightly updates are cheap.
The cache stores float16 vectors keyed by sha1(model|dims|input).
"""
from __future__ import annotations

import argparse
import hashlib
import os
import sqlite3
import sys
import threading
import time
import zlib
from concurrent.futures import FIRST_COMPLETED, ThreadPoolExecutor, wait
from pathlib import Path

import numpy as np

from .voyage import Voyage

ROOT = Path(__file__).resolve().parent.parent
CACHE = ROOT / 'data' / 'emb_cache.sqlite'
DOC_MODEL = 'voyage-4-lite'
QUERY_MODEL = 'voyage-4-large'   # searches are understood by the large model; books stay indexed with the lite one (shared space), 2026-11-11 test
DIMS = 512
PRICE_PER_M = 0.02          # dollars per million tokens for DOC_MODEL
CHARS_PER_TOKEN = 3.2       # conservative for old spelling
BATCH_TEXTS = 500
BATCH_CHARS = 1_200_000     # ~375k tokens, well under the 1M-token request cap


def cache_key(model: str, dims: int, text: str) -> str:
    return hashlib.sha1(f'{model}|{dims}|{text}'.encode('utf-8')).hexdigest()


def open_cache(path: Path = CACHE) -> sqlite3.Connection:
    path.parent.mkdir(parents=True, exist_ok=True)
    db = sqlite3.connect(path, check_same_thread=False, timeout=60)
    db.execute('PRAGMA journal_mode=WAL')
    db.execute('CREATE TABLE IF NOT EXISTS vec(key TEXT PRIMARY KEY, v BLOB NOT NULL) WITHOUT ROWID')
    return db


def iter_inputs(build_dir: Path):
    src = sqlite3.connect(build_dir / 'embed_inputs.sqlite')
    for pid, blob in src.execute('SELECT id, input FROM inputs ORDER BY id'):
        yield pid, zlib.decompress(blob).decode('utf-8')
    src.close()


def input_keys(build_dir: Path, model: str, dims: int):
    """(passage ids, cache keys) of every input of the build, in id order."""
    pids, keys = [], []
    for pid, text in iter_inputs(build_dir):
        pids.append(pid)
        keys.append(cache_key(model, dims, text))
    return pids, keys


def lookup_sorted(cache: sqlite3.Connection, keys: list[str], batch: int = 500):
    """Yields (index into keys, vector bytes or None) for every key, visiting the cache in key order.
    The cache (many GB, far bigger than RAM) is then read front to back once instead of at random,
    which took over an hour per pass on the homebox. Same rows, same bytes as one SELECT per key."""
    order = sorted(range(len(keys)), key=keys.__getitem__)
    for a in range(0, len(order), batch):
        idx = order[a:a + batch]
        want = sorted({keys[i] for i in idx})
        found = dict(cache.execute(f'SELECT key, v FROM vec WHERE key IN ({",".join("?" * len(want))})', want))
        for i in idx:
            yield i, found.get(keys[i])


# The flat store: the vectors of the last full build in one file on the big disk (next to the per-work caches,
# indexer.buildcache), sorted by cache key: [n: 8 bytes][n sha1 digests, 20 bytes each][n x dims float16].
# Its rows are bytes read from the cache, so a vector taken from it is the cache's own; only passages new since
# the last build are looked up in the (15 GB, slow to read) cache. A missing or damaged file just means the cache
# is read instead.

def flat_path(dims: int) -> Path:
    from .buildcache import cache_path
    return cache_path().parent / f'vectors-{dims}.flat'


def _digests(keys: list[str]) -> np.ndarray:
    return np.array([bytes.fromhex(k) for k in keys], dtype='S20')


def open_flat(dims: int):
    """(sorted digests, float16 rows) as read-only memory maps, or None."""
    from .buildcache import enabled
    p = flat_path(dims)
    if not enabled() or not p.exists():
        return None
    with open(p, 'rb') as f:
        n = int.from_bytes(f.read(8), 'little')
    if n == 0 or p.stat().st_size != 8 + n * 20 + n * dims * 2:
        print(f'  ignoring {p}: wrong size', flush=True)
        return None
    return (np.memmap(p, dtype='S20', mode='r', offset=8, shape=(n,)),
            np.memmap(p, dtype=np.float16, mode='r', offset=8 + n * 20, shape=(n, dims)))


def flat_positions(flat, keys: list[str]) -> np.ndarray:
    """Row of each key in the flat store, or -1."""
    if flat is None or not keys:
        return np.full(len(keys), -1, dtype=np.int64)
    fk = flat[0]
    d = _digests(keys)
    pos = np.minimum(np.searchsorted(fk, d), len(fk) - 1)
    return np.where(fk[pos] == d, pos, -1)


def save_flat(keys: list[str], X: np.ndarray, dims: int) -> None:
    """Writes the flat store for these keys and rows (replacing the old one in one rename)."""
    d, first = np.unique(_digests(keys), return_index=True)
    p = flat_path(dims)
    p.parent.mkdir(parents=True, exist_ok=True)
    tmp = p.with_suffix('.tmp')
    with open(tmp, 'wb') as f:
        f.write(len(d).to_bytes(8, 'little'))
        f.write(d.tobytes())
        for a in range(0, len(first), 100_000):
            f.write(np.ascontiguousarray(X[first[a:a + 100_000]], dtype=np.float16).tobytes())
    os.replace(tmp, p)


def fill(keys: list[str], dims: int, cache: sqlite3.Connection):
    """(float16 matrix of the vectors of keys, indexes of the keys that have none), flat store first."""
    X = np.zeros((len(keys), dims), dtype=np.float16)
    flat = open_flat(dims)
    pos = flat_positions(flat, keys)
    hit = np.flatnonzero(pos >= 0)
    for a in range(0, len(hit), 100_000):
        h = hit[a:a + 100_000]
        X[h] = flat[1][pos[h]]
    rest = np.flatnonzero(pos < 0)
    absent = []
    for j, v in lookup_sorted(cache, [keys[i] for i in rest]):
        if v is None:
            absent.append(int(rest[j]))
        else:
            X[rest[j]] = np.frombuffer(v, dtype=np.float16)
    print(f'  vectors: {len(hit)} from the flat store, {len(rest) - len(absent)} from the cache, '
          f'{len(absent)} missing', flush=True)
    return X, absent


def missing(build_dir: Path, cache: sqlite3.Connection, model: str, dims: int):
    """(passage id, cache key, text) for inputs not yet cached, in id order."""
    pids, keys = input_keys(build_dir, model, dims)
    rest = np.flatnonzero(flat_positions(open_flat(dims), keys) < 0)
    absent = {pids[rest[j]] for j, v in lookup_sorted(cache, [keys[i] for i in rest]) if v is None}
    if not absent:
        return []
    return [(pid, cache_key(model, dims, text), text) for pid, text in iter_inputs(build_dir) if pid in absent]


def batches(items, max_texts=BATCH_TEXTS, max_chars=BATCH_CHARS):
    cur, chars = [], 0
    for it in items:
        n = len(it[2])
        if cur and (len(cur) >= max_texts or chars + n > max_chars):
            yield cur
            cur, chars = [], 0
        cur.append(it)
        chars += n
    if cur:
        yield cur


def run(build_dir: Path, dry_run: bool, max_dollars: float, workers: int, model: str, dims: int):
    cache = open_cache()
    todo = missing(build_dir, cache, model, dims)
    chars = sum(len(t) for _, _, t in todo)
    est_tokens = int(chars / CHARS_PER_TOKEN)
    gross = est_tokens / 1e6 * PRICE_PER_M
    print(f'{len(todo)} passages to embed, ~{est_tokens / 1e6:.0f}M tokens, at most ${gross:.2f} '
          f'before Voyage free tokens ({model}, {dims} dims)')
    if dry_run or not todo:
        return
    if gross > max_dollars:
        sys.exit(f'refusing: estimate ${gross:.2f} exceeds --max-dollars {max_dollars}')
    api = Voyage()
    embed_batches(api, todo, max_dollars, workers, model, dims, cache)


def embed_batches(api, todo, max_dollars: float, workers: int, model: str, dims: int, cache) -> int:
    """Sends the batches with at most 2 x workers in flight. Before each request its estimate is
    reserved against --max-dollars together with the tokens billed so far, the estimates of the
    requests in flight and those of failed attempts (which may have been billed too); once the
    budget would be passed, a shared stop flag ends every further request and the queued batches
    are cancelled. Returns the tokens billed."""
    budget = max_dollars / PRICE_PER_M * 1e6        # tokens
    lock = threading.Lock()
    stop = threading.Event()
    api.stop = stop
    state = {'passages': 0, 'billed': 0, 'inflight': 0}
    t0 = time.time()

    def spent() -> float:
        return state['billed'] + state['inflight'] + getattr(api, 'failed_tokens', 0)

    def work(batch):
        if stop.is_set():
            return
        est = int(sum(len(t) for _, _, t in batch) / CHARS_PER_TOKEN) + 10
        with lock:
            if spent() + est > budget:
                stop.set()
                raise SystemExit('stopping: the next request would pass --max-dollars '
                                 f'({spent() / 1e6:.2f}M tokens billed or in flight)')
            state['inflight'] += est
        try:
            vecs, tokens = api.embed([t for _, _, t in batch], model, 'document', dims)
        finally:
            with lock:
                state['inflight'] -= est
        rows = [(key, vecs[i].astype(np.float16).tobytes()) for i, (_, key, _) in enumerate(batch)]
        with lock:
            cache.executemany('INSERT OR REPLACE INTO vec(key, v) VALUES (?,?)', rows)
            cache.commit()
            state['passages'] += len(batch)
            state['billed'] += tokens
            if state['passages'] % 20000 < len(batch):
                rate = state['billed'] / max(time.time() - t0, 1) * 60
                print(f'  {state["passages"]}/{len(todo)} passages, {state["billed"] / 1e6:.1f}M tokens, '
                      f'{rate / 1e6:.1f}M tokens/min', flush=True)
            if spent() > budget:
                stop.set()
                raise SystemExit('stopping: billed tokens passed --max-dollars')

    pending: set = set()
    with ThreadPoolExecutor(workers) as pool:
        try:
            for b in batches(todo):
                if stop.is_set():
                    break
                while len(pending) >= 2 * workers:
                    finished, pending = wait(pending, return_when=FIRST_COMPLETED)
                    for f in finished:
                        f.result()
                pending.add(pool.submit(work, b))
            while pending:
                finished, pending = wait(pending, return_when=FIRST_COMPLETED)
                for f in finished:
                    f.result()
        except BaseException:
            stop.set()
            for f in pending:
                f.cancel()
            raise
    print(f'embedded {state["passages"]} passages, {state["billed"] / 1e6:.1f}M tokens billed, '
          f'{time.time() - t0:.0f}s')
    return state['billed']


def load_matrix(build_dir: Path, model: str = DOC_MODEL, dims: int = DIMS):
    """(ids int64 array, float32 matrix) for every passage of the build, in id order."""
    pids, keys = input_keys(build_dir, model, dims)
    X, absent = fill(keys, dims, open_cache())
    if absent:
        raise SystemExit(f'passage P{pids[absent[0]]} has no cached embedding: run indexer.embed first')
    return np.array(pids, dtype=np.int64), X.astype(np.float32)


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument('build_dir', type=Path)
    ap.add_argument('--dry-run', action='store_true')
    ap.add_argument('--max-dollars', type=float, default=15.0)
    ap.add_argument('--workers', type=int, default=4)
    ap.add_argument('--model', default=DOC_MODEL)
    ap.add_argument('--dims', type=int, default=DIMS)
    args = ap.parse_args(argv)
    run(args.build_dir, args.dry_run, args.max_dollars, args.workers, args.model, args.dims)


if __name__ == '__main__':
    main()
