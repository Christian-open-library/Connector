"""Bible references: book table, reference extraction from old and modern texts, and the
Bible verse table built from the translations in the library.

    python -m indexer.scripture --try "as Saint Paul saith, Rom. viii. 28; cf. Ioh. 3.16 and Psal. 49, 15."
    python -m indexer.scripture --bibles          # parse the six Bibles, print verse counts
"""
from __future__ import annotations

import argparse
import re
from pathlib import Path

from . import corpus

REPO = Path.home() / 'library-work/books'

# id, canonical name, short name, aliases (without trailing dots; matched case-insensitively,
# the text must start with a capital letter). Ordinal books list the bare stem; the ordinal is
# parsed separately. Ids 1-66 are the Protestant canon order; 67+ are the Apocrypha.
BOOKS = [
    (1, 'Genesis', 'Gen', ['Genesis', 'Genes', 'Gene', 'Gen', 'Gn']),
    (2, 'Exodus', 'Exod', ['Exodus', 'Exod', 'Exo']),
    (3, 'Leviticus', 'Lev', ['Leviticus', 'Levit', 'Lev']),
    (4, 'Numbers', 'Num', ['Numbers', 'Numb', 'Num']),
    (5, 'Deuteronomy', 'Deut', ['Deuteronomy', 'Deuter', 'Deut', 'Deu']),
    (6, 'Joshua', 'Josh', ['Joshua', 'Iosuah', 'Iosua', 'Josua', 'Josue', 'Iosh', 'Josh', 'Ios']),
    (7, 'Judges', 'Judg', ['Judges', 'Iudges', 'Iudg', 'Judg', 'Jud', 'Iud']),
    (8, 'Ruth', 'Ruth', ['Ruth']),
    (9, '1 Samuel', '1 Sam', ['Samuel', 'Sam']),
    (11, '1 Kings', '1 Kgs', ['Kings', 'King', 'Kin', 'Kgs', 'Reg', 'Regum']),
    (13, '1 Chronicles', '1 Chr', ['Chronicles', 'Chron', 'Chro', 'Chr', 'Paralipomenon', 'Paralip', 'Par']),
    (15, 'Ezra', 'Ezra', ['Ezra', 'Ezr']),
    (16, 'Nehemiah', 'Neh', ['Nehemiah', 'Nehemias', 'Nehem', 'Neh']),
    (17, 'Esther', 'Esth', ['Esther', 'Hester', 'Esth', 'Hest']),
    (18, 'Job', 'Job', ['Job', 'Iob']),
    (19, 'Psalms', 'Ps', ['Psalms', 'Psalmes', 'Psalme', 'Psalm', 'Psal', 'Psa', 'Ps']),
    (20, 'Proverbs', 'Prov', ['Proverbs', 'Proverbes', 'Prov', 'Prou', 'Pro']),
    (21, 'Ecclesiastes', 'Eccl', ['Ecclesiastes', 'Ecclesiast', 'Eccles', 'Eccl', 'Eccle', 'Ecc', 'Qoheleth']),
    (22, 'Song of Solomon', 'Song', ['Canticles', 'Canticle', 'Cantic', 'Cant', 'Song of Solomon', 'Song of Songs', 'Song of Sol']),
    (23, 'Isaiah', 'Isa', ['Isaiah', 'Esaias', 'Isaias', 'Esay', 'Esai', 'Isai', 'Esa', 'Isa']),
    (24, 'Jeremiah', 'Jer', ['Jeremiah', 'Ieremiah', 'Jeremias', 'Ieremias', 'Hieremias', 'Jerem', 'Ierem', 'Jer', 'Ier']),
    (25, 'Lamentations', 'Lam', ['Lamentations', 'Lament', 'Lam']),
    (26, 'Ezekiel', 'Ezek', ['Ezekiel', 'Ezechiel', 'Ezech', 'Ezek', 'Ezec', 'Eze']),
    (27, 'Daniel', 'Dan', ['Daniel', 'Dan']),
    (28, 'Hosea', 'Hos', ['Hosea', 'Osee', 'Hos', 'Hose', 'Ose']),
    (29, 'Joel', 'Joel', ['Joel', 'Ioel']),
    (30, 'Amos', 'Amos', ['Amos']),
    (31, 'Obadiah', 'Obad', ['Obadiah', 'Abdias', 'Obad', 'Abd']),
    (32, 'Jonah', 'Jonah', ['Jonah', 'Ionah', 'Jonas', 'Ionas', 'Jon', 'Ion']),
    (33, 'Micah', 'Mic', ['Micah', 'Micheas', 'Michea', 'Mich', 'Mic']),
    (34, 'Nahum', 'Nah', ['Nahum', 'Nah']),
    (35, 'Habakkuk', 'Hab', ['Habakkuk', 'Habacuc', 'Abacuc', 'Habak', 'Hab']),
    (36, 'Zephaniah', 'Zeph', ['Zephaniah', 'Sophonias', 'Zeph', 'Zep', 'Soph']),
    (37, 'Haggai', 'Hag', ['Haggai', 'Aggeus', 'Agge', 'Hag', 'Agg']),
    (38, 'Zechariah', 'Zech', ['Zechariah', 'Zacharias', 'Zachary', 'Zechar', 'Zachar', 'Zech', 'Zach', 'Zac']),
    (39, 'Malachi', 'Mal', ['Malachi', 'Malachias', 'Malach', 'Mal']),
    (40, 'Matthew', 'Matt', ['Matthew', 'Matth', 'Matt', 'Mat']),
    (41, 'Mark', 'Mark', ['Mark', 'Marke', 'Marc', 'Mar']),
    (42, 'Luke', 'Luke', ['Luke', 'Luk', 'Luc']),
    (43, 'John', 'John', ['John', 'Iohn', 'Joh', 'Ioh']),
    (44, 'Acts', 'Acts', ['Acts', 'Act']),
    (45, 'Romans', 'Rom', ['Romans', 'Rom']),
    (46, '1 Corinthians', '1 Cor', ['Corinthians', 'Corinth', 'Cor']),
    (48, 'Galatians', 'Gal', ['Galatians', 'Galat', 'Gal']),
    (49, 'Ephesians', 'Eph', ['Ephesians', 'Ephes', 'Eph']),
    (50, 'Philippians', 'Phil', ['Philippians', 'Philipp', 'Philip', 'Phil']),
    (51, 'Colossians', 'Col', ['Colossians', 'Coloss', 'Col']),
    (52, '1 Thessalonians', '1 Thess', ['Thessalonians', 'Thessal', 'Thess', 'Thes']),
    (54, '1 Timothy', '1 Tim', ['Timothy', 'Timoth', 'Tim']),
    (56, 'Titus', 'Titus', ['Titus', 'Tit']),
    (57, 'Philemon', 'Phlm', ['Philemon', 'Philem', 'Phlm']),
    (58, 'Hebrews', 'Heb', ['Hebrews', 'Hebr', 'Heb']),
    (59, 'James', 'Jas', ['James', 'Iames', 'Jam', 'Iam', 'Jas', 'Jac', 'Iac']),
    (60, '1 Peter', '1 Pet', ['Peter', 'Pet']),
    (65, 'Jude', 'Jude', ['Jude', 'Iude']),
    (66, 'Revelation', 'Rev', ['Revelation', 'Revelations', 'Revel', 'Rev', 'Apocalypse', 'Apocal', 'Apoc']),
    (67, 'Tobit', 'Tob', ['Tobit', 'Tobias', 'Tob']),
    (68, 'Judith', 'Jdt', ['Judith', 'Iudith']),
    (70, 'Wisdom', 'Wis', ['Wisdom', 'Wisd', 'Sapientia', 'Sap']),
    (71, 'Sirach', 'Sir', ['Ecclesiasticus', 'Ecclus', 'Sirach', 'Syrach', 'Sir']),
    (72, 'Baruch', 'Bar', ['Baruch']),
    (77, '1 Maccabees', '1 Macc', ['Maccabees', 'Machabees', 'Macc', 'Mach', 'Mac']),
    (79, '1 Esdras', '1 Esd', ['Esdras', 'Esd']),
]
# Books that take an ordinal: stem id -> ids for 1st, 2nd, 3rd
ORDINAL = {9: (9, 10), 11: (11, 12), 13: (13, 14), 46: (46, 47), 52: (52, 53), 54: (54, 55),
           60: (60, 61), 43: (62, 63, 64), 77: (77, 78), 79: (79, 80)}
NAMES = {i: n for i, n, _, _ in BOOKS}
NAMES.update({10: '2 Samuel', 12: '2 Kings', 14: '2 Chronicles', 47: '2 Corinthians',
              53: '2 Thessalonians', 55: '2 Timothy', 61: '2 Peter', 62: '1 John', 63: '2 John',
              64: '3 John', 78: '2 Maccabees', 80: '2 Esdras', 69: 'Additions to Esther',
              73: 'Prayer of Azariah', 74: 'Susanna', 75: 'Bel and the Dragon', 76: 'Prayer of Manasseh'})
CANON_ORDER = list(range(1, 67))
# Aliases that are also ordinary English words or first names: only accepted with chapter AND verse.
AMBIGUOUS = {'job', 'iob', 'mark', 'marke', 'mar', 'acts', 'act', 'ruth', 'numbers', 'judges', 'kings',
             'king', 'kin', 'john', 'luke', 'james', 'peter', 'jude', 'dan', 'daniel', 'joel', 'amos',
             'titus', 'tit', 'col', 'mal', 'hab', 'baruch', 'tobias', 'judith', 'wisdom', 'song of songs',
             'ezra', 'hester', 'esther', 'sam', 'samuel', 'pro', 'ion', 'jon', 'luc', 'marc', 'par', 'reg',
             'gene', 'sap', 'sir', 'mac', 'esd', 'chr', 'tim', 'pet', 'jam', 'jac', 'iac', 'iam', 'cant',
             'lament', 'act', 'ios', 'iud', 'jud', 'ose', 'hose', 'mich', 'mic', 'nah', 'soph', 'agg', 'zac'}

_alias_to_id: dict[str, int] = {}
for _id, _name, _short, _aliases in BOOKS:
    for _a in _aliases:
        _alias_to_id[_a.lower()] = _id

ROMAN = {'i': 1, 'v': 5, 'x': 10, 'l': 50, 'c': 100}


def roman_to_int(s: str) -> int | None:
    s = s.lower()
    if not re.fullmatch(r'[ivxlc]+', s):
        return None
    total, prev = 0, 0
    for ch in reversed(s):
        v = ROMAN[ch]
        total = total - v if v < prev else total + v
        prev = max(prev, v)
    return total if 0 < total <= 150 else None


_alias_re = '|'.join(re.escape(a) for a in sorted(_alias_to_id, key=len, reverse=True))
REF_RE = re.compile(
    r'(?<![A-Za-z])'
    r'(?:(?P<ord>[123]|I{1,3}|i{1,3}|First|Second|Third|1st|2nd|3rd|1\.|2\.|3\.)\s*\.?\s*)?'
    r'(?P<book>' + _alias_re + r')(?![a-z])\.?\s*'
    r'(?P<ch>\d{1,3}|[ivxlcIVXLC]{1,7})(?![\d])'
    r'(?:\s*[\.:,]\s*(?P<v1>\d{1,3})(?:\s*(?:[-–—]|to)\s*(?P<v2>\d{1,3}))?)?'
    r'(?![\d])', re.I)
ORD_MAP = {'1': 0, 'i': 0, 'first': 0, '1st': 0, '1.': 0, '2': 1, 'ii': 1, 'second': 1, '2nd': 1, '2.': 1,
           '3': 2, 'iii': 2, 'third': 2, '3rd': 2, '3.': 2}
ONE_CHAPTER = {31, 57, 63, 64, 65}     # Obadiah, Philemon, 2 John, 3 John, Jude: "Jude 3" is verse 3
RANGE_AFTER_RE = re.compile(r'\s*(?:[-–—]|to)\s*(\d{1,3})(?![\d])')
# more verses of the same chapter: "1 Tim. 6. 17, 18, 19", "Heb. 12:22,23" (not "28, 9. 5": a chapter)
LIST_ITEM_RE = re.compile(r'\s*,\s*(\d{1,3})(?:\s*(?:[-–—]|to)\s*(\d{1,3}))?(?![\d])(?!\s*[.:]\s*\d)')
BOOK_AFTER_RE = re.compile(r'\s*\.?\s*(?:' + _alias_re + r')(?![a-z])', re.I)
# another chapter and verse of the same book: "Rom. i. 32; ii. 1", "Rom. 8. 28; 9. 5" (not "v. 5" or
# "c. 5", verse and chapter; roman numerals in their usual form only, up to cxcix)
CONTINUATION_RE = re.compile(r'\s*;\s*(\d{1,3}|(?!(?:v|vv|c|l)\b)(?=[ivxlc])c?(?:xc|xl|l?x{0,3})(?:ix|iv|v?i{0,3})'
                             r'(?![a-z]))\s*[.:,]\s*(\d{1,3})(?:\s*(?:[-–—]|to)\s*(\d{1,3}))?(?![\d])', re.I)


def _checked(bid: int, ch: int, v1: int | None, v2: int | None, limits: dict | None):
    """The reference with v2 None for a single verse, or None if the chapter or verse does not exist."""
    if v1 is not None and v2 is not None and v2 < v1:
        v2 = None
    if ch == 0 or v1 == 0:
        return None
    if limits is not None:
        chapters = limits.get(bid)
        if chapters:
            maxv = chapters.get(ch)
            if maxv is None:
                return None
            if v1 is not None and v1 > maxv + 2:
                return None
            if v2 is not None and v2 > maxv + 2:
                v2 = maxv
    return bid, ch, v1, v2 if v2 != v1 else None


def _add_span(spans: list[tuple[int, int | None]], a: int, b: int | None) -> None:
    """Appends verses a-b (b None: one verse), joined to the last span when they follow it."""
    if spans and a == (spans[-1][1] or spans[-1][0]) + 1:
        spans[-1] = (spans[-1][0], b or a)
    else:
        spans.append((a, b))


def _verse_list(text: str, pos: int, first: list[tuple[int, int | None]]) -> tuple[list[tuple[int, int | None]], int]:
    """The verses of `first` with those listed after them (", 18, 19"; consecutive ones joined into
    a range), and the position after them."""
    spans: list[tuple[int, int | None]] = []
    for a, b in first:
        _add_span(spans, a, b)
    while m := LIST_ITEM_RE.match(text, pos):
        a, b = int(m.group(1)), int(m.group(2)) if m.group(2) else None
        if a <= 3 and BOOK_AFTER_RE.match(text, m.end(1)):
            break                     # "Rom. 8. 28, 1 Cor. 2. 9": the ordinal of the next book
        _add_span(spans, a, b)
        pos = m.end()
    return spans, pos


def extract_refs(text: str, limits: dict | None = None) -> list[tuple[int, int, int | None, int | None]]:
    """(book, chapter, verse_start, verse_end) for every Bible reference found in plain text.
    `limits` (book -> {chapter: max_verse}) rejects impossible chapters/verses. Besides "Rom. 8. 28"
    and its kin: verse lists ("1 Tim. 6. 17, 18, 19"), more chapters of the same book after ";"
    ("Rom. i. 32; ii. 1") and a lone number in a one-chapter book ("Jude 3", "Philem. 10")."""
    out = []
    text = text.replace('ſ', 's').replace('ẜ', 's')   # re.I folds long s to s; normalize first
    for m in REF_RE.finditer(text):
        raw = m.group('book')
        if not raw[0].isupper():
            continue
        alias = raw.lower()
        bid = _alias_to_id.get(alias)
        if bid is None:
            continue
        ordinal = m.group('ord')
        if bid in ORDINAL:
            idx = ORD_MAP.get((ordinal or '1').lower().rstrip('.') if ordinal else '1', None)
            if ordinal and ordinal.lower() in ORD_MAP:
                idx = ORD_MAP[ordinal.lower()]
            if idx is None:
                continue
            ids = ORDINAL[bid]
            if bid == 43:              # John vs 1-3 John
                if not ordinal:
                    bid = 43
                elif idx < len(ids):
                    bid = ids[idx]
                else:
                    continue
            else:
                if not ordinal and bid not in (9, 11, 13):
                    # "Cor. 5. 7" without ordinal is ambiguous; assume the first epistle
                    pass
                bid = ids[idx] if idx < len(ids) else ids[0]
        elif ordinal and ordinal.lower().rstrip('.') in ('2', '3', 'ii', 'iii', 'second', 'third', '2nd', '3rd'):
            # "2 Kings" style ordinals on a non-ordinal alias: probably not a reference
            continue
        ch_raw = m.group('ch')
        if ch_raw.isdigit():
            ch = int(ch_raw)
        else:
            if not m.group('v1'):
                continue            # roman chapter only with a verse ("Rom. viii. 28")
            ch = roman_to_int(ch_raw)
            if ch is None:
                continue
        v1 = int(m.group('v1')) if m.group('v1') else None
        v2 = int(m.group('v2')) if m.group('v2') else None
        pos = m.end()
        first = [(v1, v2)] if v1 is not None else []
        if bid in ONE_CHAPTER and v1 is None:          # "Jude 3", "Jude 3-5": verses
            ch, v1 = 1, ch
            if r := RANGE_AFTER_RE.match(text, pos):
                v2, pos = int(r.group(1)), r.end()
            first = [(v1, v2)]
        elif bid in ONE_CHAPTER and ch != 1:           # "Jude 6, 7": verses 6 and 7, not chapter 6
            first = [(ch, None), (v1, v2)]
            ch, v1, v2 = 1, ch, None
        if v1 is None:
            if alias in AMBIGUOUS or len(alias) <= 2:
                continue
            if ref := _checked(bid, ch, None, None, limits):
                out.append(ref)
            continue
        # this chapter's verses, then other chapters of the book given after ";"
        while True:
            spans, pos = _verse_list(text, pos, first)
            if (head := _checked(bid, ch, *spans[0], limits)) is None:
                break
            out.append(head)
            out.extend(ref for a, b in spans[1:] if (ref := _checked(bid, ch, a, b, limits)))
            c = CONTINUATION_RE.match(text, pos)
            ch = (int(c.group(1)) if c.group(1).isdigit() else roman_to_int(c.group(1))) if c else None
            if ch is None:
                break
            v1, v2, pos = int(c.group(2)), int(c.group(3)) if c.group(3) else None, c.end()
            first = [(v1, v2)]
    return out


def format_ref(book: int, ch: int, v1: int | None, v2: int | None) -> str:
    s = f'{NAMES.get(book, str(book))} {ch}'
    if v1:
        s += f':{v1}'
        if v2:
            s += f'-{v2}'
    return s


# ---------------------------------------------------------------- Bible text from the library

BIBLE_FILES = {
    'kjv': 'Bible - King James Version',
    'asv': 'Bible - American Standard Version (1901)',
    'drb': 'Bible - Douay-Rheims',
    'darby': 'Bible - Darby Translation',
    'webster': "Bible - Webster's Bible",
    'ylt': "Bible - Young's Literal Translation",
}
# Douay-Rheims / Vulgate names that differ (only for the Douay-Rheims: in the KJV's Apocrypha "The
# First Book of Esdras" is 1 Esdras, not Ezra)
DRB_NAMES = {
    'first book of samuel': 9, 'first book of kings': 9, 'second book of samuel': 10, 'second book of kings': 10,
    'third book of kings': 11, 'fourth book of kings': 12, 'first book of paralipomenon': 13,
    'second book of paralipomenon': 14, 'first book of esdras': 15, 'second book of esdras': 16,
    'book of nehemias': 16, 'book of tobias': 67, 'canticle of canticles': 22, 'book of wisdom': 70,
    'ecclesiasticus': 71, 'prophecy of baruch': 72, 'first book of machabees': 77,
    'second book of machabees': 78, 'apocalypse': 66, 'osee': 28, 'abdias': 31, 'jonas': 32,
    'micheas': 33, 'sophonias': 36, 'aggeus': 37, 'zacharias': 38, 'malachias': 39, 'isaias': 23,
    'jeremias': 24, 'ezechiel': 26, 'josue': 6, 'book of job': 18,
}
ORD_WORDS = {'first': 0, 'second': 1, 'third': 2, 'fourth': 3}


def identify_book(heading: str, extra: str = '', translation: str = '') -> int | None:
    """Book id from a Bible book heading (plus the bold line after it, for the KJV)."""
    text = re.sub(r'[*_]', '', f'{heading} {extra}').strip()
    low = text.lower()
    if 'lamentation' in low:
        return 25
    if extra:
        # the KJV puts the book name in a bold line ("**Leviticus**"); trust it over the heading,
        # which has slips such as "The Second Book of Moses, called" for Leviticus
        ex_words = re.findall(r'[a-z]+', re.sub(r'[*_]', '', extra).lower())
        named = [w for w in ex_words if w in _alias_to_id and w not in ('of', 'st')]
        if named and _alias_to_id[named[-1]] not in ORDINAL:
            return _alias_to_id[named[-1]]
    if translation == 'drb':
        for key, bid in sorted(DRB_NAMES.items(), key=lambda kv: -len(kv[0])):
            if key in low:
                return bid
    if 'rest of the chapters of the book of esther' in low:
        return 69
    if 'revelation' in low:
        return 66
    if 'song of solomon' in low or 'song of songs' in low:
        return 22
    if 'manasses' in low or 'manasseh' in low:
        return 76
    if 'susanna' in low:
        return 74
    if 'bel and the dragon' in low or low.endswith(' bel'):
        return 75
    if 'three holy children' in low or 'song of the three' in low or 'azariah' in low:
        return 73
    if 'ecclesiasticus' in low or 'sirach' in low:
        return 71
    ordinal = None
    m = re.search(r'\b(first|second|third|fourth)\b', low)
    if m:
        ordinal = ORD_WORDS[m.group(1)]
    m = re.search(r'\b([123]|i{1,3})\s+[a-z]', low)
    if ordinal is None and m:
        ordinal = {'1': 0, '2': 1, '3': 2, 'i': 0, 'ii': 1, 'iii': 2}[m.group(1)]
    words = re.findall(r'[a-z]+', low)
    for w in reversed(words):           # the book name is usually the last word
        if w in ('called', 'book', 'of', 'the', 'moses', 'general', 'epistle', 'gospel', 'according',
                 'to', 'st', 'saint', 'apostle', 'paul', 'otherwise', 'prophet', 'divine'):
            continue
        bid = _alias_to_id.get(w)
        if bid is None:
            continue
        if 'moses' in low:
            return {0: 1, 1: 2, 2: 3, 3: 4}.get(ordinal, bid) if ordinal is not None and 'fifth' not in low else (5 if 'fifth' in low else bid)
        if bid in ORDINAL:
            ids = ORDINAL[bid]
            if bid == 43:
                return ids[ordinal] if ordinal is not None and ordinal < len(ids) else 43
            return ids[ordinal] if ordinal is not None and ordinal < len(ids) else ids[0]
        return bid
    return None


VERSE_SPLIT_RE = re.compile(r'(?:(?<=\s)|^)(\d{1,3})(?=[^\d\s.,:;)\]])')
# Webster: "17\\9:16\\The LORD is known": printed verse 17 is the KJV's 9:16 (its numbers follow
# another versification in about 90 chapters; the mark gives the KJV chapter and verse)
WEBSTER_MARK_RE = re.compile(r'^\\\\(\d{1,3}):(\d{1,3})\\\\\s*')
MD_ESCAPE_RE = re.compile(r'\\([\\`*_{}\[\]()#+\-.!|<>~"\'])')       # Markdown "\`" -> "`"
CHAPTER_LINE_RE = re.compile(r'^\*\*\s*Chapter\s+\d+\s*\*\*$', re.I)   # KJV "**Chapter 1**"


def clean_verse(text: str) -> str:
    """Verse text without Markdown escapes ("wife\\`s" -> "wife`s"), stray backslashes (the
    Webster's marks where a KJV verse is split) or the ASV's note letters ("aPraise ye"). Escapes
    are undone before images, links and HTML tags are removed, so none can come back to life."""
    text = corpus.strip_markup(MD_ESCAPE_RE.sub(r'\1', text)).replace('\\', ' ')
    text = re.sub(r"(?<![\w’'`])a(?=[A-Z])", '', text)
    return re.sub(r'\s+', ' ', text).strip()


def _verse_run(numbered: list[tuple[int, str]], chapter: int) -> list[list]:
    """[chapter, verse, text] from printed verse numbers: the run of increasing numbers; a smaller
    or much larger number is a stray number inside a verse."""
    out: list[list] = []
    last = 0
    for k, (n, t) in enumerate(numbered):
        follows = [m for m, _ in numbered[k + 1:k + 3]]
        if n == last + 1 or (not out and (n == 1 or follows == [n + 1, n + 2])):
            out.append([chapter, n, t])     # a chapter may start later: the Douay-Rheims's Esther 10 at 10:4
            last = n
        elif len(out) == 1 and last == 1 and n > 4 and follows == [n + 1, n + 2]:
            # verse "1" runs on into n, n+1, n+2: it is verse n-1, its number lost or misprinted
            # (Douay-Rheims Romans 11 starts "1Let their eyes be darkened" = 11:10; the KJV's
            # Rest of Esther starts unnumbered at 10:4)
            out[0][1] = n - 1
            out.append([chapter, n, t])
            last = n
        elif out and n <= last:
            out[-1][2] = f'{out[-1][2]} {n} {t}'
        elif n > last + 1 and n - last <= 3:
            out.append([chapter, n, t])     # tolerate a missing verse number
            last = n
        elif out:
            out[-1][2] = f'{out[-1][2]} {n}{t}'
    return out


def _marked_verses(numbered: list[tuple[int, str]], chapter: int) -> list[list]:
    """[chapter, verse, text] of a Webster chapter with "\\c:v\\" marks: a marked verse is the
    KJV's c:v; a mark repeated for the next verse continues the same KJV verse."""
    out: list[list] = []
    for n, t in numbered:
        m = WEBSTER_MARK_RE.match(t)
        if m:
            c, v, t = int(m.group(1)), int(m.group(2)), t[m.end():]
        elif out and (out[-1][0] != chapter or n != out[-1][1] + 1):
            out[-1][2] = f'{out[-1][2]} {n} {t}'    # a stray number inside a verse
            continue
        else:
            c, v = chapter, n
        if t.strip() in ('', '+'):
            continue                                # "1+ 2\\9:1\\...": printed verses 1 and 2 are KJV 9:1
        if out and (c, v) == (out[-1][0], out[-1][1]):
            out[-1][2] = f'{out[-1][2]} {t}'
        else:
            out.append([c, v, t])
    return out


def chapter_verses(text: str, chapter: int) -> list[tuple[int, int, str]]:
    """(chapter, verse, text) of the text of one chapter, verse numbers glued to the verse ("2And")."""
    text = re.sub(r'\s+', ' ', text).strip()
    text = re.sub(r'(?<=\s)(\d{1,3}) a(?=[A-Z])', r'\1', text)    # ASV "53 aHot": verse 53 and a note letter
    pieces = VERSE_SPLIT_RE.split(text)
    numbered = [(int(pieces[i]), pieces[i + 1].strip()) for i in range(1, len(pieces) - 1, 2)]
    # The text before the first verse number, without the chapter number some chapters start with
    # ("1 1In the beginning", "aALEPH. 119 1Blessed", "A Psalm of David ... 3 1 Jehovah, how"):
    # verse 1 (the KJV leaves it unnumbered), or a title that verse 1 begins with.
    lead = re.sub(rf'(?:^|\s){chapter}(?: 1)?(?=\s|$)', ' ', pieces[0], count=1).strip()
    if re.fullmatch(r'\d{1,3}\+?', lead):          # Webster "1+ 2\\9:1\\": printed 1 and 2 are one verse
        lead = ''
    if lead and numbered and numbered[0][0] == 1:
        numbered[0] = (1, f'{lead} {numbered[0][1]}')
    elif lead:
        numbered.insert(0, (1, lead))
    if any(WEBSTER_MARK_RE.match(t) for _, t in numbered):
        verses = _marked_verses(numbered, chapter)
    else:
        verses = _verse_run(numbered, chapter)
    out = []
    for c, v, t in verses:
        t = clean_verse(t)
        if t:
            out.append((c, v, t))
    return out


def parse_bible(repo: Path, translation: str):
    """Yield (book, chapter, verse, text) for one translation in the library."""
    last = None
    for row in _parse_bible(repo, translation):
        if last is not None and row[:3] == last[:3]:
            last = (*row[:3], f'{last[3]} {row[3]}')    # Webster Rev 12:18 is the start of KJV 13:1
            continue
        if last is not None:
            yield last
        last = row
    if last is not None:
        yield last


def _parse_bible(repo: Path, translation: str):
    stem = BIBLE_FILES[translation]
    folder = repo / 'CCEL_Library' / 'Bibles'
    parts = sorted(folder.glob(f'{stem} — Part *.md'), key=lambda p: int(re.search(r'Part (\d+)', p.name).group(1)))
    book = None
    chapter = None      # None: no chapter heading yet; 0: a heading without a number ("The Prologue")
    previous = 0        # the book's last chapter heading
    buf: list[str] = []

    def flush():
        nonlocal buf
        text, buf = ' '.join(buf), []
        if book is None or not chapter or not text.strip():
            return []
        return [(book, c, v, t) for c, v, t in chapter_verses(text, chapter)]

    for path in parts:
        try:
            lines = corpus.read_repo_text(repo, f'CCEL_Library/Bibles/{path.name}').split('\n')
        except corpus.UnsafePath as e:
            corpus.warn(f'bible part refused ({e}): {path.name!r}')
            continue
        i = 0
        while i < len(lines):
            line = lines[i].strip()
            if line.startswith('### '):
                yield from flush()
                chapter, previous = None, 0
                nxt = next((x for x in lines[i + 1:i + 4] if x.strip()), '')
                extra = nxt if nxt.strip().startswith('**') and len(nxt) < 80 else ''
                book = identify_book(line[4:], extra, translation)
            elif line.startswith('#### '):
                yield from flush()
                m = re.search(r'(\d+)\s*$', line)
                chapter = int(m.group(1)) if m else 0
                if chapter and chapter == previous:
                    chapter += 1        # the Douay-Rheims has "Chapter 1" twice in Baruch
                previous = chapter or previous
            elif line.startswith('#') or line.startswith('[^') or CHAPTER_LINE_RE.match(line):
                pass        # other headings, footnotes ("[^1]: ..."), the KJV's "**Chapter 1**"
            elif line.startswith('**') and line.endswith('**') and chapter is None:
                pass        # book title / argument lines
            elif line and chapter:
                buf.append(re.sub(r'[*_]', '', line))
            elif line and book is not None and chapter is None and re.match(r'^1\D', line):
                chapter = previous = 1     # Douay-Rheims books without a chapter heading for chapter 1
                buf.append(line)
            i += 1
        yield from flush()
        chapter = None   # every part file starts with the title lines and the next heading


def verse_limits(verses) -> dict:
    lim: dict = {}
    for b, c, v, _ in verses:
        lim.setdefault(b, {})
        lim[b][c] = max(lim[b].get(c, 0), v)
    return lim


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument('--try', dest='try_text')
    ap.add_argument('--bibles', action='store_true')
    args = ap.parse_args(argv)
    if args.try_text:
        kjv = list(parse_bible(REPO, 'kjv'))
        for r in extract_refs(args.try_text, verse_limits(kjv)):
            print(format_ref(*r))
    if args.bibles:
        for t in BIBLE_FILES:
            vs = list(parse_bible(REPO, t))
            books = sorted({b for b, _, _, _ in vs})
            missing = [b for b in CANON_ORDER if b not in books]
            print(f'{t}: {len(vs)} verses, {len(books)} books, missing canon books: {missing}')


if __name__ == '__main__':
    main()
