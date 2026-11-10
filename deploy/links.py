#!/usr/bin/env python3
"""Personal connector links: each person gets their own URL with its own daily limit.

The list lives on homebox in ~/.config/library-connector/links.json (mode 600, with names and keys).
The office server gets /srv/library/links/links.json with only each key's SHA-256, an id and the limits
(see plugin src/Links.php). Every change re-uploads it (deploy/deploy-office.sh links).

    python3 -I deploy/links.py add "Ben Hailey" [--per-day 500] [--per-minute 30]   prints the new URL
    python3 -I deploy/links.py list                    names, ids, limits, dates (no URLs)
    python3 -I deploy/links.py show NAME_OR_ID         prints that link's URL
    python3 -I deploy/links.py rename NAME_OR_ID NEW_NAME
    python3 -I deploy/links.py revoke NAME_OR_ID       the URL stops working at once
    python3 -I deploy/links.py usage [YYYY-MM]         tool calls per link per day, from the server log
    python3 -I deploy/links.py push                    re-upload the server file
"""
import argparse
import datetime
import hashlib
import json
import os
import re
import secrets
import subprocess
import sys
from pathlib import Path

CONF = Path.home() / '.config' / 'library-connector'
LOCAL = CONF / 'links.json'
REMOTE = 'col-library/links.json'          # relative to the server account's home
NAME_RE = re.compile(r"^[A-Za-z0-9 .,'()&_-]{1,60}$")


def die(msg):
    sys.exit(f'links: {msg}')


def base_url():
    office = CONF / 'connector-url-office'
    url = (office if office.exists() else CONF / 'connector-url').read_text().strip()
    m = re.fullmatch(r'(https://[^/\s]+/(?:wp-json/col/v1/)?mcp/)[A-Za-z0-9_-]{43,128}', url)
    if not m:
        die('connector-url does not look like https://<site>/wp-json/col/v1/mcp/<key>')
    return m.group(1)


def load():
    if not LOCAL.exists():
        return {'links': []}
    return json.loads(LOCAL.read_text())


def save(data):
    tmp = LOCAL.with_suffix('.tmp')
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, 'w') as f:
        json.dump(data, f, indent=1)
        f.write('\n')
    os.replace(tmp, LOCAL)


def find(data, who):
    hits = [l for l in data['links'] if l['id'] == who or l['name'].lower() == who.lower()]
    if len(hits) != 1:
        die(f'no single link named or numbered {who!r} (see: links.py list)')
    return hits[0]


def server_json(data):
    """The server copy: active links only, hashes only."""
    server = {'links': [{'id': l['id'], 'sha256': hashlib.sha256(l['key'].encode()).hexdigest(),
                         'calls_per_day': l['calls_per_day'], 'calls_per_minute': l['calls_per_minute']}
                        for l in data['links'] if not l.get('revoked')]}
    return json.dumps(server, indent=1) + '\n'


def push(data):
    """Upload the server copy to the office server (hashes only; written atomically there)."""
    office = subprocess.run(['bash', str(Path(__file__).resolve().parent / 'deploy-office.sh'), 'links'],
                            text=True, capture_output=True)
    if office.returncode != 0:
        die('upload failed: ' + (office.stderr.strip() or office.stdout.strip()))
    print(office.stdout.strip())


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest='cmd', required=True)
    a = sub.add_parser('add'); a.add_argument('name')
    a.add_argument('--per-day', type=int, default=500); a.add_argument('--per-minute', type=int, default=30)
    sub.add_parser('list')
    s = sub.add_parser('show'); s.add_argument('who')
    r = sub.add_parser('rename'); r.add_argument('who'); r.add_argument('new_name')
    v = sub.add_parser('revoke'); v.add_argument('who')
    u = sub.add_parser('usage'); u.add_argument('month', nargs='?', default=datetime.date.today().strftime('%Y-%m'))
    sub.add_parser('push')
    sub.add_parser('server-json')
    args = ap.parse_args()
    data = load()

    if args.cmd == 'add':
        if not NAME_RE.fullmatch(args.name):
            die("names may use letters, digits, spaces and . , ' ( ) & _ - (60 at most)")
        if any(l['name'].lower() == args.name.lower() and not l.get('revoked') for l in data['links']):
            die(f'an active link is already named {args.name!r}')
        if not (1 <= args.per_day <= 1_000_000 and 1 <= args.per_minute <= 100_000):
            die('limits out of range')
        ids = {l['id'] for l in data['links']}
        lid = 'l' + secrets.token_hex(3)
        while lid in ids:
            lid = 'l' + secrets.token_hex(3)
        link = {'id': lid, 'name': args.name, 'key': secrets.token_urlsafe(32),
                'calls_per_day': args.per_day, 'calls_per_minute': args.per_minute,
                'created': datetime.date.today().isoformat(), 'revoked': None}
        data['links'].append(link)
        save(data)
        push(data)
        print(f'{args.name} ({lid}): {base_url()}{link["key"]}')
    elif args.cmd == 'list':
        for l in data['links']:
            state = f'revoked {l["revoked"]}' if l.get('revoked') else 'active'
            print(f'{l["id"]}  {l["name"]:<30} {l["calls_per_day"]}/day {l["calls_per_minute"]}/min  '
                  f'created {l["created"]}  {state}')
    elif args.cmd == 'show':
        l = find(data, args.who)
        print(('REVOKED ' if l.get('revoked') else '') + base_url() + l['key'])
    elif args.cmd == 'rename':
        if not NAME_RE.fullmatch(args.new_name):
            die('bad name')
        find(data, args.who)['name'] = args.new_name
        save(data)
        print('renamed (names stay on homebox; nothing to upload)')
    elif args.cmd == 'revoke':
        l = find(data, args.who)
        l['revoked'] = datetime.date.today().isoformat()
        save(data)
        push(data)
        print(f'revoked {l["name"]} ({l["id"]})')
    elif args.cmd == 'usage':
        die('usage per link is not available on the office server yet (it needs a log command in library-deploy)')
    elif args.cmd == 'push':
        push(data)
    elif args.cmd == 'server-json':
        print(server_json(data), end='')


if __name__ == '__main__':
    main()
