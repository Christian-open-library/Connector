#!/usr/bin/env python3
"""Smoke test of a running connector endpoint, over HTTPS. The URL (with its key) is read from a file,
so it never appears on a command line or in output.

    python3 -I deploy/smoke.py URL_FILE [EXPECTED_REVISION]

Checks initialize (and serverInfo.version ending in +EXPECTED_REVISION if given), tools/list, a meaning
search, an exact search, read_passage on the first result, find_works, read_scripture, check_quote with KNOWN_QUOTES
(quotations whose right answer was confirmed against the library by hand, so a release that gets one wrong fails here),
the website's library search (POST /search: an answer for the website's Origin, 403 for another), and that the retired
public checker (POST /check, off since 2026-11-16) answers 404. Exit 0 if all pass.
"""
import hashlib
import hmac
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

PROTOCOL = '2025-11-25'

# check_quote as AI apps call it: (what it checks, the arguments, the verdict it must give, the author of the work it
# must cite or None). Each answer was confirmed by hand against the library (16 November 2026). Keep each one to a
# result that new books cannot change, and add a line whenever a wrong answer is fixed. (Until 2026-11-16 these ran
# through the website's checker, POST /check, now retired.)
KNOWN_QUOTES = [
    ('word for word (share link: no comma)',
     {'quote': 'Be killing sin or it will be killing you', 'author': 'John Owen'}, 'exact', 'John Owen'),
    ('word for word (punctuation ignored)',
     {'quote': 'Be killing sin, or it will be killing you.', 'author': 'John Owen'}, 'exact', 'John Owen'),
    ('word for word (a catechism\'s "A." is not a word)',
     {'quote': "What is the chief end of man? Man's chief end is to glorify God, and to enjoy him for ever."},
     'exact', 'Westminster Assembly'),
    ('worded differently',
     {'quote': 'Thou hast made us for thyself, and our hearts are restless until they rest in thee',
      'author': 'Augustine'}, 'reworded', 'Augustine'),
    ('worded differently (two words left out)',
     {'quote': 'Resolved, never to lose one moment of time; but improve it the most profitable way I possibly can',
      'author': 'Jonathan Edwards'}, 'reworded', 'Jonathan Edwards'),
    ('by someone else',
     {'quote': 'Be killing sin, or it will be killing you', 'author': 'Spurgeon'}, 'other_author', 'John Owen'),
    ('not by the credited author (misattributed)',
     {'quote': 'A lie can travel halfway around the world while the truth is putting on its shoes',
      'author': 'Spurgeon'}, 'other_author', None),
    ('not found',
     {'quote': 'Defend the Bible? I would as soon defend a lion. Just turn the Bible loose; it will defend itself.',
      'author': 'Spurgeon'}, 'not_found', None),
    ('not found (misattributed)',
     {'quote': 'Preach the gospel at all times; when necessary, use words', 'author': 'Francis of Assisi'},
     'not_found', None),
]
# The first line of check_quote's answer after its heading, by verdict.
VERDICT_LINES = {'exact': ('FOUND WORD FOR WORD',), 'reworded': ('FOUND, BUT WORDED DIFFERENTLY',),
                 'other_author': ('FOUND, BUT NOT BY', 'FOUND, BUT BY SOMEONE ELSE'), 'not_found': ('NOT FOUND',)}


def call(url, body, timeout=120):
    req = urllib.request.Request(url, data=json.dumps(body).encode(), method='POST', headers={
        'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream',
        'MCP-Protocol-Version': PROTOCOL, 'User-Agent': 'col-deploy-smoke/1.0'})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.status, json.loads(r.read().decode())


def public_check(base, origin, body, path='/check'):
    """POST /check (or /search) as the website's page does: (status, JSON answer or {}, CORS origin). Waits out one 429."""
    for attempt in range(2):
        code, d, acao = public_check_once(base, origin, body, path)
        if code != 429 or attempt:
            return code, d, acao
        time.sleep(15)   # the visitor's minute is mostly over by then
    return code, d, acao


SMOKE_CODE = ''   # set in main() from the connector's key: the smoke test's own allowance on /check


def public_check_once(base, origin, body, path='/check'):
    headers = {'Content-Type': 'text/plain;charset=UTF-8', 'Origin': origin, 'User-Agent': 'col-deploy-smoke/1.0'}
    if SMOKE_CODE:
        headers['X-Library-Smoke'] = SMOKE_CODE
    req = urllib.request.Request(base + path, data=json.dumps(body).encode(), method='POST', headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return r.status, json.loads(r.read().decode()), r.headers.get('Access-Control-Allow-Origin')
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode()), e.headers.get('Access-Control-Allow-Origin')
        except ValueError:
            return e.code, {}, None


def text(reply):
    return '\n'.join(c.get('text', '') for c in reply.get('result', {}).get('content', []))


def main():
    url = open(sys.argv[1]).read().strip()
    global SMOKE_CODE
    SMOKE_CODE = hmac.new(url.rsplit('/', 1)[1].encode(), b'public-check-smoke', hashlib.sha256).hexdigest()
    want_rev = sys.argv[2] if len(sys.argv) > 2 else None
    shown = url.rsplit('/', 1)[0] + '/***'
    print(f'smoke test: {shown}')
    passed = total = 0
    ref = None

    def check(name, ok, detail=''):
        nonlocal passed, total
        total += 1
        passed += bool(ok)
        print(f"{'PASS' if ok else 'FAIL'}  {name}{': ' + detail if detail else ''}")
        return ok

    for attempt in range(4):   # PHP's OPcache may serve the old files for a few seconds after a release
        try:
            code, r = call(url, {'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {
                'protocolVersion': PROTOCOL, 'capabilities': {}, 'clientInfo': {'name': 'col-deploy-smoke', 'version': '1'}}})
        except Exception as e:   # noqa: BLE001 - report any failure as a FAIL line
            code, r = 0, {'error': str(e)[:200]}
        version = r.get('result', {}).get('serverInfo', {}).get('version', '')
        if code == 200 and (not want_rev or version.endswith('+' + want_rev)):
            break
        time.sleep(3)
    if not check('initialize', code == 200 and (not want_rev or version.endswith('+' + want_rev)),
                 f"protocol {r.get('result', {}).get('protocolVersion', '?')}, {version or r.get('error', '?')}"):
        print('smoke test: initialize failed; other checks skipped')
        return 1
    _, r = call(url, {'jsonrpc': '2.0', 'id': 2, 'method': 'tools/list'})
    names = [t['name'] for t in r.get('result', {}).get('tools', [])]
    check('tools/list', len(names) == 7, ', '.join(names))
    for i, (name, args, must) in enumerate([
        ('search (best)', {'query': 'the assurance of salvation', 'limit': 3}, '[1] P'),
        ('search (exact)', {'query': 'grace of god', 'mode': 'exact', 'limit': 2}, '[1] P'),
    ]):
        _, r = call(url, {'jsonrpc': '2.0', 'id': 10 + i, 'method': 'tools/call',
                          'params': {'name': 'search_library', 'arguments': args}})
        t = text(r)
        check(name, must in t and not r.get('result', {}).get('isError'), t.split('\n', 1)[0][:90])
        m = re.search(r'\[1\] (P\d{6,12})', t)
        ref = ref or (m.group(1) if m else None)
    if ref:
        _, r = call(url, {'jsonrpc': '2.0', 'id': 20, 'method': 'tools/call',
                          'params': {'name': 'read_passage', 'arguments': {'ref': ref, 'before': 0, 'after': 1}}})
        t = text(r)
        check('read_passage', ref in t, t.split('\n', 1)[0][:90])
    else:
        check('read_passage', False, 'no passage ref from the searches')
    _, r = call(url, {'jsonrpc': '2.0', 'id': 21, 'method': 'tools/call',
                      'params': {'name': 'find_works', 'arguments': {'limit': 3}}})
    check('find_works', 'work_id' in text(r), text(r).split('\n', 1)[0][:90])
    _, r = call(url, {'jsonrpc': '2.0', 'id': 22, 'method': 'tools/call',
                      'params': {'name': 'read_scripture', 'arguments': {'reference': 'John 1:1'}}})
    check('read_scripture', 'In the beginning was the Word' in text(r), text(r).split('\n', 1)[0][:90])
    _, r = call(url, {'jsonrpc': '2.0', 'id': 23, 'method': 'tools/call',
                      'params': {'name': 'check_quote', 'arguments': {
                          'quote': 'Be killing sin, or it will be killing you', 'author': 'Spurgeon'}}})
    check('check_quote', 'FOUND, BUT NOT BY Spurgeon' in text(r) and 'John Owen' in text(r),
          text(r).split('\n', 2)[1][:90] if text(r).count('\n') else text(r)[:90])
    u = urllib.parse.urlsplit(url)
    base = f'{u.scheme}://{u.netloc}'
    site = 'https://christianopenlibrary.org'
    code, d, acao = public_check(base, site, {'quote': 'Be killing sin, or it will be killing you', 'author': 'John Owen'})
    check('public check retired', code == 404, str(code))
    code, d, acao = public_check(base, site, {'q': 'Be killing sin, or it will be killing you'}, '/search')
    hits = d.get('hits') or []
    first = hits[0] if hits else {}
    check('public search', code == 200 and acao == site and first.get('author') == 'John Owen'
          and '[[' in (first.get('text') or ''), f"{code} {len(hits)} hits {first.get('author') or d.get('error', '')}"[:90])
    code, d, acao = public_check(base, 'https://example.com', {'q': 'assurance of salvation'}, '/search')
    check('public search, other site', code == 403 and acao is None, str(code))
    for i, (name, args, verdict, author) in enumerate(KNOWN_QUOTES):
        _, r = call(url, {'jsonrpc': '2.0', 'id': 30 + i, 'method': 'tools/call',
                          'params': {'name': 'check_quote', 'arguments': args}})
        lines = [line for line in text(r).split('\n') if line.strip()]
        said = lines[1] if len(lines) > 1 else (lines[0] if lines else '')
        check(f'known quote, {name}', said.startswith(VERDICT_LINES[verdict])
              and (author is None or author in said.split(':', 1)[-1][:200]),
              f"{said[:70]} (want {verdict}{', ' + author if author else ''})"[:110])
    print(f'smoke test: {passed} of {total} passed')
    return 0 if passed == total else 1


if __name__ == '__main__':
    sys.exit(main())
