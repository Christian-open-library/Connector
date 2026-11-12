#!/usr/bin/env python3
"""Retrieval evaluation for the Christian Open Library connector (DESIGN.md section 9).

Calls the search_library tool of a running MCP endpoint once per question in questions.jsonl
and reports recall@3 and recall@8 (the share of questions with at least one expected work
among the top k results), overall and by kind, plus per-question hits and misses, and writes
a JSON summary.

    cd ~/library-connector
    .venv/bin/python tests/eval/run_eval.py --url-file ~/.config/library-connector/eval-url
    COL_MCP_URL='https://host/wp-json/col/v1/mcp/KEY' .venv/bin/python tests/eval/run_eval.py
    .venv/bin/python tests/eval/run_eval.py --url URL --mode best --kinds quote,exact --only q01,q02
    .venv/bin/python tests/eval/run_eval.py --compare tests/eval/results/a.json tests/eval/results/b.json

The URL holds the secret key, so prefer --url-file or COL_MCP_URL (a --url argument is visible
in the process list). The URL is never printed or saved: it appears as .../***.

Protocol: legacy MCP over Streamable HTTP (initialize with 2025-11-25, notifications/initialized,
then one tools/call per question). JSON and SSE responses are both accepted.
Work keys come from the result refs: "P<id>" -> work_id = id // 100000 -> data/work_ids.json.
Works named on an "Also in:" line count at their parent's rank (--no-also-in to turn off).
Needs only the project venv (requests). For a quick test without PHP, point it at
tests/eval/mock_server.py (a keyword-only stand-in) and pass --db for sample builds.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sqlite3
import sys
import time
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlsplit, urlunsplit

import requests

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
DEFAULT_QUESTIONS = HERE / 'questions.jsonl'
DEFAULT_IDS = ROOT / 'data' / 'work_ids.json'
RESULTS_DIR = HERE / 'results'

PROTOCOL_VERSION = '2025-11-25'
TOOL = 'search_library'
KS = (3, 8)
SEQ_MULT = 100_000                      # passage id = work_id * SEQ_MULT + seq (DESIGN.md 2)
KINDS = ('quote', 'paraphrase', 'doctrine', 'old-spelling', 'filtered', 'exact')
FILTER_KEYS = ('author', 'work_id', 'collection', 'denomination', 'tradition', 'year_from', 'year_to', 'mode',
               'offset')
USER_AGENT = 'col-library-eval/1.0'

# "[3] P1234500017 · Author, *Title* (1657) · pp. 23–24 · Section" (tolerates bold/heading marks)
HEADER_RE = re.compile(r'^[\s*_#>]*\[(\d+)\][\s*_]*P(\d{6,12})\b')
REF_RE = re.compile(r'\bP(\d{6,12})\b')


class EvalError(Exception):
    pass


class RpcError(EvalError):
    def __init__(self, code, message):
        super().__init__(f'JSON-RPC error {code}: {message}')
        self.code = code


# ---------------------------------------------------------------- secrets

class Redactor:
    """Hides the endpoint URL and its secret path segment(s) in anything we print or save."""

    def __init__(self, url: str):
        parts = urlsplit(url)
        self.secrets = [url, parts.path, parts.query, parts.password or '']
        # the key is a long URL-safe path segment (43 chars in production); hide every long one
        self.secrets += [seg for seg in parts.path.split('/') if len(seg) >= 16]
        self.secrets = sorted({s for s in self.secrets if len(s) >= 6}, key=len, reverse=True)
        safe_path = '/'.join('***' if len(seg) >= 16 else seg for seg in parts.path.split('/'))
        host = parts.hostname or ''
        if parts.port:
            host += f':{parts.port}'
        self.public_url = urlunsplit((parts.scheme, host, safe_path, '', ''))

    def __call__(self, text) -> str:
        text = str(text)
        for s in self.secrets:
            text = text.replace(s, '***')
        return text


# ---------------------------------------------------------------- MCP client

class McpClient:
    """Minimal legacy-era (2025-11-25) MCP client over Streamable HTTP POST."""

    def __init__(self, url: str, timeout: float, redact: Redactor):
        self.url = url
        self.timeout = timeout
        self.redact = redact
        self.http = requests.Session()
        self.next_id = 1
        self.protocol_version: str | None = None    # set by initialize
        self.session_id: str | None = None          # only if the server mints one
        self.server_info: dict = {}

    def _headers(self, initializing: bool) -> dict:
        h = {'Content-Type': 'application/json',
             'Accept': 'application/json, text/event-stream',
             'User-Agent': USER_AGENT}
        if not initializing and self.protocol_version:
            h['MCP-Protocol-Version'] = self.protocol_version
        if self.session_id:
            h['Mcp-Session-Id'] = self.session_id
        return h

    def _post(self, payload: dict, initializing: bool = False, retries: int = 2):
        """POST one JSON-RPC message. Returns the response object, or None for 202."""
        body = json.dumps(payload).encode('utf-8')
        for attempt in range(retries + 1):
            try:
                # no redirects: a redirected POST becomes a GET, and the key would go to the new host
                r = self.http.post(self.url, data=body, headers=self._headers(initializing),
                                   timeout=(10, self.timeout), allow_redirects=False)
            except requests.RequestException as e:
                if attempt < retries:
                    time.sleep(2 + 3 * attempt)
                    continue
                raise EvalError(f'HTTP request failed: {self.redact(e)}') from None
            if r.status_code in (429, 502, 503, 504) and attempt < retries:
                wait = r.headers.get('Retry-After', '')
                time.sleep(min(int(wait), 60) if wait.isdigit() else 5 + 5 * attempt)
                continue
            break
        if initializing and r.headers.get('Mcp-Session-Id'):
            self.session_id = r.headers['Mcp-Session-Id']
        if r.status_code == 202:
            return None
        if 300 <= r.status_code < 400:
            raise EvalError(f'HTTP {r.status_code} redirect to {self.redact(r.headers.get("Location", "?"))}; '
                            'use the final URL')
        ctype = r.headers.get('Content-Type', '').split(';')[0].strip().lower()
        if r.status_code != 200:
            excerpt = self.redact(r.text[:300]).replace('\n', ' ')
            raise EvalError(f'HTTP {r.status_code} ({ctype or "no content type"}): {excerpt}')
        if ctype == 'text/event-stream':
            return self._from_sse(r.text, payload.get('id'))
        try:
            return r.json()
        except ValueError:
            raise EvalError(f'response is not JSON ({ctype}): {self.redact(r.text[:200])}') from None

    @staticmethod
    def _from_sse(text: str, want_id):
        """Pick the JSON-RPC response with our id out of an SSE body."""
        data: list[str] = []
        events = []
        for line in text.splitlines() + ['']:
            if line.startswith('data:'):
                data.append(line[5:].lstrip(' '))
            elif line == '' and data:
                events.append('\n'.join(data))
                data = []
        for ev in events:
            try:
                msg = json.loads(ev)
            except ValueError:
                continue
            if isinstance(msg, dict) and msg.get('id') == want_id and ('result' in msg or 'error' in msg):
                return msg
        raise EvalError('SSE stream ended without a response to our request')

    def request(self, method: str, params: dict, initializing: bool = False) -> dict:
        rid = self.next_id
        self.next_id += 1
        msg = self._post({'jsonrpc': '2.0', 'id': rid, 'method': method, 'params': params},
                         initializing=initializing)
        if not isinstance(msg, dict):
            raise EvalError(f'{method}: no JSON-RPC response (HTTP 202 or a batch)')
        if 'error' in msg:
            err = msg['error'] if isinstance(msg['error'], dict) else {}
            raise RpcError(err.get('code'), self.redact(err.get('message', 'unknown error')))
        if msg.get('id') != rid or not isinstance(msg.get('result'), dict):
            raise EvalError(f'{method}: malformed JSON-RPC response')
        return msg['result']

    def initialize(self) -> dict:
        result = self.request('initialize', {
            'protocolVersion': PROTOCOL_VERSION,
            'capabilities': {},
            'clientInfo': {'name': 'col-library-eval', 'version': '1.0'},
        }, initializing=True)
        self.protocol_version = result.get('protocolVersion') or PROTOCOL_VERSION
        self.server_info = result.get('serverInfo') or {}
        try:
            self._post({'jsonrpc': '2.0', 'method': 'notifications/initialized'})   # expect 202
        except EvalError as e:
            print(f'warning: notifications/initialized was not accepted: {e}', file=sys.stderr)
        return result

    def call_tool(self, name: str, arguments: dict) -> dict:
        return self.request('tools/call', {'name': name, 'arguments': arguments})


# ---------------------------------------------------------------- result parsing

def parse_results(text: str) -> tuple[list[dict], list[str], bool]:
    """Ranked results from a search_library result text.

    Returns (results, search_notes, recognized): results = [{'pid': int, 'also': [pid, ...]}]
    in display order. Falls back to every distinct P<id> in order of appearance when the text
    has no "[n] P<id>" header lines (recognized = False).
    """
    results: list[dict] = []
    notes: list[str] = []
    current = None
    in_notes = False
    for line in text.splitlines():
        m = HEADER_RE.match(line)
        if m:
            current = {'pid': int(m.group(2)), 'also': []}
            results.append(current)
            in_notes = False
            continue
        s = line.strip()
        if s.startswith('Search notes:'):
            in_notes, current = True, None
            if s[13:].strip():
                notes.append(s[13:].strip())
        elif in_notes and s:
            notes.append(s)
        elif s.startswith('Also in:') and current is not None:
            current['also'] += [int(x) for x in REF_RE.findall(s)]
    if results:
        return results, notes, True
    seen: list[int] = []
    for x in REF_RE.findall(text):
        if int(x) not in seen:
            seen.append(int(x))
    # no refs at all = an empty result ("No passages found"), which is a recognized answer
    return [{'pid': p, 'also': []} for p in seen], notes, not seen


def score(results: list[dict], key_of, expected: set[str], count_also: bool) -> dict:
    """First rank (1-based) where an expected work appears, hits per k, and the works seen."""
    first_rank, via = None, None
    found_top8: set[str] = set()
    for rank, res in enumerate(results, 1):
        main = key_of(res['pid'])
        keys = [main] + ([key_of(p) for p in res['also']] if count_also else [])
        hit = [k for k in keys if k in expected]
        if rank <= max(KS):
            found_top8.update(hit)
        if hit and first_rank is None:
            first_rank, via = rank, ('main' if main in expected else 'also-in')
    out = {'first_hit_rank': first_rank, 'hit_via': via,
           'expected_found_top8': sorted(found_top8),
           'coverage_top8': round(len(found_top8) / len(expected), 3) if expected else 0.0}
    for k in KS:
        out[f'hit@{k}'] = first_rank is not None and first_rank <= k
    return out


# ---------------------------------------------------------------- inputs

def load_questions(path: Path) -> list[dict]:
    qs = []
    with open(path, encoding='utf-8') as fh:
        for n, line in enumerate(fh, 1):
            if not line.strip():
                continue
            try:
                q = json.loads(line)
            except ValueError as e:
                raise SystemExit(f'{path}:{n}: bad JSON: {e}')
            for field in ('id', 'question', 'kind', 'expect_any_work_keys'):
                if field not in q:
                    raise SystemExit(f'{path}:{n}: missing "{field}"')
            qs.append(q)
    return qs


def load_key_map(ids_path: Path, db_path: Path | None) -> tuple[dict[int, str], set[str] | None]:
    """work_id -> key (work_ids.json, overridden by the build's own works table if --db)."""
    ids = json.loads(ids_path.read_text(encoding='utf-8'))
    key_by_id = {int(v): k for k, v in ids.items()}
    in_build = None
    if db_path:
        db = sqlite3.connect(f'file:{db_path}?mode=ro', uri=True)
        rows = db.execute('SELECT id, key FROM works').fetchall()
        db.close()
        key_by_id.update({int(i): k for i, k in rows})
        in_build = {k for _, k in rows}
    return key_by_id, in_build


def read_url(args) -> str:
    if args.url:
        return args.url.strip()
    if args.url_file:
        return Path(args.url_file).expanduser().read_text(encoding='utf-8').strip()
    if os.environ.get('COL_MCP_URL'):
        return os.environ['COL_MCP_URL'].strip()
    raise SystemExit('give the endpoint with --url, --url-file or the COL_MCP_URL environment variable')


def tool_arguments(q: dict, mode: str | None, limit: int) -> dict:
    args = {'query': q['question']}
    for k, v in (q.get('filters') or {}).items():
        if k in FILTER_KEYS:
            args[k] = v
        else:
            print(f'  warning: {q["id"]}: ignoring unknown filter "{k}"', file=sys.stderr)
    if mode:
        args['mode'] = mode
    args['limit'] = limit
    if args.get('mode') != 'exact':
        args.pop('offset', None)      # offset is exact-mode only
    return args


def short(key: str) -> str:
    """'EEBO-TCP/John Owen/John Owen - Of Temptation.md' -> 'John Owen - Of Temptation [EEBO-TCP]'."""
    name = key.rsplit('/', 1)[-1]
    name = name[:-3] if name.endswith('.md') else name
    if len(name) > 70:
        name = name[:67] + '...'
    return f'{name} [{key.split("/", 1)[0]}]'


def rate(hits: int, n: int) -> str:
    return f'{hits / n:.2f} ({hits}/{n})' if n else '-'


# ---------------------------------------------------------------- run

def run(args) -> int:
    started = datetime.now(timezone.utc)
    url = read_url(args)
    redact = Redactor(url)
    questions = load_questions(Path(args.questions))
    if args.only:
        wanted = {x.strip() for x in args.only.split(',') if x.strip()}
        questions = [q for q in questions if q['id'] in wanted]
    if args.kinds:
        kinds = {x.strip() for x in args.kinds.split(',') if x.strip()}
        questions = [q for q in questions if q['kind'] in kinds]
    key_by_id, in_build = load_key_map(Path(args.ids), Path(args.db) if args.db else None)

    def key_of(pid: int) -> str:
        wid = pid // SEQ_MULT
        return key_by_id.get(wid, f'work_id:{wid}')

    skipped = []
    if in_build is not None:
        keep = []
        for q in questions:
            (keep if set(q['expect_any_work_keys']) & in_build else skipped).append(q)
        questions = keep
    if not questions:
        raise SystemExit('no questions to run')

    client = McpClient(url, args.timeout, redact)
    try:
        init = client.initialize()
    except EvalError as e:
        print(f'cannot initialize {redact.public_url}: {e}', file=sys.stderr)
        return 2
    info = client.server_info
    print(f'endpoint {redact.public_url}: {info.get("name", "?")} {info.get("version", "")}, '
          f'protocol {client.protocol_version}')
    if in_build is not None:
        print(f'--db: {len(skipped)} question(s) skipped (no expected work in that build)')
    print(f'{len(questions)} questions, limit {args.limit}, mode {args.mode or "per question"}, '
          f'also-in refs {"count" if not args.no_also_in else "ignored"}')
    if args.limit < max(KS):
        print(f'note: --limit {args.limit} < {max(KS)}, so recall@{max(KS)} only sees the top {args.limit}')
    print()

    records = []
    last_call = 0.0
    for q in questions:
        expected = set(q['expect_any_work_keys'])
        targs = tool_arguments(q, args.mode, args.limit)
        wait = args.min_interval - (time.monotonic() - last_call)
        if wait > 0:
            time.sleep(wait)                      # stay under the server's per-minute limit
        last_call = time.monotonic()
        rec = {'id': q['id'], 'kind': q['kind'], 'question': q['question'], 'arguments': targs,
               'expected': sorted(expected), 'error': None, 'is_error': False, 'notes': [],
               'format_recognized': None, 'results': []}
        t0 = time.monotonic()
        text = ''
        for attempt in range(3):
            try:
                result = client.call_tool(TOOL, targs)
            except EvalError as e:
                rec['error'] = str(e)
                break
            text = '\n'.join(c.get('text', '') for c in result.get('content') or []
                             if isinstance(c, dict) and c.get('type') == 'text')
            rec['is_error'] = bool(result.get('isError'))
            # back off and retry when the server says we are going too fast
            if rec['is_error'] and re.search(r'rate.?limit|too many|try again', text, re.I) and attempt < 2:
                m = re.search(r'(\d+)\s*s(?:ec|econds?)?\b', text)
                time.sleep(min(int(m.group(1)), 90) if m else 20)
                continue
            break
        rec['seconds'] = round(time.monotonic() - t0, 2)
        if args.verbose:
            print(f'  > {json.dumps(targs, ensure_ascii=False)}')
            shown = ''.join(c if c.isprintable() or c == '\n' else '?' for c in text[:400])   # no terminal escapes
            print('  < ' + shown.replace('\n', '\n    '))
        if rec['is_error']:
            rec['error'] = 'tool error: ' + text.strip().replace('\n', ' ')[:300]
        results, notes, recognized = parse_results(text) if text and not rec['is_error'] else ([], [], None)
        rec['notes'] = notes
        rec['format_recognized'] = recognized
        rec['results'] = [{'ref': f'P{r["pid"]}', 'key': key_of(r['pid']),
                           'also_in': [key_of(p) for p in r['also']]} for r in results]
        rec.update(score(results, key_of, expected, count_also=not args.no_also_in))
        rec['strict_hit@8'] = score(results, key_of, expected, count_also=False)['hit@8']
        records.append(rec)
        print_record(rec, args.show_top)
        if recognized is False:
            print('    warning: result text has no "[n] P<id>" headers; used refs in order of appearance')

    summary = summarize(records)
    print_summary(summary, records)
    out = {
        'label': args.label,
        'started_at': started.isoformat(timespec='seconds'),
        'finished_at': datetime.now(timezone.utc).isoformat(timespec='seconds'),
        'endpoint': redact.public_url,
        'server_info': info,
        'protocol_version': client.protocol_version,
        'instructions_present': bool(init.get('instructions')),
        'options': {'mode': args.mode, 'limit': args.limit, 'count_also_in': not args.no_also_in,
                    'questions_file': str(args.questions), 'db': str(args.db) if args.db else None},
        'skipped_not_in_build': [q['id'] for q in skipped],
        'summary': summary,
        'questions': records,
    }
    out_path = Path(args.out) if args.out else \
        RESULTS_DIR / f'eval-{started.strftime("%Y%m%d-%H%M%S")}.json'
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(redact(json.dumps(out, ensure_ascii=False, indent=1)), encoding='utf-8')
    print(f'\nsummary written to {out_path}')
    if args.fail_under is not None and summary['overall']['recall@8'] < args.fail_under:
        print(f'recall@8 {summary["overall"]["recall@8"]:.2f} is below --fail-under {args.fail_under}')
        return 1
    return 0


def print_record(rec: dict, show_top: int) -> None:
    marks = '  '.join(f'@{k} {"HIT " if rec[f"hit@{k}"] else "miss"}' for k in KS)
    rank = rec['first_hit_rank']
    where = f'rank {rank}' + (' (also-in)' if rec['hit_via'] == 'also-in' else '') if rank else 'not found'
    print(f'{rec["id"]:<5} {rec["kind"]:<12} {marks}  {where:<18} {rec["seconds"]:5.1f}s  '
          f'{rec["question"][:70]}')
    if rec['error']:
        print(f'    ERROR: {rec["error"]}')
        return
    if not rec['results']:
        print('    (no results)')
    elif not rec['hit@8'] or show_top > 3:
        n = max(show_top, 1)
        for i, r in enumerate(rec['results'][:n], 1):
            tag = '*' if r['key'] in rec['expected'] else ' '
            print(f'    {tag}{i}. {short(r["key"])}')
    if not rec['hit@8']:
        print(f'    expected any of: ' + '; '.join(short(k) for k in rec['expected'][:4])
              + (f' (+{len(rec["expected"]) - 4} more)' if len(rec['expected']) > 4 else ''))
    for note in rec['notes']:
        print(f'    note: {note[:200]}')


def summarize(records: list[dict]) -> dict:
    def block(recs):
        n = len(recs)
        d = {'n': n, 'errors': sum(1 for r in recs if r['error'])}
        for k in KS:
            hits = sum(1 for r in recs if r[f'hit@{k}'])
            d[f'hits@{k}'] = hits
            d[f'recall@{k}'] = round(hits / n, 4) if n else 0.0
        d['strict_recall@8'] = round(sum(1 for r in recs if r['strict_hit@8']) / n, 4) if n else 0.0
        d['mrr@8'] = round(sum(1 / r['first_hit_rank'] for r in recs
                               if r['first_hit_rank'] and r['first_hit_rank'] <= 8) / n, 4) if n else 0.0
        return d

    by_kind = defaultdict(list)
    for r in records:
        by_kind[r['kind']].append(r)
    notes = Counter(n for r in records for n in r['notes'])
    return {
        'overall': block(records),
        'by_kind': {k: block(by_kind[k]) for k in sorted(by_kind, key=lambda k: (KINDS + (k,)).index(k))},
        'misses@8': [r['id'] for r in records if not r['hit@8']],
        'misses@3': [r['id'] for r in records if not r['hit@3']],
        'errors': [r['id'] for r in records if r['error']],
        'search_notes': dict(notes.most_common(20)),
        'mean_seconds': round(sum(r['seconds'] for r in records) / len(records), 2) if records else 0,
    }


def print_summary(s: dict, records: list[dict]) -> None:
    o = s['overall']
    print('\n' + '=' * 72)
    print(f'recall@3 {rate(o["hits@3"], o["n"])}   recall@8 {rate(o["hits@8"], o["n"])}   '
          f'MRR@8 {o["mrr@8"]:.2f}   errors {o["errors"]}   mean {s["mean_seconds"]}s/query')
    print(f'{"kind":<13}{"n":>3}  {"recall@3":<14}{"recall@8":<14}')
    for kind, b in s['by_kind'].items():
        print(f'{kind:<13}{b["n"]:>3}  {rate(b["hits@3"], b["n"]):<14}{rate(b["hits@8"], b["n"]):<14}')
    if s['misses@8']:
        print('misses@8: ' + ', '.join(s['misses@8']))
    if s['errors']:
        print('errors: ' + ', '.join(s['errors']))
    for note, n in s['search_notes'].items():
        print(f'search note x{n}: {note[:150]}')


# ---------------------------------------------------------------- compare saved runs

def compare(paths: list[str]) -> int:
    runs = []
    for p in paths:
        d = json.loads(Path(p).read_text(encoding='utf-8'))
        runs.append((d.get('label') or Path(p).stem, d))
    width = max(12, *(len(name) for name, _ in runs)) + 2
    print(f'{"":<16}' + ''.join(f'{name[:width - 2]:<{width}}' for name, _ in runs))
    rows = [('overall', lambda d: d['summary']['overall'])]
    kinds = sorted({k for _, d in runs for k in d['summary']['by_kind']},
                   key=lambda k: (KINDS + (k,)).index(k))
    rows += [(k, (lambda kk: lambda d: d['summary']['by_kind'].get(kk))(k)) for k in kinds]
    for k in KS:
        print(f'recall@{k}')
        for name, get in rows:
            cells = []
            for _, d in runs:
                b = get(d)
                cells.append(rate(b[f'hits@{k}'], b['n']) if b else '-')
            print(f'  {name:<14}' + ''.join(f'{c:<{width}}' for c in cells))
    hit = [{q['id']: q['hit@8'] for q in d['questions']} for _, d in runs]
    ids = sorted(set().union(*hit))
    common = [i for i in ids if all(i in h for h in hit)]
    diff = [i for i in common if len({h[i] for h in hit}) > 1]
    if len(common) < len(ids):
        print(f'\n{len(ids) - len(common)} question(s) were not run in every summary; '
              f'the per-kind rates above cover different question sets')
    if diff:
        print('\nquestions whose hit@8 differs (H = hit, . = miss):')
        for i in diff:
            print(f'  {i:<6}' + ''.join(f'{"H" if h[i] else ".":<{width}}' for h in hit))
    return 0


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--url', help='MCP endpoint URL (contains the secret key; prefer --url-file)')
    ap.add_argument('--url-file', help='file holding the endpoint URL')
    ap.add_argument('--questions', default=str(DEFAULT_QUESTIONS))
    ap.add_argument('--ids', default=str(DEFAULT_IDS), help='work_ids.json (key -> work_id)')
    ap.add_argument('--db', help="library.sqlite of the build being served: skip questions whose "
                                 "expected works are not in it (for sample builds)")
    ap.add_argument('--mode', choices=('best', 'exact'), help='override every question\'s mode')
    ap.add_argument('--limit', type=int, default=8, help='results per search (1-20, default 8)')
    ap.add_argument('--only', help='comma-separated question ids')
    ap.add_argument('--kinds', help='comma-separated kinds: ' + ','.join(KINDS))
    ap.add_argument('--label', help='name for this run in the summary (e.g. bm25-only)')
    ap.add_argument('--out', help='summary JSON path (default tests/eval/results/eval-<time>.json)')
    ap.add_argument('--timeout', type=float, default=90.0, help='read timeout per request (s)')
    ap.add_argument('--min-interval', type=float, default=1.2,
                    help='minimum seconds between tool calls (default 1.2 = 50/min)')
    ap.add_argument('--no-also-in', action='store_true',
                    help='do not count works named in "Also in:" lines as hits')
    ap.add_argument('--show-top', type=int, default=3, help='returned works to list for misses')
    ap.add_argument('--fail-under', type=float, help='exit 1 if overall recall@8 is below this')
    ap.add_argument('--verbose', action='store_true', help='print each request\'s arguments and the '
                                                           'start of each result')
    ap.add_argument('--compare', nargs='+', metavar='SUMMARY_JSON',
                    help='compare saved summaries instead of running')
    args = ap.parse_args(argv)
    sys.stdout.reconfigure(line_buffering=True)      # progress shows up when output goes to a file
    if args.compare:
        return compare(args.compare)
    if not 1 <= args.limit <= 20:
        ap.error('--limit must be 1-20')
    return run(args)


if __name__ == '__main__':
    sys.exit(main())
