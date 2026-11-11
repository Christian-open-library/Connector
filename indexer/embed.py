"""Embed every passage of a build with Voyage, through a persistent cache (DESIGN.md section 6).

    python -m indexer.embed BUILD_DIR --dry-run          # token/cost estimate, no API calls
    python -m indexer.embed BUILD_DIR                    # embed what is missing from the cache

Only inputs missing from data/emb_cache.sqlite are sent, so reruns and nightly updates are cheap.
The cache stores float16 vectors keyed by sha1(model|dims|input).
"""
from __future__ import annotations

import argparse
import hashlib
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


def missing(build_dir: Path, cache: sqlite3.Connection, model: str, dims: int):
    """(passage id, cache key, text) for inputs not yet cached."""
    out = []
    have = cache.execute('SELECT count(*) FROM vec').fetchone()[0]
    for pid, text in iter_inputs(build_dir):
        key = cache_key(model, dims, text)
        if have and cache.execute('SELECT 1 FROM vec WHERE key=?', (key,)).fetchone():
            continue
        out.append((pid, key, text))
    return out


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
    cache = open_cache()
    ids, vecs = [], []
    for pid, text in iter_inputs(build_dir):
        row = cache.execute('SELECT v FROM vec WHERE key=?', (cache_key(model, dims, text),)).fetchone()
        if row is None:
            raise SystemExit(f'passage P{pid} has no cached embedding: run indexer.embed first')
        ids.append(pid)
        vecs.append(np.frombuffer(row[0], dtype=np.float16))
    return np.array(ids, dtype=np.int64), np.stack(vecs).astype(np.float32)


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
