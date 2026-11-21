"""End-to-end build: corpus -> database -> embeddings -> vector index -> checks.

    python -m indexer.pipeline build                  # full build (needs the Voyage key)
    python -m indexer.pipeline build --sample 40 --fake   # small offline build for tests
    python -m indexer.pipeline nightly                # pull the repo; rebuild + deploy if it changed
    python -m indexer.pipeline verify BUILD_DIR
"""
from __future__ import annotations

import argparse
import contextlib
import json
import os
import shutil
import sqlite3
import subprocess
import sys
import zlib
from datetime import datetime, timezone
from pathlib import Path

from . import build_db, corpus, embed, injection, ivf, references
from .modernize import DATA, build_lexicon

ROOT = Path(__file__).resolve().parent.parent
REPO = Path.home() / 'library-work/books'
# The licensed shelf: present-day documents used by permission (private repo mattm4444/licensed-library; never
# copied into the books repo or anything library members can read). Built into the index beside the books.
LICENSED = Path(os.environ.get('COL_LICENSED', '/mnt/photos/library-work/licensed'))
BUILDS = DATA / 'build'
DEPLOYED = DATA / 'deployed-office.json'   # written by deploy/deploy-office.sh


def step(what: str) -> None:
    """A timestamped line in the update log, so each step's time can be read off it."""
    print(f'--- {datetime.now().strftime("%H:%M:%S")} {what}', flush=True)


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


def licensed_root(required: bool) -> Path | None:
    """The licensed shelf, or None (with a warning) when it is missing; the nightly requires it, so a build
    never drops the shelf because a disk was not mounted."""
    if (LICENSED / '.git').is_dir():
        return LICENSED
    if required:
        raise SystemExit(f'the licensed shelf {LICENSED} is missing; nothing was built')
    print(f'warning: no licensed shelf at {LICENSED}; building without it', file=sys.stderr, flush=True)
    return None


@contextlib.contextmanager
def leaving_out(held: dict, roots: dict):
    """While this is open, the works in held (key -> record) are not part of the library that build_db
    reads, so nothing of them reaches the build. Yields {key: fingerprint} of the works left out. A held
    work whose files or catalog row changed since it was held is not left out: it is built and scanned again
    (it is in .changed of what is yielded, and parse_and_hold then takes it out again whatever the scan
    says: a held work never goes live by itself). A held work Matt has released (injection.released) is
    built too, while its files are still the ones that scanned clean (.released).

    Done here, around corpus.iter_works and corpus.licensed_works, and not as a parameter of build_db.build,
    because build_db.py and corpus.py are in the per-work cache key (indexer/buildcache.py): editing them
    empties the cache and costs a cold run. Make it a parameter the next time those files change anyway."""
    class Left(dict):
        pass
    left = Left()
    left.changed, left.released = {}, set()

    def keep(work) -> bool:
        rec = held.get(work.key)
        if rec is None:
            return True
        fp = injection.fingerprint(roots.get(work.root), work)
        if rec.get('fingerprint') and fp and fp != rec['fingerprint']:
            print(f'held-back work changed, checking it again (it stays held): {work.key!r}', flush=True)
            left.changed[work.key] = fp
            return True
        if injection.released(rec) and fp and fp == rec['fingerprint']:
            print(f'held-back work released by Matt, back in the build: {work.key!r}', flush=True)
            left.released.add(work.key)
            return True
        left[work.key] = fp
        return False

    iter_works, licensed_works = corpus.iter_works, corpus.licensed_works
    corpus.iter_works = lambda *a, **k: (w for w in iter_works(*a, **k) if keep(w))
    corpus.licensed_works = lambda *a, **k: [w for w in licensed_works(*a, **k) if keep(w)]
    try:
        yield left
    finally:
        corpus.iter_works, corpus.licensed_works = iter_works, licensed_works


def parse_and_hold(out: Path, sample: int, processes: int, licensed: Path | None) -> None:
    """Builds library.sqlite in out without the held-back works, scans it for text aimed at an AI
    (indexer/injection.py; THREAT-MODEL.md, T3) and, when a work has a hit that is not allowed, holds that
    work back too: the database is built again without it. Raises SystemExit when the hits look like more
    than a stray sentence (injection.why_stop): nothing is deployed then."""
    # Sample builds hold only part of the library: they keep their own list, and send no alerts.
    path = injection.HELD.with_name('held-back-sample.json') if sample else injection.HELD
    roots = {'': REPO, 'licensed': licensed}
    allowed = injection.load_allow()
    held = injection.load_held(path)
    for key in [k for k, r in held.items() if injection.all_allowed(k, r, allowed)]:
        print(f'held-back work allowed, back in the build: {key!r}', flush=True)
        del held[key]
    with leaving_out(held, roots) as left:
        build_db.build(REPO, out, sample, processes, licensed, use_cache=not sample)
    step('injection check')
    hits = injection.flagged(out, allowed)
    stop = injection.why_stop(hits, held, {**left, **left.changed})
    if stop:
        raise SystemExit(f'injection check: {stop}; nothing was deployed. Read the HIT lines above, then fix '
                         f'the books or add lines to {injection.ALLOW} (on Matt\'s word only)')
    now = injection.record(hits, held, left, changed=left.changed)
    for key in sorted(set(held) - set(now)):
        print(f'no longer held back ({"released by Matt" if key in left.released else "gone from the library"}): '
              f'{key!r}', flush=True)
    injection.save_held(now, path)      # before the second pass: a run cut off here starts with the list
    # Out again: the works just flagged, and every held work that was scanned again because it changed,
    # clean or not (its record has no fingerprint yet, so it is left out whatever its files say).
    again = sorted(set(hits) | (set(left.changed) & set(now)))
    if again:
        for key, rows in sorted(hits.items()):
            print(f'HELD BACK: {key!r}: ' + '; '.join(f'{r[0]} in {r[1]} {r[2]}' for r in rows), flush=True)
        for key in sorted(set(again) - set(hits)):
            print(f'STILL HELD BACK: {key!r} changed and scans clean now; it waits for Matt\'s "release"', flush=True)
        step(f'parse again without the {len(again)} flagged or changed work(s)')
        shutil.rmtree(out)
        with leaving_out({**now, **{k: {} for k in again}}, roots) as left:
            build_db.build(REPO, out, sample, processes, licensed, use_cache=not sample)
        missing = sorted(set(again) - set(left))
        if missing:     # (cannot happen: the same catalog was just read)
            raise SystemExit(f'injection check: could not leave out {missing!r}; nothing was deployed')
        for key in again:
            now[key]['fingerprint'] = left[key]
        injection.save_held(now, path)
    print(f'injection check: {len(now)} work(s) held back' + (f', {len(hits)} of them new' if hits else ''), flush=True)
    if not sample:
        injection.send_alerts(now, path)


def build(sample: int, fake: bool, max_dollars: float, processes: int, licensed: Path | None = None) -> Path:
    if not (DATA / 'lexicon.tsv').exists():
        build_lexicon(REPO)
    stamp = datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S')
    out = BUILDS / (stamp + ('-sample' if sample else '') + ('-fake' if fake else ''))
    try:
        step('parse works, build library.sqlite')
        parse_and_hold(out, sample, processes, licensed)
        step('references')
        references.build(out, REPO, processes, use_cache=not sample)
        if not fake:
            step('embed new passages')
            embed.run(out, dry_run=False, max_dollars=max_dollars, workers=4,
                      model=embed.DOC_MODEL, dims=embed.DIMS)
        step('vector index')
        ivf.build(out, fake=fake, k=2048, sample=200_000, iters=12, save_flat=not sample)
        step('verify')
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


def ready_main(repo: Path) -> None:
    """Put a books clone on origin/main for the build, or stop the build (THREAT-MODEL B4).

    Uncommitted or untracked files stop it (someone's unsaved work). A clean clone left on another branch
    (a session that forgot to switch back; the 2026-11-21 nightly built nothing because of one) is switched
    back to main: that branch keeps its commits, so nothing is lost. Then main is pulled fast-forward only.
    """
    def git(*a):
        return subprocess.run(['git', '-C', str(repo), *a], capture_output=True, text=True).stdout.strip()
    if git('status', '--porcelain'):
        raise SystemExit(f'{repo} has uncommitted or untracked files; nothing was built')
    branch = git('rev-parse', '--abbrev-ref', 'HEAD')
    if branch != 'main':
        sw = subprocess.run(['git', '-C', str(repo), 'switch', '-q', 'main'], capture_output=True, text=True)
        if sw.returncode != 0:
            raise SystemExit(f'{repo} is on {branch} and could not switch to main ({sw.stderr.strip()[:200]}); '
                             'nothing was built')
        print(f'{repo} was on {branch} with nothing uncommitted; switched back to main')
    if git('rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}') != 'origin/main':
        raise SystemExit(f'{repo} main does not track origin/main; nothing was built')
    subprocess.run(['git', '-C', str(repo), 'pull', '--ff-only', '-q', 'origin', 'main'], check=True)
    if git('rev-parse', 'HEAD') != git('rev-parse', 'origin/main'):
        raise SystemExit(f'{repo} HEAD is not origin/main after the pull; nothing was built')


def nightly(max_dollars: float):
    # Who may sign in: refreshed every night, even when the books didn't change. A failure here is
    # reported but doesn't stop the rebuild (yesterday's list stays on the server).
    try:
        m = subprocess.run(['bash', str(ROOT / 'deploy/deploy-office.sh'), 'members'], capture_output=True, text=True,
                           timeout=900)
    except subprocess.TimeoutExpired:
        m = subprocess.CompletedProcess([], 124, '', 'members push took longer than 15 minutes; stopped')
    print('members: ' + ((m.stdout or m.stderr).strip().splitlines() or ['no output'])[-1][:200])
    if m.returncode != 0 or m.stderr.strip():
        print('members push said:\n' + m.stderr.strip()[-2000:])
    su = subprocess.run(['python3', '-I', str(ROOT / 'deploy/members.py'), 'signups'], capture_output=True, text=True)
    if su.returncode == 0 and su.stdout.strip():
        print(su.stdout.strip().splitlines()[0][:200])
    # Build only reviewed main (THREAT-MODEL B4): the clone must be on main, tracking origin/main, with
    # nothing uncommitted or untracked, or nothing is built. The same for the licensed shelf (Matt's private repo).
    licensed = licensed_root(required=True)
    for repo in (REPO, licensed):
        ready_main(repo)
    now, now_licensed = head(REPO), head(licensed)
    last = json.loads(DEPLOYED.read_text()) if DEPLOYED.exists() else {}
    # A held-back work whose hits Matt has allowed, or that he has released, rejoins the library tonight,
    # even with no new books.
    held, allowed = injection.load_held(), injection.load_allow()
    injection.send_alerts(held)      # (an alert that could not be sent last night)
    released = injection.wants_build(held, allowed)
    # COL_FORCE=1 (deploy/update.sh by hand): rebuild anyway, for a change in the build code alone.
    force = os.environ.get('COL_FORCE') == '1'
    if last.get('repo_commit') == now and last.get('licensed_commit') == now_licensed and not released and not force:
        print(f'books repo unchanged at {now[:10]}, licensed shelf at {now_licensed[:10]}; nothing to do')
        return
    # Before building too: a run that stops before the deploy (the injection check, the spend cap)
    # never reaches the prune below, and unpruned builds filled the system disk on 2026-11-14.
    prune_builds(keep={last.get('build')})
    out = build(sample=0, fake=False, max_dollars=max_dollars, processes=3, licensed=licensed)
    # The office server (mcp.christianopenlibrary.org).
    step('deploy to the office server')
    subprocess.run(['bash', str(ROOT / 'deploy/deploy-office.sh'), 'build', str(out)], check=True)
    # Again, so tonight's embedding cost is on the public cost card (deploy/funding.py) by morning.
    subprocess.run(['bash', str(ROOT / 'deploy/deploy-office.sh'), 'members'], capture_output=True, text=True)
    prune_builds(keep={out.name})
    step('done')


def prune_builds(keep):
    """Delete old full builds: keep the ones named in keep and the newest other one. Skips the
    LATEST* symlinks and sample builds (LATEST-sample is the local test setup)."""
    olds = sorted(p for p in BUILDS.iterdir()
                  if p.is_dir() and not p.is_symlink() and p.name not in keep and 'sample' not in p.name)
    for old in olds[:-1]:
        print(f'deleting old build {old.name}')
        subprocess.run(['rm', '-rf', '--', str(old)], check=True)


def main(argv=None):
    ap = argparse.ArgumentParser()
    sub = ap.add_subparsers(dest='cmd', required=True)
    b = sub.add_parser('build')
    b.add_argument('--sample', type=int, default=0)
    b.add_argument('--fake', action='store_true')
    b.add_argument('--max-dollars', type=float, default=15.0)
    b.add_argument('--processes', type=int, default=3)
    b.add_argument('--no-licensed', action='store_true', help='leave out the licensed shelf')
    nt = sub.add_parser('nightly')
    nt.add_argument("--max-dollars", type=float, default=5.0)
    v = sub.add_parser('verify')
    v.add_argument('build_dir', type=Path)
    args = ap.parse_args(argv)
    if args.cmd == 'build':
        build(args.sample, args.fake, args.max_dollars, args.processes,
              None if args.no_licensed else licensed_root(required=False))
    elif args.cmd == 'nightly':
        nightly(args.max_dollars)
    else:
        verify(args.build_dir)


if __name__ == '__main__':
    sys.exit(main())
