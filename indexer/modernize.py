"""Old-spelling -> modern-spelling normalizer for search and embeddings (DESIGN.md section 4).

The displayed text is never changed; this only feeds the keyword index, the embeddings and the
query-side spelling table.

    python -m indexer.modernize --build-lexicon          # writes data/lexicon.tsv
    python -m indexer.modernize --try "Chriſt dyed for ſinnes, iuſtification by faith onely"
"""
from __future__ import annotations

import argparse
import collections
import os
import re
import unicodedata
from functools import lru_cache
from pathlib import Path

DATA = Path(__file__).resolve().parent.parent / 'data'
MODERN_FOLDERS = ('CCEL_Library', 'PublicDomainLibrary', 'ChurchFathers', 'StandardEbooks', 'DocSouth')

LEXICON_MIN = 3          # a word counts as "modern" if seen this often in the modern texts
RARE_MAX = 2000          # an in-lexicon word may still be replaced if seen at most this often...
RATIO = 40               # ...and a candidate is at least this many times more frequent

# Character-level fixes applied before word mapping.
CHAR_MAP = str.maketrans({
    'ſ': 's', 'ẜ': 's', 'ẝ': 's', 'ꝛ': 'r', 'Ʋ': 'V', 'ʋ': 'v', 'ꝑ': 'p', 'ꝓ': 'p', 'ꝙ': 'q',
    '’': "'", 'ʼ': "'",
})
VV_RE = re.compile(r'(?<![A-Za-z])VV|(?<![A-Za-z])Vv|vv', )

# Words whose old forms are themselves English words (or are archaic inflections) and the
# modern form a search should match. Applied after spelling fixes.
OVERRIDES = {
    'doe': 'do', 'hee': 'he', 'shee': 'she', 'wee': 'we', 'mee': 'me', 'bee': 'be',
    'yee': 'ye', 'goe': 'go', 'soe': 'so', 'noe': 'no', 'onely': 'only',
    'hath': 'has', 'doth': 'does', 'saith': 'says', 'sayth': 'says', 'shew': 'show',
    'shewed': 'showed', 'shewn': 'shown', 'shewing': 'showing', 'shews': 'shows',
    'yt': 'that', 'wch': 'which', 'wt': 'with', 'vs': 'us',
}
# never rewrite these (common words that look like variants)
KEEP = {'then', 'than', 'thee', 'thou', 'thy', 'thine', 'ye', 'art', 'wilt', 'shalt', 'hast',
        'dost', 'didst', 'canst', 'wast', 'unto', 'upon', 'lord', 'god', 'christ', 'sin', 'sins'}

WORD_RE = re.compile(r"[^\W\d_]+(?:'[^\W\d_]+)?", re.U)
SRC = DATA / 'spelling-src'
THORN_RE = re.compile(r'\b([Yy])\*([etu])\*')
SELF_RE = re.compile(r'\b(it|him|her|my|thy|our|your|them|one|him|thee)\s+(selfe|self|selues|selves|selfes)\b', re.I)
UK_RE = re.compile(r'([ivbnlmd])our(s|ed|ing|able|ably|er|ers|ite|ites)?$')


def pre_markdown(text: str) -> str:
    """Fixes that need the Markdown: thorn abbreviations y*e* / y*t* / y*u* (the, that, thou)."""
    def thorn(m):
        word = {'e': 'the', 't': 'that', 'u': 'thou'}[m.group(2)]
        return word.capitalize() if m.group(1) == 'Y' else word
    return THORN_RE.sub(thorn, text)


def _clean_key(k: str) -> str:
    return unicodedata.normalize('NFC', k.replace('\ufeff', '').strip()).lower()


def load_external(src: Path = SRC):
    """VEP (BSD-2) and MorphAdorner (NCSA) variant->standard maps, macron expansions, Latin words."""
    vep, ma, macron, latin = {}, {}, {}, set()
    if not src.exists():
        return vep, ma, macron, latin
    ok = re.compile(r"^[^\W\d_]+(?:'[^\W\d_]+)?$", re.U)
    f = src / 'vep_standardizer_dictionary.txt'
    if f.exists():
        for line in f.read_text(encoding='utf-8', errors='replace').splitlines():
            if not line or line.startswith('#') or line.count(':') < 2:
                continue
            orig, repl, code = line.rsplit(':', 2)
            orig = _clean_key(orig)
            if code.strip() in ('0', '2') and ok.match(orig) and repl and repl != orig:
                vep[orig] = repl.replace(',', ' ').lower()
    f = src / 'ememergedspellingpairs.tab'
    if f.exists():
        for line in f.read_text(encoding='utf-8', errors='replace').splitlines():
            a, _, b = line.partition('\t')
            a, b = _clean_key(a), _clean_key(b)
            if a and b and a != b and ok.match(a) and ok.match(b):
                ma[a] = b
    f = src / 'macronmap.tab'
    if f.exists():
        for line in f.read_text(encoding='utf-8', errors='replace').splitlines():
            a, _, b = line.partition('\t')
            a, b = _clean_key(a), _clean_key(b)
            if a and b and a != b and ok.match(b):
                macron[a] = b
    f = src / 'latinwords.txt'
    if f.exists():
        for line in f.read_text(encoding='utf-8', errors='replace').splitlines():
            w = _clean_key(line)
            if w and '~' not in w:
                latin.add(w)
    return vep, ma, macron, latin



def fold_chars(text: str) -> str:
    """Long s, r rotunda, VV->W and similar character fixes; keeps everything else."""
    text = text.translate(CHAR_MAP)
    if 'v' in text or 'V' in text:
        text = re.sub(r'VV(?=[A-Z])', 'W', text)
        text = re.sub(r'(?<![A-Za-z])VV', 'W', text)
        text = re.sub(r'(?<![A-Za-z])Vv', 'W', text)
        text = text.replace('vv', 'w')
    # vowels with macron/tilde stand for a following m/n; handled per word (see _nasal_variants)
    return text


def load_lexicon(path: Path | None = None) -> dict[str, int]:
    path = path or DATA / 'lexicon.tsv'
    lex: dict[str, int] = {}
    with open(path, encoding='utf-8') as fh:
        for line in fh:
            w, _, n = line.rstrip('\n').partition('\t')
            if w and n:
                lex[w] = int(n)
    return lex


def build_lexicon(repo: Path, out: Path | None = None, min_count: int = LEXICON_MIN) -> dict[str, int]:
    """Lower-case word counts over the modern-spelling collections."""
    counts: collections.Counter = collections.Counter()
    for folder in MODERN_FOLDERS:
        if (repo / folder).is_symlink():
            continue
        for dp, _, fs in os.walk(repo / folder):      # does not enter symlinked directories
            for f in fs:
                path = Path(dp) / f
                if f.endswith('.md') and not path.is_symlink():
                    try:
                        text = fold_chars(path.read_text(encoding='utf-8'))
                    except (OSError, UnicodeDecodeError):
                        continue
                    counts.update(w.lower() for w in WORD_RE.findall(text))
    lex = {w: n for w, n in counts.items() if n >= min_count}
    out = out or DATA / 'lexicon.tsv'
    out.parent.mkdir(parents=True, exist_ok=True)
    with open(out, 'w', encoding='utf-8') as fh:
        for w, n in sorted(lex.items(), key=lambda kv: -kv[1]):
            fh.write(f'{w}\t{n}\n')
    return lex


# ---------------------------------------------------------------- candidate generation

VOWELS = set('aeiouy')
NASAL = {'ā': 'a', 'ē': 'e', 'ī': 'i', 'ō': 'o', 'ū': 'u', 'ã': 'a', 'ẽ': 'e', 'ĩ': 'i', 'õ': 'o', 'ũ': 'u'}


def _swap_positions(w: str, a: str, b: str, limit: int = 4) -> set[str]:
    """All variants of w toggling up to `limit` positions holding a or b."""
    pos = [i for i, c in enumerate(w) if c in (a, b)][:limit]
    out = set()
    for mask in range(1, 1 << len(pos)):
        chars = list(w)
        for k, i in enumerate(pos):
            if mask >> k & 1:
                chars[i] = b if chars[i] == a else a
        out.add(''.join(chars))
    return out


def _single_edits(w: str, strict: bool = False) -> set[str]:
    """One-step spelling variants. strict=True (for words that are already modern words)
    leaves out the edits that also turn ordinary words into other words: a plain final-e
    drop (cane->can) and a bare double-consonant cut (inn->in)."""
    out: set[str] = set()
    out |= _swap_positions(w, 'u', 'v')
    # i <-> j only where it could be a consonant j (before a vowel)
    pos = [i for i, c in enumerate(w) if c in 'ij' and i + 1 < len(w) and w[i + 1] in VOWELS][:3]
    for mask in range(1, 1 << len(pos)):
        chars = list(w)
        for k, i in enumerate(pos):
            if mask >> k & 1:
                chars[i] = 'j' if chars[i] == 'i' else 'i'
        out.add(''.join(chars))
    if 'y' in w[:-1]:
        out.add(w[:-1].replace('y', 'i') + w[-1])
    if w.endswith('ie') and len(w) > 3:
        out.add(w[:-2] + 'y')
    if w.endswith('ies') and len(w) > 4:
        out.add(w[:-3] + 'ies')
    if w.endswith('e') and len(w) > 2 and (not strict or len(w) >= 5 or re.search(r'([bcdfgklmnprstz])\1e$|cke$', w)):
        out.add(w[:-1])
    if w.endswith('es') and len(w) > 4 and (not strict or re.search(r'([bcdfgklmnprstz])\1es$', w)):
        out.add(w[:-2] + 's')          # sinnes -> sinns (then double reduce)
    m = re.search(r'([bcdfgklmnprstz])\1(e?s?)$', w)
    if m and len(w) > 3 and (not strict or m.group(2)):
        out.add(w[:m.start()] + m.group(1) + m.group(2))
    m2 = re.search(r'([bcdfgklmnprstz])\1e(s?)$', w)
    if m2 and len(w) > 3:
        out.add(w[:m2.start()] + m2.group(1) + m2.group(2))     # sinne -> sin, farre -> far
    if w.endswith('ll') and len(w) > 5:
        out.add(w[:-1])
    if 'ee' in w:
        out.add(w.replace('ee', 'ie', 1))
        out.add(w.replace('ee', 'e', 1))
    if w.endswith('ck') and len(w) > 4:
        out.add(w[:-1])
    if w.endswith('icke'):
        out.add(w[:-2])
    if "'d" in w:
        out.add(w.replace("'d", 'ed'))
    if "'st" in w:
        out.add(w.replace("'st", 'est'))
    if w.endswith('ely') and len(w) > 5:
        out.add(w[:-3] + 'ly')
    if 'cion' in w:
        out.add(w.replace('cion', 'tion'))
    if w.endswith('eth') and len(w) > 5:     # believeth -> believes (handled by caller as verb)
        pass
    for ch, base in NASAL.items():
        if ch in w:
            i = w.index(ch)
            out.add(w[:i] + base + 'm' + w[i + 1:])
            out.add(w[:i] + base + 'n' + w[i + 1:])
    out.discard(w)
    return out


class Modernizer:
    def __init__(self, lexicon: dict[str, int], mapping: dict[str, str] | None = None, external=None):
        self.lex = lexicon
        self.mapping = mapping or {}         # optional extra variant -> standard map
        self.vep, self.ma, self.macron, self.latin = external if external is not None else load_external()
        self.used: collections.Counter = collections.Counter()   # (variant, standard) -> count
        self._cache: dict[str, str] = {}

    def count(self, w: str) -> int:
        return self.lex.get(w, 0)

    def standard(self, w: str) -> str:
        """Modern form of a lower-case word (returns w if nothing better is found)."""
        hit = self._cache.get(w)
        if hit is not None:
            return hit
        res = self._standard(w)
        self._cache[w] = res
        return res

    def _verb_eth(self, w: str) -> str | None:
        # believeth -> believes, loveth -> loves, goeth -> goes, maketh -> makes
        if not (w.endswith('eth') and len(w) > 5):
            return None
        stem = w[:-3]
        for base in (stem + 'e', stem, stem[:-1] if len(stem) > 2 and stem[-1] == stem[-2] else None):
            if base and self.count(base) >= 50:
                if base.endswith(('s', 'x', 'z', 'ch', 'sh', 'o')):
                    return base + 'es'
                if base.endswith('y') and len(base) > 2 and base[-2] not in VOWELS:
                    return base[:-1] + 'ies'
                return base + 's'
        return None

    def fold_uk(self, w: str) -> str:
        """British -our / judgement spellings -> US forms (for matching only)."""
        m = UK_RE.search(w)
        if m and len(w) > 4:
            c = w[:m.start()] + m.group(1) + 'or' + (m.group(2) or '')
            if self.count(c) >= 50:
                return c
        if 'dgement' in w:
            c = w.replace('dgement', 'dgment')
            if self.count(c) >= 50:
                return c
        return w

    def _standard(self, w: str) -> str:
        if len(w) < 2 or w in KEEP:
            return w
        if w in OVERRIDES:
            return OVERRIDES[w]
        if w in self.mapping:
            return self.mapping[w]
        own = self.count(w)
        if own > RARE_MAX:
            return self.fold_uk(self._verb_eth(w) or w)
        latin = w in self.latin and own < 50 and w not in self.vep
        best, best_n, ratio = None, 0, RATIO
        # curated EEBO lists: trusted with a lower frequency ratio (VEP 5x, macrons/MorphAdorner 10x)
        for table, tratio in ((self.vep, 5), (self.macron, 10), (self.ma, 10)):
            c = table.get(w)
            if not c or c == w:
                continue
            n = min((self.count(x) for x in c.split()), default=0) if ' ' in c else self.count(c)
            if ' ' in c:
                n = max(n, LEXICON_MIN)          # 'tis -> it is
            if n >= LEXICON_MIN and n > best_n:
                best, best_n, ratio = c, n, tratio
        frontier = {w}
        seen = {w}
        best_depth = -1                        # -1: from a curated list
        for depth in range(3):
            nxt = set()
            for v in frontier:
                for c in _single_edits(v, strict=own >= LEXICON_MIN):
                    if c in seen:
                        continue
                    seen.add(c)
                    nxt.add(c)
                    n = self.count(c)
                    # a tie at the same distance goes to the alphabetically first word: sets are visited in an
                    # order that changes from run to run (versie -> versi or versy), so builds differed
                    if n >= LEXICON_MIN and (n > best_n or (n == best_n and best_depth == depth and c < best)):
                        best, best_n, ratio, best_depth = c, n, RATIO, depth
            # stop early only on a strong hit; a weak depth-0 hit (e.g. Latin "iesus") may lose
            # to a far more common form one edit further ("jesus")
            if best is not None and (depth >= 1 or best_n >= 1000):
                break
            if len(nxt) > 2000:
                break
            frontier = nxt
        if latin and best_n < 5000:
            best = None                        # Latin word: change it only into a very common word
        if best is not None and (own == 0 or best_n >= own * ratio):
            if ' ' in best:
                return best
            if best != w:
                # iesvs -> iesus -> jesus. Always follow the chain: skipping it when best was already looked up
                # made a word's form depend on which works this process had modernized before (so builds
                # were not reproducible). A chain cannot loop: each step needs a word at least 5x as common
                # (or, from an unknown word, a known one).
                best = self.standard(best)
            eth = self._verb_eth(best)
            return self.fold_uk(eth or best)
        eth = self._verb_eth(w)
        return self.fold_uk(eth or w)

    def modernize_text(self, text: str, record: bool = False) -> str:
        text = fold_chars(unicodedata.normalize('NFC', text))
        text = SELF_RE.sub(lambda m: m.group(1) + m.group(2), text)    # it selfe -> itselfe

        def repl(m: re.Match) -> str:
            word = m.group(0)
            low = word.lower()
            std = self.standard(low)
            if std == low:
                return word
            if record:
                self.used[(low, std)] += 1
            if word.isupper() and len(word) > 1:
                return std.upper()
            if word[0].isupper():
                return std[:1].upper() + std[1:]
            return std
        return WORD_RE.sub(repl, text)

    def wordlike_share(self, text: str) -> float:
        """Share of alphabetic tokens (2+ letters) that are known modern words after mapping."""
        toks = [t.lower() for t in WORD_RE.findall(fold_chars(text)) if len(t) >= 2]
        if not toks:
            return 0.0
        good = sum(1 for t in toks if self.count(self.standard(t)) >= LEXICON_MIN)
        return good / len(toks)


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument('--repo', type=Path, default=Path.home() / 'library-work/books')
    ap.add_argument('--build-lexicon', action='store_true')
    ap.add_argument('--try', dest='try_text')
    args = ap.parse_args(argv)
    if args.build_lexicon:
        lex = build_lexicon(args.repo)
        print(f'lexicon: {len(lex)} words -> {DATA / "lexicon.tsv"}')
    if args.try_text:
        m = Modernizer(load_lexicon())
        print(m.modernize_text(args.try_text))


if __name__ == '__main__':
    main()
