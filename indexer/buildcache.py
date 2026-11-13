"""Per-work caches for the nightly build (HANDOFF-faster-update.md, step 3).

Parsing a work (build_db) and finding its Scripture citations (references) give the same rows every night
unless the work's files, its catalog row, the lexicon or the indexer code change. Those rows are kept here,
keyed by a hash of everything they depend on, so a nightly build only redoes the works that changed.

The key covers the source of every indexer module that makes these rows (all but NOT_CACHED), the lexicon, and the zlib and YAML versions (zlib output
is stored in the rows), so any code change starts from an empty cache: a cached row is always the row a full
build would make. Set LIBRARY_BUILD_CACHE=off to build without it.

Store: data/build-cache/works.sqlite (on the homebox a symlink to the big disk). After each full build the
entries that build did not use are dropped, so the store holds one library's worth of rows.
"""
from __future__ import annotations

import dataclasses
import hashlib
import json
import os
import pickle
import sqlite3
import sys
import zlib
from pathlib import Path

import yaml

INDEXER = Path(__file__).resolve().parent
DEFAULT_DIR = INDEXER.parent / 'data' / 'build-cache'
TABLES = ('parsed', 'refs')
# Modules that cannot change a cached row (vectors, the nightly driver, Voyage, the injection check): a change to
# them keeps the cache. Every other module, including any new one, is part of the key.
NOT_CACHED = {'embed.py', 'ivf.py', 'pipeline.py', 'voyage.py', 'injection.py'}


def enabled() -> bool:
    return os.environ.get('LIBRARY_BUILD_CACHE', '').lower() not in ('off', '0', 'no')


def cache_path() -> Path:
    return Path(os.environ.get('LIBRARY_BUILD_CACHE_DIR') or DEFAULT_DIR) / 'works.sqlite'


def code_version(*extra: bytes) -> str:
    """Hash of every indexer module, the lexicon and the library versions whose output is cached."""
    from .modernize import DATA
    h = hashlib.sha256()
    for p in sorted(INDEXER.glob('*.py')):
        if p.name not in NOT_CACHED:
            h.update(p.name.encode() + b'\0' + p.read_bytes() + b'\0')
    lex = DATA / 'lexicon.tsv'
    h.update(lex.read_bytes() if lex.exists() else b'-')
    h.update(f'{zlib.ZLIB_RUNTIME_VERSION}|{yaml.__version__}|{sys.version_info[:2]}'.encode())
    for e in extra:
        h.update(b'\0' + e)
    return h.hexdigest()


def work_key(version: str, repo: Path, work) -> str:
    """Hash of the code version, the work's catalog fields (its root, '' or 'licensed', among them) and the
    bytes of its files, read from repo (the work's root)."""
    from .corpus import repo_file
    h = hashlib.sha256(version.encode())
    h.update(json.dumps(dataclasses.asdict(work), sort_keys=True, ensure_ascii=False, default=str).encode())
    for rel in work.files:
        data = repo_file(repo, rel).read_bytes()
        h.update(b'\0%d\0' % len(data) + data)
    return h.hexdigest()


def open_store(readonly: bool = False) -> sqlite3.Connection:
    path = cache_path()
    if readonly:
        return sqlite3.connect(f'file:{path}?mode=ro', uri=True, timeout=120)
    path.parent.mkdir(parents=True, exist_ok=True)
    db = sqlite3.connect(path, timeout=120)
    db.execute('PRAGMA journal_mode=WAL')
    for t in TABLES:
        db.execute(f'CREATE TABLE IF NOT EXISTS {t}(key TEXT PRIMARY KEY, value BLOB NOT NULL) WITHOUT ROWID')
    db.commit()
    return db


def pack(value) -> bytes:
    return zlib.compress(pickle.dumps(value, protocol=5), 1)


def unpack(blob: bytes):
    return pickle.loads(zlib.decompress(blob))


def get(db: sqlite3.Connection, table: str, key: str):
    row = db.execute(f'SELECT value FROM {table} WHERE key=?', (key,)).fetchone()
    return None if row is None else unpack(row[0])


def put(db: sqlite3.Connection, table: str, key: str, value) -> None:
    db.execute(f'INSERT OR REPLACE INTO {table}(key, value) VALUES (?,?)', (key, pack(value)))


def put_packed(db: sqlite3.Connection, table: str, key: str, blob: bytes) -> None:
    db.execute(f'INSERT OR REPLACE INTO {table}(key, value) VALUES (?,?)', (key, blob))


def prune(db: sqlite3.Connection, table: str, used: set[str]) -> int:
    """Drops the entries of table that are not in used (call after a full build only)."""
    db.execute('CREATE TEMP TABLE IF NOT EXISTS keep(key TEXT PRIMARY KEY) WITHOUT ROWID')
    db.execute('DELETE FROM keep')
    db.executemany('INSERT OR IGNORE INTO keep VALUES (?)', ((k,) for k in used))
    n = db.execute(f'DELETE FROM {table} WHERE key NOT IN (SELECT key FROM keep)').rowcount
    db.commit()
    return n
