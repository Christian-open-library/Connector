<?php
/**
 * The quote checker (check_quote): did this author really write these words?
 *
 * 1. Candidates: passages holding the whole quotation as a phrase, passages holding runs of six of its
 *    words (a drifted quotation keeps some runs), the keyword search and, when Voyage is available,
 *    the meaning search.
 * 2. Each candidate is aligned with the quotation word by word (old spellings modernized, case,
 *    punctuation and "..." gaps ignored): an edit distance in which the passage may start and end
 *    anywhere. A quotation split across two passages is aligned again with the neighbouring passage.
 * 3. Verdict: word for word (no edits), worded differently (most words the same, in order), found but
 *    by someone else (the credited author's works do not hold it, another's do), or not found. The
 *    earliest holder is the work with the earliest date, where a work printed after its author died
 *    counts from the author's death (Schaff's Augustine is Augustine's, not 1887's).
 */

declare(strict_types=1);

namespace ColLibrary;

defined('ABSPATH') || exit;

/** What check_quote found, ready for Format::quoteCheck. */
final class QuoteResult
{
    public const EXACT = 'exact';
    public const REWORDED = 'reworded';
    public const OTHER_AUTHOR = 'other_author';
    public const NOT_FOUND = 'not_found';

    /**
     * @param list<array> $matches qualifying matches, one per work, earliest first (see Quote::match())
     * @param array|null  $primary the match the verdict is about
     * @param list<string> $notes
     */
    public function __construct(
        public readonly string $quote,
        public readonly ?string $author,
        public readonly string $verdict,
        public readonly ?array $primary,
        public readonly array $matches,
        public readonly ?array $earliest,
        public readonly int $authorWorks,     // works in the library by the credited author (0 when none given)
        public readonly int $phraseWorks,     // works holding the whole quotation as a phrase (capped)
        public readonly array $notes,
    ) {
    }
}

final class Quote
{
    public const MIN_WORDS = 4;
    public const MAX_WORDS = 150;
    public const MAX_CHARS = 1500;
    /** Worded differently: at least this share of the quotation's words found in order... */
    public const REWORDED_SAME = 0.6;
    /** ...and at most this many edits per word of the quotation. */
    public const REWORDED_EDITS = 0.45;
    /** Quotations shorter than this many words must match with at most one edit. */
    public const SHORT_WORDS = 8;
    /** Works holding the whole phrase: above this, the wording is common (a Bible verse or proverb). */
    public const COMMON_WORKS = 25;

    private const NEAR_WORDS = 8;          // distinct content words in the NEAR query
    private const NEAR_IDS = 40;
    private const WINDOW_MAX = 12;         // runs searched
    private const PHRASE_IDS = 200;        // passages holding the whole quotation, read at most
    private const WINDOW_IDS = 30;
    private const VECTOR_IDS = 40;
    private const ALIGN_MAX = 120;         // candidates aligned (passages holding the whole phrase come first)
    private const EDGE_RETRIES = 10;       // best candidates aligned again with a neighbouring passage
    private const MIN_COVER = 0.34;        // share of the quotation's distinct words a candidate must hold
    private const LIST_MAX = 8;            // other works listed
    private const MIN_SECONDS = 2.0;
    private const MIN_SECONDS_EMBED = 6.0;

    /** Words of a page marker or a word, with offsets (old letter forms and curly apostrophes included). */
    private const TOKEN_RE = "/\\[p\\. ([^\\]]{1,40})\\]|\\p{L}[\\p{L}\\p{M}]*(?:['’ʼ]\\p{L}[\\p{L}\\p{M}]*)?|\\p{N}+/u";
    private const ELLIPSIS_RE = '/(?:\.\s*){3,}|…|\[\s*(?:\.\s*){3,}\]/u';
    /** Words a writer uses when he is quoting someone else, just before the quoted words. */
    private const QUOTING_RE = '/(?:\b(?:saith|says|said|sayes|writes|wrote|observes|observed|tells us|speaks|'
        . 'remarks|an old (?:writer|divine|author|puritan|saint)|one (?:has|hath) (?:said|well said)|as one says|'
        . 'quoth|in the words of)\b[^.?!]{0,60}$|[“"‘]\s*$)/iu';

    /** @var array<string,true> */
    private array $stop = [];
    private Search $search;
    /** @var array<string,int> word => id, so the alignment compares integers */
    private array $ids = [];
    /** @var list<string> id => word (array keys turn "1668" into an integer, so the words are kept here) */
    private array $words = [];
    public array $trace = [];

    public function __construct(
        private readonly Store $store,
        private readonly Voyage $voyage,
        private readonly float $deadline,
        int $nprobe = 32,
        private readonly ?Log $log = null,
    ) {
        $this->search = new Search($store, $voyage, $deadline, $nprobe, $log);
    }

    /** @throws SearchInputException */
    public function check(string $quote, ?string $author): QuoteResult
    {
        $quote = trim($quote);
        $q = $this->quoteTokens($quote);
        $n = count($q['ids']);
        if ($n < self::MIN_WORDS) {
            throw new SearchInputException('The quotation is too short to check: give at least ' . self::MIN_WORDS
                . ' words of it.');
        }
        if ($n > self::MAX_WORDS) {
            throw new SearchInputException('The quotation is too long to check at once (at most ' . self::MAX_WORDS
                . ' words). Give its most distinctive sentence or two.');
        }
        $notes = [];
        $authorKeys = $author !== null ? self::authorWords($author) : [];
        $authorWorks = 0;
        if ($authorKeys !== []) {
            foreach ($this->store->allWorks() as $w) {
                if (self::byAuthor($w, $authorKeys)) {
                    $authorWorks++;
                }
            }
        }

        // 1. Candidates
        $t0 = microtime(true);
        $phraseIds = [];
        $cands = [];   // id => true, in order of strength
        foreach ($q['segments'] as $seg) {
            if (count($seg) >= self::MIN_WORDS && $this->phraseSafe($seg) && $this->remaining() > self::MIN_SECONDS) {
                $match = Text::ftsPhrase($seg);
                try {
                    foreach ($this->store->matchInOrder($match, null, self::PHRASE_IDS, 0) as $id) {
                        $phraseIds[$id] = true;
                    }
                } catch (\PDOException $e) {
                    $this->log?->error('quote_phrase_failed', ['error' => Text::logMessage($e)]);
                }
            }
        }
        $cands += $phraseIds;
        $this->trace['phrase_ms'] = (int) round((microtime(true) - $t0) * 1000);
        // Runs of three content words close together: a drifted quotation keeps some of them.
        $windowHits = [];
        foreach ($this->windows($q['segments']) as [$words, $span]) {
            if ($this->remaining() < self::MIN_SECONDS) {
                break;
            }
            try {
                foreach (array_keys($this->store->keywordSearch(self::near($words, $span), null, self::WINDOW_IDS)) as $id) {
                    $windowHits[$id] = ($windowHits[$id] ?? 0) + 1;
                }
            } catch (\PDOException $e) {
                $this->log?->error('quote_window_failed', ['error' => Text::logMessage($e)]);
            }
        }
        arsort($windowHits);
        $this->trace['window_ms'] = (int) round((microtime(true) - $t0) * 1000);
        $cands += array_fill_keys(array_keys($windowHits), true);
        if ($this->remaining() > self::MIN_SECONDS) {
            $cands += array_fill_keys($this->nearHits($q['segments'], count($cands) < 5), true);
        }
        $this->trace['near_ms'] = (int) round((microtime(true) - $t0) * 1000);
        if ($this->search->vectorProblem() === null && $this->remaining() > self::MIN_SECONDS_EMBED) {
            try {
                $vec = $this->voyage->embedQuery($this->search->modernQuery(str_replace('…', ' ', $quote)), $this->deadline);
                $vnotes = [];
                $cands += array_fill_keys($this->search->vectorSearch($vec, null, self::VECTOR_IDS, $vnotes), true);
            } catch (VoyageException | \RuntimeException | \LogicException $e) {
                $this->log?->error('quote_vector_failed', ['error' => $e->getMessage()]);
                $notes[] = 'The meaning search was unavailable, so a quotation reworded beyond its key words may be missed.';
            }
        }
        $this->trace['candidates'] = count($cands);
        $this->trace['phrase_hits'] = count($phraseIds);

        $this->trace['find_ms'] = (int) round((microtime(true) - $t0) * 1000);
        $t0 = microtime(true);
        // 2. Align
        $passages = $this->store->passages(array_slice(array_map('intval', array_keys($cands)), 0, self::PHRASE_IDS + 200));
        $works = $this->store->works(array_values(array_unique(array_column($passages, 'work_id'))));
        $docs = [];
        foreach ($passages as $id => $p) {
            if (isset($works[$p['work_id']])) {
                $p['work'] = $works[$p['work_id']];
                $docs[$id] = ['passages' => [$p], 'tokens' => $this->docTokens([$p])];
            }
        }
        $wanted = array_count_values(array_merge(...array_map(fn (array $s): array => $this->contentIds($s), $q['idSegs'])));
        $cover = [];
        foreach ($docs as $id => $d) {
            $have = array_flip(array_column($d['tokens'], 'id'));
            $hit = 0;
            foreach ($wanted as $wid => $_) {
                $hit += isset($have[$wid]) ? 1 : 0;
            }
            $cover[$id] = $wanted === [] ? 1.0 : $hit / count($wanted);
        }
        $this->trace['tokens_ms'] = (int) round((microtime(true) - $t0) * 1000);
        $t0 = microtime(true);
        $order = array_keys($cover);
        usort($order, static fn (int $a, int $b): int
            => [isset($phraseIds[$b]), $cover[$b]] <=> [isset($phraseIds[$a]), $cover[$a]]);
        $results = [];
        foreach (array_slice($order, 0, self::ALIGN_MAX) as $id) {
            if (!isset($phraseIds[$id]) && $cover[$id] < self::MIN_COVER) {
                break;
            }
            if ($this->remaining() < 1.0) {
                $notes[] = 'The check ran short of time, so not every possible passage was compared.';
                break;
            }
            $results[$id] = $this->alignDoc($q, $docs[$id]);
        }
        // A quotation that runs over the end or start of a passage: align once more with the neighbour.
        uasort($results, static fn (array $a, array $b): int => $a['edits'] <=> $b['edits']);
        $retries = 0;
        foreach ($results as $id => $r) {
            if ($r['edits'] === 0 || $retries >= self::EDGE_RETRIES || $this->remaining() < 1.0) {
                continue;
            }
            $tokens = $docs[$id]['tokens'];
            $atStart = $r['from'] <= 2;
            $atEnd = $r['to'] >= count($tokens) - 3;
            if (!$atStart && !$atEnd) {
                continue;
            }
            $retries++;
            $p = $docs[$id]['passages'][0];
            $near = $this->store->neighbours($p['work_id'], $p['seq'], $atStart ? 1 : 0, $atEnd ? 1 : 0);
            $list = [$p];
            if ($atStart && $near['before'] !== []) {
                $prev = $near['before'][count($near['before']) - 1];
                $prev['work'] = $p['work'];
                array_unshift($list, $prev);
            }
            if ($atEnd && $near['after'] !== []) {
                $next = $near['after'][0];
                $next['work'] = $p['work'];
                $list[] = $next;
            }
            if (count($list) > 1) {
                $doc = ['passages' => $list, 'tokens' => $this->docTokens($list)];
                $again = $this->alignDoc($q, $doc);
                if ($again['edits'] < $r['edits']) {
                    $docs[$id] = $doc;
                    $results[$id] = $again;
                }
            }
        }

        $this->trace['align_ms'] = (int) round((microtime(true) - $t0) * 1000);
        $this->trace['aligned'] = count($results);
        // 3. Matches, best per work
        $byWork = [];
        foreach ($results as $id => $r) {
            $r = $this->allowMisreads($r, $q, $docs[$id]);
            if (!$this->qualifies($r, $n)) {
                continue;
            }
            $m = $this->match($q, $docs[$id], $r, $quote);
            $w = $m['work']['id'];
            if (!isset($byWork[$w]) || $m['edits'] < $byWork[$w]['edits']) {
                $byWork[$w] = $m;
            }
        }
        $matches = array_values($byWork);
        usort($matches, static fn (array $a, array $b): int
            => [$a['when'], $a['edits'], $a['passage']['id']] <=> [$b['when'], $b['edits'], $b['passage']['id']]);
        $phraseWorks = count(array_unique(array_map(static fn (int $id): int => $passages[$id]['work_id'],
            array_filter(array_keys($phraseIds), static fn (int $id): bool => isset($passages[$id])))));

        $exact = array_values(array_filter($matches, static fn (array $m): bool => $m['edits'] === 0));
        $earliest = $exact[0] ?? $matches[0] ?? null;
        $primary = null;
        if ($matches === []) {
            $verdict = QuoteResult::NOT_FOUND;
        } elseif ($authorKeys === []) {
            $primary = $earliest;
            $verdict = $primary['edits'] === 0 ? QuoteResult::EXACT : QuoteResult::REWORDED;
        } else {
            $his = array_values(array_filter($matches, static fn (array $m): bool => self::byAuthor($m['work'], $authorKeys)));
            if ($his === []) {
                $primary = $earliest;
                $verdict = QuoteResult::OTHER_AUTHOR;
            } else {
                usort($his, static fn (array $a, array $b): int => [$a['edits'], $a['when']] <=> [$b['edits'], $b['when']]);
                $primary = $his[0];
                $verdict = $primary['edits'] === 0 ? QuoteResult::EXACT : QuoteResult::REWORDED;
                // His book has the words, but credits them to someone else (a compilation).
                if ($primary['credit'] !== null && !self::byAuthor(['author' => $primary['credit'], 'author_slug' => ''], $authorKeys)) {
                    $verdict = QuoteResult::OTHER_AUTHOR;
                }
            }
        }
        if ($earliest !== null && $primary !== null && $earliest['work']['id'] === $primary['work']['id']) {
            $earliest = $primary;
        }
        return new QuoteResult($quote, $author, $verdict, $primary, $matches, $earliest, $authorWorks, $phraseWorks,
            $notes);
    }

    // ------------------------------------------------------------------------------- tokens

    /**
     * Tokens of a text in modern spelling, each with the byte offset and length of the word it came
     * from (a word whose modern form has two words gives two tokens) and the printed page it is on.
     * @return list<array{w:string, off:int, len:int, page:?string}>
     */
    private function tokens(string $text, ?string $page): array
    {
        if (preg_match_all(self::TOKEN_RE, $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $raw = [];
        foreach ($m as $hit) {
            if (isset($hit[1]) && $hit[1][1] >= 0) {
                $page = trim($hit[1][0]);
                continue;
            }
            foreach (Text::words(Text::normalize($hit[0][0])) as $w) {
                $raw[] = ['w' => $w, 'off' => $hit[0][1], 'len' => strlen($hit[0][0]), 'page' => $page];
            }
        }
        $spelling = $this->store->spellings(array_values(array_unique(array_column($raw, 'w'))));
        $out = [];
        foreach ($raw as $t) {
            foreach (Text::modernTokens([$t['w']], $spelling) as $w) {
                $last = count($out) - 1;
                // "it selfe" -> "itself", as the indexer joins them
                if ($last >= 0 && ($w === 'self' || $w === 'selves')
                    && in_array($out[$last]['w'], ['it', 'him', 'her', 'my', 'thy', 'our', 'your', 'them', 'one', 'thee'], true)) {
                    $out[$last]['w'] .= $w;
                    $out[$last]['len'] = $t['off'] + $t['len'] - $out[$last]['off'];
                    continue;
                }
                $t2 = $t;
                $t2['w'] = $w;
                $out[] = $t2;
            }
        }
        foreach ($out as $i => $t) {
            if (!isset($this->ids[$t['w']])) {
                $this->ids[$t['w']] = count($this->words);
                $this->words[] = (string) $t['w'];
            }
            $out[$i]['id'] = $this->ids[$t['w']];
        }
        return $out;
    }

    /**
     * The quotation's tokens, split into segments at "..." gaps.
     * @return array{segments: list<list<string>>, idSegs: list<list<int>>, ids: list<int>, gaps: array<int,true>,
     *               tokens: list<array>}
     */
    private function quoteTokens(string $quote): array
    {
        $quote = mb_substr($quote, 0, self::MAX_CHARS);
        $tokens = [];
        $segments = [];
        $idSegs = [];
        $gaps = [];
        $base = 0;
        foreach (preg_split(self::ELLIPSIS_RE, $quote, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [] as [$part, $offset]) {
            $toks = $this->tokens($part, null);
            if ($toks === []) {
                continue;
            }
            if ($tokens !== []) {
                $gaps[count($tokens)] = true;   // free skip before this token
            }
            foreach ($toks as $t) {
                $t['off'] += $offset;
                $tokens[] = $t;
            }
            $segments[] = array_column($toks, 'w');
            $idSegs[] = array_column($toks, 'id');
        }
        return ['segments' => $segments, 'idSegs' => $idSegs, 'ids' => array_column($tokens, 'id'), 'gaps' => $gaps,
                'tokens' => $tokens];
    }

    /**
     * Tokens of one or more consecutive passages, each tagged with its passage index.
     * @param list<array> $passages  @return list<array>
     */
    private function docTokens(array $passages): array
    {
        $out = [];
        foreach ($passages as $k => $p) {
            foreach ($this->tokens($p['text'], $p['page_start']) as $t) {
                $t['p'] = $k;
                $out[] = $t;
            }
        }
        return $out;
    }

    /** @param list<int> $ids @return list<int> the ids of words that are not stopwords (all of them if none is) */
    private function contentIds(array $ids): array
    {
        $words = $this->words;
        $content = array_values(array_filter($ids, static fn (int $id): bool => !Text::isStopword($words[$id])));
        return $content !== [] ? $content : $ids;
    }

    /** True when a phrase query for these words is cheap enough (Search::exact's limits). @param list<string> $t */
    private function phraseSafe(array $t): bool
    {
        if (count($t) > Search::MAX_QUERY_WORDS || max(array_count_values($t)) > Search::MAX_REPEATS) {
            return false;
        }
        $common = array_filter($t, static fn (string $w): bool => Text::isStopword($w));
        return count($common) < count($t) && count($common) <= Search::MAX_COMMON_TOKENS
            && count(array_unique($common)) <= Search::MAX_COMMON_WORDS;
    }

    /**
     * Passages holding the quotation's distinct content words close together, and when $relax, the
     * same without one word at a time (longest words first).
     * @param list<list<string>> $segments  @return list<int>
     */
    private function nearHits(array $segments, bool $relax): array
    {
        $all = array_merge(...$segments);
        $words = array_values(array_unique(array_filter($all, static fn (string $w): bool => !Text::isStopword($w))));
        usort($words, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $words = array_slice($words, 0, self::NEAR_WORDS);
        if (count($words) < 2) {
            return [];
        }
        $span = count($all) + 10;
        $hits = [];
        try {
            $hits = array_keys($this->store->keywordSearch(self::near($words, $span), null, self::NEAR_IDS));
            if ($relax && count($hits) < 5 && count($words) >= 4) {
                foreach (array_slice($words, 0, 3) as $drop) {
                    if ($this->remaining() < self::MIN_SECONDS) {
                        break;
                    }
                    $rest = array_values(array_diff($words, [$drop]));
                    $hits = array_merge($hits, array_keys($this->store->keywordSearch(self::near($rest, $span), null, self::NEAR_IDS)));
                }
            }
        } catch (\PDOException $e) {
            $this->log?->error('quote_near_failed', ['error' => Text::logMessage($e)]);
        }
        return array_values(array_unique(array_map('intval', $hits)));
    }

    /** FTS5 query: these words within $span tokens of each other in the body. @param list<string> $words */
    public static function near(array $words, int $span): string
    {
        return 'body : NEAR(' . implode(' ', array_map([Text::class, 'quote'], $words)) . ', ' . $span . ')';
    }

    /**
     * Runs of three consecutive content words (two when the quotation has only two), each with the
     * span they may be spread over, sampled evenly over the quotation.
     * @param list<list<string>> $segments  @return list<array{0: list<string>, 1: int}>
     */
    private function windows(array $segments): array
    {
        $runs = [];
        foreach ($segments as $seg) {
            $pos = [];
            foreach ($seg as $i => $w) {
                if (!Text::isStopword($w)) {
                    $pos[] = $i;
                }
            }
            $size = min(3, count($pos));
            if ($size < 2) {
                continue;
            }
            for ($k = 0; $k + $size <= count($pos); $k++) {
                $at = array_slice($pos, $k, $size);
                $words = array_map(static fn (int $i): string => $seg[$i], $at);
                if (count(array_unique($words)) === $size) {
                    $runs[] = [$words, $at[$size - 1] - $at[0] + 4];
                }
            }
        }
        if (count($runs) <= self::WINDOW_MAX) {
            return $runs;
        }
        $out = [];
        $step = (count($runs) - 1) / (self::WINDOW_MAX - 1);
        for ($k = 0; $k < self::WINDOW_MAX; $k++) {
            $out[] = $runs[(int) round($k * $step)];
        }
        return $out;
    }

    // ---------------------------------------------------------------------------- alignment

    /**
     * Aligns the quotation with the best stretch of a document.
     * @return array{edits:int, same:int, from:int, to:int, ops:list<array>}
     */
    private function alignDoc(array $q, array $doc): array
    {
        $p = array_column($doc['tokens'], 'id');
        $n = count($q['ids']);
        $start = 0;
        $m = count($p);
        // Long passages: only the stretch where the quotation's words are densest (and a margin).
        $span = $q['gaps'] === [] ? $n + 10 : 3 * $n + 10;
        if ($m > $span + 2 * $n + 20) {
            [$a, $b] = self::densest($p, array_flip($this->contentIds($q['ids'])), $span);
            $start = max(0, $a - $n - 10);
            $p = array_slice($p, $start, min($m, $b + $n + 10) - $start);
        }
        $r = self::align($q['ids'], $q['gaps'], $p);
        foreach ($r['ops'] as $k => $op) {
            if ($op[2] !== null) {
                $r['ops'][$k][2] += $start;
            }
        }
        $r['from'] += $start;
        $r['to'] += $start;
        return $r;
    }

    /**
     * The window of $len tokens holding the most distinct wanted ids.
     * @param list<int> $p  @param array<int,int> $wanted  @return array{0:int,1:int} start, end (exclusive)
     */
    public static function densest(array $p, array $wanted, int $len): array
    {
        $count = [];
        $distinct = 0;
        $best = -1;
        $bestAt = 0;
        $m = count($p);
        for ($j = 0; $j < $m; $j++) {
            if (isset($wanted[$p[$j]]) && ($count[$p[$j]] = ($count[$p[$j]] ?? 0) + 1) === 1) {
                $distinct++;
            }
            $out = $j - $len;
            if ($out >= 0 && isset($wanted[$p[$out]]) && --$count[$p[$out]] === 0) {
                $distinct--;
            }
            if ($distinct > $best) {
                $best = $distinct;
                $bestAt = max(0, $j - $len + 1);
            }
        }
        return [$bestAt, min($m, $bestAt + $len)];
    }

    /** Alignment costs: a word kept scores a little below zero, so of two stretches with as many edits the one
     * keeping more words wins; an edit costs more than any number of kept words can make up in practice. */
    private const SAME = -1;
    private const EDIT = 4;

    /**
     * Word-level edit distance between the whole quotation and the best stretch of $p (which may start
     * and end anywhere); a gap in the quotation ("...") skips passage words for free.
     * ops: ['=', qi, pj] same word, ['~', qi, pj] word changed, ['+', qi, null] word in the quotation
     * only, ['-', null, pj] word in the passage only, ['_', null, pj] passage word under a "..." gap.
     * @param list<int> $q  @param array<int,true> $gaps  @param list<int> $p
     * @return array{edits:int, same:int, from:int, to:int, ops:list<array>}
     */
    public static function align(array $q, array $gaps, array $p): array
    {
        $n = count($q);
        $m = count($p);
        $prev = array_fill(0, $m + 1, 0);
        $back = [];
        for ($i = 1; $i <= $n; $i++) {
            $qi = $q[$i - 1];
            $cur = [$i * self::EDIT];
            $bp = str_repeat('U', $m + 1);
            for ($j = 1; $j <= $m; $j++) {
                $best = $prev[$j - 1] + ($qi === $p[$j - 1] ? self::SAME : self::EDIT);
                $how = 'D';
                if ($prev[$j] + self::EDIT < $best) {
                    $best = $prev[$j] + self::EDIT;
                    $how = 'U';
                }
                if ($cur[$j - 1] + self::EDIT < $best) {
                    $best = $cur[$j - 1] + self::EDIT;
                    $how = 'L';
                }
                $cur[$j] = $best;
                $bp[$j] = $how;
            }
            if (isset($gaps[$i])) {   // a gap follows quotation word i
                for ($j = 1; $j <= $m; $j++) {
                    if ($cur[$j - 1] < $cur[$j]) {
                        $cur[$j] = $cur[$j - 1];
                        $bp[$j] = 'G';
                    }
                }
            }
            $back[$i] = $bp;
            $prev = $cur;
        }
        $end = 0;
        for ($j = 1; $j <= $m; $j++) {
            if ($prev[$j] < $prev[$end]) {
                $end = $j;
            }
        }
        $ops = [];
        $i = $n;
        $j = $end;
        while ($i > 0) {
            $how = $j === 0 ? 'U' : $back[$i][$j];
            if ($how === 'D') {
                $ops[] = [$q[$i - 1] === $p[$j - 1] ? '=' : '~', $i - 1, $j - 1];
                $i--;
                $j--;
            } elseif ($how === 'U') {
                $ops[] = ['+', $i - 1, null];
                $i--;
            } else {
                $ops[] = [$how === 'G' ? '_' : '-', null, $j - 1];
                $j--;
            }
        }
        $ops = array_reverse($ops);
        // Trim passage-only words at the ends (they cost nothing to leave out of the stretch).
        while ($ops !== [] && in_array($ops[0][0], ['-', '_'], true)) {
            array_shift($ops);
        }
        $edits = count(array_filter($ops, static fn (array $o): bool => in_array($o[0], ['~', '+', '-'], true)));
        $from = null;
        $to = null;
        foreach ($ops as $op) {
            if ($op[2] !== null) {
                $from ??= $op[2];
                $to = $op[2];
            }
        }
        $same = count(array_filter($ops, static fn (array $o): bool => $o[0] === '='));
        return ['edits' => $edits, 'same' => $same, 'from' => $from ?? 0, 'to' => $to ?? 0, 'ops' => $ops];
    }

    /**
     * In a work that is uncorrected OCR of a scan, a changed word that differs from the quotation's by a letter
     * or so ("liis" for "his", "tbe" for "the") is most likely a misreading of the scan, not a different word:
     * it counts as kept, and 'misread' says how many there were.
     */
    private function allowMisreads(array $r, array $q, array $doc): array
    {
        $r['misread'] = 0;
        if (!self::fromScan($doc['passages'][0]['work']) || $r['edits'] === 0) {
            return $r;
        }
        $words = $this->words;
        foreach ($r['ops'] as $op) {
            if ($op[0] === '~' && self::misread($words[$q['ids'][$op[1]]], $words[$doc['tokens'][$op[2]]['id']])) {
                $r['misread']++;
            }
        }
        $r['edits'] -= $r['misread'];
        $r['same'] += $r['misread'];
        return $r;
    }

    /**
     * True for a work whose text is OCR of a scan: marked so, or from the Internet Archive (whose texts all come
     * from scans; 56 older ones lack the mark in the catalog).
     */
    public static function fromScan(array $work): bool
    {
        return (int) ($work['ocr'] ?? 0) === 1 || ($work['collection'] ?? '') === 'internet-archive';
    }

    /** True when two words differ only as an OCR misreading would: one letter in 3+, two in 7+. */
    public static function misread(string $a, string $b): bool
    {
        // Letters a scan is often misread as: "liis" for "his", "rn" for "m", "cl" for "d", "ii" for "u".
        $fold = static fn (string $w): string => strtr($w, ['li' => 'h', 'rn' => 'm', 'cl' => 'd', 'ii' => 'u', 'vv' => 'w',
                                                         '1' => 'l', '0' => 'o']);
        if ($fold($a) === $fold($b)) {
            return true;
        }
        $short = min(mb_strlen($a), mb_strlen($b));
        if ($short < 3 || abs(mb_strlen($a) - mb_strlen($b)) > 1) {
            return false;
        }
        return levenshtein($a, $b) <= ($short >= 7 ? 2 : 1);
    }

    /** Close enough to call it the same quotation. */
    private function qualifies(array $r, int $n): bool
    {
        if ($r['edits'] === 0) {
            return true;
        }
        if ($n < self::SHORT_WORDS) {
            return $r['edits'] <= 1 && $r['same'] >= $n - 1;
        }
        return $r['same'] >= self::REWORDED_SAME * $n && $r['edits'] <= self::REWORDED_EDITS * $n;
    }

    // ------------------------------------------------------------------------------ matches

    /**
     * A match as Format shows it: passage, work, page, the library's wording of the stretch, the sentences
     * around it, the wording side by side with the differences marked, and the date used to order works.
     */
    private function match(array $q, array $doc, array $r, string $quote): array
    {
        $tokens = $doc['tokens'];
        $first = $tokens[$r['from']];
        $last = $tokens[$r['to']];
        $passage = $doc['passages'][$first['p']];
        $work = $passage['work'];
        // Library text of the stretch and the sentences around it (in one passage, or across two).
        $texts = array_column($doc['passages'], 'text');
        $joined = '';
        $shift = [];
        foreach ($texts as $k => $t) {
            $shift[$k] = strlen($joined);
            $joined .= ($k > 0 ? "\n\n" : '') . $t;
            if ($k > 0) {
                $shift[$k] += 2;
            }
        }
        $a = $shift[$first['p']] + $first['off'];
        $b = $shift[$last['p']] + $last['off'] + $last['len'];
        $words = substr($joined, $a, $b - $a);
        $before = self::sentencesBefore(substr($joined, 0, $a));
        $after = self::sentencesAfter(substr($joined, $b));
        $credit = self::creditAfter(substr($joined, $b), $work);
        $quoting = preg_match(self::QUOTING_RE, mb_substr(Text::plain(self::lastBytes(substr($joined, 0, $a), 600)), -160)) === 1;

        // Side by side: words that differ in **bold**.
        $mark = [];       // library token index => true
        $mine = [];       // quotation token index => true
        foreach ($r['ops'] as [$op, $qi, $pj]) {
            if ($op === '~' || $op === '-') {
                $mark[$pj] = true;
            }
            if ($op === '~' || $op === '+') {
                $mine[$qi] = true;
            }
        }
        $libMarked = self::marked($joined, $tokens, $shift, $r['from'], $r['to'], $mark);
        $quoteMarked = self::markedQuote($quote, $q['tokens'], $mine);
        $edits = [];
        foreach (self::groupChanges($r['ops']) as $c) {
            $lib = implode(' ', array_map(static fn (int $j): string => self::word($joined, $tokens[$j], $shift), $c['lib']));
            $you = implode(' ', array_map(static fn (int $i): string => substr($quote, $q['tokens'][$i]['off'], $q['tokens'][$i]['len']), $c['you']));
            $edits[] = $lib !== '' && $you !== '' ? "\"$you\" for \"$lib\"" : ($you !== '' ? "added \"$you\"" : "left out \"$lib\"");
        }
        return [
            'passage' => $passage,
            'passages' => $doc['passages'],
            'work' => $work,
            'page' => $first['page'],
            'page_end' => $last['page'],
            'edits' => $r['edits'],
            'misread' => $r['misread'] ?? 0,
            'same' => $r['same'],
            'words' => count($q['ids']),
            'text' => $words,
            'before' => $before,
            'after' => $after,
            'quoting' => $quoting,
            'credit' => $credit,
            'lib_marked' => $libMarked,
            'quote_marked' => $quoteMarked,
            'changes' => $edits,
            'when' => self::when($work),
        ];
    }

    /**
     * Year used to order works: the year printed, or the author's death year when that is earlier (a
     * later edition or translation), or about 30 years after birth when neither is known.
     */
    public static function when(array $work): int
    {
        $year = $work['year'] ?? null;
        $note = (string) ($work['author_note'] ?? '');
        $death = null;
        $birth = null;
        if (preg_match('/^(?:c\. )?(\d{1,4})\s*(?:BC\s*)?–\s*(?:c\. )?(\d{1,4})(\s*BC)?/u', $note, $m) === 1) {
            $bc = isset($m[3]) && $m[3] !== '';
            $birth = $bc ? -(int) $m[1] : (int) $m[1];
            $death = $bc ? -(int) $m[2] : (int) $m[2];
        } elseif (preg_match('/^d\. (\d{3,4})/u', $note, $m) === 1) {
            $death = (int) $m[1];
        } elseif (preg_match('/^(?:fl\.|c\.) (\d{3,4})/u', $note, $m) === 1) {
            $birth = (int) $m[1] - 30;
        }
        if ($year !== null) {
            return $death !== null ? min((int) $year, $death) : (int) $year;
        }
        return $death ?? ($birth !== null ? $birth + 30 : 9999);
    }

    /** Up to two sentences (about 400 characters) before a point in the text. */
    private static function sentencesBefore(string $text): string
    {
        $tail = self::lastBytes($text, 1200);
        $cut = strlen($text) > 1200;
        if (preg_match_all('/[.?!](?=["”’)\]]*\s)/u', $tail, $m, PREG_OFFSET_CAPTURE) && count($m[0]) >= 1) {
            $marks = $m[0];
            $count = count($marks);
            // The sentence the quotation starts in, plus one before it.
            $k = $count >= 2 ? $marks[$count - 2][1] + 1 : 0;
            if ($k > 0 || $cut) {
                $tail = substr($tail, $k);
            }
        }
        $tail = ltrim($tail);
        if (mb_strlen($tail) > 450) {
            $tail = '…' . mb_substr($tail, -450);
        }
        return $tail;
    }

    /** The rest of the sentence after a point in the text, plus one more (about 400 characters). */
    private static function sentencesAfter(string $text): string
    {
        $head = mb_strcut($text, 0, 1200, 'UTF-8');
        if (preg_match_all('/[.?!](?=["”’)\]]*(?:\s|$))/u', $head, $m, PREG_OFFSET_CAPTURE) && count($m[0]) >= 1) {
            $k = count($m[0]) >= 2 ? $m[0][1][1] + 1 : $m[0][0][1] + 1;
            $head = substr($head, 0, $k);
        }
        $head = rtrim($head);
        if (mb_strlen($head) > 450) {
            $head = mb_substr($head, 0, 450) . '…';
        }
        return $head;
    }

    /**
     * The name in a credit line ending the paragraph the words are in, as compilations such as The Treasury of
     * David give their sources ("... the metal stays. — Thomas Adams." or "— John Colquhoun, in \"A Treatise on
     * Spiritual Comfort,\" 1814."); null when there is none, when it is only initials (the compiler's own, as
     * "C. H. S."), or when it names the work's own author.
     */
    public static function creditAfter(string $rest, array $work): ?string
    {
        $para = preg_split('/\n[ \t]*\n/u', mb_strcut($rest, 0, 8000, 'UTF-8'), 2)[0] ?? '';
        $at = max((int) strrpos($para, '—'), (int) strrpos($para, '--'));
        if ($at === 0 && !str_starts_with($para, '—') && !str_starts_with($para, '--')) {
            return null;
        }
        $tail = trim(ltrim(substr($para, $at), '—-'));
        if ($tail === '' || strlen($tail) > 180) {
            return null;
        }
        $part = "(?:[A-Z][\p{L}'’]*\.?|[A-Z]\.|of|de|du|van|von|la|le|the)";
        if (preg_match("/^((?:$part\s+){0,5}$part)\s*(?:[.,;:]|\s+in\b|\s+on\b|\(|$)/u", $tail, $m) !== 1) {
            return null;
        }
        $name = trim($m[1], " \t\n.,;");
        $words = array_values(array_filter(explode(' ', Text::looseKey($name)), static fn (string $w): bool => strlen($w) >= 3));
        if ($words === [] || (count($words) === 1 && in_array($words[0], ['amen', 'selah', 'that', 'this', 'lord', 'god',
                'christ', 'jesus', 'yea', 'nay', 'and', 'but', 'the', 'thus', 'see', 'ibid', 'idem', 'ver', 'verse'], true))) {
            return null;
        }
        $own = array_flip(explode(' ', Text::looseKey($work['author'] . ' ' . $work['author_slug'])));
        foreach ($words as $w) {
            if (isset($own[$w])) {
                return null;
            }
        }
        return Text::oneLine($name, 80);
    }

    /** The last $n bytes of a text, not starting inside a character. */
    private static function lastBytes(string $text, int $n): string
    {
        return strlen($text) <= $n ? $text : mb_strcut($text, strlen($text) - $n, null, 'UTF-8');
    }

    private static function word(string $joined, array $t, array $shift): string
    {
        return substr($joined, $shift[$t['p']] + $t['off'], $t['len']);
    }

    /** The library's stretch with the words that differ in **bold**. */
    private static function marked(string $joined, array $tokens, array $shift, int $from, int $to, array $mark): string
    {
        $out = '';
        $pos = $shift[$tokens[$from]['p']] + $tokens[$from]['off'];
        for ($j = $from; $j <= $to; $j++) {
            $t = $tokens[$j];
            $a = $shift[$t['p']] + $t['off'];
            if ($a < $pos) {      // one old word that became two modern tokens
                continue;
            }
            $out .= substr($joined, $pos, $a - $pos);
            $w = substr($joined, $a, $t['len']);
            $out .= isset($mark[$j]) ? "**$w**" : $w;
            $pos = $a + $t['len'];
        }
        return $out;
    }

    /** The quotation with its words that differ in **bold**. */
    private static function markedQuote(string $quote, array $tokens, array $mark): string
    {
        $out = '';
        $pos = 0;
        foreach ($tokens as $i => $t) {
            if ($t['off'] < $pos) {
                continue;
            }
            $out .= substr($quote, $pos, $t['off'] - $pos);
            $w = substr($quote, $t['off'], $t['len']);
            $out .= isset($mark[$i]) ? "**$w**" : $w;
            $pos = $t['off'] + $t['len'];
        }
        return $out . substr($quote, $pos);
    }

    /**
     * Changes next to each other as one: "your words" for "the library's words".
     * @param list<array> $ops  @return list<array{you: list<int>, lib: list<int>}>
     */
    private static function groupChanges(array $ops): array
    {
        $groups = [];
        $open = false;
        foreach ($ops as [$op, $qi, $pj]) {
            if ($op === '=' || $op === '_') {
                $open = false;
                continue;
            }
            if (!$open) {
                $groups[] = ['you' => [], 'lib' => []];
                $open = true;
            }
            if ($qi !== null) {
                $groups[count($groups) - 1]['you'][] = $qi;
            }
            if ($pj !== null) {
                $groups[count($groups) - 1]['lib'][] = $pj;
            }
        }
        return $groups;
    }

    // ------------------------------------------------------------------------------- authors

    /** Name words of the credited author to look for (words of 3+ letters; all words if none is). @return list<string> */
    public static function authorWords(string $author): array
    {
        $words = array_values(array_filter(explode(' ', Text::looseKey($author)), 'strlen'));
        $long = array_values(array_filter($words, static fn (string $w): bool => strlen($w) >= 3
            && !in_array($w, ['rev', 'the', 'saint', 'st', 'mr', 'dr', 'bishop', 'pastor'], true)));
        return $long !== [] ? $long : $words;
    }

    /** True when every credited name word is a word of the work's author. @param list<string> $keys */
    public static function byAuthor(array $work, array $keys): bool
    {
        if ($keys === []) {
            return false;
        }
        $have = array_flip(explode(' ', Text::looseKey($work['author'] . ' ' . str_replace('-', ' ', $work['author_slug']))));
        foreach ($keys as $k) {
            if (!isset($have[$k])) {
                return false;
            }
        }
        return true;
    }

    private function remaining(): float
    {
        return $this->deadline - microtime(true);
    }
}
