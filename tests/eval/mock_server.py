#!/usr/bin/env python3
"""MOCK MCP endpoint for testing run_eval.py without PHP. Keyword search only.

Serves POST http://127.0.0.1:PORT/wp-json/col/v1/mcp/<key> from a build's library.sqlite:
legacy initialize, notifications (202), ping, tools/list and tools/call search_library, with
results in the plugin's text format (PLUGIN-SPEC.md "Result text format"), including
"Also in:" lines for near-duplicate passages. Ranking is plain FTS5 bm25: no vectors, no
rerank, so its numbers are a rough keyword baseline, NOT the plugin's quality.

    .venv/bin/python tests/eval/mock_server.py --db data/build/LATEST-sample/library.sqlite
    # prints the URL to use (key is random unless --key is given); Ctrl-C to stop
"""
from __future__ import annotations

import argparse
import hmac
import json
import os
import re
import secrets
import sqlite3
import threading
import unicodedata
import zlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

VERSIONS = ('2025-11-25', '2025-06-18', '2025-03-26')
STOP = set('a an and are as at be but by for from has have he her his i in is it its me my not of '
           'on or our she so that the their them they this to was we what when where which who why '
           'will with you your thee thou thy'.split())
PATH_RE = re.compile(r'^/wp-json/col/v1/mcp/([A-Za-z0-9_-]{20,128})$')


class Library:
    def __init__(self, db_path: str, no_lock: bool = False):
        self.uri = f'file:{db_path}?mode=ro' + ('&immutable=1' if no_lock else '')
        self.local = threading.local()

    @property
    def db(self) -> sqlite3.Connection:
        if not hasattr(self.local, 'db'):
            self.local.db = sqlite3.connect(self.uri, uri=True)
        return self.local.db

    def tokens(self, query: str) -> list[str]:
        q = unicodedata.normalize('NFKC', query).replace('ſ', 's').lower()
        out = []
        for t in re.findall(r"[^\W_]+(?:'[^\W_]+)*", q):
            row = self.db.execute('SELECT standard FROM spelling WHERE variant = ?', (t,)).fetchone()
            out.append(row[0] if row else t)
        return out

    def search(self, args: dict) -> str:
        query = str(args.get('query', '')).strip()
        if not query:
            return ''
        mode = args.get('mode', 'best')
        limit = max(1, min(int(args.get('limit', 8)), 20))
        offset = max(0, int(args.get('offset', 0))) if mode == 'exact' else 0
        where, params = [], []
        if args.get('author'):
            where.append('(lower(w.author) LIKE ? OR lower(w.author_slug) LIKE ?)')
            params += ['%' + args['author'].lower() + '%'] * 2
        if args.get('collection'):
            where.append('w.collection = ?')
            params.append(args['collection'])
        if args.get('work_id'):
            where.append('w.id = ?')
            params.append(int(args['work_id']))
        if args.get('year_from') is not None or args.get('year_to') is not None:
            where.append('w.year IS NOT NULL AND w.year BETWEEN ? AND ?')
            params += [int(args.get('year_from', -9999)), int(args.get('year_to', 9999))]
        extra = (' AND ' + ' AND '.join(where)) if where else ''
        sql = ('SELECT p.id FROM passages_fts f JOIN passages p ON p.id = f.rowid '
               'JOIN works w ON w.id = p.work_id WHERE passages_fts MATCH ?' + extra)
        toks = self.tokens(query)
        quote = lambda t: '"' + t.replace('"', '""') + '"'
        if mode == 'exact':
            match = '{body} : ' + quote(' '.join(toks))
            ids = [r[0] for r in self.db.execute(sql + ' ORDER BY p.id LIMIT ? OFFSET ?',
                                                 [match] + params + [limit, offset])]
        else:
            kw = [t for t in toks if t not in STOP] or toks
            ranked = sql + ' ORDER BY bm25(passages_fts, 1.0, 0.3, 0.6) LIMIT 100'
            ids = [r[0] for r in self.db.execute(ranked, [' '.join(map(quote, kw))] + params)]
            if len(ids) < 30 and len(kw) >= 3:      # relax: drop one token at a time
                for drop in sorted(kw, key=len, reverse=True)[:4]:
                    rest = [t for t in kw if t != drop]
                    more = self.db.execute(ranked, [' '.join(map(quote, rest))] + params)
                    ids += [r[0] for r in more if r[0] not in ids]
        return self.render(query, mode, ids, limit, offset)

    def render(self, query: str, mode: str, ids: list[int], limit: int, offset: int) -> str:
        rows = []
        for pid in ids:
            r = self.db.execute(
                'SELECT p.id, p.text, p.page_start, p.page_end, p.section, w.author, w.title, w.year, '
                'w.source_url FROM passages p JOIN works w ON w.id = p.work_id WHERE p.id = ?',
                (pid,)).fetchone()
            text = zlib.decompress(r[1]).decode('utf-8')
            words = re.findall(r'\w+', text.lower())
            shingles = {' '.join(words[i:i + 5]) for i in range(max(1, len(words) - 4))}
            dup = None
            if mode != 'exact':
                for kept in rows:      # collapse near-duplicates by the same author
                    inter = len(shingles & kept['sh'])
                    if kept['author'] == r[5] and inter / max(1, len(shingles | kept['sh'])) >= 0.5:
                        dup = kept
                        break
            label = f'{r[5]}, *{r[6]}*' + (f' ({r[7]})' if r[7] else '')
            if dup:
                dup['also'].append(f'{label}, P{r[0]}')
                continue
            rows.append({'id': r[0], 'text': text, 'author': r[5], 'label': label, 'sh': shingles,
                         'pages': r[2], 'page_end': r[3], 'section': r[4], 'url': r[8], 'also': []})
            if len(rows) >= limit:
                break
        if not rows:
            return f'No passages found for "{query}".\n\nSearch notes: MOCK server, keyword search only.'
        out = [f'Results {offset + 1}-{offset + len(rows)} for "{query}" (MOCK keyword search)', '']
        for n, x in enumerate(rows, offset + 1):
            head = [f'[{n}] P{x["id"]}', x['label']]
            if x['pages']:
                head.append(f'p. {x["pages"]}' if x['pages'] == x['page_end'] or not x['page_end']
                            else f'pp. {x["pages"]}–{x["page_end"]}')
            if x['section']:
                head.append(x['section'])
            out += [' · '.join(head), x['text'], f'Source: {x["url"]}']
            if x['also']:
                out.append('Also in: ' + '; '.join(x['also']))
            out.append('')
        out.append('Search notes: MOCK server, keyword search only (no meaning search, no rerank).')
        return '\n'.join(out)


TOOL = {
    'name': 'search_library', 'title': 'Search the library (mock)',
    'description': 'MOCK keyword search over a local build.',
    'inputSchema': {'type': 'object', 'additionalProperties': False, 'required': ['query'],
                    'properties': {k: {'description': k} for k in (
                        'query', 'author', 'work_id', 'collection', 'year_from', 'year_to',
                        'mode', 'limit', 'offset')}},
    'annotations': {'title': 'Search the library (mock)', 'readOnlyHint': True},
}


def make_handler(lib: Library, key: str):
    class Handler(BaseHTTPRequestHandler):
        def log_message(self, fmt, *args):     # quiet: never log request paths (they hold the key)
            pass

        def send(self, status: int, obj=None):
            body = b'' if obj is None else json.dumps(obj).encode('utf-8')
            self.send_response(status)
            if obj is not None:
                self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def key_ok(self) -> bool:
            m = PATH_RE.match(self.path)
            return bool(m) and hmac.compare_digest(m.group(1), key)

        def do_GET(self):
            if not self.key_ok():
                return self.send(404, {})
            self.send_response(405)
            self.send_header('Allow', 'POST')
            self.send_header('Content-Length', '0')
            self.end_headers()

        do_DELETE = do_GET

        def do_POST(self):
            if not self.key_ok():
                return self.send(404, {})
            size = int(self.headers.get('Content-Length') or 0)
            if size > 65536:
                return self.send(413, {})
            try:
                msg = json.loads(self.rfile.read(size))
            except ValueError:
                return self.send(400, {'jsonrpc': '2.0', 'id': None,
                                       'error': {'code': -32700, 'message': 'Parse error'}})
            if 'id' not in msg:
                return self.send(202)
            method, params, rid = msg.get('method'), msg.get('params') or {}, msg['id']
            ok = lambda result: self.send(200, {'jsonrpc': '2.0', 'id': rid, 'result': result})
            if method == 'initialize':
                v = params.get('protocolVersion')
                return ok({'protocolVersion': v if v in VERSIONS else VERSIONS[0],
                           'capabilities': {'tools': {'listChanged': False}},
                           'serverInfo': {'name': 'col-library-mock', 'version': '0'}})
            if method == 'ping':
                return ok({})
            if method == 'tools/list':
                return ok({'tools': [TOOL]})
            if method == 'tools/call':
                if params.get('name') != 'search_library':
                    return self.send(200, {'jsonrpc': '2.0', 'id': rid,
                                           'error': {'code': -32602, 'message': 'Unknown tool'}})
                try:
                    text = lib.search(params.get('arguments') or {})
                except (ValueError, TypeError, sqlite3.Error) as e:
                    return ok({'content': [{'type': 'text', 'text': f'Bad arguments or query: {e}'}],
                               'isError': True})
                return ok({'content': [{'type': 'text', 'text': text or 'Empty query.'}],
                           'isError': not text})
            return self.send(200, {'jsonrpc': '2.0', 'id': rid,
                                   'error': {'code': -32601, 'message': 'Method not found'}})

    return Handler


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.split('\n\n')[0])
    ap.add_argument('--db', required=True, help='library.sqlite of a build')
    ap.add_argument('--port', type=int, default=8765)
    ap.add_argument('--key', default=None, help='URL key (default: random)')
    ap.add_argument('--no-lock', action='store_true',
                    help='open the database with immutable=1 (takes no locks, so it never blocks a '
                         'writer); only for a finished build that nothing is writing')
    ap.add_argument('--write-url', help='write the endpoint URL to this file (mode 600) '
                                        'instead of printing it; pass it to run_eval.py --url-file')
    args = ap.parse_args(argv)
    key = args.key or secrets.token_urlsafe(32)
    server = ThreadingHTTPServer(('127.0.0.1', args.port), make_handler(Library(args.db, args.no_lock), key))
    url = f'http://127.0.0.1:{args.port}/wp-json/col/v1/mcp/{key}'
    if args.write_url:
        fd = os.open(args.write_url, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, 'w') as fh:
            fh.write(url + '\n')
        print(f'mock MCP endpoint on 127.0.0.1:{args.port}; URL written to {args.write_url}', flush=True)
    else:
        print(f'mock MCP endpoint: {url}', flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == '__main__':
    main()
