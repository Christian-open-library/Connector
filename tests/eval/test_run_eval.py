"""Unit tests for run_eval.py helpers (no network).

    cd ~/library-connector && .venv/bin/python -m unittest tests/eval/test_run_eval.py -v
"""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import run_eval  # noqa: E402

SAMPLE = """Results 1-3 for "assurance of salvation" (meaning + keyword search, re-ranked)

[1] P8200017 · John Owen, *The Mortification of Sin* · pp. 23–24 · Chap. II
be killing sin, or it will be killing you [p. 24] ...
Notes: Col. 3.5 | Rom. 8.13
Source: https://archive.org/details/x
Also in: John Owen, *Of the Mortification of Sin in Believers* (1668), P182300031; John Owen, *Of the Mortification of Sin in Believers*, P63000012

[2] P89200005 · Synod of Dort, *Canons of Dort* (1619) · Fifth Head
[1] P99 is not a header (too few digits) and P123 in text is ignored
Source: https://example.org

**[3] P164900401** · Jonathan Edwards, *Select Sermons* · p. 7
text

Search notes: Meaning search unavailable right now; showing keyword matches.
Works without a known year were excluded.
"""

KEYS = {82: 'ArchiveLibrary/John Owen - The Mortification of Sin.md',
        1823: 'EEBO-TCP/John Owen/John Owen - Of the Mortification of Sin in Believers.md',
        630: 'CCEL_Library/John Owen - Of the Mortification of Sin in Believers.md',
        892: 'Creeds/Synod of Dort - Canons of Dort.md',
        1649: 'X/Edwards.md'}


def key_of(pid):
    return KEYS.get(pid // run_eval.SEQ_MULT, f'work_id:{pid // run_eval.SEQ_MULT}')


class ParseTests(unittest.TestCase):
    def test_parse_spec_format(self):
        results, notes, ok = run_eval.parse_results(SAMPLE)
        self.assertTrue(ok)
        self.assertEqual([r['pid'] for r in results], [8200017, 89200005, 164900401])
        self.assertEqual(results[0]['also'], [182300031, 63000012])
        self.assertEqual(results[1]['also'], [])
        self.assertEqual(notes, ['Meaning search unavailable right now; showing keyword matches.',
                                 'Works without a known year were excluded.'])

    def test_fallback_without_headers(self):
        results, _, ok = run_eval.parse_results('see P8200017 and P89200005, again P8200017')
        self.assertFalse(ok)
        self.assertEqual([r['pid'] for r in results], [8200017, 89200005])

    def test_empty_result(self):
        results, notes, ok = run_eval.parse_results('No passages found for "x".\n\nSearch notes: keyword only.')
        self.assertEqual((results, notes, ok), ([], ['keyword only.'], True))

    def test_sse_body(self):
        body = ('event: message\ndata: {"jsonrpc":"2.0","method":"notifications/progress","params":{}}\n\n'
                'event: message\ndata: {"jsonrpc":"2.0","id":7,\ndata: "result":{"content":[]}}\n\n')
        self.assertEqual(run_eval.McpClient._from_sse(body, 7), {'jsonrpc': '2.0', 'id': 7, 'result': {'content': []}})
        with self.assertRaises(run_eval.EvalError):
            run_eval.McpClient._from_sse(body, 8)

    def test_score_main_and_also_in(self):
        results, _, _ = run_eval.parse_results(SAMPLE)
        s = run_eval.score(results, key_of, {KEYS[892]}, count_also=True)
        self.assertEqual((s['first_hit_rank'], s['hit@3'], s['hit@8']), (2, True, True))
        # an edition named only in "Also in:" counts at its parent's rank unless disabled
        s = run_eval.score(results, key_of, {KEYS[1823]}, count_also=True)
        self.assertEqual((s['first_hit_rank'], s['hit_via']), (1, 'also-in'))
        s = run_eval.score(results, key_of, {KEYS[1823]}, count_also=False)
        self.assertIsNone(s['first_hit_rank'])
        self.assertFalse(s['hit@8'])

    def test_score_rank_cutoffs(self):
        results = [{'pid': 99900000 + i, 'also': []} for i in range(10)]
        results[5] = {'pid': 89200001, 'also': []}            # expected work at rank 6
        s = run_eval.score(results, key_of, {KEYS[892]}, count_also=True)
        self.assertEqual((s['first_hit_rank'], s['hit@3'], s['hit@8']), (6, False, True))
        self.assertEqual(s['coverage_top8'], 1.0)


class RedactTests(unittest.TestCase):
    def test_key_never_shown(self):
        key = 'Abcdefghijklmnopqrstuvwxyz0123456789_-ABCDE'
        url = f'https://fpc.example.org/wp-json/col/v1/mcp/{key}'
        r = run_eval.Redactor(url)
        self.assertNotIn(key, r.public_url)
        self.assertEqual(r.public_url, 'https://fpc.example.org/wp-json/col/v1/mcp/***')
        msg = f"HTTPSConnectionPool(host='fpc.example.org'): Max retries exceeded with url: /wp-json/col/v1/mcp/{key}"
        self.assertNotIn(key, r(msg))

    def test_tool_arguments(self):
        q = {'id': 'q1', 'question': 'x', 'filters': {'author': 'Owen', 'mode': 'exact', 'offset': 8}}
        self.assertEqual(run_eval.tool_arguments(q, None, 8),
                         {'query': 'x', 'author': 'Owen', 'mode': 'exact', 'offset': 8, 'limit': 8})
        self.assertEqual(run_eval.tool_arguments(q, 'best', 5),
                         {'query': 'x', 'author': 'Owen', 'mode': 'best', 'limit': 5})


if __name__ == '__main__':
    unittest.main()
