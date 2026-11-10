#!/usr/bin/env python3
"""Black-box MCP conformance checks for the Christian Open Library endpoint (DESIGN.md section 9).

    cd ~/library-connector
    .venv/bin/python tests/mcp_conformance.py URL
    .venv/bin/python tests/mcp_conformance.py --url-file ~/.config/library-connector/connector-url
    COL_MCP_URL='https://host/wp-json/col/v1/mcp/KEY' .venv/bin/python tests/mcp_conformance.py

The URL holds the secret key, so prefer --url-file or COL_MCP_URL (a URL argument is visible in
the process list). The key is never printed: it appears as *** in every message.

What it checks, over plain HTTP (requests) and with the official MCP Python SDK:
  http    wrong key -> 404 {}; GET/DELETE -> 405 with Allow: POST; 415 for a wrong Content-Type;
          413 over 64 KB; 403 for a foreign Origin; 400 for ?_jsonp; no CORS or session headers
  legacy  initialize version negotiation (echo / counter-offer), 202 for notifications, ping,
          tools/list schema checks, tools/call (all six tools, both search modes), isError for
          bad arguments, -32700 / -32600 / -32601 / -32602, batches (2025-03-26 only)
  modern  2026-07-28 requests: server/discover, tools/list, tools/call with the required
          MCP-Protocol-Version / Mcp-Method / Mcp-Name headers and params._meta; -32020 header
          mismatches, -32022 unsupported version, -32602 missing _meta, 404 -32601 unknown
          methods, subscriptions/listen
  sdk     the official MCP SDK (pip package "mcp") connects in legacy mode (initialize) and in
          auto mode (server/discover, expected to pick 2026-07-28), lists the tools and calls
          each tool once

About 80 HTTP requests and 30 tool calls, 4 of them meaning searches (Voyage) on a real server.
Exit status: 0 all checks passed, 1 some failed, 2 usage or connection error.
"""
from __future__ import annotations

import argparse
import asyncio
import base64
import json
import logging
import os
import re
import secrets
import sys
import time
from pathlib import Path
from urllib.parse import urlsplit, urlunsplit

import requests

LEGACY_VERSIONS = ('2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05')
MODERN_VERSION = '2026-07-28'
EXPECTED_TOOLS = ('search_library', 'read_passage', 'find_works', 'read_scripture', 'find_scripture_citations',
                  'define_term')
TOOL_NAME_RE = re.compile(r'^[a-z0-9_]{1,64}$')
KEY_RE = re.compile(r'/mcp/([A-Za-z0-9_-]{20,128})(?=$|[/?&#])')
REF_RE = re.compile(r'\bP(\d{6,12})\b')
MAX_RESULT_CHARS = 150_000          # claude.ai's tool result limit
USER_AGENT = 'col-library-conformance/1.0'
META_VERSION = 'io.modelcontextprotocol/protocolVersion'
META_CAPS = 'io.modelcontextprotocol/clientCapabilities'
META_CLIENT = 'io.modelcontextprotocol/clientInfo'
META_SERVER = 'io.modelcontextprotocol/serverInfo'
CLIENT_INFO = {'name': 'col-conformance', 'version': '1.0'}


class Reply:
    """One HTTP response: status, headers (lower-case names), body text and parsed JSON (or None)."""

    def __init__(self, r: requests.Response, seconds: float):
        self.status = r.status_code
        self.headers = {k.lower(): v for k, v in r.headers.items()}
        self.text = r.text
        self.seconds = seconds
        try:
            self.json = r.json() if self.text.strip() else None
        except ValueError:
            self.json = None

    @property
    def ctype(self) -> str:
        return self.headers.get('content-type', '').split(';')[0].strip().lower()

    def error_code(self):
        return self.json.get('error', {}).get('code') if isinstance(self.json, dict) else None

    def result(self) -> dict:
        r = self.json.get('result') if isinstance(self.json, dict) else None
        return r if isinstance(r, dict) else {}


class Conformance:
    def __init__(self, url: str, timeout: float, verbose: bool):
        m = KEY_RE.search(url)
        if not m:
            raise SystemExit('conformance: the URL must contain /mcp/<key> (the connector URL)')
        self.url = url
        self.key = m.group(1)
        self.timeout = timeout
        self.verbose = verbose
        self.http = requests.Session()
        self.http.headers['User-Agent'] = USER_AGENT
        self.passed = 0
        self.failed: list[str] = []
        self.next_id = 1
        self.section = ''
        self.first_ref: str | None = None

    # ------------------------------------------------------------------ output and bookkeeping

    def redact(self, text: object) -> str:
        """Text with the URL key replaced by ***, safe to print."""
        return str(text).replace(self.key, '***')

    def check(self, name: str, ok: bool, detail: object = '') -> bool:
        label = f'{self.section}: {name}'
        if ok:
            self.passed += 1
            print(f'PASS  {label}' + (f'  ({self.redact(detail)})' if detail and self.verbose else ''))
        else:
            self.failed.append(label)
            print(f'FAIL  {label}' + (f': {self.redact(detail)}' if detail else ''))
        return ok

    def info(self, text: str):
        print(f'      {self.redact(text)}')

    # ------------------------------------------------------------------------------ HTTP layer

    def send(self, method: str = 'POST', body: object = None, raw: bytes | str | None = None,
             headers: dict | None = None, url: str | None = None) -> Reply:
        h = {'Accept': 'application/json, text/event-stream'}
        if body is not None or raw is not None:
            h['Content-Type'] = 'application/json'
        h.update(headers or {})
        h = {k: v for k, v in h.items() if v is not None}   # None removes a default header
        data = raw if raw is not None else (json.dumps(body) if body is not None else None)
        t0 = time.monotonic()
        # Never follow redirects: Claude does not follow them across hosts, and a POST must not
        # silently turn into a GET. A redirect is reported as a failure by the caller.
        r = self.http.request(method, url or self.url, data=data, headers=h, timeout=self.timeout,
                              allow_redirects=False)
        reply = Reply(r, time.monotonic() - t0)
        if 300 <= reply.status < 400:
            loc = reply.headers.get('location', '?')
            raise ConnectionError(f'HTTP {reply.status} redirect to {loc}: use the final URL')
        return reply

    def rid(self) -> int:
        self.next_id += 1
        return self.next_id

    def legacy(self, method: str, params: dict | None = None, notify: bool = False,
               version: str | None = '2025-11-25', headers: dict | None = None) -> Reply:
        msg = {'jsonrpc': '2.0', 'method': method}
        if not notify:
            msg['id'] = self.rid()
        if params is not None:
            msg['params'] = params
        h = {'MCP-Protocol-Version': version} if version else {}
        h.update(headers or {})
        return self.send(body=msg, headers=h)

    def modern(self, method: str, params: dict | None = None, notify: bool = False,
               headers: dict | None = None, meta: dict | None = None, version: str = MODERN_VERSION,
               name_header: str | None = None) -> Reply:
        """A 2026-07-28 request with _meta and the required headers (override any of them)."""
        p = dict(params or {})
        m = {META_VERSION: version, META_CAPS: {}, META_CLIENT: CLIENT_INFO}
        if meta is not None:
            m = meta
        p['_meta'] = m
        msg = {'jsonrpc': '2.0', 'method': method, 'params': p}
        if not notify:
            msg['id'] = self.rid()
        h = {'MCP-Protocol-Version': version, 'Mcp-Method': method}
        if method == 'tools/call':
            h['Mcp-Name'] = name_header if name_header is not None else str(p.get('name', ''))
        h.update(headers or {})
        return self.send(body=msg, headers=h)

    # --------------------------------------------------------------------------- shared checks

    def expect_error(self, name: str, reply: Reply, code: int, status: int | tuple | None = None,
                     modern: bool = False):
        statuses = (status,) if isinstance(status, int) else status
        ok = reply.error_code() == code and (statuses is None or reply.status in statuses)
        detail = f'HTTP {reply.status}, body {reply.text[:200]!r}'
        if ok and modern and isinstance(reply.json, dict) and 'id' in reply.json and reply.json['id'] is None:
            ok, detail = False, 'modern error responses must not carry "id": null'
        self.check(name, ok, detail)

    def expect_accepted(self, name: str, reply: Reply):
        self.check(name, reply.status == 202 and reply.text == '', f'HTTP {reply.status}, body {reply.text[:120]!r}')

    def check_tool_result(self, name: str, reply: Reply, want_error: bool = False, modern: bool = False,
                          must_contain: str | None = None) -> str:
        """Checks a tools/call response; returns the result text ('' on failure)."""
        res = reply.result()
        content = res.get('content')
        text = ''
        problems = []
        if reply.status != 200 or 'result' not in (reply.json or {}):
            problems.append(f'HTTP {reply.status}, body {reply.text[:200]!r}')
        elif not isinstance(content, list) or not content:
            problems.append('result.content is not a non-empty list')
        else:
            for block in content:
                if not isinstance(block, dict) or block.get('type') != 'text' or not isinstance(block.get('text'), str):
                    problems.append(f'content block is not {{type: "text", text: str}}: {str(block)[:100]}')
                    break
            text = '\n'.join(b.get('text', '') for b in content if isinstance(b, dict))
            if res.get('isError') is not want_error:
                problems.append(f'isError is {res.get("isError")!r}, expected {want_error}: {text[:200]!r}')
            if len(text) > MAX_RESULT_CHARS:
                problems.append(f'result has {len(text)} characters (claude.ai keeps {MAX_RESULT_CHARS})')
            if must_contain and must_contain not in text:
                problems.append(f'text does not contain {must_contain!r}: {text[:200]!r}')
            if modern and res.get('resultType') != 'complete':
                problems.append(f'resultType is {res.get("resultType")!r}, expected "complete"')
        self.check(name, not problems, '; '.join(problems) or f'{len(text)} chars, {reply.seconds:.1f}s')
        return text if not problems else ''

    def check_tools_list(self, label: str, reply: Reply) -> list[dict]:
        tools = reply.result().get('tools')
        if not self.check(f'{label}: returns a list of tools', reply.status == 200 and isinstance(tools, list) and tools,
                          f'HTTP {reply.status}, body {reply.text[:200]!r}'):
            return []
        names = [t.get('name') for t in tools if isinstance(t, dict)]
        self.check(f'{label}: has {", ".join(EXPECTED_TOOLS)}', all(n in names for n in EXPECTED_TOOLS), names)
        self.check(f'{label}: tool names are unique', len(names) == len(set(names)), names)
        self.check(f'{label}: no nextCursor (one page)', 'nextCursor' not in reply.result())
        try:
            from jsonschema import Draft202012Validator
        except ImportError:
            Draft202012Validator = None
        for t in tools:
            problems = tool_problems(t, Draft202012Validator)
            self.check(f'{label}: schema of {t.get("name")!r}', not problems, '; '.join(problems))
        return tools

    # ---------------------------------------------------------------------------- the sections

    def run_http(self):
        self.section = 'http'
        wrong = secrets.token_urlsafe(96)[:len(self.key)]
        if wrong == self.key:
            wrong = wrong[::-1]
        r = self.send(body={'jsonrpc': '2.0', 'id': 1, 'method': 'ping'}, url=self.url.replace(self.key, wrong))
        self.check('wrong key -> 404 with body {}', r.status == 404 and r.text.strip() == '{}',
                   f'HTTP {r.status}, body {r.text[:100]!r}')
        for verb in ('GET', 'DELETE'):
            r = self.send(method=verb, headers={'Accept': 'text/event-stream'})
            allow = [a.strip().upper() for a in r.headers.get('allow', '').split(',')]
            self.check(f'{verb} -> 405 with Allow: POST', r.status == 405 and allow == ['POST'],
                       f'HTTP {r.status}, Allow {r.headers.get("allow")!r}')
        r = self.send(raw=json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'ping'}),
                      headers={'Content-Type': 'text/plain'})
        self.check('Content-Type text/plain -> 415', r.status == 415, f'HTTP {r.status}')
        pad = 'x' * 70_000
        r = self.send(body={'jsonrpc': '2.0', 'id': 1, 'method': 'ping', 'params': {'pad': pad}})
        self.check('body over 64 KB -> 413', r.status == 413, f'HTTP {r.status}')
        r = self.legacy('ping', headers={'Origin': 'https://evil.example'})
        self.check('foreign Origin -> 403', r.status == 403, f'HTTP {r.status}')
        r = self.legacy('ping', headers={'Origin': 'https://claude.ai'})
        self.check('Origin https://claude.ai is accepted', r.status == 200 and r.result() == {},
                   f'HTTP {r.status}, body {r.text[:100]!r}')
        parts = urlsplit(self.url)
        query = parts.query + ('&' if parts.query else '') + '_jsonp=cb'
        r = self.send(body={'jsonrpc': '2.0', 'id': 1, 'method': 'ping'},
                      headers={'MCP-Protocol-Version': '2025-11-25'},
                      url=urlunsplit(parts._replace(query=query)))
        self.check('?_jsonp -> 400 (WordPress must not wrap the output)', r.status == 400, f'HTTP {r.status}')
        r = self.legacy('initialize', {'protocolVersion': '2025-11-25', 'capabilities': {}, 'clientInfo': CLIENT_INFO},
                        version=None, headers={'Origin': None})
        bad = [h for h in r.headers if h.startswith('access-control-') or h == 'mcp-session-id']
        self.check('no CORS or Mcp-Session-Id headers', not bad, bad)
        self.check('JSON responses: Content-Type application/json, Cache-Control no-store',
                   r.ctype == 'application/json' and 'no-store' in r.headers.get('cache-control', ''),
                   f'{r.ctype!r}, cache-control {r.headers.get("cache-control")!r}')

    def run_legacy(self):
        self.section = 'legacy'
        params = {'protocolVersion': '2025-11-25', 'capabilities': {}, 'clientInfo': CLIENT_INFO}
        r = self.legacy('initialize', params, version=None)
        res = r.result()
        info = res.get('serverInfo') or {}
        self.check('initialize 2025-11-25 -> 200 with the same version',
                   r.status == 200 and res.get('protocolVersion') == '2025-11-25', f'HTTP {r.status}, {r.text[:200]!r}')
        self.check('initialize: capabilities.tools declared, listChanged false',
                   isinstance(res.get('capabilities', {}).get('tools'), dict)
                   and res['capabilities']['tools'].get('listChanged') in (False, None), res.get('capabilities'))
        self.check('initialize: serverInfo has name and version',
                   isinstance(info.get('name'), str) and isinstance(info.get('version'), str), info)
        self.check('initialize: instructions present', isinstance(res.get('instructions'), str) and res['instructions'])
        self.check('initialize: no Mcp-Session-Id minted', 'mcp-session-id' not in r.headers)
        for v in LEGACY_VERSIONS[1:]:
            r = self.legacy('initialize', dict(params, protocolVersion=v), version=None)
            self.check(f'initialize {v} -> echoed', r.result().get('protocolVersion') == v, r.text[:200])
        r = self.legacy('initialize', dict(params, protocolVersion='1999-01-01'), version=None)
        self.check('initialize with an unknown version -> counter-offer 2025-11-25',
                   r.status == 200 and r.result().get('protocolVersion') == '2025-11-25', r.text[:200])
        r = self.legacy('initialize', {'capabilities': {}, 'clientInfo': CLIENT_INFO}, version=None)
        self.expect_error('initialize without protocolVersion -> -32602', r, -32602)

        self.expect_accepted('notifications/initialized -> 202, empty body',
                             self.legacy('notifications/initialized', notify=True))
        self.expect_accepted('other notifications -> 202',
                             self.legacy('notifications/cancelled', {'requestId': 99}, notify=True))
        self.expect_accepted('a JSON-RPC response from the client -> 202',
                             self.send(body={'jsonrpc': '2.0', 'id': 5, 'result': {}},
                                       headers={'MCP-Protocol-Version': '2025-11-25'}))
        r = self.legacy('ping')
        self.check('ping -> {}', r.status == 200 and isinstance(r.json, dict) and r.json.get('result') == {},
                   r.text[:200])
        r = self.legacy('ping', version=None)
        self.check('no MCP-Protocol-Version header -> accepted (2025-03-26 assumed)', r.status == 200, r.text[:200])

        self.check_tools_list('tools/list', self.legacy('tools/list'))
        self.call_tools(modern=False)

        r = self.legacy('tools/call', {'name': 'no_such_tool', 'arguments': {}})
        self.expect_error('unknown tool -> -32602', r, -32602, 200)
        r = self.legacy('resources/list')
        self.expect_error('unknown method (resources/list) -> -32601', r, -32601)
        r = self.send(raw='{"jsonrpc": "2.0", "id": 1, "method": ', headers={'MCP-Protocol-Version': '2025-11-25'})
        self.expect_error('malformed JSON -> 400, -32700', r, -32700, 400)
        self.check('parse error carries "id": null', isinstance(r.json, dict) and 'id' in r.json and r.json['id'] is None,
                   r.text[:200])
        deep = '{"a":' * 40 + '1' + '}' * 40
        r = self.send(raw='{"jsonrpc":"2.0","id":1,"method":"ping","params":' + deep + '}',
                      headers={'MCP-Protocol-Version': '2025-11-25'})
        self.check('JSON nested 40 deep -> rejected with 400', r.status == 400 and r.error_code() in (-32700, -32600),
                   f'HTTP {r.status}, {r.text[:150]!r}')
        r = self.send(body={'jsonrpc': '1.0', 'id': 1, 'method': 'ping'}, headers={'MCP-Protocol-Version': '2025-11-25'})
        self.expect_error('jsonrpc "1.0" -> -32600', r, -32600)
        r = self.send(body={'jsonrpc': '2.0', 'id': 1}, headers={'MCP-Protocol-Version': '2025-11-25'})
        self.expect_error('no method -> -32600', r, -32600)
        r = self.send(body={'jsonrpc': '2.0', 'id': None, 'method': 'ping'}, headers={'MCP-Protocol-Version': '2025-11-25'})
        self.expect_error('id null -> -32600', r, -32600)
        r = self.legacy('tools/call', params={'name': 'find_works', 'arguments': 'not an object'})
        self.expect_error('arguments not an object -> -32602', r, -32602)
        r = self.legacy('tools/list', version='1999-01-01')
        self.check('unsupported MCP-Protocol-Version header -> 400', r.status == 400, f'HTTP {r.status}')
        r = self.send(body={'jsonrpc': '2.0', 'id': 'abc-1', 'method': 'ping'},
                      headers={'MCP-Protocol-Version': '2025-11-25'})
        self.check('string id is echoed', isinstance(r.json, dict) and r.json.get('id') == 'abc-1', r.text[:200])

        batch = [{'jsonrpc': '2.0', 'id': 101, 'method': 'ping'},
                 {'jsonrpc': '2.0', 'method': 'notifications/initialized'},
                 {'jsonrpc': '2.0', 'id': 102, 'method': 'tools/list'}]
        r = self.send(body=batch)   # no header: 2025-03-26, the only version with batches
        ids = sorted(m.get('id') for m in r.json if isinstance(m, dict)) if isinstance(r.json, list) else None
        self.check('batch (2025-03-26) -> array of 2 responses', r.status == 200 and ids == [101, 102],
                   f'HTTP {r.status}, ids {ids}')
        r = self.send(body=batch, headers={'MCP-Protocol-Version': '2025-11-25'})
        self.expect_error('batch in 2025-11-25 -> 400, -32600', r, -32600, 400)
        r = self.send(body=[{'jsonrpc': '2.0', 'method': 'notifications/initialized'}])
        self.expect_accepted('batch of notifications only -> 202', r)

    def run_modern(self):
        self.section = 'modern'
        r = self.modern('server/discover')
        res = r.result()
        self.check('server/discover -> 200, resultType complete', r.status == 200 and res.get('resultType') == 'complete',
                   f'HTTP {r.status}, {r.text[:200]!r}')
        sv = res.get('supportedVersions')
        self.check('server/discover: supportedVersions has 2026-07-28 and the legacy versions',
                   isinstance(sv, list) and MODERN_VERSION in sv and all(v in sv for v in LEGACY_VERSIONS), sv)
        self.check('server/discover: capabilities.tools declared',
                   isinstance((res.get('capabilities') or {}).get('tools'), dict), res.get('capabilities'))
        self.check('server/discover: ttlMs and cacheScope',
                   isinstance(res.get('ttlMs'), int) and res['ttlMs'] >= 0 and res.get('cacheScope') in ('public', 'private'),
                   {k: res.get(k) for k in ('ttlMs', 'cacheScope')})
        si = (res.get('_meta') or {}).get(META_SERVER) or {}
        self.check('results carry _meta serverInfo', isinstance(si.get('name'), str), res.get('_meta'))
        self.check('no Mcp-Session-Id minted', 'mcp-session-id' not in r.headers)

        r = self.modern('tools/list')
        res = r.result()
        self.check('tools/list: resultType complete, ttlMs, cacheScope',
                   res.get('resultType') == 'complete' and isinstance(res.get('ttlMs'), int)
                   and res.get('cacheScope') in ('public', 'private'), {k: res.get(k) for k in ('resultType', 'ttlMs', 'cacheScope')})
        self.check_tools_list('tools/list', r)
        self.call_tools(modern=True)

        call = {'name': 'find_works', 'arguments': {'limit': 1}}
        r = self.modern('tools/call', call, headers={'Mcp-Method': None})
        self.expect_error('missing Mcp-Method header -> 400, -32020', r, -32020, 400, modern=True)
        r = self.modern('tools/call', call, headers={'Mcp-Method': 'tools/list'})
        self.expect_error('Mcp-Method not equal to the method -> 400, -32020', r, -32020, 400, modern=True)
        r = self.modern('tools/call', call, headers={'Mcp-Name': None})
        self.expect_error('tools/call without Mcp-Name -> 400, -32020', r, -32020, 400, modern=True)
        r = self.modern('tools/call', call, name_header='search_library')
        self.expect_error('Mcp-Name not equal to params.name -> 400, -32020', r, -32020, 400, modern=True)
        meta = {META_VERSION: '2025-11-25', META_CAPS: {}, META_CLIENT: CLIENT_INFO}
        r = self.modern('tools/list', meta=meta)
        self.expect_error('MCP-Protocol-Version header not equal to _meta version -> 400, -32020', r, -32020, 400,
                          modern=True)
        r = self.modern('tools/list', version='2099-01-01')
        self.expect_error('unsupported version -> 400, -32022', r, -32022, 400, modern=True)
        data = (r.json or {}).get('error', {}).get('data') or {}
        self.check('-32022 data names the supported and requested versions',
                   MODERN_VERSION in (data.get('supported') or []) and data.get('requested') == '2099-01-01', data)
        r = self.modern('tools/list', meta={META_VERSION: MODERN_VERSION, META_CLIENT: CLIENT_INFO})
        self.expect_error('_meta without clientCapabilities -> 400, -32602', r, -32602, 400, modern=True)
        r = self.send(body={'jsonrpc': '2.0', 'id': self.rid(), 'method': 'tools/list'},
                      headers={'MCP-Protocol-Version': MODERN_VERSION, 'Mcp-Method': 'tools/list'})
        self.expect_error('no params._meta -> 400, -32602', r, -32602, 400, modern=True)
        r = self.modern('resources/list')
        self.expect_error('unknown method -> 404, -32601', r, -32601, 404, modern=True)
        r = self.modern('ping')
        self.expect_error('ping (removed in 2026-07-28) -> 404, -32601', r, -32601, 404, modern=True)
        r = self.modern('tools/call', {'name': 'no_such_tool', 'arguments': {}})
        self.expect_error('unknown tool -> -32602', r, -32602, 200, modern=True)
        self.expect_accepted('notification -> 202', self.modern('notifications/cancelled', {'requestId': 1}, notify=True))
        r = self.send(raw='{"jsonrpc": "2.0", "id": 1, ',
                      headers={'MCP-Protocol-Version': MODERN_VERSION, 'Mcp-Method': 'tools/list'})
        self.expect_error('malformed JSON -> 400, -32700 (no null id)', r, -32700, 400, modern=True)
        r = self.send(body=[{'jsonrpc': '2.0', 'id': 1, 'method': 'tools/list'}],
                      headers={'MCP-Protocol-Version': MODERN_VERSION, 'Mcp-Method': 'tools/list'})
        self.check('batch -> 400', r.status == 400, f'HTTP {r.status}')

        r = self.modern('subscriptions/listen', {'notifications': {}})
        events = sse_messages(r.text) if r.ctype == 'text/event-stream' else []
        first = events[0] if events else {}
        last = events[-1] if events else {}
        self.check('subscriptions/listen -> event stream: acknowledged, then a complete result',
                   r.status == 200 and len(events) >= 2
                   and first.get('method') == 'notifications/subscriptions/acknowledged'
                   and (last.get('result') or {}).get('resultType') == 'complete',
                   f'HTTP {r.status}, {r.ctype}, {r.text[:200]!r}')

    def call_tools(self, modern: bool):
        """search_library (best and exact), read_passage, find_works, the three cross-reference tools and
        bad-argument calls."""
        def call(name: str, args: dict, name_header: str | None = None) -> Reply:
            params = {'name': name, 'arguments': args}
            if modern:
                return self.modern('tools/call', params, name_header=name_header)
            return self.legacy('tools/call', params)

        text = self.check_tool_result('tools/call search_library (best)',
                                      call('search_library', {'query': 'the grace of God in salvation', 'limit': 3}),
                                      modern=modern, must_contain='[1] P')
        m = REF_RE.search(text)
        if m:
            self.first_ref = 'P' + m.group(1)
        self.check_tool_result('tools/call search_library (exact)',
                               call('search_library', {'query': 'grace of god', 'mode': 'exact', 'limit': 2}),
                               modern=modern, must_contain='[1] P')
        if self.first_ref:
            # In modern mode, also send Mcp-Name in its base64 form.
            enc = '=?base64?' + base64.b64encode(b'read_passage').decode() + '?=' if modern else None
            self.check_tool_result('tools/call read_passage' + (' (Mcp-Name in base64)' if modern else ''),
                                   call('read_passage', {'ref': self.first_ref, 'before': 0, 'after': 1}, enc),
                                   modern=modern, must_contain=self.first_ref)
        else:
            self.check('tools/call read_passage', False, 'no ref found in the search result to read')
        self.check_tool_result('tools/call find_works', call('find_works', {'limit': 3}), modern=modern,
                               must_contain='work_id')
        self.check_tool_result('tools/call read_scripture', call('read_scripture', {'reference': 'John 3:16'}),
                               modern=modern, must_contain='John 3:16 (BSB)\n16 For God so loved')
        self.check_tool_result('tools/call find_scripture_citations',
                               call('find_scripture_citations', {'reference': 'Rom 8:28', 'limit': 2}),
                               modern=modern, must_contain='Citations of Romans 8:28 in the library')
        self.check_tool_result('tools/call define_term', call('define_term', {'term': 'grace', 'limit': 3}),
                               modern=modern, must_contain='Dictionary entries for "grace"')
        self.check_tool_result('unknown Bible book -> isError result with close matches',
                               call('read_scripture', {'reference': 'Phillipians 4:13'}), want_error=True,
                               modern=modern, must_contain='Did you mean Philippians?')
        self.check_tool_result('bad argument (limit 99) -> isError result, not a protocol error',
                               call('search_library', {'query': 'grace', 'limit': 99}), want_error=True, modern=modern)
        self.check_tool_result('unknown argument -> isError result',
                               call('find_works', {'colour': 'blue'}), want_error=True, modern=modern)

    def run_sdk(self):
        self.section = 'sdk'
        try:
            import mcp  # noqa: F401
        except ImportError:
            self.check('official MCP SDK installed (pip install mcp)', False, 'import mcp failed')
            return
        # The SDK and httpx log request URLs (with the key) at INFO; keep them quiet.
        for name in ('mcp', 'httpx', 'httpx2', 'httpcore'):
            logging.getLogger(name).setLevel(logging.ERROR)
        for mode, want in (('legacy', LEGACY_VERSIONS[0]), ('auto', MODERN_VERSION)):
            try:
                asyncio.run(self.sdk_session(mode, want))
            except BaseException as e:   # noqa: BLE001 - report anything, including exception groups
                if isinstance(e, KeyboardInterrupt):
                    raise
                self.check(f'mode {mode}: connect, list and call', False, describe(e))

    async def sdk_session(self, mode: str, want_version: str):
        from mcp import Client
        from mcp_types import Implementation
        label = f'mode {mode}'
        async with Client(self.url, mode=mode, read_timeout_seconds=self.timeout,
                          client_info=Implementation(name='col-conformance-sdk', version='1.0')) as client:
            self.check(f'{label}: connected with protocol {want_version}', client.protocol_version == want_version,
                       client.protocol_version)
            listing = await client.list_tools()
            names = [t.name for t in listing.tools]
            self.check(f'{label}: list_tools has the six tools', all(n in names for n in EXPECTED_TOOLS), names)
            ref = None
            for name, args in (('search_library', {'query': 'faith and repentance', 'limit': 2}),
                               ('read_passage', None),
                               ('find_works', {'limit': 2}),
                               ('read_scripture', {'reference': 'Ps 23:1', 'translation': 'kjv'}),
                               ('find_scripture_citations', {'reference': 'John 3:16', 'limit': 2}),
                               ('define_term', {'term': 'conversation', 'limit': 2})):
                if name == 'read_passage':
                    if ref is None:
                        self.check(f'{label}: call_tool read_passage', False, 'no ref from the search to read')
                        continue
                    args = {'ref': ref, 'before': 0, 'after': 0}
                result = await client.call_tool(name, args)
                text = '\n'.join(getattr(c, 'text', '') for c in result.content)
                self.check(f'{label}: call_tool {name}', not result.is_error and text != '',
                           f'isError {result.is_error}: {text[:200]!r}')
                if name == 'search_library':
                    m = REF_RE.search(text)
                    ref = 'P' + m.group(1) if m else None

    def run(self, sections: list[str]):
        t0 = time.monotonic()
        try:
            r = self.legacy('ping')
        except (requests.RequestException, ConnectionError) as e:
            print(f'conformance: cannot reach the endpoint: {self.redact(describe(e))}', file=sys.stderr)
            return 2
        if r.status == 404 and r.text.strip() == '{}':
            print('conformance: the endpoint answers 404 {} to the right URL: wrong key, or the server config is '
                  'missing or invalid (see PHP\'s error log)', file=sys.stderr)
            return 2
        for section in sections:
            try:
                getattr(self, f'run_{section}')()
            except (requests.RequestException, ConnectionError) as e:
                self.check('request failed', False, describe(e))
        total = self.passed + len(self.failed)
        print(f'\n{self.passed}/{total} checks passed in {time.monotonic() - t0:.0f} s')
        if self.failed:
            print('failed:\n  ' + '\n  '.join(self.failed))
        return 0 if not self.failed else 1


def tool_problems(t: dict, validator) -> list[str]:
    """What is wrong with one tool definition (PLUGIN-SPEC.md "Tools"; claude.ai directory rules)."""
    p = []
    name = t.get('name')
    if not isinstance(name, str) or not TOOL_NAME_RE.match(name):
        p.append(f'name {name!r} is not lower snake case of at most 64 characters')
    if not (isinstance(t.get('title'), str) and t['title'].strip()):
        p.append('no title')
    if not (isinstance(t.get('description'), str) and len(t['description']) >= 40):
        p.append('no description (or under 40 characters)')
    ann = t.get('annotations') or {}
    if not (isinstance(ann.get('title'), str) and ann['title'].strip()):
        p.append('no annotations.title')
    want = {'readOnlyHint': True, 'destructiveHint': False, 'idempotentHint': True, 'openWorldHint': False}
    for k, v in want.items():
        if ann.get(k) is not v:
            p.append(f'annotations.{k} is {ann.get(k)!r}, expected {v}')
    s = t.get('inputSchema')
    if not isinstance(s, dict) or s.get('type') != 'object':
        p.append('inputSchema is not {"type": "object", ...}')
        return p
    if s.get('additionalProperties') is not False:
        p.append('inputSchema.additionalProperties is not false')
    props = s.get('properties')
    if not isinstance(props, dict) or not props:
        p.append('inputSchema.properties missing or empty')
        props = {}
    for pname, ps in props.items():
        if not isinstance(ps, dict) or not ps.get('description') or not ps.get('type'):
            p.append(f'property {pname!r} lacks a type or description')
    for req in s.get('required', []):
        if req not in props:
            p.append(f'required property {req!r} is not defined')
    if validator is not None:
        try:
            validator.check_schema(s)
        except Exception as e:   # noqa: BLE001 - jsonschema.SchemaError
            p.append(f'inputSchema is not valid JSON Schema: {str(e).splitlines()[0][:150]}')
    return p


def sse_messages(text: str) -> list[dict]:
    """The JSON messages in a text/event-stream body (data: lines of each event)."""
    out = []
    for event in re.split(r'\r?\n\r?\n', text):
        data = '\n'.join(line[5:].lstrip(' ') for line in event.splitlines() if line.startswith('data:'))
        if data:
            try:
                out.append(json.loads(data))
            except ValueError:
                out.append({'unparsed': data[:100]})
    return out


def describe(e: BaseException) -> str:
    """One line for an exception; exception groups (anyio) are unwrapped to their first leaf."""
    while isinstance(e, BaseExceptionGroup) and e.exceptions:
        e = e.exceptions[0]
    return f'{type(e).__name__}: {str(e).splitlines()[0][:300] if str(e) else ""}'


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description='Black-box MCP conformance checks (see the module docstring).')
    ap.add_argument('url', nargs='?', help='connector URL (contains the secret key; prefer --url-file)')
    ap.add_argument('--url-file', type=Path, help='file holding the connector URL')
    ap.add_argument('--only', default='http,legacy,modern,sdk',
                    help='comma-separated sections to run: http, legacy, modern, sdk (default: all)')
    ap.add_argument('--timeout', type=float, default=60.0, help='seconds per request (default 60)')
    ap.add_argument('--verbose', action='store_true', help='print details of passing checks too')
    args = ap.parse_args(argv)
    url = args.url
    if args.url_file:
        url = args.url_file.expanduser().read_text(encoding='utf-8').strip()
    url = url or os.environ.get('COL_MCP_URL', '').strip()
    if not url:
        ap.error('give the connector URL, --url-file FILE, or set COL_MCP_URL')
    if urlsplit(url).scheme not in ('http', 'https'):
        ap.error('the URL must start with https:// (or http:// for a local test server)')
    sections = [s.strip() for s in args.only.split(',') if s.strip()]
    unknown = [s for s in sections if s not in ('http', 'legacy', 'modern', 'sdk')]
    if unknown:
        ap.error(f'unknown section(s): {", ".join(unknown)}')
    return Conformance(url, args.timeout, args.verbose).run(sections)


if __name__ == '__main__':
    sys.exit(main())
