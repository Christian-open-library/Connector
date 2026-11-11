import unittest

from indexer import scripture
from indexer.scripture import chapter_verses, clean_verse, extract_refs, format_ref, identify_book


def refs(text, limits=None):
    return [format_ref(*r) for r in extract_refs(text, limits)]


# verse counts of the chapters used below, as verse_limits() gives them from the KJV
LIMITS = {45: {1: 32, 2: 29, 8: 39, 9: 33, 10: 21}, 54: {6: 21}, 58: {12: 29}, 43: {3: 36, 4: 54, 12: 50},
          49: {3: 21}, 46: {2: 16}, 65: {1: 25}, 57: {1: 25}, 31: {1: 21}, 63: {1: 13}, 64: {1: 14}}


class ExtractRefs(unittest.TestCase):
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
