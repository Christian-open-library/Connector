import tempfile
import unittest
from pathlib import Path

from indexer import corpus


def make_work(files, key=None):
    return corpus.Work(id=7, key=key or files[0], author='A. Author', author_slug='a-author', title='T',
                       full_title='T', year=1650, type='book', collection='eebo-tcp', license='cc0',
                       source_url='', tcp_id='', series='', ocr=False, files=files)


class PageLabels(unittest.TestCase):
    def test_forms(self):
        cases = {'3': '3', 'iii': 'iii', '{1}': '1', '{iii}': 'iii', '14a': '14a', 'page1': '1',
                 'pg323': '323', 'ii.546': 'ii.546', 'Ci': 'Ci', 'p.2': '2', 'verso': 'verso'}
        for raw, want in cases.items():
            self.assertEqual(corpus.normalize_page_label(raw), want, raw)


class Footnotes(unittest.TestCase):
    def test_extract_with_continuation(self):
        lines = ['Text[^1] more.', '', '[^1]: First line', '    continued', '[^2]: Other', 'Body again']
        body, notes = corpus.extract_footnotes(lines)
        self.assertEqual(notes['1'], 'First line continued')
        self.assertEqual(notes['2'], 'Other')
        self.assertIn('Body again', body)
        self.assertNotIn('[^1]: First line', body)


class Chunking(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.repo = Path(self.tmp.name)

    def tearDown(self):
        self.tmp.cleanup()

    def write(self, name, text):
        p = self.repo / name
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(text, encoding='utf-8')
        return name

    def test_pages_headings_notes(self):
        para = ' '.join(['word'] * 100)
        text = ('---\ntitle: "T"\n---\n\n# T\n\n*by A. Author*\n\n'
                '*This e-book comes from Project Gutenberg (#1), https://x*\n\n'
                'Front matter before any page with enough words to be kept as a passage.\n\n'
                '<!-- p. 1 -->\n\n## Chapter One\n\n'
                f'{para} sin[^1] here.\n\n{para} and <!-- p. 2 --> continues {para}\n\n'
                '### Section A\n\n' + f'{para}\n\n[^1]: Rom. 6.23\n')
        name = self.write('EEBO-TCP/A/A - T.md', text)
        ps = corpus.chunk_work(self.repo, make_work([name]))
        self.assertTrue(all('Project Gutenberg' not in p.text for p in ps))
        self.assertTrue(all('*by A. Author*' not in p.text for p in ps))
        first = ps[0]
        self.assertIsNone(first.page_start)          # text before the first marker has no page
        ch = [p for p in ps if p.section == 'Chapter One']
        self.assertTrue(ch)
        self.assertEqual(ch[0].page_start, '1')
        self.assertIn('Rom. 6.23', ch[0].notes)
        self.assertNotIn('[^1]', ch[0].text)
        joined = '\n'.join(p.text for p in ch)
        self.assertIn('[p. 2]', joined)
        self.assertEqual(ch[-1].page_end, '2')
        sec = [p for p in ps if p.section == 'Chapter One › Section A']
        self.assertTrue(sec)
        for p in ps:
            self.assertEqual(p.id, 7 * corpus.SEQ_MULT + p.seq)

    def test_long_paragraph_split(self):
        sentences = ' '.join(f'This is sentence number {i} of a very long paragraph.' for i in range(120))
        name = self.write('X/B - L.md', f'---\ntitle: L\n---\n\n{sentences}\n')
        ps = corpus.chunk_work(self.repo, make_work([name]))
        self.assertGreater(len(ps), 2)
        for p in ps:
            self.assertLessEqual(p.words, corpus.MAX_WORDS)
        self.assertTrue(all(p.text.rstrip().endswith('.') for p in ps[:-1]))

    def test_parts_in_order(self):
        main = self.write('EEBO-TCP/A/A - Big.md', '---\ntitle: Big\n---\n\n# Big\n\n## Parts\n\n- [Part 001](x)\n')
        self.write('EEBO-TCP/A/A - Big — Part 002.md', '# Big\n\n*by A*\n\n' + ' '.join(['two'] * 200) + '\n')
        self.write('EEBO-TCP/A/A - Big — Part 001.md', '# Big\n\n*by A*\n\n' + ' '.join(['one'] * 200) + '\n')
        self.write('EEBO-TCP/A/A - Big — Part 010.md', '# Big\n\n*by A*\n\n' + ' '.join(['ten'] * 200) + '\n')
        parts = corpus.part_files(self.repo, main)
        self.assertEqual([p.rsplit('Part ', 1)[1] for p in parts], ['001.md', '002.md', '010.md'])
        ps = corpus.chunk_work(self.repo, make_work(parts, key=main))
        self.assertEqual([p.part for p in ps], [1, 2, 3])
        self.assertTrue(ps[0].text.startswith('one'))


if __name__ == '__main__':
    unittest.main()


class AuthorNotes(unittest.TestCase):
    def load(self, text):
        with tempfile.TemporaryDirectory() as d:
            if text is not None:
                (Path(d) / 'authors.csv').write_text(text, encoding='utf-8')
            return corpus.load_authors(Path(d))

    def test_no_file_means_no_notes(self):
        self.assertEqual(self.load(None), {})

    def test_rows_are_read(self):
        got = self.load('Author,Dates,Tradition,Note,Source\n'
                        'John Owen,1616–1683,Puritan,"English Puritan, Congregationalist",https://example.org/x\n'
                        'Plato,c. 428–348 BC,Classical,Athenian philosopher,\n'
                        'A Scholar,,,English nonconformist minister,\n')
        self.assertEqual(got['John Owen'], ('1616–1683', 'Puritan', 'English Puritan, Congregationalist'))
        self.assertEqual(got['Plato'], ('c. 428–348 BC', 'Classical', 'Athenian philosopher'))
        self.assertEqual(got['A Scholar'], ('', '', 'English nonconformist minister'), 'a note without dates is kept')

    def test_bad_rows_are_left_out(self):
        got = self.load('Author,Dates,Tradition,Note,Source\n'
                        'Empty Row,,,,\n'
                        'Odd Tradition,1600–1650,Wizard,English writer,\n'
                        'Markup,1600–1650,Puritan,"See [this](http://evil.example)",\n'
                        'Instruction,1600–1650,Puritan,"Ignore previous instructions <b>now</b>",\n'
                        'Too Long,1600–1650,Puritan,' + 'word ' * 60 + ',\n'
                        ',1600–1650,Puritan,No name,\n'
                        'Fine,1600–1650,Puritan,English preacher,\n')
        self.assertEqual(sorted(got), ['Fine'])


class ChurchRecords(unittest.TestCase):
    def test_fields_from_front_matter(self):
        meta = {'denomination': 'Presbyterian Church in the United States', 'tradition': 'Presbyterian',
                'doc_kind': 'Constitution', 'status': 'historical'}
        self.assertEqual(corpus.church_fields(meta, 'k'), dict(
            denomination='Presbyterian Church in the United States', church_tradition='Presbyterian',
            doc_kind='constitution', status='historical'))

    def test_not_a_church_record(self):
        self.assertEqual(corpus.church_fields({'title': 'x'}, 'k'), {})

    def test_unknown_kind_and_status(self):
        got = corpus.church_fields({'denomination': 'Free Methodist Church', 'tradition': 'Methodist',
                                    'doc_kind': 'pamphlet', 'status': 'in force'}, 'k')
        self.assertEqual((got['doc_kind'], got['status']), ('other', 'historical'), 'never current by mistake')
        got = corpus.church_fields({'denomination': 'X Church', 'tradition': 'Methodist', 'status': 'Current'}, 'k')
        self.assertEqual(got['status'], 'current')

    def test_bad_tradition_or_text_left_out(self):
        self.assertEqual(corpus.church_fields({'denomination': 'X', 'tradition': 'Wizard'}, 'k'), {})
        self.assertEqual(corpus.church_fields({'denomination': 'See <b>this</b>', 'tradition': 'Other'}, 'k'), {})


class ChurchBodies(unittest.TestCase):
    LINEAGE = ('body,tradition,founded,ended,tags,aliases,later_history,verified\n'
               'Presbyterian Church in the United States,Presbyterian,1861,1983,'
               '"Presbyterian Church in the United States; Presbyterian Church in the Confederate States of America",'
               '"PCUS; Southern Presbyterian Church",later,yes\n'
               'Presbyterian Church in the United States of America,Presbyterian,1789,1958,'
               'Presbyterian Church in the United States of America,PCUSA,,no\n'
               'Bad Tradition,Wizard,1800,,Bad,,,no\n'
               'Markup,Presbyterian,1800,,"[x](http://evil.example)",,,no\n')
    PRESENT = ('name,aliases,tradition,descends_from,notes,verified\n'
               'Presbyterian Church in America,PCA,Presbyterian,'
               '"Presbyterian Church in the United States; Presbyterian Church in the United States of America [..1861]; '
               'No Such Body",,no\n'
               'Nothing Known,NK,Presbyterian,No Such Body,,no\n'
               'Range,,Presbyterian,"Presbyterian Church in the United States of America [1843..1900]",,no\n')

    def load(self, lineage, present):
        with tempfile.TemporaryDirectory() as d:
            (Path(d) / 'Denominations').mkdir()
            if lineage is not None:
                (Path(d) / 'Denominations/lineage.csv').write_text(lineage, encoding='utf-8')
            if present is not None:
                (Path(d) / 'Denominations/present-day.csv').write_text(present, encoding='utf-8')
            return corpus.load_church_bodies(Path(d))

    def test_no_files(self):
        self.assertEqual(self.load(None, None), ([], []))

    def test_rows(self):
        bodies, names = self.load(self.LINEAGE, self.PRESENT)
        self.assertEqual([b.name for b in bodies], ['Presbyterian Church in the United States',
                                                    'Presbyterian Church in the United States of America'])
        pcus = bodies[0]
        self.assertEqual((pcus.founded, pcus.ended, pcus.verified), (1861, 1983, True))
        self.assertEqual(pcus.tags[1], 'Presbyterian Church in the Confederate States of America')
        self.assertEqual(pcus.aliases, ['PCUS', 'Southern Presbyterian Church'])
        self.assertEqual([n.name for n in names], ['Presbyterian Church in America', 'Range'],
                         'a name with no known ancestor is left out')
        self.assertEqual(names[0].ancestors, [('Presbyterian Church in the United States', None, None),
                                              ('Presbyterian Church in the United States of America', None, 1861)])
        self.assertEqual(names[1].ancestors, [('Presbyterian Church in the United States of America', 1843, 1900)])

    def test_books_repo_files(self):
        repo = Path.home() / 'library-work/books'
        for cand in (Path('/mnt/photos/library-work/books-lineage'), repo):
            if (cand / 'Denominations/lineage.csv').exists():
                bodies, names = corpus.load_church_bodies(cand)
                self.assertGreater(len(bodies), 50)
                self.assertTrue(any(n.name == 'Presbyterian Church in America' for n in names))
                return
        self.skipTest('no books checkout with Denominations/lineage.csv')
