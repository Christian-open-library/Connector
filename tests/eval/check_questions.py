#!/usr/bin/env python3
"""Self-check for questions.jsonl (run after every edit).

    cd ~/library-connector
    .venv/bin/python tests/eval/check_questions.py              # structure + catalog + evidence
    .venv/bin/python tests/eval/check_questions.py --no-text    # skip the evidence check (fast)
    .venv/bin/python tests/eval/check_questions.py --db data/build/LATEST-sample/library.sqlite
    .venv/bin/python tests/eval/check_questions.py --chunk      # evidence via the indexer's chunker (slow)

Checks:
* every line parses; required fields and types; ids unique; kind is known;
* every expected key is a File value in catalog_full.csv, listed once;
* filters use known keys and valid values, and every expected work satisfies them
  (author substring, collection code, known year inside year_from..year_to), so a correct
  search can actually return it;
* evidence: the note quotes the distinctive phrase(s) in double quotes; every expected work's
  text must contain at least one of them (case, punctuation and markdown ignored; YAML
  frontmatter, which is not indexed, excluded; for multi-part works only the part files;
  section headings count, since the search indexes them).
  With --db, works present in that build are checked against its passages instead; with
  --chunk, the other works are chunked with the indexer's own code (corpus.chunk_work plus the
  OCR filter of build_db), so the phrase must survive exactly what the build keeps.
"""
from __future__ import annotations

import argparse
import json
import re
import sqlite3
import sys
import unicodedata
import zlib
from collections import Counter
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
sys.path.insert(0, str(ROOT))
from indexer import corpus  # noqa: E402  (read-only use of the indexer's catalog helpers)

DEFAULT_REPO = Path.home() / 'library-work' / 'books'
KINDS = ('quote', 'paraphrase', 'doctrine', 'old-spelling', 'filtered', 'exact')
FILTER_KEYS = {'author': str, 'collection': str, 'year_from': int, 'year_to': int, 'mode': str}
COLLECTIONS = set(corpus.COLLECTION_LABELS)
QUOTED_RE = re.compile(r'"([^"]{4,})"')
FRONTMATTER_RE = re.compile(r'\A---\n.*?\n---[ \t]*\n', re.S)


def loose(text: str) -> str:
    """Lowercase words separated by single spaces: no punctuation, markdown or page markers."""
    text = text.replace('ſ', 's')
    text = re.sub(r'<!--.*?-->', ' ', text, flags=re.S)     # page markers and other comments
    text = re.sub(r'\[\^[^\]\s]+\](?!:)', '', text)         # footnote refs
    text = re.sub(r'\[p\. [^\]]*\]', ' ', text)             # [p. N] markers in built passages
    return ' '.join(re.sub(r'[\W_]+', ' ', text.lower()).split())


def fold(s: str) -> str:
    """Case- and accent-insensitive form (for author filters)."""
    s = unicodedata.normalize('NFKD', s)
    return ''.join(c for c in s if not unicodedata.combining(c)).casefold()


def work_meta(repo: Path, row: dict) -> dict:
    """The fields the indexer derives for a work (same rules as corpus.iter_works)."""
    key = row['File']
    meta, _ = corpus.split_frontmatter((repo / key).read_text(encoding='utf-8'))
    top = key.split('/', 1)[0]
    parts = corpus.part_files(repo, key)
    return {
        'author': row.get('Author') or str(meta.get('author') or ''),
        'slug': str(meta.get('author') or ''),
        'year': corpus._year(row.get('Year')) or corpus._year(meta.get('date')),
        'collection': str(meta.get('collection') or corpus.COLLECTION_BY_FOLDER.get(top, top.lower())),
        'files': parts if parts else [key],
    }


def work_text(repo: Path, files: list[str]) -> str:
    return ' '.join(loose(FRONTMATTER_RE.sub('', (repo / f).read_text(encoding='utf-8', errors='replace'),
                                             count=1)) for f in files)


def indexer_works(repo: Path, keys: set[str]) -> dict:
    """corpus.Work objects for these keys, built exactly as the indexer builds them."""
    class Ids(dict):
        def __missing__(self, key):       # a catalog row newer than work_ids.json
            return 0
    ids = Ids(corpus.load_work_ids(ROOT / 'data' / 'work_ids.json'))
    return {w.key: w for w in corpus.iter_works(repo, ids) if w.key in keys}


def chunked_text(repo: Path, work, state: dict) -> str:
    """Text of the passages the build would keep for this work (display text and notes)."""
    from indexer.build_db import OCR_MIN_WORDLIKE
    from indexer.modernize import DATA, Modernizer, load_lexicon, pre_markdown
    out, section = [], None
    for p in corpus.chunk_work(repo, work):
        if work.ocr:
            if 'mod' not in state:
                state['mod'] = Modernizer(load_lexicon(DATA / 'lexicon.tsv'))
            plain = corpus.strip_markdown(pre_markdown(p.text))
            if state['mod'].wordlike_share(plain) < OCR_MIN_WORDLIKE:
                continue
        if p.section and p.section != section:    # headings are searchable (FTS header column)
            out.append(loose(p.section))
            section = p.section
        out.append(loose(p.text))
        if p.notes:
            out.append(loose(' '.join(p.notes)))
    return ' '.join(out)


def db_texts(db_path: Path) -> tuple[sqlite3.Connection, dict[str, int]]:
    db = sqlite3.connect(f'file:{db_path}?mode=ro', uri=True)
    return db, {k: i for i, k in db.execute('SELECT id, key FROM works')}


def built_text(db: sqlite3.Connection, work_id: int) -> str:
    out, last = [], None
    for section, text, notes in db.execute(
            'SELECT section, text, notes FROM passages WHERE work_id = ? ORDER BY seq', (work_id,)):
        if section and section != last:            # headings are searchable (FTS header column)
            out.append(loose(section))
            last = section
        out.append(loose(zlib.decompress(text).decode('utf-8')))
        if notes:
            out.append(loose(' '.join(json.loads(zlib.decompress(notes).decode('utf-8')))))
    return ' '.join(out)


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split('\n\n')[0])
    ap.add_argument('--questions', default=str(HERE / 'questions.jsonl'))
    ap.add_argument('--repo', default=str(DEFAULT_REPO))
    ap.add_argument('--no-text', action='store_true', help='skip the evidence (phrase) check')
    ap.add_argument('--db', help='check evidence against this build where it has the work')
    ap.add_argument('--chunk', action='store_true',
                    help='check evidence against the indexer\'s own chunking of the Markdown (slow)')
    ap.add_argument('--verbose', action='store_true', help='show which phrase matched each work')
    args = ap.parse_args(argv)
    repo = Path(args.repo)
    catalog = {r['File']: r for r in corpus.load_catalog(repo)}
    problems: list[str] = []
    questions = []
    ids = Counter()

    with open(args.questions, encoding='utf-8') as fh:
        for n, line in enumerate(fh, 1):
            if not line.strip():
                continue
            try:
                q = json.loads(line)
            except ValueError as e:
                problems.append(f'line {n}: bad JSON: {e}')
                continue
            if not isinstance(q, dict):
                problems.append(f'line {n}: not an object')
                continue
            qid = q.get('id', f'line {n}')
            for field, typ in (('id', str), ('question', str), ('kind', str),
                               ('expect_any_work_keys', list), ('note', str)):
                if not isinstance(q.get(field), typ):
                    problems.append(f'{qid}: "{field}" missing or not {typ.__name__}')
            if not re.fullmatch(r'q\d{2,3}', str(q.get('id', ''))):
                problems.append(f'{qid}: id should look like q01')
            extra = set(q) - {'id', 'question', 'kind', 'expect_any_work_keys', 'filters', 'note'}
            if extra:
                problems.append(f'{qid}: unexpected fields {sorted(extra)}')
            if q.get('kind') not in KINDS:
                problems.append(f'{qid}: unknown kind {q.get("kind")!r}')
            if not 1 <= len(str(q.get('question', ''))) <= 500:
                problems.append(f'{qid}: question must be 1-500 characters')
            ids[qid] += 1
            questions.append(q)

    for qid, c in ids.items():
        if c > 1:
            problems.append(f'{qid}: id used {c} times')

    metas: dict[str, dict] = {}
    for q in questions:
        qid = q.get('id')
        keys = q.get('expect_any_work_keys') or []
        if not keys:
            problems.append(f'{qid}: no expected works')
        if len(keys) != len(set(keys)):
            problems.append(f'{qid}: an expected key is listed twice')
        for key in keys:
            if key not in catalog:
                problems.append(f'{qid}: not a catalog File value: {key}')
            elif key not in metas:
                metas[key] = work_meta(repo, catalog[key])
        filters = q.get('filters') or {}
        if not isinstance(filters, dict):
            problems.append(f'{qid}: filters must be an object')
            continue
        for k, v in filters.items():
            if k not in FILTER_KEYS:
                problems.append(f'{qid}: unknown filter {k}')
            elif not isinstance(v, FILTER_KEYS[k]) or isinstance(v, bool):
                problems.append(f'{qid}: filter {k} must be {FILTER_KEYS[k].__name__}')
        if filters.get('mode') not in (None, 'best', 'exact'):
            problems.append(f'{qid}: mode must be best or exact')
        if 'collection' in filters and filters['collection'] not in COLLECTIONS:
            problems.append(f'{qid}: unknown collection {filters["collection"]}')
        if (q.get('kind') == 'exact') != (filters.get('mode') == 'exact'):
            problems.append(f'{qid}: kind "exact" and filters.mode "exact" must go together')
        if q.get('kind') == 'filtered' and not set(filters) - {'mode'}:
            problems.append(f'{qid}: a filtered question needs an author/collection/year filter')
        for key in keys:
            m = metas.get(key)
            if not m:
                continue
            if 'author' in filters and fold(filters['author']) not in fold(m['author']) \
                    and fold(filters['author']) not in fold(m['slug']):
                problems.append(f'{qid}: author filter excludes {key} ({m["author"]})')
            if 'collection' in filters and filters['collection'] != m['collection']:
                problems.append(f'{qid}: collection filter excludes {key} ({m["collection"]})')
            if ('year_from' in filters or 'year_to' in filters):
                y = m['year']
                if y is None or y < filters.get('year_from', -10**6) or y > filters.get('year_to', 10**6):
                    problems.append(f'{qid}: year filter excludes {key} (year {y})')

    checked = 0
    if not args.no_text:
        db, in_db = (None, {})
        if args.db:
            db, in_db = db_texts(Path(args.db))
        cache: dict[str, str] = {}
        chunk_state: dict = {}
        if args.chunk:
            work_objs = indexer_works(repo, set(metas) - set(in_db))
        for q in questions:
            phrases = [loose(p) for p in QUOTED_RE.findall(q.get('note', ''))]
            phrases = [p for p in phrases if len(p.split()) >= 2 or len(p) >= 6]
            if not phrases:
                problems.append(f'{q.get("id")}: note quotes no evidence phrase in double quotes')
                continue
            for key in q.get('expect_any_work_keys') or []:
                if key not in metas:
                    continue
                if key not in cache:
                    if key in in_db:
                        cache[key] = built_text(db, in_db[key])
                    elif args.chunk:
                        cache[key] = chunked_text(repo, work_objs[key], chunk_state)
                    else:
                        cache[key] = work_text(repo, metas[key]['files'])
                text = ' ' + cache[key] + ' '
                found = [p for p in phrases if f' {p} ' in text]
                checked += 1
                if not found:
                    problems.append(f'{q["id"]}: no quoted phrase found in {key}'
                                    + (' (built passages)' if key in in_db else
                                       ' (chunked passages)' if args.chunk else ''))
                elif args.verbose:
                    print(f'{q["id"]}: "{found[0]}" in {key}')
            if len(cache) > 400:
                cache.clear()

    kinds = Counter(q.get('kind') for q in questions)
    n_keys = [len(q.get('expect_any_work_keys') or []) for q in questions]
    print(f'{len(questions)} questions: ' + ', '.join(f'{k} {kinds[k]}' for k in KINDS if kinds[k]))
    print(f'{len(metas)} distinct expected works; {sum(n_keys)} expectations '
          f'(1 work: {sum(1 for n in n_keys if n == 1)}, 2-4: {sum(1 for n in n_keys if 2 <= n <= 4)}, '
          f'5+: {sum(1 for n in n_keys if n >= 5)}); with filters: '
          f'{sum(1 for q in questions if set(q.get("filters") or {}) - {"mode"})}')
    if not args.no_text:
        print(f'evidence checked for {checked} (question, work) pairs')
    if problems:
        print(f'\n{len(problems)} problem(s):')
        for p in problems:
            print('  ' + p)
        return 1
    print('OK')
    return 0


if __name__ == '__main__':
    sys.exit(main())
