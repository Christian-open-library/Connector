#!/usr/bin/env python3
"""Write plugin/col-library-connector/src/ModernList.php from writers.tsv.

    python3 -I tools/modern/make.py

writers.tsv is the hand-checked list of commonly quoted Christian writers whose books are still in copyright
(died 1956 or later, or living): name, death year, other names, the surname when it alone means this writer,
Wikidata id. Death years were checked against Wikidata on 2026-11-16 (wikidata.py; wikidata.tsv is that run,
where DIFF rows were a namesake or a recent death, settled by hand). authors.php shows which names collide with
writers the library holds.
"""
import json, os

here = os.path.dirname(os.path.abspath(__file__))
rows = []
for line in open(os.path.join(here, 'writers.tsv'), encoding='utf-8'):
    if line.startswith('#') or not line.strip():
        continue
    name, died, other, alone, _ = (line.rstrip('\n').split('\t') + [''] * 5)[:5]
    if died != '-' and int(died) < 1956:
        raise SystemExit(f'{name}: died {died}, before 1956: not for this list')
    rows.append([name, None if died == '-' else int(died), [o for o in other.split(';') if o], alone])
php = ['<?php', '// Written by tools/modern/make.py from tools/modern/writers.tsv. Do not edit by hand.', '',
       'declare(strict_types=1);', '', 'namespace ColLibrary;', '', "defined('ABSPATH') || exit;", '',
       'final class ModernList', '{', '    /** [name, death year or null, other names, surname that alone means this writer] */',
       '    public const WRITERS = [']
for r in rows:
    php.append('        [' + ', '.join([json.dumps(r[0], ensure_ascii=False).replace('$', '\\$'),
                                       'null' if r[1] is None else str(r[1]),
                                       '[' + ', '.join(json.dumps(o, ensure_ascii=False) for o in r[2]) + ']',
                                       json.dumps(r[3], ensure_ascii=False)]) + '],')
php += ['    ];', '}', '']
open(os.path.join(here, '../../plugin/col-library-connector/src/ModernList.php'), 'w', encoding='utf-8').write('\n'.join(php))
print(len(rows), 'writers')
