import unittest

from indexer import references
from indexer.references import _parsed_refs, bible_problems, unique_rows


def tag(passage, parsed):
    return f'<scripRef passage="{passage}" parsed="{parsed}" osisRef="x">{passage}</scripRef>'


class TopicRefs(unittest.TestCase):
    def test_one_chapter_books_read_the_verses_after_the_tag(self):
        # CCEL tags only the "Jude 1" of "Jude 1:7"; every one of them used to become verse 1
        frag = ('<p>Archangel ' + tag('Jude 1', '|Jude|1|1|0|0') + ':9; '
                + tag('Jude 1', '|Jude|1|1|0|0') + ':8-13; ' + tag('3Jo 1', '|3John|1|1|0|0') + ':9,10</p>'
                + tag('Phm 1', '|Phlm|1|1|0|0') + ':10, 12-14')
        self.assertEqual(sorted(_parsed_refs(frag)), [
            (57, 1, 10, None), (57, 1, 12, 14), (64, 1, 9, None), (64, 1, 10, None), (65, 1, 8, 13), (65, 1, 9, None)])
        # without verses after it the tag means what it says; a parsed verse other than 1 is kept
        self.assertEqual(list(_parsed_refs(tag('2Jo 1', '|2John|1|1|0|0') + '; ')), [(63, 1, 1, None)])
        self.assertEqual(list(_parsed_refs(tag('Ob 3', '|Obad|1|3|0|0') + ':2; ')), [(31, 1, 3, None)])

    def test_a_tag_followed_by_a_letter_is_skipped(self):
        # Nave: "Son of Ezra, 1Ch 4:17" tagged as "Ezra 1" + "Ch 4:17"
        self.assertEqual(list(_parsed_refs(tag('Ezra 1', '|Ezra|1|0|0|0') + 'Ch 4:17</p>')), [])
        self.assertEqual(list(_parsed_refs(tag('Ge 4:1-15', '|Gen|4|1|4|15') + ','
                                           + tag('25', '|Gen|4|25|0|0') + '</p>')),
                         [(1, 4, 1, 15), (1, 4, 25, None)])


class DictionaryHeads(unittest.TestCase):
    def test_heading_text_drops_a_trailing_sig_and_long_space_runs_are_cheap(self):
        import time
        head = lambda line: references.dict_head(references.HEAD_DICT_RE.match(line))
        self.assertEqual(head('## Abba sig:  '), 'Abba')
        self.assertEqual(head('### Grace  sig'), 'Grace')
        self.assertEqual(head('## Faith.'), 'Faith.')
        t = time.perf_counter()
        self.assertEqual(head('## a' + ' ' * 200000 + 'b'), 'a' + ' ' * 200000 + 'b')
        self.assertLess(time.perf_counter() - t, 1.0)


class CitationExclusions(unittest.TestCase):
    def test_cross_reference_tables_are_left_out_of_the_citation_index(self):
        import sqlite3
        db = sqlite3.connect(':memory:')
        db.execute('CREATE TABLE works(id INTEGER PRIMARY KEY, title TEXT, full_title TEXT)')
        db.executemany('INSERT INTO works VALUES (?,?,?)', [
            (1, 'The Treasury of Scripture Knowledge', ''), (2, 'Sermons', ''), (3, 'x', 'A Treasury of Scripture Knowledge, 1836')])
        self.assertEqual(references.no_citation_work_ids(db), {1, 3})


class BibleChecks(unittest.TestCase):
    def test_a_verse_parsed_twice_stops_the_build(self):
        rows = [('kjv', 15, 1, 1, 'Now in the first year of Cyrus'), ('kjv', 15, 1, 2, 'Thus saith Cyrus')]
        self.assertEqual(unique_rows(rows), rows)
        with self.assertRaisesRegex(ValueError, 'kjv Ezra 1:1 parsed twice'):
            unique_rows(rows + [('kjv', 15, 1, 1, 'And Josias held the feast of the passover')])

    def test_problems(self):
        rows = [('webster', 1, 43, 1, '\\\\43:2\\\\And it came to pass'), ('kjv', 31, 1, 1, 'Chapter 1 The vision'),
                ('asv', 1, 1, 1, '1 1 In the beginning'), ('drb', 45, 11, 1, 'x' * 1001),
                ('kjv', 1, 1, 2, 'And the earth was without form')]
        self.assertEqual(bible_problems(rows), [
            'webster Genesis 43:1: backslash', "kjv Obadiah 1:1: starts with 'Chapter 1 Th'",
            "asv Genesis 1:1: starts with '1 1 In the b'", 'drb Romans 11:1: 1001 characters'])
        self.assertEqual(bible_problems(rows[-1:], {(1, 1): 31, (2, 1): 22, (70, 1): 16}),
                         ['kjv Genesis 1: 1 verses, 31 in the reference', 'kjv Exodus 1: 0 verses, 22 in the reference'])


@unittest.skipUnless((references.CCEL_RAW / 'nave_bible.xml').exists(), 'CCEL sources not available')
class NaveTorrey(unittest.TestCase):
    def test_jude_topics_spread_over_the_verses(self):
        verses = set()
        for fname in ('nave_bible.xml', 'torrey_ttt.xml'):
            xml = (references.CCEL_RAW / fname).read_text(encoding='utf-8', errors='replace')
            for _, d in references.TERM_RE.findall(xml):
                verses |= {r[2] for r in _parsed_refs(d) if r[0] == 65}
        self.assertGreaterEqual(len(verses), 20)


if __name__ == '__main__':
    unittest.main()
