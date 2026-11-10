#!/usr/bin/env python3
"""Smoke test of a running connector endpoint, over HTTPS. The URL (with its key) is read from a file,
so it never appears on a command line or in output.

    python3 -I deploy/smoke.py URL_FILE [EXPECTED_REVISION]

Checks initialize (and serverInfo.version ending in +EXPECTED_REVISION if given), tools/list, a meaning
search, an exact search, read_passage on the first result, find_works and read_scripture. Exit 0 if all pass.
"""
import json
import re
import sys
import time
import urllib.request

PROTOCOL = '2025-11-25'


def call(url, body, timeout=120):
    req = urllib.request.Request(url, data=json.dumps(body).encode(), method='POST', headers={
        'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream',
        'MCP-Protocol-Version': PROTOCOL, 'User-Agent': 'col-deploy-smoke/1.0'})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.status, json.loads(r.read().decode())


def text(reply):
    return '\n'.join(c.get('text', '') for c in reply.get('result', {}).get('content', []))


def main():
    url = open(sys.argv[1]).read().strip()
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
    check('tools/list', len(names) == 6, ', '.join(names))
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
    print(f'smoke test: {passed} of {total} passed')
    return 0 if passed == total else 1


if __name__ == '__main__':
    sys.exit(main())
