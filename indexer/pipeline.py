"""End-to-end build: corpus -> database -> embeddings -> vector index -> checks.

    python -m indexer.pipeline build                  # full build (needs the Voyage key)
    python -m indexer.pipeline build --sample 40 --fake   # small offline build for tests
    python -m indexer.pipeline nightly                # pull the repo; rebuild + deploy if it changed
    python -m indexer.pipeline verify BUILD_DIR
"""
from __future__ import annotations

import argparse
import json
import os
import shutil
import sqlite3
import subprocess
import sys
import zlib
from datetime import datetime, timezone
from pathlib import Path

from . import build_db, embed, ivf, references
from .modernize import DATA, build_lexicon

ROOT = Path(__file__).resolve().parent.parent
REPO = Path.home() / 'library-work/books'
BUILDS = DATA / 'build'
DEPLOYED = DATA / 'deployed-office.json'   # written by deploy/deploy-office.sh


def verify(build_dir: Path) -> dict:
    """Sanity checks on a finished build; raises SystemExit on failure."""
    db = sqlite3.connect(f'file:{build_dir / "library.sqlite"}?mode=ro', uri=True)
    meta = dict(db.execute('SELECT key, value FROM meta'))
    n = db.execute('SELECT count(*) FROM passages').fetchone()[0]
    n_vec = db.execute('SELECT count(*) FROM passages WHERE vec_pos IS NOT NULL').fetchone()[0]
    dims = int(meta.get('embed_dims', 0))
    problems = []
    if n == 0:
        problems.append('no passages')
    if n_vec != n:
        problems.append(f'{n - n_vec} passages have no vector')
    vb = build_dir / 'vectors.bin'
    ib = build_dir / 'ids.bin'
    if not vb.exists() or vb.stat().st_size != n_vec * dims:
        problems.append('vectors.bin size mismatch')
    if not ib.exists() or ib.stat().st_size != n_vec * 4:
        problems.append('ids.bin size mismatch')
    k = db.execute('SELECT count(*), sum(count) FROM ivf').fetchone()
    if k[1] != n_vec:
        problems.append(f'ivf lists hold {k[1]} rows, expected {n_vec}')
    hits = db.execute("SELECT count(*) FROM passages_fts WHERE passages_fts MATCH '\"grace\"'").fetchone()[0]
    if hits == 0:
        problems.append('full-text search finds nothing for "grace"')
    row = db.execute('SELECT text FROM passages LIMIT 1').fetchone()
    zlib.decompress(row[0]).decode('utf-8')
    integrity = db.execute('PRAGMA quick_check').fetchone()[0]
    if integrity != 'ok':
        problems.append(f'quick_check: {integrity}')
    db.close()
    # The server runs SQLite 3.37.2; prove that version reads this build (FTS5 + reference tables).
    old_sqlite = ROOT / 'tools/sqlite-3.37.2/sqlite3-3.37.2'
    if old_sqlite.exists():
        probe = ("select count(*) from passages_fts where passages_fts match '\"grace\"';"
                 "select count(*) from works_fts where works_fts match '\"god\"';"
                 "select count(*) from scripture_refs where book=43 and chapter=3;"
                 "select count(*) from dictionary where norm='grace';"
                 "select count(*) from bible where translation='kjv' and book=1;")
        r = subprocess.run([str(old_sqlite), '-readonly', str(build_dir / 'library.sqlite'), probe],
                           capture_output=True, text=True, timeout=600)
        if r.returncode != 0 or 'Error' in r.stderr:
            problems.append(f'SQLite 3.37.2 cannot read the build: {r.stderr.strip()[:300]}')
        else:
            print('SQLite 3.37.2 check ok:', ' '.join(r.stdout.split()))
    if problems:
        raise SystemExit(f'verify failed for {build_dir}: ' + '; '.join(problems))
    print(f'verify ok: {n} passages, {k[0]} lists, {hits} passages mention grace')
    return meta


def build(sample: int, fake: bool, max_dollars: float, processes: int) -> Path:
    if not (DATA / 'lexicon.tsv').exists():
        build_lexicon(REPO)
    stamp = datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S')
    out = BUILDS / (stamp + ('-sample' if sample else '') + ('-fake' if fake else ''))
    try:
        build_db.build(REPO, out, sample, processes)
        references.build(out, REPO, processes)
        if not fake:
            embed.run(out, dry_run=False, max_dollars=max_dollars, workers=4,
                      model=embed.DOC_MODEL, dims=embed.DIMS)
        ivf.build(out, fake=fake, k=2048, sample=200_000, iters=12)
        verify(out)
    except BaseException:
        # A failed build is never deployed: do not leave its (multi-GB) directory behind.
        shutil.rmtree(out, ignore_errors=True)
        raise
    build_db.save_ids(out)      # new work ids become permanent only with a good build
    latest = BUILDS / ('LATEST-sample' if sample else 'LATEST')
    tmp = latest.with_name(latest.name + '.tmp')
    if tmp.is_symlink() or tmp.exists():
        tmp.unlink()
    tmp.symlink_to(out.name)
    os.replace(tmp, latest)
    return out


def head(repo: Path) -> str:
    return subprocess.run(['git', '-C', str(repo), 'rev-parse', 'HEAD'], capture_output=True,
                          text=True, check=True).stdout.strip()


def nightly(max_dollars: float):
    # Who may sign in: refreshed every night, even when the books didn't change. A failure here is
    # reported but doesn't stop the rebuild (yesterday's list stays on the server).
    m = subprocess.run(['bash', str(ROOT / 'deploy/deploy-office.sh'), 'members'], capture_output=True, text=True)
    print('members: ' + ((m.stdout or m.stderr).strip().splitlines() or ['no output'])[-1][:200])
    if m.returncode != 0 or m.stderr.strip():
        print('members push said:\n' + m.stderr.strip()[-2000:])
    # Build only reviewed main (THREAT-MODEL B4): the clone must be on main, tracking origin/main, with
    # nothing uncommitted or untracked, or nothing is built.
    def git(*a):
        return subprocess.run(['git', '-C', str(REPO), *a], capture_output=True, text=True).stdout.strip()
    if (git('rev-parse', '--abbrev-ref', 'HEAD') != 'main'
            or git('rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}') != 'origin/main'
            or git('status', '--porcelain')):
        raise SystemExit(f'{REPO} is not a clean main tracking origin/main; nothing was built')
    subprocess.run(['git', '-C', str(REPO), 'pull', '--ff-only', '-q', 'origin', 'main'], check=True)
    if git('rev-parse', 'HEAD') != git('rev-parse', 'origin/main'):
        raise SystemExit(f'{REPO} HEAD is not origin/main after the pull; nothing was built')
    now = head(REPO)
    last = json.loads(DEPLOYED.read_text()) if DEPLOYED.exists() else {}
    if last.get('repo_commit') == now:
        print(f'books repo unchanged at {now[:10]}; nothing to do')
        return
    out = build(sample=0, fake=False, max_dollars=max_dollars, processes=3)
    # The office server (mcp.christianopenlibrary.org).
    subprocess.run(['bash', str(ROOT / 'deploy/deploy-office.sh'), 'build', str(out)], check=True)
    # Again, so tonight's embedding cost is on the public cost card (deploy/funding.py) by morning.
    subprocess.run(['bash', str(ROOT / 'deploy/deploy-office.sh'), 'members'], capture_output=True, text=True)
    # Keep this build and the newest one before it. Skip the LATEST* symlinks and sample builds
    # (LATEST-sample is the local test setup).
    olds = sorted(p for p in BUILDS.iterdir()
                  if p.is_dir() and not p.is_symlink() and p != out and 'sample' not in p.name)
    for old in olds[:-1]:
        subprocess.run(['rm', '-rf', '--', str(old)], check=True)


def main(argv=None):
    ap = argparse.ArgumentParser()
    sub = ap.add_subparsers(dest='cmd', required=True)
    b = sub.add_parser('build')
    b.add_argument('--sample', type=int, default=0)
    b.add_argument('--fake', action='store_true')
    b.add_argument('--max-dollars', type=float, default=15.0)
    b.add_argument('--processes', type=int, default=3)
    nt = sub.add_parser('nightly')
    nt.add_argument('--max-dollars', type=float, default=3.0)
    v = sub.add_parser('verify')
    v.add_argument('build_dir', type=Path)
    args = ap.parse_args(argv)
    if args.cmd == 'build':
        build(args.sample, args.fake, args.max_dollars, args.processes)
    elif args.cmd == 'nightly':
        nightly(args.max_dollars)
    else:
        verify(args.build_dir)


if __name__ == '__main__':
    sys.exit(main())
