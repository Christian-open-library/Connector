#!/usr/bin/env python3
"""Costs and gifts for the public cost card (https://mcp.christianopenlibrary.org/support.svg).

    python3 -I deploy/funding.py show        this month's lines, for checking
    python3 -I deploy/funding.py attach      member-list JSON on stdin -> the same with "funding" added

deploy-office.sh members runs members.py json through `attach`, so the card is refreshed whenever the
member list is (nightly). The server prices the searches it was billed for itself; this adds:
  - deploy/costs.json: monthly costs (domain, electricity estimate), one-time costs, and the months
    the curator paid for himself (covered_by_curator: those months need no gifts, so gifts go forward);
  - new books: the Voyage tokens nightly builds were billed for ("embedded ..., 2.9M tokens billed" in
    data/logs/update-YYYYMMDD.log), at voyage-4-lite's price;
  - gifts: GitHub Sponsors, read with gh as the account that receives them (totals only, no names).
    A monthly sponsorship counts in every month it is active, a one-time gift in the month it was
    made. GitHub only says who sponsors now, so a month that is over keeps the amount recorded for it
    in data/funding-ledger.json. If GitHub can't be reached, the last recorded amounts are used.
The ledger also keeps each nightly log's billed tokens, since only the newest 14 logs are kept.
`attach` never stops the member push: on any error it passes the member list through unchanged.
"""
import json
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
COSTS = ROOT / 'deploy/costs.json'
LOGS = ROOT / 'data/logs'
LEDGER = ROOT / 'data/funding-ledger.json'
SPONSORS_URL = 'https://github.com/sponsors/mattm4444'
EMBED_CENTS_PER_M = 2.0     # voyage-4-lite, US cents per million tokens
NEW_BOOKS = 'Adding new books (Voyage AI)'
AHEAD = 3                   # coming months sent besides this one (the card shows them)
BILLED = re.compile(r'^embedded \d+ passages, ([\d.]+)M tokens billed', re.M)
QUERY = '''query($endCursor: String) { viewer { sponsorshipsAsMaintainer(first: 100, after: $endCursor,
  includePrivate: true, activeOnly: false) { pageInfo { hasNextPage endCursor }
  nodes { createdAt isActive isOneTimePayment tier { monthlyPriceInCents } } } } }'''


def next_month(m: str) -> str:
    y, mo = int(m[:4]), int(m[5:7])
    return f'{y + mo // 12:04d}-{mo % 12 + 1:02d}'


def build_cents(seen: dict) -> dict:
    """Cents billed for embedding new books, by month. `seen` (log name -> million tokens, kept in the
    ledger) is updated from the logs still on disk, so costs from rotated-out logs are not lost."""
    for log in LOGS.glob('update-*.log'):
        if re.fullmatch(r'update-\d{8}\.log', log.name):
            seen[log.name] = sum(float(x) for x in BILLED.findall(log.read_text(errors='replace')))
    tokens = {}
    for name, mtok in seen.items():
        m = f'{name[7:11]}-{name[11:13]}'
        tokens[m] = tokens.get(m, 0.0) + mtok
    return {m: round(t * EMBED_CENTS_PER_M) for m, t in tokens.items()}


def sponsorships() -> list:
    """Every sponsorship of the account gh is signed in as (createdAt, isActive, isOneTimePayment, cents)."""
    out, cursor = [], None
    for _ in range(50):
        args = ['gh', 'api', 'graphql', '-f', f'query={QUERY}'] + (['-f', f'endCursor={cursor}'] if cursor else [])
        r = subprocess.run(args, capture_output=True, text=True, timeout=60)
        if r.returncode != 0:
            raise RuntimeError('GitHub Sponsors: ' + (r.stderr.strip().splitlines() or ['gh failed'])[-1][:200])
        page = json.loads(r.stdout)['data']['viewer']['sponsorshipsAsMaintainer']
        for n in page['nodes']:
            cents = (n.get('tier') or {}).get('monthlyPriceInCents') or 0
            out.append((n['createdAt'][:7], bool(n['isActive']), bool(n['isOneTimePayment']), int(cents)))
        if not page['pageInfo']['hasNextPage']:
            return out
        cursor = page['pageInfo']['endCursor']
    raise RuntimeError('GitHub Sponsors: too many pages')


def given(spons: list, month: str) -> int:
    """Gifts for a month: active monthly sponsorships that started by then, one-time gifts made in it."""
    return sum(c for made, active, once, c in spons
               if (once and made == month) or (not once and active and made <= month))


def compute(now: datetime | None = None) -> dict:
    current = (now or datetime.now(timezone.utc)).strftime('%Y-%m')
    costs = json.loads(COSTS.read_text())
    ledger = json.loads(LEDGER.read_text()) if LEDGER.exists() else {}
    gifts = ledger.setdefault('given', {})
    try:
        spons, problem = sponsorships(), None
    except Exception as e:      # noqa: BLE001 - reported, and the recorded amounts are used
        spons, problem = None, str(e)
    builds = build_cents(ledger.setdefault('build_logs', {}))
    curator = costs.get('covered_by_curator', {})
    end = current
    for _ in range(AHEAD):
        end = next_month(end)
    months, m = {}, costs['start']
    while m <= end:
        lines = [[NEW_BOOKS, builds[m], 'metered']] if builds.get(m) else []
        lines += [[c['name'], int(c['cents']), 'once'] for c in costs.get('one_time', []) if c['month'] == m]
        lines += [[c['name'], int(c['cents']) if 'cents' in c else round(c['cents_per_year'] / 12), 'fixed']
                  for c in costs.get('monthly', [])]
        if m < current and m in gifts:
            gift = int(gifts[m])
        elif spons is not None:
            gift = given(spons, m)
        else:
            gift = int(gifts.get(m, 0))
        months[m] = {'given_cents': gift, 'costs': lines}
        if m in curator.get('months', []):
            months[m]['covered_by'] = curator['name']
        m = next_month(m)
    if spons is not None:
        gifts[current] = months[current]['given_cents']
    LEDGER.parent.mkdir(parents=True, exist_ok=True)
    tmp = LEDGER.with_suffix('.tmp')
    tmp.write_text(json.dumps(ledger, indent=1, sort_keys=True) + '\n')
    tmp.replace(LEDGER)
    if problem:
        print(f'funding: {problem}; using the recorded gifts', file=sys.stderr)
    return {'updated': datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
            'sponsors_url': SPONSORS_URL, 'months': months}


def main(argv: list) -> int:
    cmd = argv[1] if len(argv) > 1 else ''
    if cmd == 'show':
        f = compute()
        m = datetime.now(timezone.utc).strftime('%Y-%m')
        row = f['months'][m]
        for name, cents, _ in row['costs']:
            print(f'  {name:<44} ${cents / 100:>7.2f}')
        print(f'  {"(searches: counted on the server)":<44}')
        total = sum(c for _, c, _ in row['costs'])
        print(f'  {"Costs from here":<44} ${total / 100:>7.2f}\n  {"Given":<44} ${row["given_cents"] / 100:>7.2f}')
        if row.get('covered_by'):
            print(f'  covered by {row["covered_by"]}')
        return 0
    if cmd == 'attach':
        data = sys.stdin.read()
        try:
            doc = json.loads(data)
            doc['funding'] = compute()
            print(json.dumps(doc, indent=1))
        except Exception as e:      # noqa: BLE001 - the member list must still go through
            print(f'funding: not attached ({e})', file=sys.stderr)
            sys.stdout.write(data)
        return 0
    print(__doc__.split('\n\n')[1], file=sys.stderr)
    return 2


if __name__ == '__main__':
    sys.exit(main(sys.argv))
