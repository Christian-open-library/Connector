"""The nightly's books-clone check (indexer/pipeline.py ready_main; THREAT-MODEL B4).

A clean clone left on another branch is switched back to main and built (the 2026-11-21 nightly built nothing
because a session left the clone on its branch). Uncommitted or untracked files still stop the build.
"""
import contextlib
import io
import subprocess
import tempfile
import unittest
from pathlib import Path

from indexer import pipeline


def git(repo, *a):
    return subprocess.run(['git', '-C', str(repo), *a], capture_output=True, text=True, check=True).stdout.strip()


class ReadyMain(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        origin, self.clone = root / 'origin.git', root / 'books'
        subprocess.run(['git', 'init', '-q', '--bare', '-b', 'main', str(origin)], check=True)
        subprocess.run(['git', 'clone', '-q', str(origin), str(self.clone)], check=True, capture_output=True)
        for k, v in (('user.name', 't'), ('user.email', 't@example.invalid'), ('commit.gpgsign', 'false')):
            git(self.clone, 'config', k, v)
        (self.clone / 'a.md').write_text('one\n')
        git(self.clone, 'add', 'a.md')
        git(self.clone, 'commit', '-q', '-m', 'first')
        git(self.clone, 'push', '-q', '-u', 'origin', 'main')

    def tearDown(self):
        self.tmp.cleanup()

    def ready(self):
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            pipeline.ready_main(self.clone)
        return out.getvalue()

    def test_clean_main_builds(self):
        self.assertEqual(self.ready(), '')
        self.assertEqual(git(self.clone, 'rev-parse', '--abbrev-ref', 'HEAD'), 'main')

    def test_clean_clone_left_on_a_branch_goes_back_to_main(self):
        git(self.clone, 'switch', '-q', '-c', 'authors-fill')
        (self.clone / 'b.md').write_text('two\n')
        git(self.clone, 'add', 'b.md')
        git(self.clone, 'commit', '-q', '-m', 'branch work')
        self.assertIn('was on authors-fill', self.ready())
        self.assertEqual(git(self.clone, 'rev-parse', '--abbrev-ref', 'HEAD'), 'main')
        self.assertFalse((self.clone / 'b.md').exists())          # the build sees main only
        self.assertIn('branch work', git(self.clone, 'log', '--oneline', 'authors-fill'))   # the branch is kept

    def test_uncommitted_or_untracked_files_stop_the_build(self):
        for change in ('modify', 'untracked'):
            with self.subTest(change):
                if change == 'modify':
                    (self.clone / 'a.md').write_text('edited\n')
                else:
                    git(self.clone, 'checkout', '-q', '--', 'a.md')
                    (self.clone / 'new.md').write_text('x\n')
                with self.assertRaises(SystemExit) as e:
                    self.ready()
                self.assertIn('nothing was built', str(e.exception))

    def test_dirty_branch_is_not_switched(self):
        git(self.clone, 'switch', '-q', '-c', 'work')
        (self.clone / 'a.md').write_text('unsaved\n')
        with self.assertRaises(SystemExit):
            self.ready()
        self.assertEqual(git(self.clone, 'rev-parse', '--abbrev-ref', 'HEAD'), 'work')
        self.assertEqual((self.clone / 'a.md').read_text(), 'unsaved\n')

    def test_main_behind_origin_is_pulled(self):
        other = Path(self.tmp.name) / 'other'
        subprocess.run(['git', 'clone', '-q', str(Path(self.tmp.name) / 'origin.git'), str(other)], check=True,
                       capture_output=True)
        for k, v in (('user.name', 't'), ('user.email', 't@example.invalid'), ('commit.gpgsign', 'false')):
            git(other, 'config', k, v)
        (other / 'c.md').write_text('three\n')
        git(other, 'add', 'c.md')
        git(other, 'commit', '-q', '-m', 'merged elsewhere')
        git(other, 'push', '-q', 'origin', 'main')
        self.ready()
        self.assertTrue((self.clone / 'c.md').exists())


if __name__ == '__main__':
    unittest.main()
