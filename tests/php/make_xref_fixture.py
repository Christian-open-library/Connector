#!/usr/bin/env python3
"""Cross-reference tables for the PHP test fixture (DESIGN.md section 10, PLUGIN-SPEC.md addendum).

    cd ~/library-connector && .venv/bin/python tests/php/make_fixture.py   # calls add_xref_tables()

add_xref_tables() adds the tables of indexer/references.py to the fixture's library.sqlite:
  bible_books      all rows of data/build/LATEST-sample
  bible            the chapters in CHAPTERS (all seven translations) and PARSE_CHAPTERS (BSB and KJV,
                   for the reference parsing tests), plus the last verse of every one of those books
                   in each translation, so chapter counts are right
  dictionary       the entries in NORMS / PREFIXES and every entry the copied topic links point to
  topic_refs       Nave's and Torrey's links for the copied chapters
  scripture_refs   the references indexer.scripture finds in the fixture passages (real), plus the
                   SYNTHETIC rows below, which give the citation tests works with and without a
                   year, verse ranges, margin notes and whole-chapter references
  spelling         a few old spellings of dictionary headwords (EXTRA_SPELLING)
and writes xref_expected.json next to it: norm_headword() of indexer/references.py (without the
modernizer) for headwords that the PHP port (Dictionary::norm) must reproduce exactly.

Rows are copied from data/build/LATEST-sample/library.sqlite, which must exist.
"""
from __future__ import annotations

import json
import random
import sqlite3
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
sys.path.insert(0, str(ROOT))

from indexer import references  # noqa: E402

SAMPLE = ROOT / 'data/build/LATEST-sample/library.sqlite'

# (book, chapter) copied in every translation that has them
CHAPTERS = [
    (1, 1), (1, 2), (17, 10), (17, 11), (19, 22), (19, 23), (19, 117), (31, 1), (40, 17), (43, 3), (45, 8),
    (45, 9), (57, 1), (63, 1), (64, 1), (65, 1), (67, 1), (73, 1),
]
# (book, chapter) copied in the BSB and KJV only: references the parsing tests read
PARSE_CHAPTERS = [
    (19, 118), (19, 119), (1, 50), (2, 1), (2, 20), (3, 19), (4, 6), (5, 6), (6, 1), (7, 1), (8, 1), (9, 3), (10, 7), (11, 3),
    (12, 2), (14, 7), (18, 1), (20, 3), (21, 1), (22, 2), (23, 53), (24, 31), (26, 36), (27, 3), (32, 1),
    (39, 3), (40, 5), (41, 1), (42, 2), (44, 2), (46, 13), (47, 5), (48, 2), (49, 2), (50, 4), (51, 1),
    (52, 4), (53, 3), (54, 3), (55, 3), (56, 2), (58, 11), (59, 1), (60, 1), (61, 1), (62, 1), (66, 1),
    (66, 22), (70, 7), (71, 1), (77, 1),
]
NORMS = ['grace', 'conversation', 'sin', 'justification', 'faith', 'melchizedek', 'melchisedec', 'a',
         'aaron', 'abba', 'ab', 'conversion', 'justify', 'self-denial', 'charity']
PREFIXES = ['grace', 'conver', 'justif', 'melchi']
EXTRA_SPELLING = {'conuersation': 'conversation', 'conuersion': 'conversion', 'iustifie': 'justify',
                  'charitie': 'charity', 'fauour': 'favor'}

# SYNTHETIC citations (made up for the tests; the fixture passages are made up too):
# (book, chapter, verse_start, verse_end, passage_id, in_note)
SYNTHETIC = [
    # Romans 8: works 101 (1668), 103 (1886), 102 and 104 (no year)
    (45, 8, 28, None, 10100000, 0),
    (45, 8, 26, 30, 10100002, 1),      # a range that contains verse 28, in a margin note
    (45, 8, 28, None, 10100005, 1),
    (45, 8, None, None, 10100006, 0),  # the whole chapter
    (45, 8, 29, 30, 10100008, 0),      # after verse 28
    (45, 8, 28, None, 10300001, 0),
    (45, 8, 27, 29, 10300001, 1),      # same passage: text and margin note
    (45, 8, 28, 29, 10300004, 1),
    (45, 8, 1, 27, 10300005, 0),       # before verse 28
    (45, 8, 39, None, 10200001, 0),
    (45, 8, 28, None, 10200003, 0),
    (45, 8, None, None, 10200003, 0),  # also cites the whole chapter
    (45, 8, 28, None, 10400002, 1),
    (45, 8, None, None, 10400004, 0),
    (45, 8, None, None, 10400005, 1),
    # Romans 9, John 3, Psalm 23, Jude
    (45, 9, 1, None, 10300002, 0),
    (45, 9, 5, None, 10200002, 1),
    (43, 3, 16, None, 10400003, 0),
    (43, 3, 14, 17, 10300003, 1),
    (43, 3, None, None, 10200005, 0),
    (19, 23, 1, None, 10300000, 0),
    (19, 23, None, None, 10300006, 0),
    (19, 23, 4, None, 10100009, 1),
    (65, 1, 3, None, 10200004, 0),
]

# Headwords whose norm_headword() the PHP port must reproduce (besides a sample of real ones).
NORM_CASES = [
    'Grace', 'GRACE OF GOD', 'Grace, means of', 'Iustifie. sig: pro', 'Melchi-shua', 'Self-denial',
    'JESUS, THE CHRIST', 'WICKED (PEOPLE)', 'Love of God, The', 'Abba (Father)', 'Aaron’s rod',
    "Aaron's Rod", '“Quoted”', 'A.', 'A, a', '*Emphasis*', '_under_score_', 'back`tick`',
    'Trailing!?', 'Trailing ;:', '  spaced   out  ', 'tab\tand\nnewline', 'ſinne', 'Conuersation',
    'Bèze', 'Théodore de Bèze', 'Æthiopia', 'naïve café', 'İstanbul', 'Straße', 'ΧΡΙΣΤΟΣ', 'Ⅻ apostles',
    'x²', '50% off', 'a_b%c\\d', '(only parens)', 'nested (a (b) c) d', 'unclosed (paren', 'A &amp; B',
    '&lt;tag&gt;', 'O&#39;Neil', 'em—dash', 'en–dash', 'hy-phen-ated', '12 Apostles', 'St. Paul',
    'Joh. 3. 16.', "Lov'd", 'COMBINING é', 'ﬁnal ﬂower', 'trailing dash -', '- leading dash',
]


def _limits(sample: sqlite3.Connection) -> dict:
    lim: dict = {}
    for b, c, v in sample.execute("SELECT book, chapter, max(verse) FROM bible WHERE translation = 'kjv' "
                                  'GROUP BY book, chapter'):
        lim.setdefault(b, {})[c] = v
    return lim


def add_xref_tables(db: sqlite3.Connection, out_dir: Path, sample_path: Path = SAMPLE) -> dict:
    """Adds the cross-reference tables to an open fixture database; returns counts."""
    if not sample_path.exists():
        raise SystemExit(f'{sample_path} is missing: the cross-reference fixture copies its rows from the sample '
                         'build (python -m indexer.pipeline --sample)')
    sample = sqlite3.connect(f'file:{sample_path}?mode=ro', uri=True)
    db.executescript(references.SCHEMA)

    db.executemany('INSERT INTO bible_books VALUES (?,?,?,?,?,?)',
                   sample.execute('SELECT id, name, short, osis, aliases, chapters FROM bible_books ORDER BY id'))

    n_verses = 0
    for chapters, only in ((CHAPTERS, None), (PARSE_CHAPTERS, ('bsb', 'kjv'))):
        for book, chapter in chapters:
            rows = sample.execute('SELECT translation, book, chapter, verse, text FROM bible WHERE book = ? AND chapter = ? '
                                  'ORDER BY translation, verse', (book, chapter)).fetchall()
            rows = [r for r in rows if only is None or r[0] in only]
            db.executemany('INSERT OR IGNORE INTO bible VALUES (?,?,?,?,?)', rows)
            n_verses += len(rows)
    # the last verse of each book in each translation, so the last chapter and its verse count are right
    books = sorted({b for b, _ in CHAPTERS + PARSE_CHAPTERS})
    last = sample.execute(
        'SELECT b.translation, b.book, b.chapter, b.verse, b.text FROM bible b JOIN (SELECT translation, book, '
        'max(chapter) AS c FROM bible WHERE book IN (%s) GROUP BY translation, book) m ON m.translation = b.translation '
        'AND m.book = b.book AND m.c = b.chapter WHERE b.verse = (SELECT max(verse) FROM bible WHERE translation = '
        'b.translation AND book = b.book AND chapter = b.chapter)' % ','.join('?' * len(books)), books).fetchall()
    n_verses += db.executemany('INSERT OR IGNORE INTO bible VALUES (?,?,?,?,?)', last).rowcount

    # topic links of the copied chapters, and the entries they point to
    pairs = sorted(set(CHAPTERS) | {(19, 118), (40, 5), (49, 2), (46, 13)})
    cond = ' OR '.join('(book = ? AND chapter = ?)' for _ in pairs)
    params = [x for p in pairs for x in p]
    topic_rows = sample.execute(f'SELECT dictionary_id, book, chapter, verse_start, verse_end FROM topic_refs '
                                f'WHERE {cond} ORDER BY rowid', params).fetchall()
    ids = {r[0] for r in topic_rows}
    ids |= {r[0] for r in sample.execute('SELECT id FROM dictionary WHERE norm IN (%s)' % ','.join('?' * len(NORMS)),
                                         NORMS)}
    for p in PREFIXES:
        ids |= {r[0] for r in sample.execute("SELECT id FROM dictionary WHERE norm LIKE ? || '%'", (p,))}
    dict_rows = sample.execute('SELECT id, source, work_id, headword, norm, page, text FROM dictionary '
                               'WHERE id IN (%s) ORDER BY id' % ','.join('?' * len(ids)), sorted(ids)).fetchall()
    db.executemany('INSERT INTO dictionary VALUES (?,?,?,?,?,?,?)', dict_rows)
    db.executemany('INSERT INTO topic_refs VALUES (?,?,?,?,?)', topic_rows)

    # Scripture citations: real ones from the fixture passages, then the synthetic rows
    references._init(_limits(sample))
    passages = db.execute('SELECT id, work_id, text, notes FROM passages ORDER BY id').fetchall()
    real = references._refs_for(passages)
    work_of = {pid: wid for pid, wid, _, _ in passages}
    synthetic = [(b, c, v1, v2, pid, work_of[pid], note) for b, c, v1, v2, pid, note in SYNTHETIC]
    db.executemany('INSERT INTO scripture_refs VALUES (?,?,?,?,?,?,?)', real + synthetic)

    have = {r[0] for r in db.execute('SELECT variant FROM spelling')}
    db.executemany('INSERT INTO spelling(variant, standard) VALUES (?,?)',
                   [(v, s) for v, s in sorted(EXTRA_SPELLING.items()) if v not in have])
    db.executescript(references.INDEXES)
    translations = sample.execute("SELECT value FROM meta WHERE key = 'bible_translations'").fetchone()[0]
    db.executemany('INSERT OR REPLACE INTO meta(key, value) VALUES (?,?)', [
        ('bible_translations', translations), ('n_scripture_refs', str(len(real) + len(synthetic))),
        ('n_dictionary_entries', str(len(dict_rows)))])

    # norm_headword() cases for the PHP port: the list above plus a sample of real headwords
    rng = random.Random(7)
    heads = [r[0] for r in sample.execute('SELECT headword FROM dictionary ORDER BY id')]
    cases = NORM_CASES + rng.sample(heads, 400)
    expected = {'norm_headword': [[h, references.norm_headword(h)] for h in cases]}
    lines = ',\n'.join(json.dumps(pair, ensure_ascii=False) for pair in expected['norm_headword'])
    (out_dir / 'xref_expected.json').write_text('{"norm_headword": [\n' + lines + '\n]}\n', encoding='utf-8')
    sample.close()
    return {'verses': n_verses, 'dictionary': len(dict_rows), 'topic_refs': len(topic_rows),
            'scripture_refs': len(real) + len(synthetic)}


def drop_xref_tables(db: sqlite3.Connection) -> None:
    """Removes the cross-reference tables again (the fixture's realmode copy does not need them)."""
    for table in ('bible_books', 'bible', 'scripture_refs', 'dictionary', 'topic_refs'):
        db.execute(f'DROP TABLE IF EXISTS {table}')
