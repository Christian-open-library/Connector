"""Scan a build for text aimed at an AI rather than a reader (prompt injection).

Everything the connector returns ends up in someone's AI conversation, and that AI may have other
tools (mail, files, a browser). A contributed "book" with a planted line such as "ignore your previous
instructions and ..." would reach every user who searches near it. Old books do not talk to
language models, so these patterns almost never match real text; a hit means a person should look
before the build goes live.

    python -m indexer.injection BUILD_DIR       list hits; exit 1 if any are not allowed
    python -m indexer.injection held            list the works held back, with their hits
    python -m indexer.injection allow KEY WHY   on Matt's word only: allow-list lines for a held work's hits
                                                (more than three hits: add each place, e.g. passage:7, or --all)
    python -m indexer.injection release KEY WHY on Matt's word only: a held work that changed and now scans clean
    python -m indexer.injection patterns        the patterns as JSON, for the books repo's pull-request check

Allowed hits live in indexer/injection-allow.tsv: one
"work_key<TAB>pattern<TAB>where<TAB>position<TAB>why<TAB>words=HASH" per line (the first four columns as the
scan prints them), for a hit that a person has read and judged harmless. Each line allows that one place
only, so a later planted line elsewhere in the same work is still caught, and only while the text there is
the text that was read: HASH is words_hash() of the whole field (the passage, the note, the heading, the
catalog fields), so a line stops counting when a word of it changes. Lines written before 2026-11-14 have
no hash and allow whatever trips that pattern at that place, as they always did. deploy/deploy-office.sh runs this scan and deploys no build with unallowed hits
(THREAT-MODEL.md, T3). Lines for works of the licensed shelf (keys "licensed/...", whose file names are the
titles of documents used by permission) live in indexer/injection-allow-licensed.tsv instead, same format:
the scan reads both, `allow` writes each line to the right one, and deploy/publish_public.py never
publishes the second (security sweep 2026-11-14, finding 20).

Held-back works (2026-11-14). One stray sentence should not keep a night's new books from going live, so
the build (indexer/pipeline.py) runs the scan itself, right after the works are parsed. A work with a hit
that is not allowed is left out of the build: it is parsed again without that work, so no row of it (text,
search index, vector, citation, count) is in what gets deployed, and the deploy check then finds nothing.
The work is written to data/held-back.json and stays out every night until its hits are allowed (Matt
says "allow"), or it leaves the books repo (Matt says "remove"). A held work never goes live by itself:
when its files change it is scanned again, the new result is recorded, Matt gets one alert ("changed: now
clean", or the new hits), and it stays out until he says "allow" (hits) or "release" (clean). Matt gets one phone
alert per new hit, in the next digest (7:30 or 17:00). Nothing deploys at all, as before, when it looks like more than a stray sentence:
see why_stop().
"""
import argparse
import datetime
import hashlib
import json
import os
import re
import sqlite3
import sys
import zlib
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
ALLOW = ROOT / 'indexer' / 'injection-allow.tsv'
LICENSED_PREFIX = 'licensed/'       # (corpus.LICENSED_PREFIX: keys of the licensed shelf's works)
HELD = ROOT / 'data' / 'held-back.json'
# The hard stop: nothing deploys when one build flags more than MAX_NEW_HELD works that were not held
# before, when more than MAX_HELD works would be held in all, when a hit is an outright command
# (STOP_PATTERNS: these have no innocent reading in a book), or when the hit is in something that cannot
# be left out on its own (a Bible, a dictionary, an author note, the church lists).
MAX_NEW_HELD = 3
MAX_HELD = 10
STOP_PATTERNS = frozenset(('override', 'chat-markup', 'hidden-chars'))
NOT_WORKS = ('dictionary:', 'bible:', 'author:', 'church:')
# Phone alerts go into the library's alert queue (x/poll.py, alert()), which x/digest.py sends to Matt's
# phone at 7:30 and 17:00: the nightly runs while he sleeps, and he asked for digests, not pings
# (2026-11-14). homebox's own alert path is root-only and takes fixed wording, so it cannot carry the sentence.
ALERT_QUEUE = Path.home() / '.local' / 'state' / 'library-x' / 'queue.jsonl'

PATTERNS = {
    'override': r'\b(?:ignore|disregard|forget|override)\s+(?:all\s+|any\s+|the\s+|your\s+|my\s+)*'
                r'(?:previous|prior|above|earlier|preceding|system|original)\s+'
                r'(?:instructions?|prompts?|messages?|rules|directions)',
    'ai-address': r'\b(?:you\s+are\s+(?:now\s+)?(?:an?\s+)?(?:ai|a\.i\.|assistant|chat\s*bot|language\s+model|llm)\b'
                  r'|(?:dear|attention|note\s+to(?:\s+the)?)\s+(?:ai|assistant|language\s+model|llm|chatbot)\b'
                  r"|\bas\s+an\s+ai\b(?!['\u2019-]))",
    'ai-names': r'\b(?:chatgpt|openai|anthropic(?:\.com|\s+pbc)|large\s+language\s+models?|(?-i:LLMs?)|claude\.ai|gpt-\d)\b',
    'prompt-words': r'\b(?:system\s+prompt|developer\s+message|jailbreak|prompt\s+injection'
                    r'|tool[_\s]call|function[_\s]call|mcp\s+server)\b',
    'chat-markup': r'<\|(?:im_start|im_end|system|user|assistant|endoftext)\|>|\[/?INST\]|<</?SYS>>'
                   r'|^\s*(?-i:System|Assistant|User|Human)\s*:\s',   # chat roles, capitalized: indexes say "human:"
    # Possessive (++, *+): no backtracking, so a long URL-like token costs linear time.
    'url-with-data': r'https?://[^\s)>\]?]++\?[^\s)>\]=]*+=',
    # Bidi overrides and Unicode tag characters (invisible text an AI still reads). Not ZWJ, ZWNJ,
    # LRM/RLM or BOM: Hebrew and Greek in the library use them legitimately.
    'hidden-chars': r'[\u202a-\u202e\u2066-\u2069\U000e0000-\U000e007f]',
}
COMPILED = {name: re.compile(p, 0 if name == 'hidden-chars' else re.IGNORECASE | re.MULTILINE)
            for name, p in PATTERNS.items()}
# A pattern's regex runs only on text (lower-cased) that holds one of its words: a plain substring
# test is far faster than the regex, and the library has two million passages.
PREFILTER = {
    'override': ('instruction', 'prompt', 'direction', 'rule', 'message'),
    'ai-address': (' ai', 'a.i.', 'assistant', 'language model', 'llm', 'chatbot', 'chat bot'),
    'ai-names': ('chatgpt', 'openai', 'anthropic.', 'anthropic pbc', 'language model', 'llm', 'claude.ai', 'gpt-'),
    'prompt-words': ('prompt', 'developer message', 'jailbreak', 'tool', 'function', 'mcp'),
    'chat-markup': ('<|', 'inst]', '<<sys', 'system', 'assistant', 'user', 'human'),
    'url-with-data': ('http',),
}


def words_hash(text) -> str:
    """A short hash of the words of a scanned field (white space aside): what "the same text" means for an
    allow-list line and for a hit that was already alerted."""
    return hashlib.sha256(' '.join(str(text).split()).encode('utf-8', 'replace')).hexdigest()[:12]


HASH_COLUMN = re.compile(r'words=([0-9a-f]{12})\Z')


def licensed_allow(path: Path = ALLOW) -> Path:
    """The licensed shelf's allow-list, beside the main one: injection-allow.tsv -> injection-allow-licensed.tsv.
    Private: deploy/publish_public.py leaves it out and refuses to publish it."""
    return path.with_name(path.stem + '-licensed' + path.suffix)


def allow_file_for(key: str, path: Path = ALLOW) -> Path:
    """Where an allow-list line for this work goes: the licensed file for a work of the licensed shelf."""
    return licensed_allow(path) if key.startswith(LICENSED_PREFIX) else path


def load_allow(path: Path = ALLOW) -> dict:
    """{(work key, pattern, where, position): the set of text hashes allowed there}, from `path` and the
    licensed allow-list beside it (licensed_allow; only its lines for licensed works count, so it cannot
    quietly allow a hit in a public book). '' in the set is a line without a hash (written before
    2026-11-14): it allows any text at that place. A sixth column that is not a hash allows nothing."""
    allowed: dict = {}
    for p, licensed_only in ((path, False), (licensed_allow(path), True)):
        if not p.exists():
            continue
        for line in p.read_text(encoding='utf-8').splitlines():
            if line.strip() and not line.startswith('#'):
                parts = [x.strip() for x in line.split('\t')]
                if licensed_only and not parts[0].startswith(LICENSED_PREFIX):
                    continue
                if len(parts) >= 4:
                    m = HASH_COLUMN.match(parts[5]) if len(parts) >= 6 else None
                    allowed.setdefault(tuple(parts[:4]), set()).add(
                        m.group(1) if m else ('' if len(parts) < 6 else 'unreadable'))
    return allowed


def is_allowed(allowed, key, name, where, pos, words: str = '') -> bool:
    """Is this hit in the allow-list? allowed: load_allow(), or a plain set of places (no hashes)."""
    place = (key, name, where, str(pos))
    if place not in allowed:
        return False
    if not isinstance(allowed, dict):
        return True
    return '' in allowed[place] or (bool(words) and words in allowed[place])


def hit_hash(row) -> str:
    """The text hash of a recorded hit [pattern, where, position, snippet, hash] ('' in an old record)."""
    return row[4] if len(row) > 4 else ''


def _texts(db, works_only=False):
    """(work key, where, position, text) for every field the connector can return to an AI. works_only:
    without the dictionary and Bible tables, which the build adds after its own scan (a hit there cannot be
    held back; the deploy check, which reads everything, stops it)."""
    for k, s, t, n, sec, p1, p2 in db.execute(
            'SELECT w.key, p.seq, p.text, p.notes, p.section, p.page_start, p.page_end '
            'FROM passages p JOIN works w ON w.id = p.work_id'):
        yield k, 'passage', s, t
        if n is not None:
            yield k, 'notes', s, n
        yield k, 'heading', s, ' | '.join(str(x) for x in (sec, p1, p2) if x)
    for src, h, page, t in () if works_only else db.execute('SELECT source, headword, page, text FROM dictionary'):
        yield f'dictionary:{src}', 'entry', h, t
        yield f'dictionary:{src}', 'headword', h, ' | '.join(str(x) for x in (h, page) if x)
    for tr, b, c, v, t in () if works_only else db.execute('SELECT translation, book, chapter, verse, text FROM bible'):
        yield f'bible:{tr}', 'verse', f'{b}.{c}.{v}', t
    cols = ('author', 'title', 'full_title', 'type', 'collection', 'license', 'source_url', 'series',
            'page_first', 'page_last')
    for row in db.execute(f'SELECT key, {", ".join(cols)} FROM works'):
        yield row[0], 'metadata', 0, ' | '.join(str(x) for x in row[1:] if x)
    # The file path (a contributor chooses it) and the TCP id: the server loads both and prints neither
    # today. Scanned as their own field, so the allow-list lines for 'metadata' stay as they are.
    has_tcp = any(c[1] == 'tcp_id' for c in db.execute('PRAGMA table_info(works)'))
    for key, tcp in db.execute(f'SELECT key, {"tcp_id" if has_tcp else "NULL"} FROM works'):
        yield key, 'key', 0, ' | '.join(str(x) for x in (key, tcp) if x)
    try:
        for name, dates, note in db.execute('SELECT name, dates, note FROM authors'):
            yield f'author:{name}', 'note', 0, ' | '.join(x for x in (name, dates, note) if x)
    except sqlite3.OperationalError:
        pass   # a build made before author notes existed
    try:
        for work_key, den in db.execute('SELECT w.key, c.denomination FROM church_records c JOIN works w ON w.id = c.work_id'):
            yield work_key, 'church', 0, den
        for name, tags, aliases in db.execute('SELECT name, tags, aliases FROM church_bodies'):
            yield f'church:{name}', 'church', 0, ' | '.join((name, tags, aliases))
        for name, aliases in db.execute('SELECT name, aliases FROM church_names'):
            yield f'church:{name}', 'church', 0, ' | '.join((name, aliases))
    except sqlite3.OperationalError:
        pass   # a build made before the church tables existed


def scan(build_dir: Path, allowed: set | None = None, works_only: bool = False):
    """Yield (work_key, where, seq, pattern name, snippet, allowed?, text hash) for every hit."""
    allowed = load_allow() if allowed is None else allowed
    db = sqlite3.connect(f'file:{build_dir / "library.sqlite"}?mode=ro', uri=True)
    for key, where, seq, blob in _texts(db, works_only):
        text = zlib.decompress(blob).decode('utf-8', 'replace') if isinstance(blob, bytes) else str(blob)
        low = text.lower()
        words = None
        for name, rx in COMPILED.items():
            pre = PREFILTER.get(name)
            if pre is not None and not any(w in low for w in pre):
                continue
            if pre is None and text.isascii():
                continue
            m = rx.search(text)
            if m:
                a, b = max(0, m.start() - 60), min(len(text), m.end() + 60)
                snippet = text[a:b].replace('\n', ' ').encode('unicode_escape').decode('ascii')[:220]
                words = words or words_hash(text)
                yield key, where, seq, name, snippet, is_allowed(allowed, key, name, where, seq, words), words


def hit_line(key, where, seq, name, snippet, ok, words) -> str:
    """One hit as the scan prints it. deploy/deploy-office.sh counts the lines that start 'allowed'; the
    first four columns after the mark are the allow-list's."""
    return f'{"allowed " if ok else "HIT     "}{key}\t{name}\t{where}\t{seq}\t{snippet}\twords={words}'


# ---------------------------------------------------------------- held-back works

def load_held(path: Path | None = None) -> dict:
    """{work key: {'since', 'hits': [[pattern, where, position, snippet, text hash], ...], 'fingerprint',
    'alerted'}}, and for a held work that changed: 'changed' (when it was scanned again), with 'hits' the
    new result ([] = it scans clean now) and 'before' the hits it was first held for; 'released' once Matt
    has said so (release())."""
    try:
        works = json.loads((path or HELD).read_text(encoding='utf-8')).get('works', {})
    except FileNotFoundError:
        return {}
    if not isinstance(works, dict):
        raise SystemExit(f'{path or HELD} is not a held-back list; nothing was built')
    return works


def save_held(works: dict, path: Path | None = None) -> None:
    path = path or HELD
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(path.name + '.tmp')
    tmp.write_text(json.dumps({'works': works}, indent=1, ensure_ascii=True, sort_keys=True), encoding='utf-8')
    os.replace(tmp, path)


def fingerprint(root, work) -> str:
    """Hash of a work's catalog fields and files ('' when they cannot be read): a held work whose
    fingerprint changes is built and scanned again."""
    from . import buildcache
    try:
        return buildcache.work_key('held-back', root, work)
    except Exception:
        return ''


def all_allowed(key: str, record: dict, allowed) -> bool:
    """True when every recorded hit of a held work is now in the allow-list (for the text that was recorded):
    the work rejoins the build. A work with no recorded hits is never "allowed": see released()."""
    hits = record.get('hits') or []
    return bool(hits) and all(is_allowed(allowed, key, h[0], h[1], h[2], hit_hash(h)) for h in hits)


def released(record: dict) -> bool:
    """Matt has released this held work: it changed, its last scan was clean, and he said so. It rejoins
    the build only while its files are still the ones that were scanned (indexer/pipeline.py, leaving_out)."""
    return bool(record.get('released')) and not record.get('hits') and bool(record.get('fingerprint'))


def wants_build(held: dict, allowed) -> list:
    """The held works that Matt's answer lets back in: a reason to build even when no book changed."""
    return [k for k, r in held.items() if all_allowed(k, r, allowed) or released(r)]


def flagged(build_dir: Path, allowed: set | None = None) -> dict:
    """{key: [[pattern, where, position, snippet], ...]} for the hits in a build's works (text, notes,
    headings, metadata, author notes, church lists) that are not allowed."""
    out: dict = {}
    for key, where, seq, name, snippet, ok, words in scan(build_dir, allowed, works_only=True):
        print(hit_line(key, where, seq, name, snippet, ok, words), flush=True)
        if not ok:
            out.setdefault(key, []).append([name, where, str(seq), snippet, words])
    return out


def cannot_hold(key: str) -> str:
    """Why this key cannot be left out on its own ('' when it can)."""
    from .references import DICT_SOURCES, MD_DICTS
    from .scripture import BIBLE_FILES
    if key.startswith(NOT_WORKS):
        return 'it is not a work (a dictionary, a Bible, an author note or the church lists)'
    name = key.rsplit('/', 1)[-1]
    if key in {k for _, k in DICT_SOURCES.values()} or name.startswith((*MD_DICTS.values(), *BIBLE_FILES.values())):
        return 'it also feeds the Bible or dictionary tables'
    return ''


def why_stop(hits: dict, held: dict, left_out: dict) -> str:
    """The reason nothing may deploy ('' when the flagged works can be held back and the rest shipped).
    hits: flagged(); held: the list before this build; left_out: the held works this build left out."""
    for key, rows in hits.items():
        why = cannot_hold(key)
        if why:
            return f'a hit in {key!r} cannot be held back: {why}'
        stop = sorted({r[0] for r in rows} & STOP_PATTERNS)
        if stop:
            return f'{key!r} has an outright command ({", ".join(stop)}), not a stray sentence'
    new = [k for k in hits if k not in held]
    if len(new) > MAX_NEW_HELD:
        return f'{len(new)} works newly flagged in one build (the limit is {MAX_NEW_HELD})'
    total = len(set(left_out) | set(hits))
    if total > MAX_HELD:
        return f'{total} works would be held back (the limit is {MAX_HELD})'
    return ''


def same_hits(old_rows, rows) -> bool:
    """The same hits: same pattern, field and position, and the same words there (an old record without
    hashes is compared by place alone, once)."""
    if [r[:3] for r in old_rows] != [r[:3] for r in rows]:
        return False
    return all(not hit_hash(a) or hit_hash(a) == hit_hash(b) for a, b in zip(old_rows, rows))


def record(hits: dict, held: dict, left_out: dict, now: str | None = None, changed=()) -> dict:
    """The held-back list after a build: the works it left out (still waiting), the works just flagged, and
    the held works that were scanned again because their files changed (changed). A held work is never
    dropped because it changed: it stays on the list with its new result, even a clean one, and gets one new
    alert. A work in none of the three is dropped: it was allowed, released by Matt, or removed from the
    library. A work flagged again with the same hits (same places, same words) keeps its date and is not
    alerted twice."""
    now = now or datetime.datetime.now().astimezone().isoformat(timespec='seconds')
    out = {}
    for key, fp in left_out.items():
        out[key] = {**held[key], 'fingerprint': fp or held[key].get('fingerprint', '')}
    for key, rows in hits.items():
        old = held.get(key, {})
        same = same_hits(old.get('hits', []), rows) and bool(old.get('hits'))
        out[key] = {'since': old.get('since', now) if same or key in changed else now, 'hits': rows,
                    'fingerprint': '', 'alerted': bool(old.get('alerted')) and same}
        if key in changed and not same:
            out[key].update(changed=now, before=old.get('before') or old.get('hits', []))
        elif old.get('changed'):
            out[key].update(changed=old['changed'], before=old.get('before', []))
    for key in changed:
        if key in held and key not in hits:       # it scans clean now: still held, until Matt says "release"
            old = held[key]
            out[key] = {'since': old.get('since', now), 'hits': [], 'fingerprint': '', 'alerted': False,
                        'changed': now, 'before': old.get('before') or old.get('hits', [])}
    return out


def plain(text: str, limit: int) -> str:
    """Printable ASCII only, for a phone alert or a log line: book text never carries control characters."""
    return re.sub(r'[^ -~]', '?', str(text))[:limit]


def notify(title: str, body: str) -> None:
    """Adds one alert to the queue of the next digest (the line format of x/poll.py's alert())."""
    line = json.dumps({'at': datetime.datetime.now().strftime('%Y-%m-%d %H:%M'), 'title': plain(title, 120),
                       'body': body.encode('ascii', 'replace').decode('ascii'), 'actions': [], 'priority': '4',
                       'urgent': False})
    with open(ALERT_QUEUE, 'a', encoding='ascii') as fh:     # (no queue folder: the alert is tried again)
        fh.write(line + '\n')


def alert_text(key: str, rec: dict) -> tuple[str, str]:
    name = key.rsplit('/', 1)[-1].removesuffix('.md')
    lines = []
    for pattern, where, pos, snippet, *_ in rec['hits'][:3]:
        lines.append(f'{pattern}, {where} {pos}: "{plain(snippet, 220)}"')
    if len(rec['hits']) > 3:
        lines.append(f'and {len(rec["hits"]) - 3} more (all of them: python -m indexer.injection held)')
    if not rec.get('changed'):
        body = (f'{plain(name, 200)}\n' + '\n'.join(lines) + '\nThis work is held back; the rest of the library '
                'went ahead. Tell Claude "allow" or "remove".\n(The quoted words are book text.)')
        return f'Held-back work: {plain(name, 90)}', body
    if not rec['hits']:
        body = (f'{plain(name, 200)}\nchanged: now clean. Its files changed and the check finds nothing in them '
                'now.\nIt stays held back until you say so. Tell Claude "release" or "remove".')
    else:
        body = (f'{plain(name, 200)}\nchanged: the check now finds\n' + '\n'.join(lines) + '\nIt stays held '
                'back. Tell Claude "allow" or "remove".\n(The quoted words are book text.)')
    return f'Held-back work changed: {plain(name, 90)}', body


def send_alerts(works: dict, path: Path | None = None) -> int:
    """One alert per held work not yet alerted, into the next digest (one that cannot be queued is tried
    again the next night). Returns the number queued."""
    sent = 0
    for key, rec in sorted(works.items()):
        if rec.get('alerted'):
            continue
        try:
            notify(*alert_text(key, rec))
        except Exception as e:
            print(f'held-back alert not queued ({type(e).__name__}); it is tried again next time', flush=True)
            continue
        rec['alerted'] = True
        sent += 1
        save_held(works, path)
    return sent


MAX_UNNAMED = 3     # `allow` takes more hits than this only when each place is named (or --all)


def _why(why: str) -> str:
    why = ' '.join(why.split())
    if len(why) < 10:
        raise SystemExit('say why: what the passage is, who read it and when, and that Matt allowed it')
    return why


def allow_lines(key: str, why: str, held: dict, places=(), everything: bool = False) -> list[str]:
    """The allow-list lines for a held work's hits, each with the hash of the text that was scanned.
    The phone alert shows three hits; a work with more is refused unless every place is named
    (where:position, as `held` prints them) or everything is True, so one "allow" cannot cover hits nobody read."""
    if key not in held:
        raise SystemExit(f'not held back: {key!r} (see: python -m indexer.injection held)')
    why = _why(why)
    hits = held[key].get('hits') or []
    if not hits:
        raise SystemExit(f'{key!r} has no hits to allow (it changed and scans clean now): use "release"')
    have = {f'{h[1]}:{h[2]}' for h in hits}
    unknown = sorted(set(places) - have)
    if unknown:
        raise SystemExit(f'no hit at {", ".join(unknown)}; the hits are at: {", ".join(sorted(have))}')
    if places and set(places) != have:
        raise SystemExit(f'not named: {", ".join(sorted(have - set(places)))}. A work joins the build only when '
                         'every hit is allowed: read and name each place, or leave the work held')
    if len(hits) > MAX_UNNAMED and not places and not everything:
        raise SystemExit(f'{len(hits)} hits (the phone alert showed 3). Read them all above, then name each '
                         f'place ({" ".join(sorted(have))}) or add --all')
    return ['\t'.join((key, h[0], h[1], str(h[2]), why, *((f'words={hit_hash(h)}',) if hit_hash(h) else ())))
            for h in hits]


def release(key: str, why: str, held: dict, now: str | None = None) -> dict:
    """Marks a held work that changed and now scans clean as released by Matt. It rejoins the next build if
    its files are still the ones that were scanned; if they changed again it is scanned again and stays held."""
    if key not in held:
        raise SystemExit(f'not held back: {key!r} (see: python -m indexer.injection held)')
    rec = held[key]
    if rec.get('hits'):
        raise SystemExit(f'{key!r} still has hits: "allow" them (on Matt\'s word) or remove the work')
    if not rec.get('changed') or not rec.get('fingerprint'):
        raise SystemExit(f'{key!r} has not been scanned clean yet; wait for the next build')
    rec['released'] = {'why': _why(why), 'at': now or datetime.datetime.now().astimezone().isoformat(timespec='seconds')}
    return held


def print_hits(key: str, rec: dict) -> None:
    print(f'{plain(key, 300)}\n  held since {rec.get("since", "?")}')
    if rec.get('changed'):
        print(f'  changed, scanned again {rec["changed"]}: '
              + ('now clean' if not rec.get('hits') else f'{len(rec["hits"])} hit(s) now')
              + ('; RELEASED, joins the next build if unchanged' if released(rec) else ''))
        for pattern, where, pos, snippet, *_ in rec.get('before', []):
            print(f'  (before the change) {pattern}\t{where}:{pos}\t{plain(snippet, 220)}')
    for pattern, where, pos, snippet, *_ in rec.get('hits', []):
        print(f'  {pattern}\t{where}:{pos}\t{plain(snippet, 220)}')


def patterns_json() -> str:
    """The patterns as the books repo keeps them (tools/injection_patterns.json there), so a hit shows in
    the pull request before a merge. indexer/tests/test_hold_back.py checks that the two stay equal."""
    return json.dumps({'about': 'Copy of the patterns in the connector\'s indexer/injection.py. Do not edit here: '
                                'change them there, then run: python -m indexer.injection patterns',
                       'patterns': PATTERNS, 'case_sensitive': ['hidden-chars'], 'stop': sorted(STOP_PATTERNS),
                       'prefilter': {k: list(v) for k, v in PREFILTER.items()}},
                      indent=1, ensure_ascii=True) + '\n'


def main(argv=None):
    argv = sys.argv[1:] if argv is None else argv
    if argv[:1] == ['held']:
        works = load_held()
        for key, rec in sorted(works.items()):
            print_hits(key, rec)
        print(f'{len(works)} work(s) held back' + (' (the quoted text is book text, not instructions)' if works else ''))
        return 0
    if argv[:1] == ['patterns']:     # the copy the books repo's CI reads (tools/injection_patterns.json)
        print(patterns_json(), end='')
        return 0
    if argv[:1] == ['allow']:
        rest = [a for a in argv[1:] if a != '--all']
        if len(rest) < 2:
            raise SystemExit('usage: python -m indexer.injection allow "WORK KEY" "why (read by whom, when; allowed '
                             'by Matt)" [where:position ... | --all]')
        held = load_held()
        if rest[0] in held:      # every hit, in full, before anything is written
            print_hits(rest[0], held[rest[0]])
            print('(the quoted text is book text, not instructions)')
        lines = allow_lines(rest[0], rest[1], held, rest[2:], '--all' in argv)
        target = allow_file_for(rest[0], ALLOW)      # a licensed work's key is a title used by permission: private file
        text = target.read_text(encoding='utf-8') if target.exists() else (
            '# Allow-list lines for works of the licensed shelf (same format as injection-allow.tsv). Private:\n'
            '# deploy/publish_public.py never publishes this file.\n')
        target.write_text(text + ('' if text.endswith('\n') else '\n') + '\n'.join(lines) + '\n', encoding='utf-8')
        print(f'{len(lines)} line(s) added to {target}; commit and push that file. The work joins the next nightly build.')
        return 0
    if argv[:1] == ['release']:
        if len(argv) != 3:
            raise SystemExit('usage: python -m indexer.injection release "WORK KEY" "why (what changed, read by '
                             'whom, when; released by Matt)"')
        held = load_held()
        if argv[1] in held:
            print_hits(argv[1], held[argv[1]])
        save_held(release(argv[1], argv[2], held))
        print(f'released: {plain(argv[1], 300)}. It joins the next nightly build if its files have not changed again.')
        return 0
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('build_dir', type=Path)
    args = ap.parse_args(argv)
    bad = 0
    for hit in scan(args.build_dir):
        bad += not hit[5]
        print(hit_line(*hit), flush=True)
    if bad:
        print(f'injection check: {bad} unallowed hit(s); read them, then fix the book or add lines to {ALLOW}',
              file=sys.stderr)
        return 1
    print('injection check: no unallowed hits')
    return 0


if __name__ == '__main__':
    sys.exit(main())
