#!/usr/bin/env python3
"""The connector's sign-in lists: members (WorkOS users whose GitHub account is in the
Christian-open-library organization) and blocked accounts. Since 2026-11-10 anyone signed in may use the
connector (oauth.php members_only false) except blocked accounts; the member list stays for later use.

    python3 -I deploy/members.py json           print {"members": [...], "blocked": [...]} (WorkOS user ids)
    python3 -I deploy/members.py list           who signed in: members, others, blocked
    python3 -I deploy/members.py block LOGIN    block a GitHub account or an email address (then: deploy/deploy-office.sh members)
    python3 -I deploy/members.py unblock LOGIN  (LOGIN: a GitHub login or an email address)

The WorkOS API key stays on homebox (~/.config/library-connector/workos.key); GitHub membership comes
from the gh CLI (Matt's login). The office server only ever receives the list of WorkOS user ids.
deploy/deploy-office.sh members sends it; the nightly update does that every night.
"""
import json
import re
import shutil
import subprocess
import sys
import urllib.parse
import urllib.request
from pathlib import Path

ORG = 'Christian-open-library'
CONF = Path.home() / '.config' / 'library-connector'
API = 'https://api.workos.com'
BLOCKED = CONF / 'blocked.txt'   # GitHub logins, one per line


def workos(path, params=None):
    key = (CONF / 'workos.key').read_text().strip()
    url = API + path + ('?' + urllib.parse.urlencode(params) if params else '')
    req = urllib.request.Request(url, headers={'Authorization': f'Bearer {key}', 'User-Agent': 'col-members/1.0'})
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read().decode())


def users():
    out, after = [], None
    while True:
        d = workos('/user_management/users', {'limit': 100, **({'after': after} if after else {})})
        out += d.get('data', [])
        after = (d.get('list_metadata') or {}).get('after')
        if not after:
            return out


def github_ids(user_id):
    d = workos(f'/user_management/users/{urllib.parse.quote(user_id)}/identities')
    items = d if isinstance(d, list) else d.get('data', [])
    return {str(i['idp_id']) for i in items
            if isinstance(i, dict) and str(i.get('provider', '')).lower() == 'githuboauth' and i.get('idp_id')}


def org_members():
    gh = shutil.which('gh') or str(Path.home() / '.local' / 'bin' / 'gh')
    r = subprocess.run([gh, 'api', f'orgs/{ORG}/members', '--paginate', '--jq', '.[] | "\\(.id) \\(.login)"'],
                       capture_output=True, text=True, check=True)
    return dict(line.split(' ', 1) for line in r.stdout.splitlines() if ' ' in line)


def resolve_login(login):
    """The numeric GitHub id of a login (raises if GitHub doesn't know it)."""
    gh = shutil.which('gh') or str(Path.home() / '.local' / 'bin' / 'gh')
    r = subprocess.run([gh, 'api', f'users/{login}', '--jq', '.id'], capture_output=True, text=True, timeout=60)
    if r.returncode != 0 or not r.stdout.strip().isdigit():
        raise SystemExit(f'no GitHub account {login!r}: nothing pushed (fix blocked.txt or unblock it)')
    return r.stdout.strip()


def blocked_ids():
    """GitHub user ids of the blocked accounts, and the blocked email addresses (lower case).

    blocked.txt holds one entry per line: "login:12345" (the GitHub id saved when blocking, so a
    rename can't lift the block), a bare login (older entries, resolved now; a failure stops the
    push so yesterday's list stays), or an email address."""
    ids, emails = set(), set()
    for entry in (BLOCKED.read_text().split() if BLOCKED.exists() else []):
        if '@' in entry:
            emails.add(entry.lower())
        elif ':' in entry and entry.rsplit(':', 1)[1].isdigit():
            ids.add(entry.rsplit(':', 1)[1])
        else:
            ids.add(resolve_login(entry))
    return ids, emails


def compute():
    members, (blocked, blocked_emails) = org_members(), blocked_ids()
    allowed, others, banned = [], [], []
    for u in users():
        gids = github_ids(u['id'])
        logins = sorted(members[g] for g in gids if g in members)
        row = (u['id'], logins[0] if logins else '', u.get('email') or '')
        if gids & blocked or (u.get('email') or '').lower() in blocked_emails:
            banned.append(row)
        (allowed if logins else others).append(row)
    return allowed, others, banned


def main():
    cmd = sys.argv[1] if len(sys.argv) > 1 else ''
    if cmd == 'json':
        allowed, _, banned = compute()
        print(json.dumps({'members': sorted(uid for uid, _, _ in allowed),
                          'blocked': sorted(uid for uid, _, _ in banned)}, indent=1))
    elif cmd == 'list':
        allowed, others, banned = compute()
        print(f'{len(allowed)} library members have signed in:')
        for uid, login, email in allowed:
            print(f'  {login:<24} {email}')
        print(f'{len(others)} others have signed in' + (':' if others else '.'))
        for uid, _, email in others:
            print(f'  {email or uid}')
        if banned:
            print(f'{len(banned)} blocked:')
            for uid, login, email in banned:
                print(f'  {login or email or uid}')
    elif cmd in ('block', 'unblock') and len(sys.argv) == 3:
        login = sys.argv[2].strip().lstrip('@')
        if not re.fullmatch(r'[A-Za-z0-9-]{1,39}|[^\s@]+@[^\s@]+', login):
            sys.exit('give a GitHub login or an email address')
        names = BLOCKED.read_text().split() if BLOCKED.exists() else []
        key = lambda n: n.split(':', 1)[0].lower()
        if cmd == 'block':
            entry = login if '@' in login else f'{login}:{resolve_login(login)}'
            names = sorted({n for n in names if key(n) != login.lower()} | {entry})
        else:
            names = [n for n in names if key(n) != login.lower()]
        BLOCKED.write_text(''.join(n + '\n' for n in names))
        BLOCKED.chmod(0o600)
        print(f'{cmd}ed {login}; now run: deploy/deploy-office.sh members')
    else:
        sys.exit(__doc__)


if __name__ == '__main__':
    main()
