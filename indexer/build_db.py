"""Build the search database from the books repo (DESIGN.md section 5).

    python -m indexer.build_db                       # full build into data/build/<build_id>/
    python -m indexer.build_db --limit-works 40      # small sample build (for tests)

Writes library.sqlite (deployed) and embed_inputs.sqlite (build-side only: the text that
embed.py sends to Voyage for each passage).
"""
from __future__ import annotations

import argparse
import json
import multiprocessing as mp
import os
import shutil
import sqlite3
import subprocess
import sys
import time
import zlib
from datetime import datetime, timezone
from pathlib import Path

from . import corpus
from .modernize import Modernizer, load_lexicon, pre_markdown

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / 'data'
OCR_MIN_WORDLIKE = 0.5

SCHEMA = """
CREATE TABLE meta(key TEXT PRIMARY KEY, value TEXT);
CREATE TABLE works(
  id INTEGER PRIMARY KEY, key TEXT UNIQUE, author TEXT, author_slug TEXT, title TEXT,
  full_title TEXT, year INTEGER, type TEXT, collection TEXT, license TEXT, source_url TEXT,
  tcp_id TEXT, series TEXT, ocr INTEGER, n_passages INTEGER, page_first TEXT, page_last TEXT);
CREATE TABLE passages(
  id INTEGER PRIMARY KEY, work_id INTEGER NOT NULL, seq INTEGER NOT NULL, part INTEGER,
  page_start TEXT, page_end TEXT, section TEXT,
  text BLOB NOT NULL, notes BLOB, words INTEGER, vec_pos INTEGER);
CREATE VIRTUAL TABLE passages_fts USING fts5(
  body, notes, header, content='', tokenize='porter unicode61 remove_diacritics 2');
CREATE VIRTUAL TABLE works_fts USING fts5(
  author, title, full_title, content='works', content_rowid='id',
  tokenize='porter unicode61 remove_diacritics 2');
CREATE TABLE spelling(variant TEXT PRIMARY KEY, standard TEXT NOT NULL) WITHOUT ROWID;
CREATE TABLE authors(name TEXT PRIMARY KEY, dates TEXT NOT NULL, tradition TEXT NOT NULL,
                     note TEXT NOT NULL) WITHOUT ROWID;
CREATE TABLE church_records(work_id INTEGER PRIMARY KEY, denomination TEXT NOT NULL, tradition TEXT NOT NULL,
                            doc_kind TEXT NOT NULL, status TEXT NOT NULL);
CREATE TABLE church_bodies(name TEXT PRIMARY KEY, tradition TEXT NOT NULL, founded INTEGER, ended INTEGER,
                           tags TEXT NOT NULL, aliases TEXT NOT NULL, verified INTEGER NOT NULL) WITHOUT ROWID;
CREATE TABLE church_names(name TEXT PRIMARY KEY, aliases TEXT NOT NULL, tradition TEXT NOT NULL,
                          ancestors TEXT NOT NULL, verified INTEGER NOT NULL) WITHOUT ROWID;
CREATE TABLE ivf(cluster INTEGER PRIMARY KEY, centroid BLOB NOT NULL,
                 start INTEGER NOT NULL, count INTEGER NOT NULL);
"""

_worker: dict = {}


def _init_worker(repo: str, lexicon_path: str, licensed: str | None = None):
    # Each work's files are read from its own root: the books repo, or the licensed shelf.
    _worker['roots'] = {'': Path(repo), 'licensed': Path(licensed) if licensed else None}
    _worker['mod'] = Modernizer(load_lexicon(Path(lexicon_path)))


def header_text(work: corpus.Work, section: str) -> str:
    parts = [work.author, work.title]
    if work.full_title and work.full_title != work.title:
        parts.append(work.full_title)
    if section:
        parts.append(section)
    return ' . '.join(p for p in parts if p)


def embed_input(work: corpus.Work, section: str, search_text: str) -> str:
    head = work.author
    head += f', {work.title}' if work.title else ''
    head += f' ({work.year})' if work.year else ''
    head += '.'
    if section:
        head += f' {section}.'
    return f'{head}\n{search_text}'


def _process(work: corpus.Work):
    """Worker: chunk one work and prepare rows. Returns (work, rows, used_spellings, dropped), or
    (work, None, error message, 0) when the work cannot be read: it is then left out of the build."""
    try:
        return _process_work(work)
    except Exception as e:      # UnicodeDecodeError, an unsafe part file, too many passages, ...
        return work, None, f'{type(e).__name__}: {str(e)[:200]}', 0


def _process_work(work: corpus.Work):
    mod: Modernizer = _worker['mod']
    mod.used.clear()
    rows = []
    dropped = 0
    root = _worker['roots'].get(work.root)
    if root is None:
        raise ValueError(f'no root {work.root!r} for this work')
    for p in corpus.chunk_work(root, work):
        plain = corpus.strip_markdown(pre_markdown(p.text))
        if work.ocr and mod.wordlike_share(plain) < OCR_MIN_WORDLIKE:
            dropped += 1
            continue
        body = mod.modernize_text(plain, record=True)
        notes_plain = ' '.join(corpus.strip_markdown(n) for n in p.notes)
        notes_mod = mod.modernize_text(notes_plain, record=True) if notes_plain else ''
        section_mod = mod.modernize_text(p.section) if p.section else ''
        header = mod.modernize_text(header_text(work, p.section))
        rows.append((
            p.id, p.work_id, p.seq, p.part, p.page_start, p.page_end, p.section,
            zlib.compress(p.text.encode('utf-8'), 6),
            zlib.compress(json.dumps(p.notes, ensure_ascii=False).encode('utf-8'), 6) if p.notes else None,
            p.words, body, notes_mod, header,
            embed_input(work, section_mod, body + ((' Notes: ' + notes_mod) if notes_mod else '')),
        ))
    return work, rows, dict(mod.used), dropped


def add_church_bodies(db: sqlite3.Connection, repo: Path, licensed: Path | None = None) -> None:
    """The church bodies and present-day names of the repo's Denominations/*.csv (lists as JSON), so searches
    can be limited to one denomination and the bodies it descends from, plus the bodies of the licensed shelf's
    lineage.csv (same columns; a body the books repo already has is left to it). A tag no work of this build
    carries is kept: the connector simply finds nothing for it."""
    bodies, names = corpus.load_church_bodies(repo)
    if licensed is not None:
        known = {b.name for b in bodies}
        for b in corpus.load_church_bodies(licensed, 'lineage.csv', None)[0]:
            if b.name in known:
                corpus.warn(f'licensed lineage.csv: {b.name[:80]!r} is already a body of the books repo; kept that one')
            else:
                bodies.append(b)
    db.executemany('INSERT INTO church_bodies VALUES (?,?,?,?,?,?,?)', [
        (b.name, b.tradition, b.founded, b.ended, json.dumps(b.tags, ensure_ascii=False),
         json.dumps(b.aliases, ensure_ascii=False), int(b.verified)) for b in bodies])
    db.executemany('INSERT INTO church_names VALUES (?,?,?,?,?)', [
        (n.name, json.dumps(n.aliases, ensure_ascii=False), n.tradition,
         json.dumps([list(a) for a in n.ancestors], ensure_ascii=False), int(n.verified)) for n in names])
    tagged = {row[0] for row in db.execute('SELECT DISTINCT denomination FROM church_records')}
    owned = {t for b in bodies for t in b.tags}
    for t in sorted(tagged - owned):
        corpus.warn(f'denomination {t[:80]!r} is in no body of Denominations/lineage.csv')
    n_records = db.execute('SELECT count(*) FROM church_records').fetchone()[0]
    print(f'  church records: {n_records} works, {len(bodies)} bodies, {len(names)} present-day names', flush=True)


def repo_commit(repo: Path) -> str:
    try:
        return subprocess.run(['git', '-C', str(repo), 'rev-parse', 'HEAD'], capture_output=True,
                              text=True, check=True).stdout.strip()
    except Exception:
        return ''


IDS_PATH = DATA / 'work_ids.json'


def build(repo: Path, out_dir: Path, limit_works: int = 0, processes: int = 3, licensed: Path | None = None) -> Path:
    """Builds library.sqlite in out_dir, from the books repo and, when given, the licensed shelf (works used by
    permission; corpus.licensed_works). The work-id map (new works get new ids) is written to
    out_dir/work_ids.json only; save_ids() makes it the persistent map once the build has passed
    its checks, so a failed or refused build never uses up ids."""
    t0 = time.time()
    rows = corpus.valid_rows(repo, corpus.load_catalog(repo))
    ids = corpus.assign_work_ids(rows, corpus.load_work_ids(IDS_PATH))
    works = sorted(corpus.iter_works(repo, ids, rows), key=lambda w: w.id)
    if limit_works:
        # spread a sample across collections
        step = max(1, len(works) // limit_works)
        works = works[::step][:limit_works]
    n_licensed = 0
    if licensed is not None:
        extra = corpus.licensed_works(licensed, ids)
        n_licensed = len(extra)
        works = sorted(works + extra, key=lambda w: w.id)
        print(f'  licensed shelf: {n_licensed} works from {licensed}', flush=True)

    out_dir.mkdir(parents=True, exist_ok=True)
    db_path = out_dir / 'library.sqlite'
    emb_path = out_dir / 'embed_inputs.sqlite'
    for p in (db_path, emb_path):
        if p.exists():
            p.unlink()
    corpus.save_work_ids(out_dir / 'work_ids.json', ids)
    db = sqlite3.connect(db_path)
    db.executescript('PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF; PRAGMA cache_size=-400000;')
    db.executescript(SCHEMA)
    emb = sqlite3.connect(emb_path)
    emb.executescript('PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF;'
                      'CREATE TABLE inputs(id INTEGER PRIMARY KEY, input BLOB NOT NULL);')

    used_total: dict = {}
    n_pass = n_drop = 0
    lex_path = DATA / 'lexicon.tsv'
    with mp.Pool(processes, initializer=_init_worker,
                 initargs=(str(repo), str(lex_path), str(licensed) if licensed else None)) as pool:
        n_skipped = 0
        for i, (work, prow, used, dropped) in enumerate(pool.imap(_process, works, chunksize=2), 1):
            if prow is None:
                corpus.warn(f'work skipped ({used!r}): {work.key!r}')
                n_skipped += 1
                continue
            n_drop += dropped
            db.executemany('INSERT INTO passages(id, work_id, seq, part, page_start, page_end, section,'
                           ' text, notes, words) VALUES (?,?,?,?,?,?,?,?,?,?)', [r[:10] for r in prow])
            db.executemany('INSERT INTO passages_fts(rowid, body, notes, header) VALUES (?,?,?,?)',
                           [(r[0], r[10], r[11], r[12]) for r in prow])
            emb.executemany('INSERT INTO inputs(id, input) VALUES (?,?)',
                            [(r[0], zlib.compress(r[13].encode('utf-8'), 6)) for r in prow])
            pages = [r[4] for r in prow if r[4]] + [r[5] for r in prow if r[5]]
            first_page = next((r[4] for r in prow if r[4]), None)
            last_page = next((r[5] for r in reversed(prow) if r[5]), None)
            db.execute('INSERT INTO works VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', (
                work.id, work.key, work.author, work.author_slug, work.title, work.full_title,
                work.year, work.type, work.collection, work.license, work.source_url, work.tcp_id,
                work.series, int(work.ocr), len(prow), first_page, last_page))
            if work.denomination:
                db.execute('INSERT INTO church_records VALUES (?,?,?,?,?)', (
                    work.id, work.denomination, work.church_tradition, work.doc_kind, work.status))
            for k, v in used.items():
                used_total[k] = used_total.get(k, 0) + v
            n_pass += len(prow)
            if i % 100 == 0:
                db.commit()
                emb.commit()
                print(f'  {i}/{len(works)} works, {n_pass} passages, {time.time() - t0:.0f}s', flush=True)
    db.commit()
    emb.commit()

    db.execute("INSERT INTO works_fts(works_fts) VALUES('rebuild')")
    # Who each author was (the repo's authors.csv), for the authors this build holds.
    held = {row[0] for row in db.execute('SELECT DISTINCT author FROM works')}
    notes = [(name, *fields) for name, fields in sorted(corpus.load_authors(repo).items()) if name in held]
    db.executemany('INSERT INTO authors(name, dates, tradition, note) VALUES (?,?,?,?)', notes)
    print(f'  author notes: {len(notes)} of {len(held)} authors', flush=True)
    add_church_bodies(db, repo, licensed)
    spell = {}
    for (variant, standard), n in used_total.items():
        if n >= 2 and variant != standard:
            spell[variant] = standard
    db.executemany('INSERT INTO spelling(variant, standard) VALUES (?,?)', sorted(spell.items()))
    print('  optimizing full-text index ...', flush=True)
    db.execute("INSERT INTO passages_fts(passages_fts) VALUES('optimize')")
    db.execute('CREATE INDEX passages_work_seq ON passages(work_id, seq)')
    build_id = out_dir.name
    meta = {
        'build_id': build_id,
        'built_at': datetime.now(timezone.utc).isoformat(timespec='seconds'),
        'repo_commit': repo_commit(repo),
        'chunker_version': str(corpus.CHUNKER_VERSION),
        'n_works': str(len(works) - n_skipped),
        'n_passages': str(n_pass),
        'n_dropped_ocr': str(n_drop),
        'licensed_commit': repo_commit(licensed) if licensed is not None else '',
        'n_licensed_works': str(n_licensed),
    }
    db.executemany('INSERT INTO meta(key, value) VALUES (?,?)', meta.items())
    db.commit()
    db.execute('PRAGMA journal_mode=DELETE')
    db.close()
    emb.close()
    (out_dir / 'build.json').write_text(json.dumps(meta, indent=1), encoding='utf-8')
    print(f'built {db_path} in {time.time() - t0:.0f}s: {len(works) - n_skipped} works, {n_pass} passages '
          f'({n_drop} OCR passages dropped, {n_skipped} works skipped), {len(spell)} spelling variants')
    return db_path


def save_ids(build_dir: Path) -> None:
    """Makes the work-id map of a finished, checked build the persistent one (data/work_ids.json)."""
    path = build_dir / 'work_ids.json'
    if path.exists():
        corpus.save_work_ids(IDS_PATH, corpus.load_work_ids(path))


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument('--repo', type=Path, default=Path.home() / 'library-work/books')
    ap.add_argument('--out', type=Path)
    ap.add_argument('--limit-works', type=int, default=0)
    ap.add_argument('--processes', type=int, default=3)
    ap.add_argument('--licensed', type=Path, help='the licensed shelf (works used by permission)')
    args = ap.parse_args(argv)
    if not (DATA / 'lexicon.tsv').exists():
        sys.exit('data/lexicon.tsv missing: run python -m indexer.modernize --build-lexicon first')
    stamp = datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S')
    out = args.out or DATA / 'build' / (stamp + ('-sample' if args.limit_works else ''))
    fresh = not out.exists()
    try:
        build(args.repo, out, args.limit_works, args.processes, args.licensed)
    except BaseException:
        if fresh:
            shutil.rmtree(out, ignore_errors=True)
        raise
    save_ids(out)


if __name__ == '__main__':
    main()
