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
