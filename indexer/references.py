"""Cross-reference tables for a build: Bible text, Scripture citations, dictionaries and topical
indexes (DESIGN.md section 10).

    python -m indexer.references BUILD_DIR          # adds the tables to BUILD_DIR/library.sqlite

Sources:
* Bible verses: the six translations in CCEL_Library/Bibles (KJV, ASV, Douay-Rheims, Darby,
  Webster, YLT), parsed by indexer.scripture.
* Scripture citations: every Bible reference found in passage text and notes.
* Dictionaries: Easton, Smith, Hitchcock, Nave, Torrey from the CCEL ThML sources cached by the
  library pipeline (~/ocd-work/ccel-raw/*.xml: <term>/<def> glossaries with parsed scripRefs);
  Thomas Wilson's Christian Dictionarie (1612) and Elisha Coles's English Dictionary (1677)
  from the library Markdown.
"""
from __future__ import annotations

import argparse
import hashlib
import html
import json
import multiprocessing as mp
import re
import sqlite3
import time
import zlib
from collections import deque
from pathlib import Path

from . import buildcache, corpus
from .modernize import Modernizer, load_lexicon, pre_markdown
from .scripture import (BIBLE_FILES, BOOKS, NAMES, ORDINAL, REPO, extract_refs, format_ref, parse_bible,
                        verse_limits)

CCEL_RAW = Path.home() / 'ocd-work/ccel-raw'

SCHEMA = """
DROP TABLE IF EXISTS bible_books; DROP TABLE IF EXISTS bible; DROP TABLE IF EXISTS scripture_refs;
DROP TABLE IF EXISTS dictionary; DROP TABLE IF EXISTS topic_refs;
CREATE TABLE bible_books(id INTEGER PRIMARY KEY, name TEXT NOT NULL, short TEXT, osis TEXT,
                         aliases TEXT NOT NULL, chapters INTEGER);
CREATE TABLE bible(translation TEXT NOT NULL, book INTEGER NOT NULL, chapter INTEGER NOT NULL,
                   verse INTEGER NOT NULL, text TEXT NOT NULL,
                   PRIMARY KEY(translation, book, chapter, verse)) WITHOUT ROWID;
CREATE TABLE scripture_refs(book INTEGER NOT NULL, chapter INTEGER NOT NULL, verse_start INTEGER,
                            verse_end INTEGER, passage_id INTEGER NOT NULL, work_id INTEGER NOT NULL,
                            in_note INTEGER NOT NULL);
CREATE TABLE dictionary(id INTEGER PRIMARY KEY, source TEXT NOT NULL, work_id INTEGER,
                        headword TEXT NOT NULL, norm TEXT NOT NULL, page TEXT, text BLOB NOT NULL);
CREATE TABLE topic_refs(dictionary_id INTEGER NOT NULL, book INTEGER NOT NULL, chapter INTEGER NOT NULL,
                        verse_start INTEGER, verse_end INTEGER);
"""
# scripture_refs_bcv covers every column the plugin's find_scripture_citations reads, so a chapter's
# citations come from one stretch of the index instead of thousands of scattered table pages
# (Romans 8, 18,000 citations: about 15 ms instead of 0.5-1 s with a cold disk cache; +16 MB).
INDEXES = """
CREATE INDEX scripture_refs_bcv ON scripture_refs(book, chapter, verse_start, verse_end, work_id, passage_id, in_note);
CREATE INDEX dictionary_norm ON dictionary(norm);
CREATE INDEX dictionary_source ON dictionary(source, norm);
CREATE INDEX topic_refs_bcv ON topic_refs(book, chapter, verse_start);
CREATE INDEX topic_refs_dict ON topic_refs(dictionary_id);
"""

OSIS = {
    'Gen': 1, 'Exod': 2, 'Lev': 3, 'Num': 4, 'Deut': 5, 'Josh': 6, 'Judg': 7, 'Ruth': 8, '1Sam': 9,
    '2Sam': 10, '1Kgs': 11, '2Kgs': 12, '1Chr': 13, '2Chr': 14, 'Ezra': 15, 'Neh': 16, 'Esth': 17,
    'Job': 18, 'Ps': 19, 'Prov': 20, 'Eccl': 21, 'Song': 22, 'Isa': 23, 'Jer': 24, 'Lam': 25,
    'Ezek': 26, 'Dan': 27, 'Hos': 28, 'Joel': 29, 'Amos': 30, 'Obad': 31, 'Jonah': 32, 'Mic': 33,
    'Nah': 34, 'Hab': 35, 'Zeph': 36, 'Hag': 37, 'Zech': 38, 'Mal': 39, 'Matt': 40, 'Mark': 41,
    'Luke': 42, 'John': 43, 'Acts': 44, 'Rom': 45, '1Cor': 46, '2Cor': 47, 'Gal': 48, 'Eph': 49,
    'Phil': 50, 'Col': 51, '1Thess': 52, '2Thess': 53, '1Tim': 54, '2Tim': 55, 'Titus': 56,
    'Phlm': 57, 'Heb': 58, 'Jas': 59, '1Pet': 60, '2Pet': 61, '1John': 62, '2John': 63, '3John': 64,
    'Jude': 65, 'Rev': 66, 'Tob': 67, 'Jdt': 68, 'AddEsth': 69, 'EsthGr': 69, 'Wis': 70, 'Sir': 71,
    'Bar': 72, 'PrAzar': 73, 'Sus': 74, 'Bel': 75, 'PrMan': 76, '1Macc': 77, '2Macc': 78,
    '1Esd': 79, '2Esd': 80,
}
OSIS_BY_ID = {}
for _k, _v in OSIS.items():
    OSIS_BY_ID.setdefault(_v, _k)

DICT_SOURCES = {
    # source code: (xml file, catalog File of the library work, label)
    'easton': ('easton_ebd2.xml', "CCEL_Library/Reference/Matthew George Easton - Easton's Bible Dictionary.md"),
    'smith': ('smith_w_bibledict.xml', "CCEL_Library/Reference/William Smith - Smith's Bible Dictionary.md"),
    'hitchcock': ('hitchcock_bible_names.xml', "CCEL_Library/Reference/Roswell D. Hitchcock - Hitchcock's Bible Names Dictionary.md"),
    'nave': ('nave_bible.xml', "CCEL_Library/Reference/Orville J. Nave - Nave's Topical Bible.md"),
    'torrey': ('torrey_ttt.xml', "CCEL_Library/Reference/R. A. Torrey - Torrey's New Topical Textbook.md"),
}
MD_DICTS = {
    'wilson': 'Thomas Wilson - A Christian Dictionarie',
    'wilson1661': 'Thomas Wilson - A Complete Christian Dictionary',
    'coles': 'Elisha Coles - An English Dictionary Explaining the Difficult Terms',
}


# ---------------------------------------------------------------- Bible

BSB_ZIP = Path(__file__).resolve().parent.parent / 'data/bible-src/bsb/engbsb_vpl.zip'
# eBible.org VPL book codes, in canon order (book id = index + 1)
VPL_CODES = ['GEN', 'EXO', 'LEV', 'NUM', 'DEU', 'JOS', 'JDG', 'RUT', '1SA', '2SA', '1KI', '2KI', '1CH',
             '2CH', 'EZR', 'NEH', 'EST', 'JOB', 'PSA', 'PRO', 'ECC', 'SOL', 'ISA', 'JER', 'LAM', 'EZE',
             'DAN', 'HOS', 'JOE', 'AMO', 'OBA', 'JON', 'MIC', 'NAH', 'HAB', 'ZEP', 'HAG', 'ZEC', 'MAL',
             'MAT', 'MAR', 'LUK', 'JOH', 'ACT', 'ROM', '1CO', '2CO', 'GAL', 'EPH', 'PHI', 'COL', '1TH',
             '2TH', '1TI', '2TI', 'TIT', 'PHM', 'HEB', 'JAM', '1PE', '2PE', '1JO', '2JO', '3JO', 'JUD', 'REV']
VPL_LINE_RE = re.compile(r'^([1-3]?[A-Z]{2,3}) (\d+):(\d+) (.*)$')


def parse_bsb(path: Path = BSB_ZIP):
    """Berean Standard Bible (public domain since 2023-04-30) from eBible.org's verse-per-line file."""
    import zipfile
    if not path.exists():
        return
    ids = {c: i + 1 for i, c in enumerate(VPL_CODES)}
    text = zipfile.ZipFile(path).read('engbsb_vpl.txt').decode('utf-8-sig')
    for line in text.splitlines():
        m = VPL_LINE_RE.match(line.strip())
        if m and m.group(1) in ids and m.group(4).strip():
            yield ids[m.group(1)], int(m.group(2)), int(m.group(3)), m.group(4).strip()


def book_aliases() -> dict[int, list[str]]:
    """Lower-case aliases per book id, ordinals expanded ("1 cor", "1cor", "i cor", "first cor")."""
    out: dict[int, set] = {}
    for bid, name, short, aliases in BOOKS:
        stems = {a.lower() for a in aliases} | {name.lower(), short.lower()}
        if bid in ORDINAL:
            ids = ORDINAL[bid]
            if bid == 43:
                out.setdefault(43, set()).update(stems)
            for k, oid in enumerate(ids):
                if bid == 43 and k == 0 and oid == 62:
                    pass
                prefixes = [str(k + 1), ['i', 'ii', 'iii'][k], ['first', 'second', 'third'][k]]
                s = out.setdefault(oid, set())
                for st in stems:
                    st = re.sub(r'^[123]\s*', '', st)
                    for p in prefixes:
                        s.add(f'{p} {st}')
                        if p.isdigit():
                            s.add(f'{p}{st}')
        else:
            out.setdefault(bid, set()).update(stems)
    for bid, osis in OSIS_BY_ID.items():
        out.setdefault(bid, set()).add(osis.lower())
    for bid, name in NAMES.items():
        out.setdefault(bid, set()).add(name.lower())
    return {k: sorted(v) for k, v in out.items()}


def bible_problems(rows: list[tuple], reference: dict | None = None) -> list[str]:
    """Signs that a translation was parsed wrongly: text with backslashes or "Chapter N", text
    that starts with a number, verses over 1,000 characters, and (with `reference`: verse counts
    by (book, chapter) of another translation) chapters of books 1-66 more than 3 verses off.
    rows: (translation, book, chapter, verse, text)."""
    out = []
    counts: dict = {}
    for tr, b, c, v, t in rows:
        counts[(b, c)] = counts.get((b, c), 0) + 1
        where = f'{tr} {format_ref(b, c, v, None)}'
        if '\\' in t:
            out.append(f'{where}: backslash')
        if re.match(r'Chapter \d|\d', t):
            out.append(f'{where}: starts with {t[:12]!r}')
        if len(t) > 1000:
            out.append(f'{where}: {len(t)} characters')
    name = rows[0][0] if rows else '?'
    for (b, c), n in sorted((reference or {}).items()):
        if b <= 66 and abs(counts.get((b, c), 0) - n) > 3:
            out.append(f'{name} {format_ref(b, c, None, None)}: {counts.get((b, c), 0)} verses, {n} in the reference')
    return out


def unique_rows(rows: list[tuple]) -> list[tuple]:
    """The rows (translation, book, chapter, verse, text); ValueError if a verse comes twice (a
    book or chapter read as another one, as the KJV's 1 Esdras once was read as Ezra)."""
    seen = set()
    for r in rows:
        if r[:4] in seen:
            raise ValueError(f'{r[0]} {format_ref(*r[1:4], None)} parsed twice: check the book and chapter '
                             'headings (indexer.scripture.parse_bible)')
        seen.add(r[:4])
    return rows


def _chapter_sizes(rows: list[tuple]) -> dict:
    sizes: dict = {}
    for _, b, c, _, _ in rows:
        sizes[(b, c)] = sizes.get((b, c), 0) + 1
    return sizes


def add_bible(db: sqlite3.Connection, repo: Path = REPO) -> tuple[dict, list[str]]:
    """Adds the Bible tables; returns the KJV's verse limits and the translations stored. A
    translation that fails to parse (a verse read twice, an unreadable file) is left out with a
    warning; only the KJV, which the citation index depends on, stops the build."""
    loaded = []
    bsb = unique_rows([('bsb', b, c, v, t) for b, c, v, t in parse_bsb()])
    kjv = unique_rows([('kjv', b, c, v, re.sub(r"’S\b", "’s", t)) for b, c, v, t in parse_bible(repo, 'kjv')])
    for tr in BIBLE_FILES:
        try:
            rows = kjv if tr == 'kjv' else unique_rows(
                [(tr, b, c, v, re.sub(r"’S\b", "’s", t)) for b, c, v, t in parse_bible(repo, tr)])
        except Exception as e:
            corpus.warn(f'bible {tr} left out: {type(e).__name__}: {str(e)[:200]!r}')
            continue
        loaded.append(tr)
        db.executemany('INSERT INTO bible VALUES (?,?,?,?,?)', rows)
        print(f'  bible {tr}: {len(rows)} verses', flush=True)
        # verse counts compared with the BSB's (or the KJV's); the Douay-Rheims numbers many chapters
        # as the Vulgate does. Known: the KJV's Esther 10 has the Rest of Esther from 10:4, and its
        # Prayer of Manasseh has no verse numbers in the source.
        problems = bible_problems(rows, None if tr == 'drb' else _chapter_sizes(bsb or kjv))
        for p in problems[:20]:
            print(f'    check: {p}', flush=True)
        if len(problems) > 20:
            print(f'    check: ... and {len(problems) - 20} more', flush=True)
    if bsb:
        db.executemany('INSERT INTO bible VALUES (?,?,?,?,?)', bsb)
        loaded.append('bsb')
        print(f'  bible bsb: {len(bsb)} verses', flush=True)
    limits = verse_limits([(b, c, v, t) for _, b, c, v, t in kjv])
    aliases = book_aliases()
    for bid in sorted(NAMES):
        short = next((s for i, _, s, _ in BOOKS if i == bid), None)
        db.execute('INSERT INTO bible_books VALUES (?,?,?,?,?,?)',
                   (bid, NAMES[bid], short, OSIS_BY_ID.get(bid), json.dumps(aliases.get(bid, [])),
                    max(limits.get(bid, {0: 0}))))
    return limits, loaded


# ---------------------------------------------------------------- citations

_w: dict = {}


def _init(limits):
    _w['limits'] = limits


def _refs_for_groups(groups):
    return [_refs_for(rows) for rows in groups]


def _refs_for(rows):
    out = []
    limits = _w['limits']
    for pid, work_id, text, notes in rows:
        seen = set()
        body = corpus.strip_markdown(pre_markdown(zlib.decompress(text).decode('utf-8')))
        for ref in extract_refs(body, limits):
            if ref not in seen:
                seen.add(ref)
                out.append((*ref, pid, work_id, 0))
        if notes:
            for n in json.loads(zlib.decompress(notes).decode('utf-8')):
                for ref in extract_refs(corpus.strip_markdown(pre_markdown(n)), limits):
                    if ref not in seen:
                        seen.add(ref)
                        out.append((*ref, pid, work_id, 1))
    return out


# Cross-reference tables list hundreds of thousands of verses and would turn up for almost every verse in
# "who cited this verse": left out of the citation index (the text stays searchable and readable).
NO_CITATIONS_TITLES = ('Treasury of Scripture Knowledge',)


def no_citation_work_ids(db: sqlite3.Connection) -> set:
    ids = set()
    for t in NO_CITATIONS_TITLES:
        ids |= {r[0] for r in db.execute('SELECT id FROM works WHERE title LIKE ? OR full_title LIKE ?', (f'%{t}%', f'%{t}%'))}
    return ids


def add_scripture_refs(db: sqlite3.Connection, limits: dict, processes: int = 3, use_cache: bool = False) -> int:
    """Citations of every passage, inserted in passage id order. With use_cache (full builds), a work whose
    passages (text, notes, ids) and the code are unchanged takes its rows from indexer.buildcache."""
    skip = no_citation_work_ids(db)
    store = buildcache.open_store() if use_cache else None
    version = buildcache.code_version(repr(sorted(limits.items())).encode()) if use_cache else ''

    def works():
        """(work_id, rows) for each work in id order; rows (id, work_id, text, notes) in id order."""
        cur = db.execute('SELECT id, work_id, text, notes FROM passages ORDER BY id')
        wid, rows = None, []
        for r in iter(lambda: cur.fetchmany(2000), []):
            for row in r:
                if row[1] != wid:
                    if rows:
                        yield wid, rows
                    wid, rows = row[1], []
                rows.append(row)
        if rows:
            yield wid, rows

    def key_of(rows) -> str:
        h = hashlib.sha256(version.encode())
        for pid, wid, text, notes in rows:
            h.update(b'\0%d\0%d\0%d\0' % (pid, wid, len(text)) + text + b'\0' + (notes or b'-'))
        return h.hexdigest()

    # Streamed in passage id order with a few batches in flight: the citations are inserted as each batch comes
    # back, so neither all the text nor all the citations are held in memory at once.
    def batches():
        """Lists of (cache key or '', cached?, rows or None) covering about 2000 passages to scan each."""
        batch, size = [], 0
        for wid, rows in works():
            if wid in skip:
                continue
            key = key_of(rows) if store is not None else ''
            cached = bool(key) and store.execute('SELECT 1 FROM refs WHERE key=?', (key,)).fetchone() is not None
            batch.append((key, cached, None if cached else rows))
            size += 0 if cached else len(rows)
            if size >= 2000 or len(batch) >= 500:
                yield batch
                batch, size = [], 0
        if batch:
            yield batch

    n = n_works = n_cached = 0
    used: set = set()
    pending: deque = deque()

    def finish():
        nonlocal n, n_works, n_cached
        batch, res = pending.popleft()
        found = iter(res.get() if res is not None else [])
        for key, cached, _ in batch:
            refs = buildcache.get(store, 'refs', key) if cached else next(found)
            if key:
                used.add(key)
                if not cached:
                    buildcache.put(store, 'refs', key, refs)
            db.executemany('INSERT INTO scripture_refs VALUES (?,?,?,?,?,?,?)', refs)
            n += len(refs)
            n_works += 1
            n_cached += cached

    with mp.Pool(processes, initializer=_init, initargs=(limits,)) as pool:
        for batch in batches():
            groups = [rows for _, cached, rows in batch if not cached]
            pending.append((batch, pool.apply_async(_refs_for_groups, (groups,)) if groups else None))
            while len(pending) > processes * 2:
                finish()
        while pending:
            finish()
    if store is not None:
        store.commit()
        dropped = buildcache.prune(store, 'refs', used)
        store.close()
        print(f'  per-work cache: citations of {n_cached} of {n_works} works unchanged, '
              f'{dropped} old entries dropped', flush=True)
    return n


# ---------------------------------------------------------------- dictionaries

TAG_RE = re.compile(r'<[^>]+>')
TERM_RE = re.compile(r'<term\b[^>]*>(.*?)</term>\s*<def\b[^>]*>(.*?)</def>', re.S)
SCRIPREF_RE = re.compile(r'<scripRef\b[^>]*\bparsed="([^"]*)"[^>]*>(.*?)</scripRef>', re.S)
ONE_CHAPTER = {31, 57, 63, 64, 65}     # Obadiah, Philemon, 2 John, 3 John, Jude
VERSE_LIST_RE = re.compile(r':(\d+(?:\s*[-–]\s*\d+)?(?:\s*,\s*\d+(?:\s*[-–]\s*\d+)?)*)')


def _plain(xml_fragment: str) -> str:
    s = re.sub(r'</p>|<br\s*/?>|</li>', '\n', xml_fragment)
    s = TAG_RE.sub('', s)
    s = corpus.strip_markup(html.unescape(s))     # entities can spell markup ("&lt;img ...&gt;")
    s = re.sub(r'[ \t\r\f\v]+', ' ', s)
    s = re.sub(r'\n\s*\n+', '\n', s)
    return s.strip()


def _verses_after(text: str) -> list[tuple[int, int | None]]:
    """":7", ":8-13", ":9,10" -> [(7, None)], [(8, 13)], [(9, None), (10, None)]."""
    m = VERSE_LIST_RE.match(text)
    out = []
    for item in (m.group(1).split(',') if m else []):
        a, _, b = item.replace('–', '-').partition('-')
        v1, v2 = int(a), int(b) if b.strip() else None
        if v1 > 0:
            out.append((v1, v2 if v2 is not None and v2 > v1 else None))
    return out


def _parsed_refs(fragment: str):
    """scripRef parsed="|Book|ch|v|ch2|v2|..." -> (book, ch, v1, v2) tuples.
    In one-chapter books CCEL tags only the "Jude 1" of "Jude 1:7" (parsed as verse 1): the verses
    are read from the text after the tag. A tag followed directly by a letter is a slip of the
    tagger (Nave's "Ezra, 1Ch 4:17" is tagged "Ezra 1" + "Ch 4:17") and is skipped."""
    for m in SCRIPREF_RE.finditer(fragment):
        after = fragment[m.end():m.end() + 60]
        if re.match(r'[A-Za-z]', after):
            continue
        # groups of 5 after the leading empty field
        f = m.group(1).split('|')[1:]
        for i in range(0, len(f) - 4, 5):
            code, c1, v1, c2, v2 = f[i:i + 5]
            bid = OSIS.get(code)
            if not bid or not c1.isdigit():
                continue
            c1i = int(c1)
            v1i = int(v1) if v1.isdigit() and int(v1) > 0 else None
            v2i = int(v2) if v2.isdigit() and int(v2) > 0 and (not c2.isdigit() or int(c2) in (0, c1i)) else None
            verses = _verses_after(after) if bid in ONE_CHAPTER and c1i == 1 and v1i in (None, 1) and v2i is None \
                and re.search(r'\b1\s*$', TAG_RE.sub('', m.group(2))) else []
            if verses:
                for a, b in verses:
                    yield bid, 1, a, b
            elif c1i > 0:
                yield bid, c1i, v1i, v2i if v2i != v1i else None


def norm_headword(word: str, mod: Modernizer | None = None) -> str:
    w = html.unescape(word)
    w = re.sub(r'[*_`]', '', w)
    w = re.sub(r'\(.*?\)', '', w)
    w = re.sub(r'[\s,;:.!?\'"’“”]+$', '', w.strip())
    if mod is not None:
        w = mod.modernize_text(w)
    w = re.sub(r'[^\w\s-]', '', w.lower())
    return re.sub(r'\s+', ' ', w).strip()


def add_ccel_dictionaries(db: sqlite3.Connection, work_ids: dict, mod: Modernizer) -> int:
    n = 0
    for source, (fname, key) in DICT_SOURCES.items():
        path = CCEL_RAW / fname
        if not path.exists():
            print(f'  {source}: {path} missing, skipped')
            continue
        xml = path.read_text(encoding='utf-8', errors='replace')
        count = 0
        for term_x, def_x in TERM_RE.findall(xml):
            head = clean_head(_plain(term_x))
            if not head:
                continue
            text = _plain(def_x)
            if not text:
                continue
            cur = db.execute('INSERT INTO dictionary(source, work_id, headword, norm, page, text) VALUES (?,?,?,?,?,?)',
                             (source, work_ids.get(key), head, norm_headword(head, mod), None,
                              zlib.compress(text.encode('utf-8'), 6)))
            if source in ('nave', 'torrey'):
                refs = {r for r in _parsed_refs(def_x)}
                db.executemany('INSERT INTO topic_refs VALUES (?,?,?,?,?)',
                               [(cur.lastrowid, *r) for r in refs])
            count += 1
        n += count
        print(f'  {source}: {count} entries', flush=True)
    return n


MAX_ENTRY_CHARS = 200_000     # one dictionary entry (the longest real ones have about 50,000)
MAX_HEAD_CHARS = 300          # the longest real headword has 277 characters


def clean_head(text: str) -> str:
    """A headword: no markup, emphasis or control characters; at most MAX_HEAD_CHARS."""
    s = corpus.CONTROL_RE.sub(' ', corpus.clean_inline(text))
    return re.sub(r'\s+', ' ', re.sub(r'[*_]', '', s)).strip()[:MAX_HEAD_CHARS]


HEAD_DICT_RE = re.compile(r'^#{2,4}\s+(.*)$')


def dict_head(m) -> str:
    """The heading text without a trailing "sig"/"sig:" and spaces (plain string work, so a long run of spaces
    costs nothing extra)."""
    s = m.group(1).rstrip()
    for tail in ('sig:', 'sig'):
        if s.endswith(tail):
            s = s[:-len(tail)]
            break
    return s.rstrip()
COLES_RE = re.compile(r'^\*([^*]{1,60}?)[,.]?\s*(?:[a-zA-Z]{1,3}\.)?\*\s*(.+)$')


def add_markdown_dictionaries(db: sqlite3.Connection, repo: Path, work_ids: dict, mod: Modernizer) -> int:
    """Wilson's (headings) and Coles's (one entry per line) dictionaries from the library text."""
    n = 0
    for source, stem in MD_DICTS.items():
        main = next((k for k in work_ids if Path(k).name.startswith(stem) and ' — Part ' not in k), None)
        if not main:
            print(f'  {source}: not in the library, skipped')
            continue
        files = corpus.part_files(repo, main) or [main]
        count = 0
        page = None
        head, body = None, []

        def emit():
            nonlocal count
            if head and body:
                text = '\n'.join(body).strip()
                text = re.sub(r'\[\^[^\]]+\]', '', text)
                text = re.sub(r'^\*?;;\s*', '', text)      # 1661 Wilson: "*;;is the free ..."
                text = corpus.clip_text(corpus.clean_inline(text).strip(), MAX_ENTRY_CHARS)
                if not text:
                    return
                db.execute('INSERT INTO dictionary(source, work_id, headword, norm, page, text) VALUES (?,?,?,?,?,?)',
                           (source, work_ids[main], head, norm_headword(head, mod), page_at_head,
                            zlib.compress(text.encode('utf-8'), 6)))
                count += 1

        page_at_head = None
        for rel in files:
            try:
                raw = corpus.read_repo_text(repo, rel)
            except Exception as e:      # an unsafe path, a missing file, invalid UTF-8
                corpus.warn(f'{source}: file skipped ({type(e).__name__}): {rel!r}')
                continue
            _, raw = corpus.split_frontmatter(raw)
            for line in raw.split('\n'):
                for m in corpus.PAGE_RE.finditer(line):
                    page = corpus.clean_label(m.group(1))
                line = corpus.PAGE_RE.sub('', line).rstrip()
                if corpus.FN_DEF_RE.match(line):
                    continue
                if source.startswith('wilson'):
                    hm = HEAD_DICT_RE.match(line)
                    if hm and not line.startswith('# '):
                        emit()
                        head = clean_head(dict_head(hm)).rstrip('.]').strip()
                        body, page_at_head = [], page
                    elif head is not None and line.strip():
                        body.append(line.strip())
                else:
                    cm = COLES_RE.match(line.strip())
                    if cm:
                        head, body, page_at_head = clean_head(cm.group(1)).rstrip(','), [cm.group(2).strip()], page
                        emit()
                        head, body = None, []
            emit()
            head, body = None, []
        n += count
        print(f'  {source}: {count} entries', flush=True)
    return n


def rebuild_bible(build_dir: Path, repo: Path = REPO):
    """Refresh only the bible and bible_books tables of an existing build."""
    db = sqlite3.connect(build_dir / 'library.sqlite')
    db.execute('DELETE FROM bible')
    db.execute('DELETE FROM bible_books')
    _, loaded = add_bible(db, repo)
    db.execute("INSERT OR REPLACE INTO meta(key, value) VALUES ('bible_translations', ?)", (','.join(loaded),))
    db.commit()
    db.close()


def build(build_dir: Path, repo: Path = REPO, processes: int = 3, use_cache: bool = False):
    t0 = time.time()
    db = sqlite3.connect(build_dir / 'library.sqlite')
    db.execute('PRAGMA journal_mode=OFF')
    db.execute('PRAGMA synchronous=OFF')
    db.executescript(SCHEMA)
    limits, loaded = add_bible(db, repo)
    db.commit()
    n_refs = add_scripture_refs(db, limits, processes, use_cache=use_cache and buildcache.enabled())
    db.commit()
    print(f'  scripture citations: {n_refs} ({time.time() - t0:.0f}s)', flush=True)
    work_ids = dict(db.execute('SELECT key, id FROM works'))
    if len(work_ids) < 3000:
        # sample builds: still resolve dictionary works from the persistent id map
        ids_path = Path(__file__).resolve().parent.parent / 'data/work_ids.json'
        if ids_path.exists():
            work_ids = {**json.loads(ids_path.read_text()), **work_ids}
    mod = Modernizer(load_lexicon())
    n_dict = add_ccel_dictionaries(db, work_ids, mod) + add_markdown_dictionaries(db, repo, work_ids, mod)
    db.executescript(INDEXES)
    db.executemany('INSERT OR REPLACE INTO meta(key, value) VALUES (?,?)', [
        ('n_scripture_refs', str(n_refs)), ('n_dictionary_entries', str(n_dict)),
        ('bible_translations', ','.join(loaded))])
    db.commit()
    db.execute('PRAGMA journal_mode=DELETE')
    db.close()
    print(f'references done in {time.time() - t0:.0f}s: {n_refs} citations, {n_dict} dictionary entries')


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument('build_dir', type=Path)
    ap.add_argument('--processes', type=int, default=3)
    ap.add_argument('--only-bible', action='store_true', help='refresh just the Bible text tables')
    args = ap.parse_args(argv)
    if args.only_bible:
        rebuild_bible(args.build_dir)
    else:
        build(args.build_dir, processes=args.processes)


if __name__ == '__main__':
    main()
