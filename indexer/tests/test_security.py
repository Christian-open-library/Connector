"""Regression tests for the security-audit fixes in the indexer (2026-11 audit, run-1):
path containment, markup removal, bounded front matter and sizes, per-work isolation, the work-id
ceiling and the embedding spend stop."""
import contextlib
import io
import os
import sqlite3
import tempfile
import threading
import unittest
import zlib
from pathlib import Path
from unittest import mock

from indexer import build_db, corpus, embed, references, scripture

CANARY = 'CANARY-NOT-A-SECRET alpha beta gamma delta epsilon zeta eta theta'
HEADER = 'Author,Title,Full title,Year,Type,License,Parts,File,Source\n'
PROSE = ' '.join(['Grace and peace be multiplied unto you through the knowledge of God.'] * 3)


class Repo(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.repo = self.root / 'books'
        self.repo.mkdir()
        (self.root / 'private').mkdir()
        (self.root / 'private' / 'canary.md').write_text(CANARY + '\n', encoding='utf-8')

    def tearDown(self):
        self.tmp.cleanup()

    def write(self, rel, text, raw=False):
        p = self.repo / rel
        p.parent.mkdir(parents=True, exist_ok=True)
        if raw:
            p.write_bytes(text)
        else:
            p.write_text(text, encoding='utf-8')
        return rel

    def catalog(self, *files):
        lines = [HEADER] + [f'A,T{i},,1650,book,PD,,"{f}",\n' for i, f in enumerate(files)]
        self.write('catalog_full.csv', ''.join(lines))

    def works(self):
        err = io.StringIO()
        with contextlib.redirect_stderr(err):
            rows = corpus.valid_rows(self.repo, corpus.load_catalog(self.repo))
            ids = corpus.assign_work_ids(rows, {})
            works = list(corpus.iter_works(self.repo, ids, rows))
            passages = [p for w in works for p in corpus.chunk_work(self.repo, w)]
        return works, passages, err.getvalue()


class PathContainment(Repo):
    def test_catalog_paths_outside_the_repo_are_refused(self):
        os.makedirs(self.repo / 'EEBO-TCP')
        os.symlink('../../private/canary.md', self.repo / 'EEBO-TCP' / 'link.md')
        os.symlink(self.root / 'private', self.repo / 'Linked')
        self.write('EEBO-TCP/ok.md', PROSE + '\n')
        self.catalog('../private/canary.md', str(self.root / 'private' / 'canary.md'), 'EEBO-TCP/link.md',
                     'Linked/canary.md', '.git/config', 'EEBO-TCP/../EEBO-TCP/ok.md', 'EEBO-TCP/ok.md',
                     'missing\x1b]0;MARK\x07\n=== update finished: exit 0.md')
        works, passages, err = self.works()
        self.assertEqual([w.key for w in works], ['EEBO-TCP/ok.md'])
        self.assertTrue(passages)
        self.assertFalse(any('CANARY' in p.text for p in passages))
        self.assertEqual(err.count('warning: catalog file refused'), 7, err)
        # catalog text is printed with repr(): no raw escape sequence or forged log line
        self.assertNotIn('\x1b', err)
        self.assertNotIn('\n===', err)
        self.assertIn("'missing\\x1b]0;MARK\\x07\\n=== update finished: exit 0.md'", err)

    def test_symlinked_part_files_are_skipped(self):
        main = self.write('EEBO-TCP/A/A - Big.md', '# Big\n\n' + PROSE + '\n')
        self.write('EEBO-TCP/A/A - Big — Part 001.md', PROSE + '\n')
        os.symlink(self.root / 'private' / 'canary.md', self.repo / 'EEBO-TCP/A/A - Big — Part 002.md')
        err = io.StringIO()
        with contextlib.redirect_stderr(err):
            parts = corpus.part_files(self.repo, main)
        self.assertEqual(parts, ['EEBO-TCP/A/A - Big — Part 001.md'])
        self.assertIn('part file refused', err.getvalue())

    def test_repo_file(self):
        self.write('a/b.md', 'x')
        self.assertEqual(corpus.repo_file(self.repo, 'a/b.md'), (self.repo / 'a/b.md').resolve())
        for bad in ['', '/etc/passwd', '../x', 'a/../a/b.md', 'a//b.md', './a/b.md', '.git/HEAD', 'a\\b.md',
                    'a/b.md\n', None]:
            with self.assertRaises(corpus.UnsafePath, msg=repr(bad)):
                corpus.repo_file(self.repo, bad)
        with self.assertRaises(FileNotFoundError):
            corpus.repo_file(self.repo, 'a/none.md')
        with self.assertRaises(FileNotFoundError):
            corpus.repo_file(self.repo, 'a')        # a directory is not a file

    def test_markdown_dictionary_files_are_contained(self):
        os.makedirs(self.repo / 'R')
        os.symlink(self.root / 'private' / 'canary.md',
                   self.repo / 'R' / 'Thomas Wilson - A Christian Dictionarie.md')
        db = sqlite3.connect(':memory:')
        db.executescript(references.SCHEMA)
        err = io.StringIO()
        with contextlib.redirect_stderr(err), contextlib.redirect_stdout(io.StringIO()):
            references.add_markdown_dictionaries(
                db, self.repo, {'R/Thomas Wilson - A Christian Dictionarie.md': 5}, None)
        self.assertEqual(db.execute('SELECT count(*) FROM dictionary').fetchone()[0], 0)
        db.close()
        self.assertIn('file skipped', err.getvalue())

    def test_symlinked_bible_parts_are_refused(self):
        stem = scripture.BIBLE_FILES['kjv']
        os.makedirs(self.repo / 'CCEL_Library' / 'Bibles')
        os.symlink(self.root / 'private' / 'canary.md', self.repo / 'CCEL_Library' / 'Bibles' / f'{stem} — Part 1.md')
        err = io.StringIO()
        with contextlib.redirect_stderr(err):
            self.assertEqual(list(scripture.parse_bible(self.repo, 'kjv')), [])
        self.assertIn('bible part refused', err.getvalue())


class Markup(Repo):
    def test_images_links_and_tags_are_removed_everywhere(self):
        text = ('---\ntitle: T\n---\n\n## Ch ![h](https://canary.invalid/h.png) one\n\n'
                + PROSE + ' ![a]\\(https://canary.invalid/a.png) and \\!\\[b\\]\\(https://canary.invalid/b.png)'
                ' and ![c][r] and <img\nsrc="https://canary.invalid/i.png"> and [link](https://x.invalid/) end.[^1]\n\n'
                '[r]: https://canary.invalid/r.png\n\n'
                '<!-- p. ![p](https://canary.invalid/p.png) -->\n\n' + PROSE + '\n\n'
                '[^1]: note ![n](https://canary.invalid/n.png) <script>x</script>\n')
        self.write('C/t.md', text)
        self.catalog('C/t.md')
        _, passages, _ = self.works()
        everything = repr([(p.text, p.notes, p.section, p.page_start, p.page_end) for p in passages])
        self.assertNotIn('canary', everything)
        self.assertNotIn('<img', everything)
        self.assertNotIn('<script', everything)
        self.assertIn('link', everything)
        self.assertEqual(passages[0].section, 'Ch one')

    def test_footnote_refs_after_a_bang_are_kept(self):
        self.assertEqual(corpus.clean_inline('Amen![^29][^30]'), 'Amen![^29][^30]')
        self.assertEqual(corpus.clean_inline('a [Matt. 6, 33]: Seek ye first'), 'a [Matt. 6, 33]: Seek ye first')
        self.assertEqual(corpus.clean_inline('23\\. and \\[sic\\]'), '23. and [sic]')

    def test_bible_verse_markup(self):
        self.assertEqual(scripture.clean_verse('For God ![x](https://a.invalid/x.png) so \\!\\[y\\]\\(https://a/e.png\\) '
                                               'loved \\<img src=x\\> the world'), 'For God so loved the world')

    def test_dictionary_markup(self):
        self.assertEqual(references._plain('<def>&lt;img src=x&gt;Grace &lt; law</def>'), 'Grace < law')
        db = sqlite3.connect(':memory:')
        db.executescript(references.SCHEMA)
        self.write('R/Thomas Wilson - A Christian Dictionarie.md',
                   '# W\n\n## GRACE ![g](https://canary.invalid/g.png)\n\n'
                   '<!-- p. ![p](https://canary.invalid/p.png) -->\n\n'
                   'Favour <img src="https://canary.invalid/w.png"> ![g](https://canary.invalid/grace.png) free.\n')
        with contextlib.redirect_stdout(io.StringIO()):
            references.add_markdown_dictionaries(db, self.repo, {'R/Thomas Wilson - A Christian Dictionarie.md': 5}, None)
        head, page, blob = db.execute('SELECT headword, page, text FROM dictionary').fetchone()
        db.close()
        stored = head + ' ' + str(page) + ' ' + zlib.decompress(blob).decode()
        self.assertNotIn('canary', stored)
        self.assertNotIn('<img', stored)
        self.assertEqual(head, 'GRACE')


class FrontMatter(unittest.TestCase):
    def test_aliases_and_merge_keys_are_refused(self):
        y = '---\nl0: &a [x, x, x, x]\nl1: &b [*a, *a, *a, *a]\nseries: *b\ntitle: T\n---\nbody'
        meta, body = corpus.split_frontmatter(y)
        self.assertEqual(body, 'body')
        self.assertTrue(len(str(meta.get('series', ''))) < 100, meta)      # the line fallback: '*b'
        meta, _ = corpus.split_frontmatter('---\nm: &m {a: 1}\nn:\n  <<: *m\nauthor: X\n---\n')
        self.assertEqual(meta.get('author'), 'X')

    def test_values_are_scalars_and_bounded(self):
        meta, _ = corpus.split_frontmatter('---\ntags: [a, b]\nauthor: ' + 'y' * 5000 + '\n---\n')
        self.assertNotIn('tags', meta)
        self.assertEqual(len(meta['author']), corpus.MAX_META_CHARS)
        meta, _ = corpus.split_frontmatter('---\ndate: 1650-00-00\nauthor: A\n---\n')    # ValueError: line fallback
        self.assertEqual(meta, {'date': '1650-00-00', 'author': 'A'})
        meta, body = corpus.split_frontmatter('---\nauthor: ' + 'z' * 40000 + '\n---\nbody')
        self.assertEqual(meta, {})              # over MAX_FRONTMATTER: ignored
        meta, _ = corpus.split_frontmatter('---\ntcp_id: ' + '9' * 4400 + '\n---\n')   # no exception
        self.assertIn('tcp_id', meta)

    def test_deep_nesting_does_not_raise(self):
        corpus.split_frontmatter('---\nx: ' + '[' * 5000 + ']' * 5000 + '\n---\n')


class Robustness(Repo):
    def test_a_bad_file_skips_only_its_work(self):
        self.write('C/bad.md', b'\xff\xfe not utf-8 ' + PROSE.encode(), raw=True)
        self.write('C/good.md', PROSE + '\n')
        self.write('C/date.md', '---\ndate: 2023-02-30\n---\n' + PROSE + '\n')
        self.catalog('C/bad.md', 'C/good.md', 'C/date.md', 'C/good.md')
        works, passages, err = self.works()
        self.assertEqual(sorted(w.key for w in works), ['C/date.md', 'C/good.md'])
        self.assertIn('work skipped (UnicodeDecodeError', err)
        self.assertIn('listed twice', err)

    def test_a_bad_part_file_skips_only_its_work_in_the_build_worker(self):
        self.write('C/bad.md', PROSE + '\n')
        work = corpus.Work(id=3, key='C/bad.md', author='', author_slug='', title='', full_title='', year=None,
                           type='book', collection='c', license='', source_url='', tcp_id='', series='', ocr=False,
                           files=['C/bad.md', 'C/../../private/canary.md'])
        build_db._worker.update(roots={'': self.repo, 'licensed': None}, mod=build_db.Modernizer({}))
        w, rows, err, _, _, _, _ = build_db._process(work)
        self.assertIsNone(rows)
        self.assertIn('UnsafePath', err)

    def test_the_per_work_cache_gives_the_same_rows_and_notices_changes(self):
        import os
        from indexer import buildcache
        self.write('C/w.md', PROSE + '\n')
        work = corpus.Work(id=3, key='C/w.md', author='A', author_slug='', title='T', full_title='', year=None,
                           type='book', collection='c', license='', source_url='', tcp_id='', series='', ocr=False,
                           files=['C/w.md'])
        os.environ['LIBRARY_BUILD_CACHE_DIR'] = str(self.repo / 'cache')
        try:
            store = buildcache.open_store()
            build_db._worker.update(roots={'': self.repo, 'licensed': None}, mod=build_db.Modernizer({}),
                                    version='v1', store=None)
            first = build_db._process(work)
            self.assertFalse(first[5])
            buildcache.put_packed(store, 'parsed', first[4], first[6])
            store.commit()
            again = build_db._process(work)
            self.assertTrue(again[5])
            self.assertEqual(again[1:4], first[1:4])
            import dataclasses                                  # same file on the licensed shelf: its own entry
            build_db._worker.update(roots={'': self.repo, 'licensed': self.repo})
            self.assertNotEqual(build_db._process(dataclasses.replace(work, root='licensed'))[4], first[4])
            self.write('C/w.md', PROSE + ' Amen.\n')
            self.assertFalse(build_db._process(work)[5])
            build_db._worker.update(version='v2', store=None)
            self.write('C/w.md', PROSE + '\n')
            self.assertFalse(build_db._process(work)[5])      # code changed: not from the cache
        finally:
            del os.environ['LIBRARY_BUILD_CACHE_DIR']
            build_db._worker.update(version='', store=None)

    def test_too_many_passages_is_an_error(self):
        self.write('C/many.md', '\n\n'.join([PROSE] * 5) + '\n')
        work = corpus.Work(id=3, key='C/many.md', author='', author_slug='', title='', full_title='', year=None,
                           type='book', collection='c', license='', source_url='', tcp_id='', series='', ocr=False,
                           files=['C/many.md'])
        with mock.patch.object(corpus, 'MIN_WORDS', 10), mock.patch.object(corpus, 'SEQ_MULT', 3):
            with self.assertRaisesRegex(ValueError, 'more than 3 passages'):
                corpus.chunk_work(self.repo, work)

    def test_footnotes_and_passages_are_capped(self):
        blocks = ''.join(f'## h{i}\n\na a a a a a a a grace[^n]\n\n' for i in range(30))
        self.write('C/f.md', blocks + '[^n]: x' + ' ' * 100_000 + 'y' + ' z' * 60_000 + '\n')
        self.write('C/long.md', 'a b c d e f g h ' + 'word,' * 20_000 + '\n')
        self.catalog('C/f.md', 'C/long.md')
        _, passages, _ = self.works()
        for p in passages:
            self.assertLessEqual(len(p.text), corpus.MAX_PASSAGE_CHARS)
            self.assertLessEqual(sum(len(n) for n in p.notes), corpus.MAX_NOTES_CHARS)
            self.assertTrue(all(len(n) <= corpus.MAX_NOTE_CHARS for n in p.notes))
        self.assertTrue(any(p.text.endswith('…') for p in passages))

    def test_page_labels_are_short_and_plain(self):
        self.write('C/p.md', '<!-- p. ' + 'x' * 500 + ' -->\n\n' + PROSE + '\n')
        self.catalog('C/p.md')
        _, passages, _ = self.works()
        self.assertEqual(passages[0].page_start, 'x' * corpus.MAX_LABEL_CHARS)


class WorkIds(Repo):
    def test_ids_stop_at_the_uint32_ceiling(self):
        rows = [{'File': f'f{i}.md'} for i in range(3)]
        err = io.StringIO()
        with contextlib.redirect_stderr(err):
            ids = corpus.assign_work_ids(rows, {'old.md': corpus.MAX_WORK_ID - 1})
        self.assertEqual(ids, {'old.md': corpus.MAX_WORK_ID - 1, 'f0.md': corpus.MAX_WORK_ID})
        self.assertEqual(err.getvalue().count('no work id left'), 2)
        self.assertLess((corpus.MAX_WORK_ID + 1) * corpus.SEQ_MULT - 1, 2**32)

    def test_missing_rows_get_no_id_and_the_map_is_saved_only_after_a_good_build(self):
        self.write('C/good.md', PROSE + '\n')
        self.catalog('C/good.md', *[f'C/bogus{i}.md' for i in range(50)])
        ids_path = self.root / 'work_ids.json'
        out = self.root / 'build'
        lex = self.root / 'lexicon.tsv'
        lex.write_text('grace\t5\n', encoding='utf-8')
        with mock.patch.object(build_db, 'IDS_PATH', ids_path), mock.patch.object(build_db, 'DATA', self.root), \
                contextlib.redirect_stderr(io.StringIO()), contextlib.redirect_stdout(io.StringIO()):
            build_db.build(self.repo, out, processes=1)
            self.assertFalse(ids_path.exists())
            self.assertEqual(corpus.load_work_ids(out / 'work_ids.json'), {'C/good.md': 1})
            build_db.save_ids(out)
        self.assertEqual(corpus.load_work_ids(ids_path), {'C/good.md': 1})


class EmbedStop(unittest.TestCase):
    def test_no_request_after_the_budget_is_passed(self):
        calls = []

        class Stub:
            failed_tokens = 0
            stop = None

            def embed(self, texts, model, input_type, dims):
                calls.append(len(texts))
                import numpy as np
                est = sum(len(t) for t in texts) / embed.CHARS_PER_TOKEN
                return np.ones((len(texts), dims), dtype='float32'), int(est * 10)   # bills 10x the estimate

        todo = [(i, f'k{i}', 'x' * 100) for i in range(40)]
        cache = sqlite3.connect(':memory:', check_same_thread=False)
        cache.execute('CREATE TABLE vec(key TEXT PRIMARY KEY, v BLOB NOT NULL)')
        with mock.patch.object(embed, 'BATCH_TEXTS', 5), contextlib.redirect_stdout(io.StringIO()):
            # 8 batches of ~160 estimated tokens; budget ~3,000 tokens: the first batch bills 1,560
            with self.assertRaises(SystemExit):
                embed.embed_batches(Stub(), todo, max_dollars=3000 * embed.PRICE_PER_M / 1e6, workers=1,
                                    model='m', dims=4, cache=cache)
        cache.close()
        self.assertLessEqual(len(calls), 2, calls)

    def test_failed_attempts_count_and_stop_ends_retries(self):
        from indexer import voyage
        api = voyage.Voyage(key='k', base='https://example.invalid')
        api.gate = mock.Mock()
        api.stop = threading.Event()
        responses = iter([voyage.requests.ConnectionError('x')])

        def post(*a, **k):
            api.stop.set()
            raise next(responses)

        with mock.patch.object(api.session, 'post', side_effect=post), mock.patch.object(voyage.time, 'sleep'):
            with self.assertRaisesRegex(voyage.VoyageError, 'stopped'):
                api._post('/embeddings', {}, 123)
        self.assertEqual(api.failed_tokens, 123)


if __name__ == '__main__':
    unittest.main()
