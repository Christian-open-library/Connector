import unittest

from indexer import scripture
from indexer.scripture import chapter_verses, clean_verse, extract_refs, format_ref, identify_book


def refs(text, limits=None, vulgate=False):
    return [format_ref(*r) for r in extract_refs(text, limits, vulgate)]


# verse counts of the chapters used below, as verse_limits() gives them from the KJV
LIMITS = {45: {1: 32, 2: 29, 8: 39, 9: 33, 10: 21}, 54: {6: 21}, 58: {12: 29}, 43: {3: 36, 4: 54, 12: 50},
          49: {3: 21}, 46: {2: 16}, 65: {1: 25}, 57: {1: 25}, 31: {1: 21}, 63: {1: 13}, 64: {1: 14}}


class ExtractRefs(unittest.TestCase):
    # the next three from .github issue #97 (fidpa)
    def test_an_ordinal_beyond_the_books_is_not_read_as_the_first_book(self):
        # "3 Chron." is an OCR slip for "2 Chron.": there is no third Chronicles, so it must not
        # count as a citation of 1 Chronicles
        self.assertEqual(refs('3 Chron. vii. 7-33 with 1 Kings viii. 64'), ['1 Kings 8:64'])
        self.assertEqual(refs('3 Cor. 5. 7'), [])
        self.assertEqual(refs('3 Tim. 2. 4'), [])
        self.assertEqual(refs('3 John 4'), ['3 John 1:4'], 'a real third book still counts')

    def test_a_number_before_the_book_that_is_not_an_ordinal_does_not_mean_the_first_book(self):
        self.assertNotIn('1 Kings 15:19', refs('g2 Kings XV. 19'))
        self.assertNotIn('1 Kings 2:11', refs('4 Kings 2:11'))
        self.assertNotIn('1 Kings 2:11', refs('IV Reg. 2. 11'))
        self.assertEqual(refs('Cor. 5. 7'), ['1 Corinthians 5:7'], 'no ordinal at all: first epistle, as before')

    def test_the_verse_of_the_reference_before_is_not_a_book_number(self):
        self.assertEqual(refs('Luke 24. 49. John 14. 16'), ['Luke 24:49', 'John 14:16'])
        self.assertEqual(refs('Isa. 37. 36. Kings 19. 35'), ['Isaiah 37:36', '1 Kings 19:35'])
        self.assertEqual(refs('John v. 1 Kings 2. 11'), ['John 5:1', '1 Kings 2:11'])

    def test_latin_and_douay_numbering(self):
        self.assertEqual(refs('1 Reg. 15. 22'), ['1 Kings 15:22'], 'used both ways; left as it was')
        self.assertEqual(refs('III Reg. 19. 12'), ['1 Kings 19:12'])
        self.assertEqual(refs('IV Reg. 2. 11'), ['2 Kings 2:11'])
        self.assertEqual(refs('3 Kings 19. 12'), ['1 Kings 19:12'])
        self.assertEqual(refs('4 Kings 2:11'), ['2 Kings 2:11'])
        self.assertEqual(refs('4 Esdras 7. 28'), ['2 Esdras 7:28'])
        self.assertEqual(refs('IV Mac 16:9'), [], 'no 4 Maccabees in the library')
        self.assertEqual(refs('Mt 5:4 Joh 14:16'), ['John 14:16'])
        self.assertEqual(refs('2 Kings 2:11'), ['2 Kings 2:11'])
        self.assertEqual(refs('4 Gen. 3. 5'), ['Genesis 3:5'], 'as before: only 2 and 3 rule out a book without ordinals')
        self.assertEqual(refs('Rom. 8. 3, 4 Gal. 5. 1'), ['Romans 8:3-4', 'Galatians 5:1'])
        self.assertEqual(refs('Rom. 8. 28, 1 Cor. 2. 9'), ['Romans 8:28', '1 Corinthians 2:9'])

    def test_a_footnote_letter_before_an_ordinal(self):
        self.assertEqual(refs('g2 Kings XV. 19; xvi, 7'), ['2 Kings 15:19', '2 Kings 16:7'])
        self.assertEqual(refs('fi2  Chron.  7  :  14.'), ['2 Chronicles 7:14'])
        self.assertEqual(refs('m1 Cor. Xi, 23.'), ['1 Corinthians 11:23'])
        self.assertEqual(refs('Psalm 23. 1. a4 Kings 2. 11'), ['Psalms 23:1', '2 Kings 2:11'])
        self.assertEqual(refs('i3 Ps. xxxix. 5. i2 Luke xviii. 14.'), ['Psalms 39:5', 'Luke 18:14'])
        self.assertEqual(refs('i3 John iii. 2; vii. 50'), ['John 3:2', 'John 7:50'])

    def test_one_chapter_books_take_a_lone_number_as_the_verse(self):
        self.assertEqual(refs('as in Jude 6, the angels', LIMITS), ['Jude 1:6'])
        self.assertEqual(refs('Jude 3-5', LIMITS), ['Jude 1:3-5'])
        self.assertEqual(refs('Jude 1:3', LIMITS), ['Jude 1:3'])
        self.assertEqual(refs('Philem. 10', LIMITS), ['Philemon 1:10'])
        self.assertEqual(refs('Obad. 3', LIMITS), ['Obadiah 1:3'])
        self.assertEqual(refs('2 John 10; 3 John 11', LIMITS), ['2 John 1:10', '3 John 1:11'])
        self.assertEqual(refs('Jude 28', LIMITS), [], 'Jude has 25 verses (2 more are tolerated)')
        self.assertEqual(refs('In Jude 6, 7, the lost angels', LIMITS), ['Jude 1:6-7'])
        self.assertEqual(refs('Jude 6, 8', LIMITS), ['Jude 1:6', 'Jude 1:8'])
        self.assertEqual(refs('John 3', LIMITS), [], 'a chapter of an ambiguous name alone is still skipped')

    def test_verse_lists(self):
        self.assertEqual(refs('1 Tim. 6. 17, 18, 19', LIMITS), ['1 Timothy 6:17-19'])
        self.assertEqual(refs('Hebrews 12:22,23', LIMITS), ['Hebrews 12:22-23'])
        self.assertEqual(refs('Eph. iii. 14, 15', LIMITS), ['Ephesians 3:14-15'])
        self.assertEqual(refs('Iohn xii.23, 24, 27', LIMITS), ['John 12:23-24', 'John 12:27'])
        self.assertEqual(refs('Rom. 8. 28-30, 35', LIMITS), ['Romans 8:28-30', 'Romans 8:35'])
        # the number after the comma is the ordinal of the next book, a chapter, or a year
        self.assertEqual(refs('Rom. 8. 28, 1 Cor. 2. 9', LIMITS), ['Romans 8:28', '1 Corinthians 2:9'])
        self.assertEqual(refs('Rom. 8. 28, 1. Cor. 2. 9', LIMITS), ['Romans 8:28', '1 Corinthians 2:9'])
        self.assertEqual(refs('Rom. 8. 28, 9. 5', LIMITS), ['Romans 8:28'])
        self.assertEqual(refs('Rom. 8. 28, 1650.', LIMITS), ['Romans 8:28'])
        self.assertEqual(refs('Rom. 8. 28, 45', LIMITS), ['Romans 8:28'], 'Romans 8 has 39 verses')

    def test_more_chapters_of_the_same_book_after_a_semicolon(self):
        self.assertEqual(refs('Rom. i. 32; ii. 1', LIMITS), ['Romans 1:32', 'Romans 2:1'])
        self.assertEqual(refs('Rom. 8. 28; 9. 5; 10. 4', LIMITS), ['Romans 8:28', 'Romans 9:5', 'Romans 10:4'])
        self.assertEqual(refs('John 3. 16, 17; 4. 2', LIMITS), ['John 3:16-17', 'John 4:2'])
        self.assertEqual(refs('Rom. 8. 28; 2 Cor. 5. 17', LIMITS), ['Romans 8:28', '2 Corinthians 5:17'])
        self.assertEqual(refs('Rom. 8. 28; v. 30', LIMITS), ['Romans 8:28'], '"v. 30" is a verse, not chapter 5')
        self.assertEqual(refs('Rom. 8. 28; 99. 1', LIMITS), ['Romans 8:28'], 'no chapter 99')
        self.assertEqual(refs('Rom. 8. 28; civil. 5', LIMITS), ['Romans 8:28'], 'a word, not a roman numeral')
        # the American 1800s style: a period and a comma after the chapter
        self.assertEqual(refs('John iii., 16; iv., 24', LIMITS), ['John 3:16', 'John 4:24'])
        self.assertEqual(refs('Rom. viii., 28-30', LIMITS), ['Romans 8:28-30'])
        self.assertEqual(refs('Rom. viii. 28; IX. 5', LIMITS), ['Romans 8:28', 'Romans 9:5'])

    def test_unchanged_forms(self):
        self.assertEqual(refs('as Saint Paul saith, Rom. viii. 28; cf. Ioh. 3.16 and Psal. 49, 15.'),
                         ['Romans 8:28', 'John 3:16', 'Psalms 49:15'])
        self.assertEqual(refs('Rom. 8. 28. 1 Cor. 2. 9.', LIMITS), ['Romans 8:28', '1 Corinthians 2:9'])


class IdentifyBook(unittest.TestCase):
    def test_esdras_is_ezra_only_in_the_douay_rheims(self):
        self.assertEqual(identify_book('The First Book of', '**Esdras**', 'kjv'), 79)
        self.assertEqual(identify_book('The Second Book of', '**Esdras**', 'kjv'), 80)
        self.assertEqual(identify_book('The First Book of Esdras', '', 'drb'), 15)
        self.assertEqual(identify_book('The Second Book of Esdras, alias Nehemias', '', 'drb'), 16)
        self.assertEqual(identify_book('Ezra', '', 'kjv'), 15)
        self.assertEqual(identify_book('The Book of', '**Nehemiah**', 'kjv'), 16)


class ChapterVerses(unittest.TestCase):
    def test_kjv_first_verse_unnumbered(self):
        self.assertEqual(chapter_verses('In the beginning God created. 2And the earth was void. 3And God said.', 1),
                         [(1, 1, 'In the beginning God created.'), (1, 2, 'And the earth was void.'),
                          (1, 3, 'And God said.')])

    def test_a_chapter_number_before_verse_one_is_dropped(self):
        # ASV: "1 1In the beginning", a psalm title before "3 1", a heading before "119 1Blessed"
        self.assertEqual(chapter_verses('1 1In the beginning. 2And the earth.', 1)[0], (1, 1, 'In the beginning.'))
        self.assertEqual(chapter_verses('A Psalm of David. 3 1 Jehovah, how are they increased! 2Many are they.', 3)[0],
                         (3, 1, 'A Psalm of David. Jehovah, how are they increased!'))
        self.assertEqual(chapter_verses('aALEPH. 119 1Blessed are they. 2Blessed are they that keep.', 119)[0],
                         (119, 1, 'ALEPH. Blessed are they.'))
        self.assertEqual(chapter_verses('1Blessed. 2Blessed are they. 3 aHot indignation. 4Thy statutes.', 119)[2],
                         (119, 3, 'Hot indignation.'), 'ASV "53 aHot": a verse number before a note letter')

    def test_verse_one_that_runs_on_into_later_verses(self):
        # Douay-Rheims Romans 11: the source lost verses 1-9 and numbered verse 10 "1"
        got = chapter_verses('1Let their eyes be darkened. 11I say then, have they so stumbled? 12Now if the '
                             'offence. 13For I say to you.', 11)
        self.assertEqual([v for _, v, _ in got], [10, 11, 12, 13])
        self.assertEqual(got[0], (11, 10, 'Let their eyes be darkened.'))
        # the KJV's Rest of Esther starts unnumbered at 10:4
        got = chapter_verses('Then Mardocheus said, God hath done these things. 5For I remember a dream. 6A little '
                             'fountain. 7And the two dragons.', 10)
        self.assertEqual([v for _, v, _ in got], [4, 5, 6, 7])
        # a stray number inside a verse is still kept in its verse
        got = chapter_verses('In the beginning. 2And the 40days passed. 3And then.', 1)
        self.assertEqual([v for _, v, _ in got], [1, 2, 3])
        # a chapter that starts later: the Douay-Rheims's Additions to Esther at 10:4
        got = chapter_verses('4Then Mardochai said. 5I remember a dream. 6The little fountain.', 10)
        self.assertEqual([v for _, v, _ in got], [4, 5, 6])

    def test_webster_marks_give_the_kjv_numbers(self):
        # Genesis 42 ends with KJV 43:1; Psalm 9 "1+ 2\\9:1\\": printed 1 and 2 are KJV 9:1
        got = chapter_verses('1Now when Jacob saw. 2And he said. 3\\\\43:1\\\\And the famine was severe.', 42)
        self.assertEqual(got, [(42, 1, 'Now when Jacob saw.'), (42, 2, 'And he said.'),
                               (43, 1, 'And the famine was severe.')])
        got = chapter_verses('1+ 2\\\\9:1\\\\To the chief Musician. I will praise thee. 3\\\\9:2\\\\I will be glad. '
                             '21\\\\9:20\\\\Put them in fear.', 9)
        self.assertEqual(got, [(9, 1, 'To the chief Musician. I will praise thee.'), (9, 2, 'I will be glad.'),
                               (9, 20, 'Put them in fear.')])
        # 3 John 14 split in two: the second part is marked 1:14 again
        got = chapter_verses('13I had many things. 14But I trust. \\\\ 15\\\\1:14\\\\Peace be to thee.', 1)
        self.assertEqual(got[-1], (1, 14, 'But I trust. Peace be to thee.'))
        self.assertEqual(len(got), 2)

    def test_text_cleanup(self):
        self.assertEqual(clean_verse('his wife\\`s name Eve, \\`Let light be;\''), "his wife`s name Eve, `Let light be;'")
        self.assertEqual(clean_verse('for ever.\\\\ And he arose'), 'for ever. And he arose')
        self.assertEqual(clean_verse('lower than aGod, set to aShoshannim'), 'lower than God, set to Shoshannim')
        self.assertEqual(clean_verse('a man and a God'), 'a man and a God')


class VulgatePsalms(unittest.TestCase):
    """Latin and Greek editions number the Psalms as the Vulgate and Septuagint do (.github #99, fidpa:
    Ambrose's Expositio Psalmi CXVIII is on the KJV's Psalm 119)."""
    PS = {19: {9: 20, 10: 18, 51: 19, 114: 8, 115: 18, 116: 19, 118: 29, 119: 176, 120: 7, 147: 20, 3: 8, 1: 6}}

    def test_the_reported_examples(self):
        # Ambrose P337300713: "Ps 118:69; ... Ps 119:6; Ps 119:7"; Augustine P337900291: "Ps 50:1" (the
        # heading of the Vulgate's 50 is verses 1-2, so verse 1 is the psalm only)
        self.assertEqual(refs('Ps 118:69; Ps 119:6; Ps 119:7', self.PS, True),
                         ['Psalms 119:69', 'Psalms 120:6', 'Psalms 120:7'])
        self.assertEqual(refs('Ps 50:1', self.PS, True), ['Psalms 51'])
        self.assertEqual(refs('Ps 50:3', self.PS, True), ['Psalms 51:1'], 'miserere mei, Deus')
        self.assertEqual(refs('Ps 118:69', self.PS), [], 'without the flag: KJV numbers, no such verse')

    def test_headings_and_unchanged_psalms(self):
        self.assertEqual(refs('Ps 1:2', self.PS, True), ['Psalms 1:2'])
        self.assertEqual(refs('Ps 3:6', self.PS, True), ['Psalms 3:5'], 'ego dormivi: KJV 3:5')
        self.assertEqual(refs('Ps 3:1', self.PS, True), ['Psalms 3'], 'the heading: the psalm only')
        self.assertEqual(refs('Psal. cxviii. 105', self.PS, True), ['Psalms 119:105'])

    def test_joined_and_divided_psalms(self):
        self.assertEqual(refs('Ps 9:2; 9:22; 9:39', self.PS, True), ['Psalms 9:1', 'Psalms 10:1', 'Psalms 10:18'])
        self.assertEqual(refs('Ps 9:20-23', self.PS, True), ['Psalms 9:19-20'], 'cut at the end of KJV 9')
        self.assertEqual(refs('Ps 113:1; 113:9; 113:26', self.PS, True),
                         ['Psalms 114:1', 'Psalms 115:1', 'Psalms 115:18'])
        self.assertEqual(refs('Ps 114:3; 115:1; 115:10; 115:12', self.PS, True),
                         ['Psalms 116:3', 'Psalms 116:10', 'Psalms 116', 'Psalms 116:12'])
        self.assertEqual(refs('Ps 146:1; 147:1; 147:11; 147:20', self.PS, True),
                         ['Psalms 147:1', 'Psalms 147:12', 'Psalms 147', 'Psalms 147:20'])
        self.assertEqual(scripture.vulgate_psalm(9, None, None), [(9, None, None), (10, None, None)])
        self.assertEqual(scripture.vulgate_psalm(113, None, None), [(114, None, None), (115, None, None)])
        self.assertEqual(scripture.vulgate_psalm(114, 12, None), [], 'no such verse')

    def test_other_books_are_unchanged(self):
        self.assertEqual(refs('Rom. 8. 28; Ps 118:1', None, True), ['Romans 8:28', 'Psalms 119:1'])


class DouayWorks(unittest.TestCase):
    """English works that follow the Douay-Rheims are read like the Latin editions (follow-up to #99)."""

    def db(self, *works):
        import sqlite3
        db = sqlite3.connect(':memory:')
        db.execute('CREATE TABLE works(id INTEGER PRIMARY KEY, author TEXT, title TEXT)')
        db.executemany('INSERT INTO works VALUES (?, ?, ?)', [(i + 1, a, t) for i, (a, t) in enumerate(works)])
        return db

    def test_listed_works_are_flagged(self):
        from indexer import references
        db = self.db(('Thomas Aquinas', 'Summa Theologica, Part I'),
                     ('Thomas Aquinas', 'Catena Aurea - Gospel of Matthew'),
                     ('Someone Else', 'Summa Theologica, Part I'),
                     ('Ambrose of Milan', 'Expositio Psalmi CXVIII (Latin, CSEL)'))
        self.assertEqual(references.vulgate_work_ids(db), frozenset({1, 4}))

    def test_plugin_list_is_the_same(self):
        import ast, re
        from indexer import references
        from pathlib import Path
        php = Path(__file__).resolve().parents[2] / 'plugin/col-library-connector/src/Format.php'
        body = re.search(r'const DOUAY_WORKS = \[(.*?)\n    \];', php.read_text(), re.S).group(1)
        pairs = {(ast.literal_eval(a), ast.literal_eval(t))
                 for a, t in re.findall(r"\[('(?:[^'\\]|\\.)*'), ('(?:[^'\\]|\\.)*')\]", body)}
        self.assertEqual(pairs, set(references.DOUAY_WORKS))


@unittest.skipUnless(scripture.REPO.joinpath('CCEL_Library', 'Bibles').is_dir(), 'library Bibles not available')
class LibraryBibles(unittest.TestCase):
    """The six Bibles of the library, parsed: no verse twice, KJV Ezra and Nehemiah without the
    Apocrypha's 1 and 2 Esdras, Webster and Douay-Rheims numbering, no Markdown left in the text."""

    @classmethod
    def setUpClass(cls):
        from indexer.references import bible_problems, parse_bsb, unique_rows
        cls.rows = {tr: unique_rows([(tr, *r) for r in scripture.parse_bible(scripture.REPO, tr)])
                    for tr in scripture.BIBLE_FILES}
        cls.verse = {(tr, b, c, v): t for tr, rows in cls.rows.items() for _, b, c, v, t in rows}
        bsb_sizes: dict = {}
        for b, c, _, _ in parse_bsb():
            bsb_sizes[(b, c)] = bsb_sizes.get((b, c), 0) + 1
        cls.problems = {tr: bible_problems(rows, None if tr == 'drb' or not bsb_sizes else bsb_sizes)
                        for tr, rows in cls.rows.items()}

    def chapters(self, tr, book):
        sizes: dict = {}
        for _, b, c, _, _ in self.rows[tr]:
            if b == book:
                sizes[c] = sizes.get(c, 0) + 1
        return sizes

    def test_kjv_ezra_nehemiah_and_esdras(self):
        self.assertEqual(sum(self.chapters('kjv', 15).values()), 280)
        self.assertEqual(max(self.chapters('kjv', 15)), 10)
        self.assertEqual(sum(self.chapters('kjv', 16).values()), 406)
        self.assertEqual(max(self.chapters('kjv', 16)), 13)
        self.assertNotIn(('kjv', 15, 3, 20), self.verse)
        self.assertTrue(self.verse[('kjv', 79, 3, 20)].startswith('It turneth also every thought'))
        self.assertTrue(self.verse[('kjv', 80, 1, 1)].startswith('The second book of the prophet Esdras'))
        self.assertTrue(self.verse[('kjv', 17, 10, 4)].startswith('Then Mardocheus said'))
        self.assertTrue(self.verse[('drb', 17, 10, 4)].startswith('Then Mardochai said'))
        self.assertEqual(self.chapters('drb', 15), self.chapters('asv', 15))

    def test_webster_numbering(self):
        self.assertEqual(self.verse[('webster', 1, 43, 1)], 'And the famine was severe in the land.')
        self.assertTrue(self.verse[('webster', 1, 43, 2)].startswith('And it came to pass, when they had consumed'))
        self.assertEqual(self.chapters('webster', 19)[9], 20)
        self.assertTrue(self.verse[('webster', 19, 9, 1)].startswith('To the chief Musician upon Muthlabben'))
        self.assertNotIn(('webster', 64, 1, 15), self.verse)
        self.assertTrue(self.verse[('webster', 66, 13, 1)].startswith('And I stood upon the sand of the sea, and saw'))

    def test_douay_rheims_chapters(self):
        self.assertTrue(self.verse[('drb', 45, 11, 10)].startswith('Let their eyes be darkened'))
        self.assertTrue(self.verse[('drb', 45, 11, 11)].startswith('I say then'))
        self.assertNotIn(('drb', 45, 11, 1), self.verse)
        self.assertTrue(self.verse[('drb', 71, 1, 1)].startswith('All wisdom is from the Lord God'), 'not the prologue')
        self.assertEqual(sorted(self.chapters('drb', 72)), [1, 2, 3, 4, 5, 6], 'Baruch')

    def test_vulgate_psalm_table_matches_the_douay_rheims(self):
        """The last verse of each Vulgate psalm (the Douay-Rheims's numbers) is the last of the KJV psalm
        it is converted to, except where only the psalm is indexed (VULGATE_UNALIGNED)."""
        drb, kjv = self.chapters('drb', 19), self.chapters('kjv', 19)
        last = {v: max(c for (t, b, c2, c) in self.verse if t == 'drb' and b == 19 and c2 == v) for v in drb}
        for v in range(1, 151):
            if v in scripture.VULGATE_UNALIGNED:
                continue
            [(k, verse, _)] = scripture.vulgate_psalm(v, last[v], None)
            want = {114: 9, 146: 11}.get(v, kjv[k])          # the first part of KJV 116 and 147
            self.assertEqual(verse, want, f'Vulgate {v}:{last[v]} -> KJV {k}:{verse}')

    def test_text_is_clean(self):
        self.assertTrue(self.verse[('ylt', 1, 3, 20)].startswith("And the man calleth his wife`s name Eve"))
        self.assertTrue(self.verse[('asv', 1, 1, 1)].startswith('In the beginning God created'))
        self.assertTrue(self.verse[('kjv', 65, 1, 1)].startswith('Jude, the servant of Jesus Christ'))
        for tr, problems in self.problems.items():
            known = {'kjv Prayer of Manasseh 1:1: 2257 characters',      # no verse numbers in the source
                     'kjv Esther 10: 13 verses, 3 in the reference'}      # the Rest of Esther from 10:4
            self.assertEqual([p for p in problems if p not in known], [], tr)


if __name__ == '__main__':
    unittest.main()
