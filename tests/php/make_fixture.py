#!/usr/bin/env python3
"""Build the small fixture index used by the PHP tests (PLUGIN-SPEC.md "Tests").

    cd ~/library-connector && .venv/bin/python tests/php/make_fixture.py

Writes tests/php/fixture/:
  library.sqlite   indexer.build_db.SCHEMA: 4 works, 28 passages (old spelling, footnotes, roman
                   and arabic page labels, a seq gap, a passage without a vector, a near-duplicate
                   passage across two editions, an accented author, works without a year),
                   a spelling table, 8-dim int8 vectors in 4 IVF clusters (indexer.ivf.fake_embed)
  vectors.bin, ids.bin, manifest.json   same formats as a real build
  config.php       test server config (voyage_fake on, data_dir = this folder)
  expected.json    values computed here in Python that the PHP tests must reproduce:
                   fake_embed vectors, exact vector rankings, bm25 orders
  library.sqlite also gets the cross-reference tables (Bible, citations, dictionaries), copied in part
  from data/build/LATEST-sample by make_xref_fixture.py, which also writes xref_expected.json

The text is made up for the tests (in the style of the real works); it is not a quotation.
"""
from __future__ import annotations

import json
import re
import sqlite3
import sys
import zlib
from pathlib import Path

import numpy as np

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
sys.path.insert(0, str(ROOT))

from indexer import build_db, corpus, ivf  # noqa: E402
from indexer.modernize import WORD_RE, fold_chars, pre_markdown  # noqa: E402
from make_xref_fixture import add_xref_tables, drop_xref_tables  # noqa: E402

OUT = HERE / 'fixture'
DIMS = 8
K = 4

# Old spelling -> modern spelling, as the indexer's `spelling` table would hold it.
SPELLING = {
    'sinne': 'sin', 'sinnes': 'sins', 'beleevers': 'believers', 'beleever': 'believer',
    'mortifie': 'mortify', 'doe': 'do', 'hath': 'has', 'doth': 'does', 'iustification': 'justification',
    'onely': 'only', 'loue': 'love', 'liue': 'live', 'vnto': 'unto', 'vs': 'us', 'haue': 'have',
    'euery': 'every', 'worke': 'work', 'alwaies': 'always', 'knowne': 'known', 'selfe': 'self',
    'itselfe': 'itself', 'hee': 'he', 'giue': 'give', 'mercie': 'mercy', 'heauen': 'heaven',
    'spirite': 'spirit', 'holinesse': 'holiness', 'lusts': 'lusts', 'deedes': 'deeds',
    'faithe': 'faith', 'iesus': 'jesus', 'christe': 'christ', 'saith': 'says', 'assoon': 'as soon',
    "lov'd": 'loved', 'diuers': 'divers', 'grievous': 'grievous', 'yt': 'that',
}
SPELLING = {k: v for k, v in SPELLING.items() if k != v}

WORKS = [
    dict(id=101, key='EEBO-TCP/John Owen/John Owen - Of the Mortification of Sinne in Beleevers.md',
         author='John Owen', author_slug='john-owen', title='Of the Mortification of Sinne in Beleevers',
         full_title='Of the mortification of sinne in beleevers: the necessity, nature, and means of it',
         year=1668, type='book', collection='eebo-tcp', license='cc0',
         source_url='https://quod.lib.umich.edu/e/eebo/A53698.0001.001', tcp_id='A53698', series='', ocr=False),
    dict(id=102, key='CCEL_Library/John Owen - Of the Mortification of Sin in Believers.md',
         author='John Owen', author_slug='john-owen', title='Of the Mortification of Sin in Believers',
         full_title='Of the Mortification of Sin in Believers', year=None, type='book', collection='ccel',
         license='public-domain', source_url='https://ccel.org/ccel/owen/mort', tcp_id='', series='',
         ocr=False),
    dict(id=103, key='ChurchFathers/Augustine/Augustine - The Confessions.md', author='Augustine',
         author_slug='augustine', title='The Confessions', full_title='The Confessions', year=1886,
         type='book', collection='ccel-schaff', license='public-domain',
         source_url='https://ccel.org/ccel/schaff/npnf101.vi.html', tcp_id='', series='NPNF1 1', ocr=False),
    dict(id=104, key='PublicDomainLibrary/Théodore de Bèze - A Brief Sum of the Christian Faith.md',
         author='Théodore de Bèze', author_slug='theodore-de-beze', title='A Brief Sum of the Christian Faith',
         full_title='A Brief Sum of the Christian Faith, with a Short Catechism', year=None, type='book',
         collection='project-gutenberg', license='public-domain',
         source_url='https://www.gutenberg.org/ebooks/99999', tcp_id='', series='', ocr=False),
]

# (work_id, seq, part, page_start, page_end, section, text, notes)
PASSAGES = [
    # Work 101: original 1668 spelling, roman preface pages, a dropped passage (seq 4)
    (101, 0, 1, 'iii', 'iii', 'The Preface',
     'I Shall not need to acquaint the Reader with the occaſion of this diſcourſe; it is ſufficiently '
     'knowne vnto many, and the nature of it doth diſcover it ſelfe. Hee that would liue a holy life '
     'muſt make it his daily worke to mortifie the deeds of the body.', []),
    (101, 1, 1, 'iii', 'iv', 'The Preface',
     'The Reader may obſerve that I haue not handled euery queſtion that might be raiſed. [p. iv] '
     'What I haue written is for the vſe of plaine Chriſtians, who deſire to walke with God in the '
     'mortification of their luſts, and in the comfort of his loue.', ['Rom. 8. 13.']),
    (101, 2, 1, 'v', 'v', 'The Preface',
     'Let none thinke that this worke is finiſhed in a day. Sinne will not be killed with a ſingle '
     'blow, but by a conſtant and daily warfare, carried on in the ſtrength of the Spirite of '
     '*Chriſt*.', []),
    (101, 3, 1, '1', '1', 'CHAP. I.',
     'Doe you mortifie; doe you make it your daily worke; be alwaies at it whilſt you liue; ceaſe not '
     'a day from this worke; be killing ſinne or it will be killing you. Your being dead with Chriſt '
     'virtually, your being quickened with him, will not excuſe you from this worke.',
     ['Rom. 8. 13. If ye through the Spirit doe mortifie the deeds of the body, ye ſhall liue.']),
    (101, 5, 1, '1', '3', 'CHAP. I.',
     'Sinne doth not onely ſtill abide in vs, but is ſtill acting, ſtill labouring to bring forth the '
     'deeds of the fleſh. [p. 2] When ſinne lets vs alone we may let ſinne alone; but as ſinne is never '
     'leſſe quiet then when it ſeemes to be moſt quiet, ſo we muſt watch. [p. 3] The beſt Saints in '
     'the world haue need to make it their buſineſſe to mortifie the indwelling power of ſinne.', []),
    (101, 6, 1, '3', '3', 'CHAP. II.',
     'Iuſtification is by faith onely, and not by the workes of the Law; yet the ſame faith that '
     'iuſtifieth doth alſo purifie the heart. He that is iuſtified will be ſanctified, and he that '
     'is ſanctified will make it his worke to mortifie ſinne.', []),
    (101, 7, 1, '4', '4', 'CHAP. II.',
     'The Spirite alone is ſufficient for this worke; all wayes and meanes without him are as a '
     'thing of nought; and hee is the great efficient of it, he workes in vs as he pleaſeth. Without '
     'him we cannot mortifie the deeds of the body.', ['Ezek. 36. 26.', 'Rom. 8. 13.']),
    (101, 8, 1, '4', '5', 'CHAP. II.',
     'Holineſſe is the image of God renewed in the ſoule. They that are lov\'d of God are made holy '
     'by his Spirite, [p. 5] and they that are holy will hate ſinne in euery forme of it, and ſeeke '
     'the deſtruction of it with all their might.', []),
    (101, 9, 1, '5', '5', 'CHAP. III.',
     'Let this then be the concluſion of the whole matter: the vigour, and power, and comfort of our '
     'ſpirituall life depends on the mortification of the deeds of the fleſh. The Lord giue vs '
     'mercie to walke in it vnto the end.', []),
    # Work 102: modern edition of the same book (no page numbers); seq 0 duplicates 101/3
    (102, 0, 1, None, None, 'Chapter I',
     'Do you mortify; do you make it your daily work; be always at it whilst you live; cease not a '
     'day from this work; be killing sin or it will be killing you. Your being dead with Christ '
     'virtually, your being quickened with him, will not excuse you from this work.', []),
    (102, 1, 1, None, None, 'Chapter I',
     'The vigour, and power, and comfort of our spiritual life depends on the mortification of the '
     'deeds of the flesh. Sin is always acting, always conceiving, always seducing and tempting.', []),
    (102, 2, 1, None, None, 'Chapter II',
     'Mortification is the daily work of believers. Indwelling sin always abides while we are in '
     'this world; therefore it is always to be mortified, and the deeds of the body put to death.', []),
    (102, 3, 1, None, None, 'Chapter II',
     'The choicest believers, who are assuredly freed from the condemning power of sin, ought yet '
     'to make it their business all their days to mortify the indwelling power of sin.', []),
    (102, 4, 1, None, None, 'Chapter III',
     'The Holy Spirit is our great sufficiency for this work. He alone clearly and fully convinces '
     'the soul of the evil and guilt of sin, and brings the cross of Christ into the heart.', []),
    (102, 5, 1, None, None, 'Chapter III',
     'Set faith at work on Christ for the killing of thy sin. His blood is the great sovereign '
     'remedy for sin-sick souls. Live in this, and thou wilt die a conqueror.', []),
    # Work 103: Augustine (1886), arabic pages, footnotes
    (103, 0, 1, '45', '45', 'Book I › Chapter 1',
     'Great art Thou, O Lord, and greatly to be praised; great is Thy power, and of Thy wisdom there '
     'is no end. And man, being a part of Thy creation, desires to praise Thee. Thou movest us to '
     'delight in praising Thee; for Thou hast made us for Thyself, and our heart is restless, until '
     'it repose in Thee.', ['Ps. cxlv. 3.', 'Ps. cxlvii. 5.']),
    (103, 1, 1, '45', '46', 'Book I › Chapter 2',
     'And how shall I call upon my God, my God and Lord, since when I call for Him, I shall be '
     'calling Him to myself? [p. 46] And what room is there within me, whither my God can come into '
     'me, whither God can come, God who made heaven and earth?', []),
    (103, 2, 1, '46', '46', 'Book I › Chapter 5',
     'Oh! that I might repose on Thee! Oh! that Thou wouldest enter into my heart, and inebriate it, '
     'that I may forget my ills, and embrace Thee, my sole good! What art Thou to me?', []),
    (103, 3, 1, '47', '47', 'Book XI › Chapter 14',
     'What then is time? If no one asks me, I know: if I wish to explain it to one that asketh, I '
     'know not. Yet I say boldly that I know, that if nothing passed away, time past were not.', []),
    (103, 4, 1, '48', '49', 'Book VIII › Chapter 12',
     'I heard from a neighbouring house a voice, as of boy or girl, chanting, and oft repeating, '
     '"Take up and read; Take up and read." [p. 49] So I seized the book, opened, and read in '
     'silence that section on which my eyes first fell.', ['Rom. xiii. 13, 14.']),
    (103, 5, 1, '50', '50', 'Book X › Chapter 27',
     'Too late loved I Thee, O Thou Beauty of ancient days, yet ever new! too late I loved Thee! '
     'Thou wert with me, but I was not with Thee. Thou calledst, and shoutedst, and burstest my '
     'deafness.', []),
    (103, 6, 1, '51', '52', 'Book X › Chapter 29',
     'And all my hope is no where but in Thy exceeding great mercy. Give what Thou enjoinest, and '
     'enjoin what Thou wilt. [p. 52] Thou enjoinest us continency; and when I knew that no man can '
     'be continent unless God give it, this also was a part of wisdom.', []),
    # Work 104: accented author, no year, a page break inside a passage, last passage has no vector
    (104, 0, 1, '7', '7', 'Of God',
     'We believe that there is one only God, eternal, infinite, almighty, most wise and most good, '
     'who of His mere mercy has chosen His own in Jesus Christ before the foundation of the world.',
     []),
    (104, 1, 1, '8', '8', 'Of Faith',
     'Faith is a certain knowledge of the goodness of God toward us, grounded upon the free promise '
     'in Christ, and sealed in our hearts by the Holy Spirit; by it alone we lay hold on Christ.', []),
    (104, 2, 1, '9', '10', 'Of Faith',
     'This faith is not of ourselves; it is the gift of God, who gives it to whom He will, and works '
     'it by the preaching of the Word. [p. 10] And faith being given, the assurance of salvation '
     'follows, for whom God has justified, them He has also glorified.', []),
    (104, 3, 1, '10', '11', 'A Short Catechism',
     'What is the chief end of man? Man\'s chief end is to glorify God, and to enjoy Him for ever. '
     '[p. 11] What rule has God given to direct us how we may glorify and enjoy Him? The Word of '
     'God is the only rule.', []),
    (104, 4, 1, '11', '12', 'A Short Catechism',
     'What is sin? Sin is any want of conformity unto, or transgression of, the law of God. [p. 12] '
     'What is justification? Justification is an act of God\'s free grace, wherein He pardons all '
     'our sins and accepts us as righteous in His sight.', []),
    (104, 5, 1, '12', '12', 'A Short Catechism',
     'What is the duty which God requires of man? The duty which God requires of man is obedience '
     'to His revealed will, in the strength of His grace and by the help of His Spirit.', []),
]
NO_VECTOR = {10400005}

# Strings whose fake_embed vectors the PHP port must reproduce (expected.json).
FAKE_EMBED_STRINGS = [
    'be killing sin or it will be killing you',
    'Thou hast made us for Thyself, and our heart is restless',
    'ſinne and grace',
    'İstanbul KELVIN K sign: Straße ÆTHER',
    'mixed-case WORDS, numbers 123 and   spaces\n\ttabs',
    '',
    '!!!',
    'chief end of man',
]
# Queries for the exact/IVF vector ranking checks.
VECTOR_QUERIES = ['killing sin', 'restless heart repose in thee', 'chief end of man glorify god',
                  'faith assurance salvation']
# FTS5 MATCH strings whose bm25 order the PHP Store must reproduce.
FTS_MATCHES = ['"sin"', '"mortify" "deeds"', '"faith"', 'body : "deeds of the body"', '"god" "heart"']


def modernize(text: str) -> str:
    """The indexer's search-text transform, with the fixture's fixed spelling table."""
    text = fold_chars(text)

    def repl(m: re.Match) -> str:
        word = m.group(0)
        std = SPELLING.get(word.lower())
        if std is None:
            return word
        if word.isupper() and len(word) > 1:
            return std.upper()
        if word[0].isupper():
            return std[:1].upper() + std[1:]
        return std
    return WORD_RE.sub(repl, text)


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    for name in ('library.sqlite', 'vectors.bin', 'ids.bin', 'manifest.json'):
        (OUT / name).unlink(missing_ok=True)
    db = sqlite3.connect(OUT / 'library.sqlite')
    db.executescript(build_db.SCHEMA)

    works = {w['id']: corpus.Work(**w) for w in WORKS}
    counts: dict[int, list] = {}
    embed_inputs: dict[int, str] = {}
    for wid, seq, part, p0, p1, section, text, notes in PASSAGES:
        work = works[wid]
        pid = wid * corpus.SEQ_MULT + seq
        plain = corpus.strip_markdown(pre_markdown(text))
        body = modernize(plain)
        notes_plain = ' '.join(corpus.strip_markdown(n) for n in notes)
        notes_mod = modernize(notes_plain) if notes_plain else ''
        section_mod = modernize(section) if section else ''
        header = modernize(build_db.header_text(work, section))
        words = len(text.split())
        db.execute('INSERT INTO passages(id, work_id, seq, part, page_start, page_end, section, text, notes, '
                   'words) VALUES (?,?,?,?,?,?,?,?,?,?)',
                   (pid, wid, seq, part, p0, p1, section, zlib.compress(text.encode('utf-8'), 6),
                    zlib.compress(json.dumps(notes, ensure_ascii=False).encode('utf-8'), 6) if notes else None,
                    words))
        db.execute('INSERT INTO passages_fts(rowid, body, notes, header) VALUES (?,?,?,?)',
                   (pid, body, notes_mod, header))
        if pid not in NO_VECTOR:
            embed_inputs[pid] = build_db.embed_input(
                work, section_mod, body + ((' Notes: ' + notes_mod) if notes_mod else ''))
        counts.setdefault(wid, []).append((p0, p1))
    for w in WORKS:
        pages = counts[w['id']]
        first = next((a for a, _ in pages if a), None)
        last = next((b for _, b in reversed(pages) if b), None)
        db.execute('INSERT INTO works VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', (
            w['id'], w['key'], w['author'], w['author_slug'], w['title'], w['full_title'], w['year'],
            w['type'], w['collection'], w['license'], w['source_url'], w['tcp_id'], w['series'],
            int(w['ocr']), len(pages), first, last))
    db.execute("INSERT INTO works_fts(works_fts) VALUES('rebuild')")
    # Who the authors were (the build takes this from the books repository's authors.csv). Bèze has no row.
    db.execute('CREATE TABLE IF NOT EXISTS authors(name TEXT PRIMARY KEY, dates TEXT NOT NULL, tradition TEXT NOT NULL, '
               'note TEXT NOT NULL) WITHOUT ROWID')
    db.executemany('INSERT INTO authors(name, dates, tradition, note) VALUES (?,?,?,?)', [
        ('John Owen', '1616–1683', 'Puritan', 'English Puritan, Congregationalist'),
        ('Augustine', '354–430', 'Church Father', 'bishop of Hippo, Latin Church Father'),
    ])
    db.executemany('INSERT INTO spelling(variant, standard) VALUES (?,?)', sorted(SPELLING.items()))

    # Vectors: hashed bag of words (the PHP fake mode computes the same for queries).
    ids = np.array(sorted(embed_inputs), dtype=np.int64)
    X = np.stack([ivf.fake_embed(embed_inputs[i], DIMS) for i in ids]).astype(np.float32)
    old_stdout, sys.stdout = sys.stdout, open('/dev/null', 'w')
    try:
        C = ivf.kmeans(X, K, sample=len(X), iters=10)
    finally:
        sys.stdout.close()
        sys.stdout = old_stdout
    scale = float(127.0 / max(np.percentile(np.abs(X), 99.999), 1e-6))
    assign = np.argmax(X @ C.T, axis=1)
    order = np.lexsort((ids, assign))
    counts_k = np.bincount(assign, minlength=K)
    starts = np.concatenate([[0], np.cumsum(counts_k)[:-1]])
    Q = np.clip(np.rint(X[order] * scale), -127, 127).astype(np.int8)
    (OUT / 'vectors.bin').write_bytes(Q.tobytes())
    ids[order].astype('<u4').tofile(OUT / 'ids.bin')
    db.executemany('INSERT INTO ivf(cluster, centroid, start, count) VALUES (?,?,?,?)',
                   [(c, C[c].astype('<f4').tobytes(), int(starts[c]), int(counts_k[c])) for c in range(K)])
    pos = {int(ids[r]): i for i, r in enumerate(order)}
    db.executemany('UPDATE passages SET vec_pos=? WHERE id=?', [(p, i) for i, p in pos.items()])

    meta = {
        'build_id': 'fixture', 'built_at': '2026-11-08T00:00:00+00:00', 'repo_commit': '',
        'chunker_version': str(corpus.CHUNKER_VERSION), 'n_works': str(len(WORKS)),
        'n_passages': str(len(PASSAGES)), 'n_dropped_ocr': '1',
        'embed_model': 'voyage-4-lite', 'query_model': 'voyage-4-lite', 'embed_dims': str(DIMS),
        'embed_dtype': 'int8', 'embed_scale': repr(scale), 'ivf_k': str(K), 'rerank_model': 'rerank-3',
        'embed_fake': '1', 'n_vectors': str(len(ids)),
    }
    db.executemany('INSERT INTO meta(key, value) VALUES (?,?)', meta.items())
    xref = add_xref_tables(db, OUT)
    db.execute("INSERT INTO passages_fts(passages_fts) VALUES('optimize')")
    db.execute('CREATE INDEX passages_work_seq ON passages(work_id, seq)')
    db.commit()
    db.execute('VACUUM')
    db.close()
    ivf.write_manifest(OUT)

    # Expected values for the PHP tests.
    db = sqlite3.connect(f'file:{OUT / "library.sqlite"}?mode=ro', uri=True)
    expected = {'dims': DIMS, 'scale': scale, 'fake_embed': [], 'vector_exact': [], 'ivf': [], 'fts': []}
    for s in FAKE_EMBED_STRINGS:
        for d in (DIMS, 512):
            expected['fake_embed'].append({'text': s, 'dims': d,
                                           'vec': [float(x) for x in ivf.fake_embed(s, d)]})
    rows = Q.astype(np.float64)
    row_ids = ids[order]
    cents = C.astype(np.float64)
    for qtext in VECTOR_QUERIES:
        qv = ivf.fake_embed(qtext, DIMS).astype(np.float64)
        scores = rows @ qv
        top = sorted(range(len(row_ids)), key=lambda r: (-round(scores[r], 9), int(row_ids[r])))
        expected['vector_exact'].append({'query': qtext, 'ids': [int(row_ids[r]) for r in top],
                                         'scores': [float(scores[r]) for r in top]})
        # IVF with nprobe 2: best two centroids, then exact scores inside their lists
        probe = sorted(range(K), key=lambda c: -float(cents[c] @ qv))[:2]
        inside = [r for c in probe for r in range(int(starts[c]), int(starts[c] + counts_k[c]))]
        inside.sort(key=lambda r: (-round(scores[r], 9), int(row_ids[r])))
        expected['ivf'].append({'query': qtext, 'nprobe': 2, 'clusters': [int(c) for c in probe],
                                'ids': [int(row_ids[r]) for r in inside],
                                'scores': [float(scores[r]) for r in inside]})
    for match in FTS_MATCHES:
        got = db.execute('SELECT rowid, bm25(passages_fts, 1.0, 0.3, 0.6) FROM passages_fts '
                         'WHERE passages_fts MATCH ? ORDER BY 2, rowid', (match,)).fetchall()
        expected['fts'].append({'match': match, 'ids': [r[0] for r in got], 'scores': [r[1] for r in got]})
    db.close()
    (OUT / 'expected.json').write_text(json.dumps(expected, indent=1, ensure_ascii=False) + '\n',
                                       encoding='utf-8')

    # The same build marked as having real Voyage vectors, so tests can drive the non-fake Voyage
    # code path (with a simulated HTTP transport) through the whole search pipeline.
    real = OUT / 'realmode'
    real.mkdir(exist_ok=True)
    for name in ('vectors.bin', 'ids.bin'):
        (real / name).write_bytes((OUT / name).read_bytes())
    (real / 'library.sqlite').unlink(missing_ok=True)
    src = sqlite3.connect(OUT / 'library.sqlite')
    dst = sqlite3.connect(real / 'library.sqlite')
    src.backup(dst)
    src.close()
    dst.execute("UPDATE meta SET value = '0' WHERE key = 'embed_fake'")
    drop_xref_tables(dst)
    dst.commit()
    dst.execute('VACUUM')
    dst.close()

    (OUT / 'config.php').write_text("""<?php
// Test server config for the PHP tests (generated by tests/php/make_fixture.py).
// Same format as the production config; voyage_fake replaces Voyage with a deterministic fake.
return [
    'url_key'        => 'fixture-test-key-0123456789abcdefghijklmnopqrstuvwxyz',
    'voyage_api_key' => '',
    'voyage_fake'    => true,
    'data_dir'       => __DIR__,
    'state_dir'      => dirname(__DIR__, 3) . '/data/local-state',
    'embed_model'    => 'voyage-4-lite',
    'embed_dims'     => 8,
    'rerank_model'   => 'rerank-3',
    'nprobe'         => 2,
    'max_calls_per_minute'      => 100000,
    'max_calls_per_day'         => 1000000,
    'max_voyage_tokens_per_day' => 3000000,
    'log_level'      => 'off',
    'log_queries'    => false,
];
""", encoding='utf-8')
    print(f'fixture: {len(WORKS)} works, {len(PASSAGES)} passages, {len(ids)} vectors in {K} lists '
          f'(sizes {counts_k.tolist()}), scale {scale:.2f}; cross-references {xref} -> {OUT}')


if __name__ == '__main__':
    main()
