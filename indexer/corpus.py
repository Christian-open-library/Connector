"""Read the books repo into works and passages (DESIGN.md section 3).

Usage:
    python -m indexer.corpus --repo ~/library-work/books --sample 5      # print a few passages
    python -m indexer.corpus --repo ~/library-work/books --stats         # whole-corpus counts
"""
from __future__ import annotations

import argparse
import csv
import datetime
import json
import os
import re
import sys
from dataclasses import dataclass, field
from pathlib import Path
from typing import Iterator

import yaml

CHUNKER_VERSION = 1
MIN_WORDS = 160      # flush once a passage reaches this many words
MAX_WORDS = 350      # never let merging push a passage past this
TARGET_WORDS = 220   # piece size when splitting long paragraphs
HARD_SPLIT = 300     # split point when a long paragraph has no usable boundary
MIN_ALPHA_WORDS = 8  # drop passages with fewer alphabetic words than this
SEQ_MULT = 100_000   # passage id = work_id * SEQ_MULT + seq
# Passage ids are stored as uint32 (ids.bin): the largest work id whose ids all fit.
MAX_WORK_ID = (2**32 - 1) // SEQ_MULT - 1
# Size bounds on books-repo text (DESIGN.md section 3); far above anything in the real library.
MAX_PASSAGE_CHARS = 40_000   # display text of one passage (longest real one: about 21,000)
MAX_NOTE_CHARS = 20_000      # one footnote (longest real one: about 16,000)
MAX_NOTES_CHARS = 40_000     # all notes of one passage (longest real total: about 35,000)
MAX_WORK_NOTES_CHARS = 16_000_000  # notes copied into all of one work's passages (largest real: about 10M; a footnote cited
                                   # by many passages is copied into each; this bounds the copies)
MAX_LABEL_CHARS = 40         # a printed page label
MAX_META_CHARS = 2_000       # one catalog or front-matter value
MAX_FRONTMATTER = 16_384     # characters of YAML front matter

COLLECTION_BY_FOLDER = {
    'EEBO-TCP': 'eebo-tcp',
    'ChurchFathers': 'ccel-schaff',
    'CCEL_Library': 'ccel',
    'ArchiveLibrary': 'internet-archive',
    'PublicDomainLibrary': 'project-gutenberg',
    'StandardEbooks': 'standard-ebooks',
    'DocSouth': 'docsouth',
    'Creeds': 'creeds-json',
    'Contributed': 'contributed',
}

COLLECTION_LABELS = {
    'eebo-tcp': 'EEBO-TCP (original 1500s-1700s editions)',
    'evans-tcp': 'Evans-TCP (early American imprints)',
    'ecco-tcp': 'ECCO-TCP (1700s editions)',
    'ccel-schaff': 'Church Fathers (Schaff, CCEL)',
    'ccel': 'CCEL',
    'internet-archive': 'Internet Archive (OCR)',
    'project-gutenberg': 'Project Gutenberg',
    'standard-ebooks': 'Standard Ebooks',
    'docsouth': 'Documenting the American South',
    'creeds-json': 'Creeds and confessions',
    'contributed': 'Contributed',
}


@dataclass
class Work:
    id: int
    key: str                 # catalog File value (repo-relative main file)
    author: str
    author_slug: str
    title: str
    full_title: str
    year: int | None
    type: str
    collection: str
    license: str
    source_url: str
    tcp_id: str
    series: str
    ocr: bool
    files: list[str] = field(default_factory=list)   # repo-relative text files, in order


@dataclass
class Passage:
    id: int
    work_id: int
    seq: int
    part: int
    page_start: str | None
    page_end: str | None
    section: str
    text: str                # display text, verbatim, [p. N] markers inline
    notes: list[str]
    words: int


# ---------------------------------------------------------------- catalog and ids

def warn(msg: str) -> None:
    print(f'warning: {msg}', file=sys.stderr, flush=True)


class UnsafePath(ValueError):
    """A books-repo path that must not be read (outside the repo, a symlink, .git, ...)."""


CONTROL_RE = re.compile(r'[\x00-\x1f\x7f]')


def repo_file(repo: Path, rel) -> Path:
    """The regular file `rel` inside the books repo. Raises UnsafePath for an empty, absolute or
    control-character path, a '..' or '.git' segment, a symlink anywhere below the repo, or a path
    whose resolved form leaves the repo; FileNotFoundError if there is no such regular file."""
    if not isinstance(rel, str) or not rel or CONTROL_RE.search(rel) or '\\' in rel:
        raise UnsafePath('empty path or one with control characters or backslashes')
    if rel.startswith('/') or Path(rel).is_absolute():
        raise UnsafePath('absolute path')
    parts = rel.split('/')
    if any(p in ('', '.', '..') for p in parts):
        raise UnsafePath("empty, '.' or '..' segment")
    if any(p.lower() == '.git' for p in parts):
        raise UnsafePath('.git segment')
    root = repo.resolve()
    cur = root
    for part in parts:
        cur = cur / part
        if cur.is_symlink():
            raise UnsafePath('symlink in the path')
    try:
        real = cur.resolve(strict=True)
    except (FileNotFoundError, NotADirectoryError):
        raise FileNotFoundError(rel) from None
    if not real.is_relative_to(root) or real == root:
        raise UnsafePath('resolves outside the repo')
    if not real.is_file():
        raise FileNotFoundError(rel)
    return real


def read_repo_text(repo: Path, rel: str) -> str:
    """Text of a books-repo file (repo_file checks; strict UTF-8)."""
    return repo_file(repo, rel).read_text(encoding='utf-8')


def clean_meta(value) -> str:
    """A catalog or front-matter value as one bounded line of text."""
    s = CONTROL_RE.sub(' ', str(value if value is not None else ''))
    return s.strip()[:MAX_META_CHARS]


def load_catalog(repo: Path) -> list[dict]:
    with open(repo_file(repo, 'catalog_full.csv'), newline='', encoding='utf-8') as fh:
        return list(csv.DictReader(fh))


def valid_rows(repo: Path, rows: list[dict]) -> list[dict]:
    """Catalog rows whose File is a safe, existing repo file (the others are skipped with a
    warning), each File once."""
    out, seen = [], set()
    for row in rows:
        key = row.get('File')
        try:
            repo_file(repo, key)
        except UnsafePath as e:
            warn(f'catalog file refused ({e}): {key!r}')
            continue
        except (FileNotFoundError, OSError):
            warn(f'catalog file missing: {key!r}')
            continue
        if key in seen:
            warn(f'catalog file listed twice: {key!r}')
            continue
        seen.add(key)
        out.append(row)
    return out


def load_work_ids(path: Path) -> dict[str, int]:
    if path.exists():
        return json.loads(path.read_text(encoding='utf-8'))
    return {}


def save_work_ids(path: Path, ids: dict[str, int]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix('.tmp')
    tmp.write_text(json.dumps(ids, ensure_ascii=False, indent=0, sort_keys=True), encoding='utf-8')
    os.replace(tmp, path)


def assign_work_ids(rows: list[dict], ids: dict[str, int]) -> dict[str, int]:
    """Give every catalog row a persistent id; new rows get max+1 in File order. Rows beyond
    MAX_WORK_ID (passage ids must fit in uint32) get none and are left out with a warning."""
    next_id = max(ids.values(), default=0) + 1
    for key in sorted({r['File'] for r in rows if isinstance(r.get('File'), str)}):
        if key not in ids:
            if next_id > MAX_WORK_ID:
                warn(f'no work id left (the largest is {MAX_WORK_ID}): {key!r} left out')
                continue
            ids[key] = next_id
            next_id += 1
    return ids


# ---------------------------------------------------------------- files

FRONTMATTER_RE = re.compile(r'\A---\n(.*?)\n---[ \t]*\n', re.S)


class _FrontMatterLoader(yaml.SafeLoader):
    """safe_load without aliases or merge keys (they can expand a few bytes into gigabytes)."""

    def compose_node(self, parent, index):
        if self.check_event(yaml.AliasEvent):
            raise yaml.YAMLError('aliases are not accepted in front matter')
        return super().compose_node(parent, index)

    def flatten_mapping(self, node):
        if any(k.tag == 'tag:yaml.org,2002:merge' for k, _ in node.value):
            raise yaml.YAMLError('merge keys are not accepted in front matter')
        return super().flatten_mapping(node)


def split_frontmatter(text: str) -> tuple[dict, str]:
    """(metadata, body). Only scalar values are kept, each at most MAX_META_CHARS characters; front
    matter over MAX_FRONTMATTER characters is ignored. YAML problems fall back to "key: value" lines."""
    m = FRONTMATTER_RE.match(text, 0, MAX_FRONTMATTER + 64) if text.startswith('---\n') else None
    if not m:
        return {}, text
    raw = m.group(1)
    try:
        meta = yaml.load(raw, Loader=_FrontMatterLoader) or {}
        if not isinstance(meta, dict):
            meta = {}
    except Exception:            # YAMLError, but also ValueError for dates such as 1650-00-00
        meta = {}
        for line in raw.splitlines():
            mm = re.match(r'([A-Za-z_]+):[ \t]*(.*)$', line)
            if mm:
                meta[mm.group(1)] = mm.group(2).strip().strip('"')
    out = {}
    for k, v in meta.items():
        if isinstance(k, str) and (v is None or isinstance(v, (str, int, float, bool, datetime.date))):
            out[k] = v[:MAX_META_CHARS] if isinstance(v, str) else v
    return out, text[m.end():]


PART_RE = re.compile(r'^(?P<stem>.+?)(?: — Part |, Part )(?P<n>\d+)\.md$')


def part_files(repo: Path, main_rel: str) -> list[str]:
    """Sibling part files of a main file, in numeric order (empty if single-file). Only safe repo
    files (repo_file) are listed; others are skipped with a warning."""
    try:
        main = repo_file(repo, main_rel)
    except (UnsafePath, OSError):
        return []
    stem = main.name[:-3] if main.name.endswith('.md') else main.name
    parts = []
    try:
        names = os.listdir(main.parent)
    except OSError:
        return []
    for name in names:
        m = PART_RE.match(name)
        if m and m.group('stem') == stem:
            rel = '/'.join([*main_rel.split('/')[:-1], name])
            try:
                repo_file(repo, rel)
            except (UnsafePath, OSError) as e:
                warn(f'part file refused ({e}): {rel!r}')
                continue
            parts.append((int(m.group('n')), rel))
    parts.sort()
    return [p for _, p in parts]


def _year(value) -> int | None:
    if value is None:
        return None
    m = re.search(r'\b(\d{3,4})\b', str(value)[:MAX_META_CHARS])
    if m:
        y = int(m.group(1))
        if 100 <= y <= 2100:
            return y
    return None


def iter_works(repo: Path, ids: dict[str, int], rows: list[dict] | None = None) -> Iterator[Work]:
    """Works of the catalog rows (default: valid_rows of the catalog) that have an id. A row whose
    file is unsafe, missing or unreadable is skipped with a warning (one bad file never stops the
    build)."""
    for row in (valid_rows(repo, load_catalog(repo)) if rows is None else rows):
        key = row['File']
        try:
            work_id = ids[key]          # (a dict subclass may supply missing ids)
        except KeyError:
            continue                    # no id: left out (assign_work_ids said why)
        try:
            yield _work(repo, row, key, work_id)
        except Exception as e:      # UnicodeDecodeError, OSError, ...
            warn(f'work skipped ({type(e).__name__}: {str(e)[:200]!r}): {key!r}')


def _work(repo: Path, row: dict, key: str, work_id: int) -> Work:
    meta, _ = split_frontmatter(read_repo_text(repo, key))
    top = key.split('/', 1)[0]
    parts = part_files(repo, key)
    m = lambda k: clean_meta(meta.get(k) or '')       # noqa: E731
    r = lambda k: clean_meta(row.get(k) or '')        # noqa: E731
    return Work(
        id=work_id,
        key=key,
        author=r('Author') or m('author'),
        author_slug=m('author'),
        title=r('Title') or m('title'),
        full_title=r('Full title') or m('full_title') or r('Title'),
        year=_year(row.get('Year')) or _year(meta.get('date')),
        type=r('Type') or m('type') or 'book',
        collection=m('collection') or COLLECTION_BY_FOLDER.get(top, top.lower()),
        license=r('License') or m('license'),
        source_url=r('Source') or m('source'),
        tcp_id=m('tcp_id'),
        series=m('series'),
        ocr=bool(meta.get('ocr')),
        files=parts if parts else [key],
    )


# ---------------------------------------------------------------- parsing one file

# Bounded spans keep these linear on hostile lines (an unclosed '<!-- p.' with many spaces was cubic).
PAGE_RE = re.compile(r'<!--[ \t]{0,20}p\.[ \t]{0,20}([^<>\n]{0,2000}?)[ \t]{0,20}-->')
FN_DEF_RE = re.compile(r'^\[\^([^\]\s]+)\]:[ \t]?(.*)$')
FN_REF_RE = re.compile(r'\[\^([^\]\s]+)\](?!:)')
HEADING_RE = re.compile(r'^(#{1,6})[ \t]+(.*)$')   # trailing spaces and #s are stripped in code (linear)
# Markup removed from display text (bounded spans keep the patterns linear on long paragraphs).
IMAGE_RE = re.compile(r'!\[[^\]]{0,1000}\]\((?:<[^>\n]{0,2000}>|[^)]{0,2000})\)')
REF_IMAGE_RE = re.compile(r'!\[(?!\^)[^\]]{0,1000}\]\[[^\]]{0,200}\]')          # ![alt][ref] (not "![^1]")
LINK_RE = re.compile(r'\[([^\]]{1,1000})\]\((?:<[^>\n]{0,2000}>|[^)\s]{0,2000})(?:\s+"[^"]{0,500}")?\)')
REF_DEF_RE = re.compile(r'^[ \t]{0,3}\[[^\]^][^\]]{0,200}\]:[ \t]*<?(?:[a-z][a-z0-9+.-]{1,20}:|www\.|/)\S*.*$',
                        re.M | re.I)                                             # [ref]: https://...
HTML_TAG_RE = re.compile(r'</?[A-Za-z][A-Za-z0-9]*(?:[ \t][^<>\n]{0,2000})?/?>')     # one line
# tags that load or run something, also across lines ("<img\nsrc=...>")
ACTIVE_TAG_RE = re.compile(r'</?(?:img|image|a|iframe|frame|script|style|svg|video|audio|source|picture|object|'
                           r'embed|link|meta|base|form|input|button|math)\b[^<>]{0,4000}>', re.I)
BOILERPLATE_RE = re.compile(
    r'^\s*[*_]+\s*(This e-book comes from Project Gutenberg|Text read by OCR from a scan|'
    r'From Schaff,|Electronic edition from Documenting the American South|'
    r'From the Standard Ebooks edition)', re.I)
BY_LINE_RE = re.compile(r'^\s*\*by [^*]+\*\s*$')
# CCEL plain-text title-page metadata and separators (first 80 lines of a file)
CCEL_META_RE = re.compile(
    r'^\s*(?:_{10,}|(?:Title|Creator\(s\)|CCEL Subjects|LC Call no|LC Subjects|Print Basis|'
    r'Rights|Date Created|Contributor\(s\)|Edition|Publisher|Status|Source)\s*:.*)$')
FIGURE_RE = re.compile(r'^\s*\[figure\].*$', re.I)
MARK = '\x00'   # stands for a page marker inside a paragraph: MARK + label + MARK


def normalize_page_label(raw: str) -> str:
    s = raw.strip().strip('{}').strip()
    s = re.sub(r'^(?:page|pg|p\.)\s*', '', s, flags=re.I)
    return s.strip('{} ') or raw.strip()


def strip_comments(text: str) -> str:
    """Remove HTML comments ("<!-- ... -->"), in one linear pass. An unclosed "<!--" and everything
    after it are kept, as the old regex did (it found no match there)."""
    out, i = [], 0
    while True:
        j = text.find('<!--', i)
        k = text.find('-->', j + 4) if j >= 0 else -1
        if j < 0 or k < 0:
            out.append(text[i:])
            return ''.join(out)
        out.append(text[i:j])
        i = k + 3


def extract_footnotes(lines: list[str]) -> tuple[list[str], dict[str, str]]:
    """Remove footnote definitions (with indented continuation lines); return body and notes."""
    body, notes = [], {}
    i = 0
    while i < len(lines):
        m = FN_DEF_RE.match(lines[i])
        if not m:
            body.append(lines[i])
            i += 1
            continue
        fid, parts = m.group(1), [m.group(2).strip()]
        i += 1
        while i < len(lines):
            nxt = lines[i]
            if nxt.startswith(('    ', '\t')) and nxt.strip():
                parts.append(nxt.strip())
                i += 1
            elif not nxt.strip() and i + 1 < len(lines) and lines[i + 1].startswith(('    ', '\t')) \
                    and lines[i + 1].strip() and not FN_DEF_RE.match(lines[i + 1].strip()):
                i += 1      # blank line inside a multi-paragraph note
            else:
                break
        notes[fid] = clean_note(' '.join(p for p in parts if p)[:MAX_NOTE_CHARS * 4])
    return body, notes


MD_ESCAPE_RE = re.compile(r'\\([\\`{}\[\]()#+\-.!|>~])')


def strip_markup(text: str) -> str:
    """Images (inline and reference style), inline links (text kept), link definitions and HTML
    tags removed, repeated until nothing changes (removing one can join the pieces of another)."""
    for _ in range(5):
        before = text
        text = IMAGE_RE.sub('', text)
        text = REF_IMAGE_RE.sub('', text)
        text = LINK_RE.sub(r'\1', text)
        text = REF_DEF_RE.sub('', text)
        text = HTML_TAG_RE.sub('', text)
        text = ACTIVE_TAG_RE.sub('', text)
        if text == before:
            break
    return text


def clean_inline(text: str) -> str:
    """Markdown/HTML cleanup for display text (page markers already turned into MARKs). Escapes
    are undone first ("23\\." -> "23."), so an escaped image cannot come back to life afterwards.
    Works on whole paragraphs (multi-line tags and images)."""
    return strip_markup(MD_ESCAPE_RE.sub(r'\1', text))


def clean_label(raw: str) -> str:
    """A page label: no markup, no control characters or MARKs, at most MAX_LABEL_CHARS."""
    s = CONTROL_RE.sub(' ', clean_inline(normalize_page_label(raw)))
    return re.sub(r'\s+', ' ', s).strip()[:MAX_LABEL_CHARS]


def clean_note(text: str) -> str:
    """A footnote: no markup, at most MAX_NOTE_CHARS."""
    return clip_text(clean_inline(text).strip(), MAX_NOTE_CHARS)


def clip_text(text: str, n: int) -> str:
    """Text cut to at most n characters (at a space when there is one near the end), with '…'."""
    if len(text) <= n:
        return text
    cut = text[:n - 1]
    sp = cut.rfind(' ')
    if sp > n * 0.6:
        cut = cut[:sp]
    return cut.rstrip() + '…'


def file_blocks(text: str, is_part: bool) -> tuple[list[tuple[str, object]], dict[str, str]]:
    """Turn one file into ('heading', (level, text)) / ('para', str) / ('page', label) blocks."""
    _, body = split_frontmatter(text)
    lines = body.split('\n')
    lines, notes = extract_footnotes(lines)

    # Drop top-of-file boilerplate: title heading, by-line, source notices (first 15 non-empty lines).
    seen = 0
    for idx, line in enumerate(lines):
        if not line.strip():
            continue
        seen += 1
        if seen > 15:
            break
        if seen <= 3 and line.startswith('# '):
            lines[idx] = ''
        elif BY_LINE_RE.match(line) or BOILERPLATE_RE.match(line):
            lines[idx] = ''

    in_lc_subjects = False
    for idx, line in enumerate(lines):
        if idx < 80 and re.match(r'^\s*_{10,}\s*$', line):
            in_lc_subjects = False
            lines[idx] = ''
        elif idx < 80 and re.match(r'^\s*LC Subjects\s*:', line):
            in_lc_subjects = True
            lines[idx] = ''
        elif in_lc_subjects and idx < 80:
            lines[idx] = ''      # LC subject list continues until the separator line
        elif FIGURE_RE.match(line) or (idx < 80 and CCEL_META_RE.match(line)):
            lines[idx] = ''

    blocks: list[tuple[str, object]] = []
    para: list[str] = []

    def flush_para():
        if para:
            joined = clean_inline(strip_comments('\n'.join(para)))
            joined = '\n'.join(ln.rstrip() for ln in joined.split('\n') if ln.strip())
            if joined.strip():
                blocks.append(('para', joined))
            para.clear()

    for line in lines:
        stripped = line.strip()
        if not stripped:
            flush_para()
            continue
        hm = HEADING_RE.match(stripped)
        if hm:
            flush_para()
            head = hm.group(2).rstrip(' \t#')
            label = clean_inline(strip_comments(head))
            for raw in PAGE_RE.findall(head):
                blocks.append(('page', clean_label(raw)))
            label = re.sub(r'\s+', ' ', CONTROL_RE.sub(' ', label)).strip()[:MAX_META_CHARS]
            blocks.append(('heading', (len(hm.group(1)), label)))
            continue
        # A line that is only a page marker (possibly several) becomes page blocks.
        only_marks = PAGE_RE.sub('', stripped).strip() == '' and PAGE_RE.search(stripped)
        if only_marks and not para:
            for raw in PAGE_RE.findall(stripped):
                blocks.append(('page', clean_label(raw)))
            continue
        # Inline content: page markers -> MARK label MARK, other comments dropped (markup is
        # removed per paragraph, in flush_para).
        line = PAGE_RE.sub(lambda m: f' {MARK}{clean_label(m.group(1))}{MARK} ', line)
        line = strip_comments(line)
        if line.strip():
            para.append(line.rstrip())
    flush_para()
    return blocks, notes


# ---------------------------------------------------------------- chunking

WORD_RE = re.compile(r'[^\W\d_]+', re.U)
SENT_END_RE = re.compile(r'[.?!;:][*_)\]’”"\']*$')
SENT_START_RE = re.compile(r'^[*_(\[‘“"\']*[A-ZÆŒ]')


def strip_markdown(text: str) -> str:
    """Plain text for search/embedding: no emphasis markers, no [p. N], single spaces."""
    text = re.sub(r'\[p\. [^\]]*\]', ' ', text)
    text = text.replace(MARK, ' ')
    text = re.sub(r'(\*\*|__|\*|_)(?=\S)|(?<=\S)(\*\*|__|\*|_)', '', text)
    text = text.replace('\\', '')
    return re.sub(r'\s+', ' ', text).strip()


def _render(tokens: list[str]) -> str:
    """Tokens (words, '\\n', MARK-labels) back to display text."""
    out: list[str] = []
    for tok in tokens:
        if tok == '\n':
            out.append('\n')
        elif tok.startswith(MARK):
            out.append(f'[p. {tok[1:]}]')
        else:
            out.append(tok)
    text = ''
    for piece in out:
        if piece == '\n':
            text = text.rstrip(' ') + '\n'
        elif not text or text.endswith('\n'):
            text += piece
        else:
            text += ' ' + piece
    return text.strip()


def _tokenize_para(para: str) -> list[str]:
    toks: list[str] = []
    for li, line in enumerate(para.split('\n')):
        if li:
            toks.append('\n')
        for piece in re.split(f'({MARK}[^{MARK}]*{MARK})', line):
            if not piece:
                continue
            if piece.startswith(MARK):
                toks.append(MARK + piece.strip(MARK))
            else:
                toks.extend(piece.split())
    while toks and toks[-1] == '\n':
        toks.pop()
    return toks


def _is_word(tok: str) -> bool:
    return tok != '\n' and not tok.startswith(MARK)


def _split_long(toks: list[str]) -> list[list[str]]:
    """Split a long paragraph's tokens into pieces of about TARGET_WORDS words."""
    pieces: list[list[str]] = []
    start = 0
    n = len(toks)
    before = [0] * (n + 1)          # words in toks[:i], so each piece costs O(its length), not O(n)
    for i, t in enumerate(toks):
        before[i + 1] = before[i] + (1 if _is_word(t) else 0)
    while True:
        words_left = before[n] - before[start]
        if words_left <= MAX_WORDS:
            pieces.append(toks[start:])
            break
        # candidate boundaries between TARGET-60 and HARD_SPLIT words from start
        best, best_score, count = None, None, 0
        for i in range(start, n):
            if _is_word(toks[i]):
                count += 1
            if count < TARGET_WORDS - 60:
                continue
            if count > HARD_SPLIT:
                break
            if not _is_word(toks[i]):
                if toks[i] == '\n':
                    score = 1 + abs(count - TARGET_WORDS) / 100
                    if best_score is None or score < best_score:
                        best, best_score = i + 1, score
                continue
            nxt = next((t for t in toks[i + 1:i + 4] if _is_word(t)), '')
            if SENT_END_RE.search(toks[i]) and SENT_START_RE.match(nxt or 'A'):
                score = abs(count - TARGET_WORDS) / 100
                if best_score is None or score < best_score:
                    best, best_score = i + 1, score
        if best is None:
            # hard split at HARD_SPLIT words
            count = 0
            for i in range(start, n):
                if _is_word(toks[i]):
                    count += 1
                    if count == HARD_SPLIT:
                        best = i + 1
                        break
        pieces.append(toks[start:best])
        start = best
        while start < n and toks[start] == '\n':
            start += 1
    return [p for p in pieces if any(_is_word(t) for t in p)]


def chunk_work(repo: Path, work: Work) -> list[Passage]:
    passages: list[Passage] = []
    page: str | None = None
    heads: dict[int, str] = {}

    cur_tokens: list[list[str]] = []   # paragraphs (token lists)
    cur_words = 0
    cur_start_page: str | None = None
    cur_notes: list[str] = []
    cur_note_ids: set[str] = set()
    cur_part = 1
    file_notes: dict[str, str] = {}
    work_notes_room = MAX_WORK_NOTES_CHARS
    pending: list[str] = []            # page labels seen since the last paragraph

    def section() -> str:
        names = [re.sub(r'[*_`]', '', heads[k]).strip() for k in sorted(heads) if heads[k].strip()]
        s = ' › '.join(n for n in names if n)
        return s[:200]

    def flush():
        nonlocal cur_tokens, cur_words, cur_start_page, cur_notes, cur_note_ids, work_notes_room
        if not cur_tokens:
            return
        paras = [_render(t) for t in cur_tokens]
        text = '\n\n'.join(p for p in paras if p)
        page_end = cur_start_page
        for toks in cur_tokens:
            for t in toks:
                if t.startswith(MARK):
                    page_end = t[1:]
        alpha = len(WORD_RE.findall(strip_markdown(text)))
        if alpha >= MIN_ALPHA_WORDS:
            seq = len(passages)
            if seq >= SEQ_MULT:
                raise ValueError(f'more than {SEQ_MULT} passages: ids would run into the next work')
            notes, room = [], min(MAX_NOTES_CHARS, work_notes_room)
            for n in cur_notes:
                if room <= 0:
                    break
                notes.append(clip_text(n, room))
                room -= len(notes[-1])
            work_notes_room -= sum(len(n) for n in notes)
            passages.append(Passage(
                id=work.id * SEQ_MULT + seq, work_id=work.id, seq=seq, part=cur_part,
                page_start=cur_start_page, page_end=page_end, section=section(),
                text=clip_text(text, MAX_PASSAGE_CHARS), notes=notes, words=cur_words))
        cur_tokens, cur_words, cur_start_page = [], 0, None
        cur_notes, cur_note_ids = [], set()

    def take_notes(toks: list[str]) -> list[str]:
        """Remove footnote refs from tokens, collecting their texts."""
        out = []
        for tok in toks:
            if _is_word(tok) and '[^' in tok:
                for fid in FN_REF_RE.findall(tok):
                    if fid not in cur_note_ids and fid in file_notes:
                        cur_note_ids.add(fid)
                        cur_notes.append(file_notes[fid])
                tok = FN_REF_RE.sub('', tok)
                if not tok:
                    continue
            out.append(tok)
        return out

    def add_para(toks: list[str]):
        nonlocal cur_words, cur_start_page, page
        words = sum(1 for t in toks if _is_word(t))
        if cur_tokens and cur_words + words > MAX_WORDS:
            flush()
        if cur_tokens and pending:
            # page breaks between paragraphs of one passage stay visible in the text
            toks = [MARK + v for v in pending] + toks
        pending.clear()
        if not cur_tokens:
            # page in effect at the first word of this paragraph
            first_page = page
            for t in toks:
                if t.startswith(MARK):
                    first_page = t[1:]
                elif _is_word(t):
                    break
            cur_start_page = first_page
        cur_tokens.append(take_notes(toks))
        cur_words += words
        for t in toks:
            if t.startswith(MARK):
                page = t[1:]
        if cur_words >= MIN_WORDS:
            flush()

    for part_no, rel in enumerate(work.files, start=1):
        flush()
        cur_part = part_no
        text = read_repo_text(repo, rel)
        blocks, file_notes = file_blocks(text, is_part=len(work.files) > 1)
        for kind, value in blocks:
            if kind == 'page':
                page = value
                pending.append(value)
            elif kind == 'heading':
                level, label = value
                flush()
                if level == 1:
                    continue
                heads = {k: v for k, v in heads.items() if k < level}
                heads[level] = label
            else:
                toks = _tokenize_para(value)
                if not any(_is_word(t) for t in toks):
                    for t in toks:
                        if t.startswith(MARK):
                            page = t[1:]
                    continue
                words = sum(1 for t in toks if _is_word(t))
                if words > MAX_WORDS:
                    for piece in _split_long(toks):
                        add_para(piece)
                else:
                    add_para(toks)
        flush()
    return passages


# ---------------------------------------------------------------- CLI

def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument('--repo', type=Path, default=Path.home() / 'library-work/books')
    ap.add_argument('--ids', type=Path, default=Path(__file__).resolve().parent.parent / 'data/work_ids.json')
    ap.add_argument('--sample', type=int, default=0, help='print N passages from a few works')
    ap.add_argument('--work', help='substring of a catalog File to inspect')
    ap.add_argument('--stats', action='store_true')
    args = ap.parse_args(argv)
    rows = valid_rows(args.repo, load_catalog(args.repo))
    ids = assign_work_ids(rows, load_work_ids(args.ids))
    works = list(iter_works(args.repo, ids, rows))
    if args.work:
        works = [w for w in works if args.work in w.key]
    if args.sample:
        for w in works[: max(1, min(len(works), 3))]:
            ps = chunk_work(args.repo, w)
            print(f'== {w.id} {w.author} | {w.title} | {w.year} | {w.collection} | files={len(w.files)} passages={len(ps)}')
            for p in ps[: args.sample]:
                print(f'-- P{p.id} part={p.part} pages={p.page_start}-{p.page_end} words={p.words} section={p.section!r}')
                print(p.text[:700])
                if p.notes:
                    print('   notes:', p.notes[:3])
    if args.stats:
        total = words = 0
        sizes = []
        for w in works:
            ps = chunk_work(args.repo, w)
            total += len(ps)
            words += sum(p.words for p in ps)
            sizes.extend(p.words for p in ps)
        sizes.sort()
        print(f'works={len(works)} passages={total} words={words}')
        if sizes:
            print('passage words p5/p50/p95/max:', sizes[len(sizes)//20], sizes[len(sizes)//2], sizes[len(sizes)*19//20], sizes[-1])


if __name__ == '__main__':
    main()
