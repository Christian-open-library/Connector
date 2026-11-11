"""Scan a build for text aimed at an AI rather than a reader (prompt injection).

Everything the connector returns ends up in someone's AI conversation, and that AI may have other
tools (mail, files, a browser). A contributed "book" with a planted line such as "ignore your previous
instructions and ..." would reach every user who searches near it. Old books do not talk to
language models, so these patterns almost never match real text; a hit means a person should look
before the build goes live.

    python -m indexer.injection BUILD_DIR       list hits; exit 1 if any are not allowed

Allowed hits live in indexer/injection-allow.tsv: one "work_key<TAB>pattern<TAB>where<TAB>position<TAB>why"
per line (the first four columns as the scan prints them), for a hit that a person has read and judged
harmless. Each line allows that one place only, so a later planted line elsewhere in the same work is
still caught. deploy/deploy.sh runs this in its local checks
and deploys no build with unallowed hits (THREAT-MODEL.md, T3).
"""
import argparse
import re
import sqlite3
import sys
import zlib
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
ALLOW = ROOT / 'indexer' / 'injection-allow.tsv'

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


def load_allow(path: Path = ALLOW) -> set:
    allowed = set()
    if path.exists():
        for line in path.read_text(encoding='utf-8').splitlines():
            if line.strip() and not line.startswith('#'):
                parts = [x.strip() for x in line.split('\t')]
                if len(parts) >= 4:
                    allowed.add(tuple(parts[:4]))
    return allowed


def _texts(db):
    """(work key, where, position, text) for every field the connector can return to an AI."""
    for k, s, t, n, sec, p1, p2 in db.execute(
            'SELECT w.key, p.seq, p.text, p.notes, p.section, p.page_start, p.page_end '
            'FROM passages p JOIN works w ON w.id = p.work_id'):
        yield k, 'passage', s, t
        if n is not None:
            yield k, 'notes', s, n
        yield k, 'heading', s, ' | '.join(str(x) for x in (sec, p1, p2) if x)
    for src, h, page, t in db.execute('SELECT source, headword, page, text FROM dictionary'):
        yield f'dictionary:{src}', 'entry', h, t
        yield f'dictionary:{src}', 'headword', h, ' | '.join(str(x) for x in (h, page) if x)
    for tr, b, c, v, t in db.execute('SELECT translation, book, chapter, verse, text FROM bible'):
        yield f'bible:{tr}', 'verse', f'{b}.{c}.{v}', t
    cols = ('author', 'title', 'full_title', 'type', 'collection', 'license', 'source_url', 'series',
            'page_first', 'page_last')
    for row in db.execute(f'SELECT key, {", ".join(cols)} FROM works'):
        yield row[0], 'metadata', 0, ' | '.join(str(x) for x in row[1:] if x)
    try:
        for name, dates, note in db.execute('SELECT name, dates, note FROM authors'):
            yield f'author:{name}', 'note', 0, ' | '.join(x for x in (name, dates, note) if x)
    except sqlite3.OperationalError:
        pass   # a build made before author notes existed


def scan(build_dir: Path, allowed: set | None = None):
    """Yield (work_key, where, seq, pattern name, snippet, allowed?) for every hit."""
    allowed = load_allow() if allowed is None else allowed
    db = sqlite3.connect(f'file:{build_dir / "library.sqlite"}?mode=ro', uri=True)
    for key, where, seq, blob in _texts(db):
        text = zlib.decompress(blob).decode('utf-8', 'replace') if isinstance(blob, bytes) else str(blob)
        low = text.lower()
        for name, rx in COMPILED.items():
            words = PREFILTER.get(name)
            if words is not None and not any(w in low for w in words):
                continue
            if words is None and text.isascii():
                continue
            m = rx.search(text)
            if m:
                a, b = max(0, m.start() - 60), min(len(text), m.end() + 60)
                snippet = text[a:b].replace('\n', ' ').encode('unicode_escape').decode('ascii')[:220]
                yield key, where, seq, name, snippet, (key, name, where, str(seq)) in allowed


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('build_dir', type=Path)
    args = ap.parse_args(argv)
    bad = 0
    for key, where, seq, name, snippet, ok in scan(args.build_dir):
        bad += not ok
        print(f'{"allowed " if ok else "HIT     "}{key}\t{name}\t{where}\t{seq}\t{snippet}', flush=True)
    if bad:
        print(f'injection check: {bad} unallowed hit(s); read them, then fix the book or add lines to {ALLOW}',
              file=sys.stderr)
        return 1
    print('injection check: no unallowed hits')
    return 0


if __name__ == '__main__':
    sys.exit(main())
