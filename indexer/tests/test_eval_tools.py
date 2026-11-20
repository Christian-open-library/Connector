"""The eval scorer's copy of the tradition filter's writer mapping (tests/eval/denom_eval.py) equals the
server's (plugin Churches::WRITER_TRADITIONS, WRITER_EXCEPTIONS, PURITAN_NOTE)."""
import importlib.util
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PHP = ROOT / 'plugin/col-library-connector/src/Churches.php'
SCORER = ROOT / 'tests/eval/denom_eval.py'   # private: not in the public copy (deploy/publish_public.py)


def scorer():
    spec = importlib.util.spec_from_file_location('denom_eval', SCORER)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def php_map(text: str, const: str) -> dict:
    body = re.search(r'const ' + const + r' = \[(.*?)\];\n', text, re.S)
    out = {}
    for key, vals in re.findall(r"'([^']*)' => (\[[^\]]*\]|'[^']*')", body.group(1)):
        out[key] = re.findall(r"'([^']*)'", vals) if vals.startswith('[') else vals.strip("'")
    return out


@unittest.skipUnless(SCORER.is_file(), 'the eval scorer is not in this copy')
class WriterTraditionsMatch(unittest.TestCase):
    def test_same_mapping(self):
        text = PHP.read_text(encoding='utf-8')
        mod = scorer()
        self.assertEqual(php_map(text, 'WRITER_TRADITIONS'), mod.WRITER_TRADITIONS)
        self.assertEqual(php_map(text, 'WRITER_EXCEPTIONS'), mod.WRITER_EXCEPTIONS)
        php_notes = {k: v.replace('\\\\', '\\').strip('/').removesuffix('/i')
                     for k, v in php_map(text, 'PURITAN_NOTE').items()}
        self.assertEqual(php_notes, mod.PURITAN_NOTE)

    def test_puritans(self):
        w = scorer().writer_traditions
        self.assertEqual(w('X', 'Puritan', 'English Presbyterian minister'), ['Presbyterian'])
        self.assertEqual(w('X', 'Puritan', 'English Nonconformist minister, Church of England, ejected 1662'),
                         ['Presbyterian', 'Congregational'])
        self.assertEqual(w('X', 'Puritan', 'Church of England rector'), ['Anglican'])
        self.assertEqual(w('Jan Hus', 'Medieval', ''), ['Moravian'])


if __name__ == '__main__':
    unittest.main()
