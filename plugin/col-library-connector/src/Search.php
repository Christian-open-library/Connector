<?php
/**
 * The search pipelines (PLUGIN-SPEC.md "Search pipeline").
 *
 * best:  keyword (FTS5 AND of the query words, relaxed by dropping one word at a time when it
 *        finds fewer than 30 passages) + meaning (Voyage query vector against the IVF index, or an
 *        exact scan when filters leave <= 20,000 passages) -> reciprocal-rank fusion (k = 60) ->
 *        top 50 -> Voyage rerank -> near-duplicates across editions collapsed -> top `limit`.
 * exact: one FTS5 phrase query on the body column, passages in book order, with paging.
 * When Voyage is missing, failing, over budget or the deadline is near, the missing stages are
 * skipped and the result says so in its notes.
 */

declare(strict_types=1);

namespace ColLibrary;

defined('ABSPATH') || exit;

/** A problem with the request that the user can fix; the message is shown to them as is. */
final class SearchInputException extends \RuntimeException
{
}

/** Search filters shared by search_library and find_works. */
final class Filters
{
    public function __construct(
        public readonly ?string $author = null,
        public readonly ?int $workId = null,
        public readonly ?string $collection = null,
        public readonly ?int $yearFrom = null,
        public readonly ?int $yearTo = null,
        public readonly ?string $denomination = null,
        public readonly ?string $tradition = null,
    ) {
    }

    public function active(): bool
    {
        return $this->author !== null || $this->workId !== null || $this->collection !== null
            || $this->yearFrom !== null || $this->yearTo !== null || $this->churchOnly();
    }

    /** True when only church records are wanted (a denomination or tradition filter). */
    public function churchOnly(): bool
    {
        return $this->denomination !== null || $this->tradition !== null;
    }

    public function hasYears(): bool
    {
        return $this->yearFrom !== null || $this->yearTo !== null;
    }

    /** Short description for result headers and notes, e.g. 'author contains "owen", 1600–1700'. */
    public function describe(): string
    {
        $parts = [];
        if ($this->author !== null) {
            $parts[] = 'author contains "' . Text::echo($this->author, 60) . '"';
        }
        if ($this->workId !== null) {
            $parts[] = 'work_id ' . $this->workId;
        }
        if ($this->collection !== null) {
            $parts[] = 'collection ' . $this->collection;
        }
        if ($this->denomination !== null) {
            $parts[] = 'denomination "' . Text::echo($this->denomination, 80) . '"';
        }
        if ($this->tradition !== null) {
            $parts[] = 'church records of the ' . $this->tradition . ' tradition';
        }
        if ($this->yearFrom !== null && $this->yearTo !== null) {
            $parts[] = $this->yearFrom === $this->yearTo ? "year {$this->yearFrom}" : "years {$this->yearFrom}–{$this->yearTo}";
        } elseif ($this->yearFrom !== null) {
            $parts[] = "from {$this->yearFrom}";
        } elseif ($this->yearTo !== null) {
            $parts[] = "up to {$this->yearTo}";
        }
        return implode(', ', $parts);
    }

    /**
     * Ids of the works these filters allow (null when there are no filters), plus notes.
     * @return array{ids: list<int>|null, notes: list<string>}
     * @throws SearchInputException when a work_id does not exist or nothing matches
     */
    public function resolve(Store $store): array
    {
        if (!$this->active()) {
            return ['ids' => null, 'notes' => []];
        }
        $works = $store->allWorks();
        if ($this->workId !== null && !isset($works[$this->workId])) {
            throw new SearchInputException("No work has work_id {$this->workId}. Work ids come from the "
                . 'library\'s own results (a ref such as P1234500017 belongs to work 12345).');
        }
        $authorKey = $this->author !== null ? Text::looseKey($this->author) : null;
        $church = $this->churchOnly() ? Churches::allowed($store, $works, $this->denomination, $this->tradition) : null;
        $ids = [];
        $noYear = 0;
        foreach ($works as $id => $w) {
            if ($this->workId !== null && $id !== $this->workId) {
                continue;
            }
            if ($church !== null && !isset($church['ids'][$id])) {
                continue;
            }
            if ($this->collection !== null && $w['collection'] !== $this->collection) {
                continue;
            }
            if ($authorKey !== null && !str_contains(Text::looseKey($w['author']), $authorKey)
                && !str_contains(Text::looseKey($w['author_slug']), $authorKey)) {
                continue;
            }
            if ($this->hasYears()) {
                if ($w['year'] === null) {
                    $noYear++;
                    continue;
                }
                if (($this->yearFrom !== null && $w['year'] < $this->yearFrom)
                    || ($this->yearTo !== null && $w['year'] > $this->yearTo)) {
                    continue;
                }
            }
            $ids[] = $id;
        }
        $notes = ['Filters: ' . $this->describe() . ' (' . count($ids) . ' work' . (count($ids) === 1 ? '' : 's') . ').'];
        if ($church !== null) {
            $notes = array_merge($notes, $church['notes']);
        }
        if ($noYear > 0) {
            $notes[] = "Works without a known year are excluded by a year filter ($noYear of them matched the other filters).";
        }
        if ($ids === []) {
            $msg = 'No works in the library match these filters: ' . $this->describe() . '.';
            if ($authorKey !== null) {
                $similar = self::similarAuthors($works, $authorKey);
                $msg .= $similar !== [] ? ' Authors with similar names: ' . implode('; ', $similar) . '.'
                    : ' Check the spelling of the author, or try only the surname.';
            }
            if ($noYear > 0) {
                $msg .= " $noYear matching works have no known year and are left out when a year range is given.";
            }
            if ($church !== null) {
                $msg .= ' ' . implode(' ', array_filter($church['notes'], static fn (string $n): bool
                    => !Churches::isRule($n)));
                if ($church['ids'] !== []) {
                    $msg .= ' The church records alone match ' . count($church['ids']) . ' works: loosen the other filters.';
                }
            }
            throw new SearchInputException($msg);
        }
        return ['ids' => $ids, 'notes' => $notes];
    }

    /**
     * Bounds of the "similar authors" suggestion: requested words compared (distinct, 3 to
     * SIMILAR_WORD_MAX bytes), and stored name words longer than SIMILAR_PART_MAX bytes skipped,
     * so its levenshtein work stays small whatever the request or the catalog holds.
     */
    private const SIMILAR_WORDS = 8;
    private const SIMILAR_WORD_MAX = 32;
    private const SIMILAR_PART_MAX = 64;

    /** @return list<string> up to 5 author names that look like the requested one */
    private static function similarAuthors(array $works, string $key): array
    {
        $wanted = array_slice(array_values(array_unique(array_filter(explode(' ', $key),
            static fn (string $w): bool => strlen($w) >= 3 && strlen($w) <= self::SIMILAR_WORD_MAX))), 0, self::SIMILAR_WORDS);
        if ($wanted === []) {
            return [];
        }
        $found = [];
        $seen = [];      // each distinct name (with its slug) is compared once
        foreach ($works as $w) {
            $name = $w['author'];
            $both = $name . ' ' . $w['author_slug'];
            if ($name === '' || isset($found[$name]) || isset($seen[$both])) {
                continue;
            }
            $seen[$both] = true;
            foreach (array_unique(explode(' ', Text::looseKey(mb_substr($both, 0, 400)))) as $part) {
                if (strlen($part) > self::SIMILAR_PART_MAX) {
                    continue;
                }
                foreach ($wanted as $q) {
                    if (str_starts_with($part, $q) || (strlen($q) >= 4 && levenshtein($q, $part) <= 2)) {
                        $found[$name] = true;
                        if (count($found) >= 5) {
                            break 3;
                        }
                        continue 3;
                    }
                }
            }
        }
        return array_map(static fn (string $n): string => Text::inert(Text::oneLine($n, 100)),
            array_slice(array_map('strval', array_keys($found)), 0, 5));
    }
}

/** What a search found, ready for Format. */
final class SearchResult
{
    /**
     * @param list<array> $hits passage rows (Store::passages) with 'work' (work row) and
     *                          'also' (list of near-duplicate passage rows, each with 'work')
     * @param list<string> $notes
     */
    public function __construct(
        public readonly string $query,
        public readonly string $mode,
        public readonly string $method,
        public readonly array $hits,
        public readonly array $notes,
        public readonly int $offset = 0,
        public readonly ?int $total = null,
        public readonly bool $totalCapped = false,
    ) {
    }
}

final class Search
{
    public const RRF_K = 60;
    public const PER_WORK = 2;        // best mode: passages per work before other works get a turn
    public const PHRASE_MIN_WORDS = 5;  // best mode: a query this long may be a quotation...
    public const PHRASE_MAX = 30;       // ...found word for word in at most this many passages
    private const MIN_SECONDS_PHRASE = 6.0;
    public const FEW_WORKS = 3;       // a search limited to this many works is not capped
    public const FUSE_TOP = 50;
    public const KEYWORD_TOP = 100;
    public const VECTOR_TOP = 100;
    public const RELAX_BELOW = 30;
    public const RELAX_QUERIES = 4;
    public const EXACT_SCAN_MAX = 20000;
    public const DUPLICATE_JACCARD = 0.5;
    public const RERANK_DOC_CHARS = 1800;
    public const EXACT_COUNT_CAP = 1000;     // exact mode counts matches only this far ("more than 1,000")
    public const MAX_QUERY_WORDS = 64;
    public const MAX_REPEATS = 4;            // exact mode: times one word may occur in the phrase
    public const MAX_COMMON_PHRASE = 12;     // exact mode: words in a phrase made only of stopwords
    public const MAX_COMMON_TOKENS = 24;     // exact mode: stopwords in any phrase, repeats counted
    public const MAX_COMMON_WORDS = 16;      // exact mode: different stopwords in any phrase
    public const PREPARE_CHARS = 20000;      // passage text folded for rerank and dedupe (the rest is cut)
    public const MEANING_DOWN = 'Meaning search unavailable right now; showing keyword matches.';

    /**
     * Match strength in words, from the reranker's relevance score (rerank-3). Measured 2026-11-11 on the
     * 75 evaluation questions plus 20 searches the library cannot answer: passages from the expected work
     * scored 0.89 at the median (0.73 at the 10th percentile); searches on subjects the library has nothing
     * about never scored above 0.29; searches for modern authors it lacks mostly topped out at 0.54-0.69.
     * Measure again if the rerank model changes.
     */
    public const MATCH_CLOSE = 0.75;     // at or above: "close"
    public const MATCH_LOOSE = 0.45;     // below: "loose"; in between: "partial"

    // Seconds that must remain before starting a stage (the deadline is ~25 s per request).
    private const MIN_SECONDS_RELAX = 5.0;
    private const MIN_SECONDS_EMBED = 3.0;
    private const MIN_SECONDS_SCAN = 1.5;
    private const MIN_SECONDS_RERANK = 3.0;
    private const MIN_SECONDS_EXACT = 1.5;

    /** @var array<string,float|int|string|bool> timings and counts for the log */
    public array $trace = [];

    public function __construct(
        private readonly Store $store,
        private readonly Voyage $voyage,
        private readonly float $deadline,
        private readonly int $nprobe = 32,
        private readonly ?Log $log = null,
        private readonly float $prefilterKeep = 1.0,
        private readonly int $exactScanMax = self::EXACT_SCAN_MAX,   // lower in tests
    ) {
    }

    // ----------------------------------------------------------------------------------- best

    /** @throws SearchInputException */
    public function best(string $query, Filters $filters, int $limit): SearchResult
    {
        $tokens = $this->queryTokens($query);
        if ($tokens === []) {
            throw new SearchInputException('The query has no words to search for. Use words or a phrase, '
                . 'for example "assurance of salvation".');
        }
        ['ids' => $workIds, 'notes' => $notes] = $filters->resolve($this->store);

        // A quotation: passages holding the whole query word for word come first.
        $phrase = $this->phraseHits($tokens, $workIds);

        // Keyword side
        $keyword = [];
        $keywordRan = true;
        $t = microtime(true);
        try {
            $keyword = $this->keywordList($tokens, $workIds);
        } catch (\PDOException $e) {
            $keywordRan = false;
            $this->log?->error('keyword_search_failed', ['error' => Text::logMessage($e)]);
            $notes[] = 'Keyword search failed on the server; showing meaning matches only.';
        }
        $this->trace['keyword_ms'] = self::ms($t);
        $this->trace['keyword_hits'] = count($keyword);

        // Meaning side
        $vector = [];
        $vectorRan = false;
        $canRerank = $this->voyage->configured();
        $modern = $this->modernQuery($query);
        $problem = $this->vectorProblem();
        if ($problem !== null) {
            $this->trace['vector'] = $problem;
            $notes[] = self::MEANING_DOWN;
        } elseif ($this->remaining() < self::MIN_SECONDS_EMBED) {
            $this->trace['vector'] = 'skipped: deadline';
            $notes[] = 'Meaning search skipped to stay within the time limit; showing keyword matches.';
        } else {
            try {
                $t = microtime(true);
                $qv = $this->voyage->embedQuery($modern, $this->deadline);
                $this->trace['embed_ms'] = self::ms($t);
                $t = microtime(true);
                $vector = $this->vectorSearch($qv, $workIds, self::VECTOR_TOP, $notes);
                $vectorRan = true;
                $this->trace['scan_ms'] = self::ms($t);
                $this->trace['vector_hits'] = count($vector);
            } catch (VoyageException $e) {
                $canRerank = false;   // Voyage is failing: do not spend more time on it
                $this->trace['vector'] = 'failed';
                $this->log?->error('voyage_embed_failed', ['error' => $e->getMessage()]);
                $notes[] = self::MEANING_DOWN;
            } catch (\RuntimeException | \LogicException $e) {
                // vector files or the ivf table unreadable: keep the keyword results
                $this->trace['vector'] = 'failed';
                $this->log?->error('vector_scan_failed', ['error' => Text::logMessage($e)]);
                $notes[] = self::MEANING_DOWN;
            }
        }

        $fused = self::rrf([$keyword, $vector], self::RRF_K, self::FUSE_TOP);
        if ($phrase !== []) {
            $fused = array_values(array_unique(array_merge($phrase, $fused)));
        }
        $cands = $this->prepare($this->load($fused));

        // Rerank
        $reranked = false;
        if ($canRerank && count($cands) > 1) {
            if ($this->remaining() < self::MIN_SECONDS_RERANK) {
                $notes[] = 'Re-ranking skipped to stay within the time limit.';
            } else {
                try {
                    $t = microtime(true);
                    $order = $this->voyage->rerank($modern, array_column($cands, 'doc'),
                        min(count($cands), $limit * 2), $this->deadline);
                    $this->trace['rerank_ms'] = self::ms($t);
                    foreach ($order as $o) {
                        if (isset($cands[$o['index']])) {
                            $cands[$o['index']]['score'] = $o['score'];
                        }
                    }
                    $cands = self::reorder($cands, array_column($order, 'index'));
                    $reranked = true;
                } catch (VoyageException $e) {
                    $this->trace['rerank'] = 'failed';
                    $this->log?->error('voyage_rerank_failed', ['error' => $e->getMessage()]);
                    $notes[] = 'Re-ranking unavailable right now; results are in fused order.';
                }
            }
        }

        if ($phrase !== []) {
            $cands = self::phraseFirst($cands, $phrase);
        }
        // Breadth: at most PER_WORK passages from one work, unless the search is limited to a few works.
        $perWork = $workIds !== null && count($workIds) <= self::FEW_WORKS ? PHP_INT_MAX : self::PER_WORK;
        $hits = $this->withRetellings(self::withMatch(self::collapse($cands, $limit, $perWork), $phrase), $tokens);
        $sides = array_keys(array_filter(['meaning' => $vectorRan, 'keyword' => $keywordRan]));
        $method = ($sides !== [] ? implode(' + ', $sides) : 'no') . ' search' . ($reranked ? ', re-ranked' : '');
        return new SearchResult($query, 'best', $method, $hits, array_values(array_unique($notes)));
    }

    /**
     * Marks each hit with how well it matches: 'exact' (it holds the query word for word), or by the
     * reranker's score 'close', 'partial' or 'loose'; null when it was not scored.
     * @param list<array> $hits  @param list<int> $phraseIds  @return list<array>
     */
    public static function withMatch(array $hits, array $phraseIds): array
    {
        $exact = array_flip($phraseIds);
        foreach ($hits as $i => $h) {
            $score = $h['score'] ?? null;
            $hits[$i]['match'] = isset($exact[$h['id']]) ? 'exact'
                : ($score === null ? null : self::matchWord((float) $score));
        }
        return $hits;
    }

    /**
     * Marks an exact or close hit that credits the words near the query's to another writer the library
     * holds ("as Augustine tells us"): 'retold' => [name, said]. Right topic is not right source.
     * @param list<array> $hits  @param list<string> $tokens  @return list<array>
     */
    private function withRetellings(array $hits, array $tokens): array
    {
        $stems = Quote::queryStems($tokens);
        if (count($stems) < 2) {
            return $hits;
        }
        $writers = null;
        $modern = fn (array $words): array => Text::modernTokens($words, $this->store->spellings($words));
        foreach ($hits as $i => $h) {
            if (!in_array($h['match'] ?? null, ['exact', 'close'], true)) {
                continue;
            }
            $writers ??= Quote::writerKeys($this->store->allWorks());
            $r = Quote::retold((string) $h['text'], $h['work'], $writers, $stems, $modern);
            if ($r !== null) {
                $hits[$i]['retold'] = $r;
            }
        }
        return $hits;
    }

    public static function matchWord(float $score): string
    {
        return $score >= self::MATCH_CLOSE ? 'close' : ($score >= self::MATCH_LOOSE ? 'partial' : 'loose');
    }

    /**
     * Keyword list: AND of the keyword tokens ranked by bm25; if that finds fewer than
     * RELAX_BELOW passages and there are at least 3 tokens, the query is re-run without one
     * token at a time (longest first, at most RELAX_QUERIES times) and those hits follow.
     * @param list<string> $tokens  @param list<int>|null $workIds  @return list<int>
     */
    public function keywordList(array $tokens, ?array $workIds): array
    {
        $kw = Text::keywordTokens($tokens);
        if (array_filter($kw, static fn (string $t): bool => !Text::isStopword($t)) === []) {
            // Only very common words ("to be or not to be"): ranking every passage that has them
            // all would score most of the library, so they are matched as a phrase instead, and a
            // single common word is left to the meaning search.
            $this->trace['keyword'] = 'common words: phrase';
            $phrase = array_slice($tokens, 0, self::MAX_COMMON_PHRASE);
            return count($phrase) < 2 ? []
                : array_map('intval', array_keys($this->store->keywordSearch(Text::ftsPhrase($phrase), $workIds, self::KEYWORD_TOP)));
        }
        $hits = $this->store->keywordSearch(Text::ftsAll($kw), $workIds, self::KEYWORD_TOP);
        if (count($hits) < self::RELAX_BELOW && count($kw) >= 3) {
            $longest = $kw;
            usort($longest, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
            $extra = [];
            foreach (array_slice($longest, 0, self::RELAX_QUERIES) as $drop) {
                if ($this->remaining() < self::MIN_SECONDS_RELAX) {
                    break;
                }
                $rest = array_values(array_filter($kw, static fn (string $t): bool => $t !== $drop));
                foreach ($this->store->keywordSearch(Text::ftsAll($rest), $workIds, self::KEYWORD_TOP) as $id => $score) {
                    if (!isset($hits[$id]) && $score < ($extra[$id] ?? INF)) {
                        $extra[$id] = $score;
                    }
                }
            }
            asort($extra);
            $hits += array_slice($extra, 0, max(0, self::KEYWORD_TOP - count($hits)), true);
            $this->trace['relaxed'] = true;
        }
        return array_map('intval', array_keys($hits));
    }

    /**
     * Reciprocal-rank fusion: each list adds 1 / (k + rank) to an item's score (rank from 1).
     * Equal scores keep the order in which items were first seen (keyword list first).
     * @param list<list<int>> $lists  @return list<int> best first
     */
    public static function rrf(array $lists, int $k = self::RRF_K, int $top = self::FUSE_TOP): array
    {
        $scores = [];
        foreach ($lists as $list) {
            foreach (array_values($list) as $rank => $id) {
                $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / ($k + $rank + 1);
            }
        }
        arsort($scores);   // stable since PHP 8.0
        return array_slice(array_map('intval', array_keys($scores)), 0, $top);
    }

    /**
     * Keeps the best of each group of near-duplicates: a candidate whose word 5-shingles have
     * Jaccard >= 0.5 with a kept result by the same author is listed under it as "also in".
     * Candidates after the first $limit results are only checked as duplicates.
     * @param list<array> $ordered candidates best first (with 'shingles' and 'author_key')
     * @return list<array> at most $limit, each with 'also'
     */
    public static function collapse(array $ordered, int $limit, int $perWork = PHP_INT_MAX): array
    {
        $kept = [];
        $spill = [];          // over the per-work cap: used only if slots are left at the end
        $perWorkCount = [];
        foreach ($ordered as $c) {
            foreach ($kept as $i => $k) {
                if ($k['author_key'] === $c['author_key']
                    && Text::jaccard($k['shingles'], $c['shingles']) >= self::DUPLICATE_JACCARD) {
                    $kept[$i]['also'][] = $c;
                    continue 2;
                }
            }
            if (count($kept) >= $limit) {
                continue;
            }
            $w = $c['work_id'] ?? null;
            if ($w !== null && ($perWorkCount[$w] ?? 0) >= $perWork) {
                $spill[] = $c;
                continue;
            }
            $c['also'] = [];
            $kept[] = $c;
            if ($w !== null) {
                $perWorkCount[$w] = ($perWorkCount[$w] ?? 0) + 1;
            }
        }
        foreach ($spill as $c) {
            if (count($kept) >= $limit) {
                break;
            }
            foreach ($kept as $i => $k) {
                if ($k['author_key'] === $c['author_key']
                    && Text::jaccard($k['shingles'], $c['shingles']) >= self::DUPLICATE_JACCARD) {
                    $kept[$i]['also'][] = $c;
                    continue 2;
                }
            }
            $c['also'] = [];
            $kept[] = $c;
        }
        return $kept;
    }

    // ---------------------------------------------------------------------------------- exact

    /** @throws SearchInputException */
    public function exact(string $query, Filters $filters, int $limit, int $offset): SearchResult
    {
        $tokens = $this->queryTokens($query);
        if ($tokens === []) {
            throw new SearchInputException('The phrase has no words to search for.');
        }
        if (count($tokens) > self::MAX_QUERY_WORDS) {
            throw new SearchInputException('The phrase is too long for exact mode (at most ' . self::MAX_QUERY_WORDS
                . ' words). Use a shorter, distinctive part of it.');
        }
        // Every word of a phrase makes SQLite read all the places that word occurs: a phrase of
        // the same word many times, or a long run of only very common words, costs a great deal
        // and finds nothing useful.
        $counts = array_count_values($tokens);
        arsort($counts);
        if (reset($counts) > self::MAX_REPEATS) {
            throw new SearchInputException('The phrase repeats "' . Text::echo((string) key($counts), 40) . '" too often for '
                . 'exact mode (at most ' . self::MAX_REPEATS . ' times). Use a shorter, distinctive part of it.');
        }
        if (count($tokens) > self::MAX_COMMON_PHRASE
            && array_filter($tokens, static fn (string $t): bool => !Text::isStopword($t)) === []) {
            throw new SearchInputException('The phrase has only very common words. Use a shorter part of it (at most '
                . self::MAX_COMMON_PHRASE . ' words), or include a more distinctive word.');
        }
        // A rare word doesn't make the common ones cheaper: each distinct common word still costs a read of
        // where it occurs, so their number is capped too.
        $commonTokens = array_filter($tokens, static fn (string $t): bool => Text::isStopword($t));
        $common = count(array_unique($commonTokens));
        if ($common > self::MAX_COMMON_WORDS || count($commonTokens) > self::MAX_COMMON_TOKENS) {
            throw new SearchInputException('The phrase has too many different common words for exact mode (at most '
                . self::MAX_COMMON_WORDS . '). Use a shorter, distinctive part of it.');
        }
        ['ids' => $workIds, 'notes' => $notes] = $filters->resolve($this->store);
        $match = Text::ftsPhrase($tokens);
        $t = microtime(true);
        $this->requireTime();
        $total = $this->store->countMatches($match, $workIds, self::EXACT_COUNT_CAP + 1);
        if ($total > $offset) {
            $this->requireTime();
        }
        $ids = $total > $offset ? $this->store->matchInOrder($match, $workIds, $limit, $offset) : [];
        $this->trace['exact_ms'] = self::ms($t);
        $hits = [];
        foreach ($this->load($ids) as $p) {
            $p['also'] = [];
            $hits[] = $p;
        }
        $capped = $total > self::EXACT_COUNT_CAP;
        return new SearchResult($query, 'exact', 'exact phrase, in book order', $hits, $notes, $offset,
            $capped ? self::EXACT_COUNT_CAP : $total, $capped);
    }

    // -------------------------------------------------------------------------------- vectors

    /** Why the vector side cannot run for this build and config, or null if it can. */
    public function vectorProblem(): ?string
    {
        if (!$this->store->vectorsReady()) {
            return 'index has no vectors';
        }
        if (!$this->voyage->configured()) {
            return 'voyage not configured';
        }
        // Test vectors and real Voyage vectors live in different spaces; never mix them.
        if (($this->store->meta('embed_fake') === '1') !== $this->voyage->isFake()) {
            return 'vectors and voyage mode differ (test vectors vs real)';
        }
        return null;
    }

    /**
     * Passage ids nearest to a query vector, best first. Without filters the best `nprobe` IVF
     * lists are scanned. With filters: works holding at most EXACT_SCAN_MAX passages are scanned
     * exactly (their rows found through passages.vec_pos); otherwise nprobe * 3 lists are scanned
     * and rows of other works are skipped.
     * @param list<float> $q  @param list<int>|null $workIds  @param list<string> $notes
     * @return list<int>
     */
    public function vectorSearch(array $q, ?array $workIds, int $top, array &$notes): array
    {
        $dims = $this->store->dims();
        if (count($q) !== $dims) {
            throw new VoyageException('query vector has ' . count($q) . " dimensions, the index $dims");
        }
        $q1 = [];   // keys 1..dims, like the arrays unpack() returns
        foreach (array_values($q) as $i => $w) {
            $q1[$i + 1] = (float) $w;
        }
        $allowed = null;
        $nprobe = $this->nprobe;
        if ($workIds !== null) {
            $works = $this->store->works($workIds);
            $passages = array_sum(array_column($works, 'n_passages'));
            if ($passages <= $this->exactScanMax) {
                $this->trace['scan'] = 'exact';
                return $this->scanPositions($q1, $this->store->vectorPositions($workIds), $top, $notes);
            }
            $allowed = array_flip($workIds);
            // More lists the more the filter leaves out (up to 3x), so about as many allowed rows
            // are seen as in an unfiltered search; a broad filter (most works) adds few lists.
            $total = max(1, (int) ($this->store->meta('n_vectors') ?? $this->store->meta('n_passages') ?? 1));
            $nprobe = (int) min($nprobe * 3, ceil($nprobe * $total / max(1, $passages)));
        }
        $this->trace['scan'] = 'ivf';
        $scores = [];
        $rowsScored = 0;
        $querySigns = $this->prefilterKeep < 1.0 ? self::signs($q) : '';
        foreach ($this->probe($q1, $nprobe) as [$start, $count]) {
            if ($this->remaining() < self::MIN_SECONDS_SCAN) {
                $notes[] = 'Meaning search was cut short to stay within the time limit.';
                break;
            }
            $ids = $this->store->readIds($start, $count);
            $rows = null;
            if ($allowed !== null) {
                $rows = [];
                foreach ($ids as $r => $id) {
                    if (isset($allowed[intdiv($id, Store::SEQ_MULT)])) {
                        $rows[] = $r;
                    }
                }
                if ($rows === []) {
                    continue;
                }
            }
            $buf = $this->store->readRows($start, $count);
            if ($rows === null && $querySigns !== '' && $count > 64) {
                $rows = self::signPrefilter($buf, $dims, $querySigns, $this->prefilterKeep);
            }
            foreach (self::scoreRows($q1, $buf, $dims, $rows) as $r => $s) {
                $scores[$ids[$r]] = $s;
            }
            $rowsScored += $rows === null ? $count : count($rows);
        }
        $this->trace['rows_scored'] = $rowsScored;
        arsort($scores);
        return array_slice(array_map('intval', array_keys($scores)), 0, $top);
    }

    /**
     * Dot products of a query (float, keys 1..dims) with rows packed in $buf: int8 rows
     * ($type 'c', vectors.bin) or float32 little-endian rows ($type 'g', IVF centroids).
     * Four rows share each pass over the query, which saves a fifth of the time in plain PHP.
     * @param list<int>|null $rows row numbers to score (null = every row)
     * @return array<int,float> row number => score
     */
    public static function scoreRows(array $q1, string $buf, int $dims, ?array $rows = null, string $type = 'c'): array
    {
        $rowBytes = $type === 'g' ? 4 * $dims : $dims;
        $n = intdiv(strlen($buf), $rowBytes);
        if ($rows === null) {
            $rows = $n > 0 ? range(0, $n - 1) : [];
        } elseif ($rows !== [] && (min($rows) < 0 || max($rows) >= $n)) {
            throw new \OutOfRangeException('row number outside the buffer');
        }
        $fmt = $type . $dims;
        $out = [];
        $count = count($rows);
        $i = 0;
        for (; $i + 3 < $count; $i += 4) {
            $ra = $rows[$i];
            $rb = $rows[$i + 1];
            $rc = $rows[$i + 2];
            $rd = $rows[$i + 3];
            $a = unpack($fmt, $buf, $ra * $rowBytes);
            $b = unpack($fmt, $buf, $rb * $rowBytes);
            $c = unpack($fmt, $buf, $rc * $rowBytes);
            $d = unpack($fmt, $buf, $rd * $rowBytes);
            $sa = $sb = $sc = $sd = 0.0;
            foreach ($q1 as $k => $w) {
                $sa += $w * $a[$k];
                $sb += $w * $b[$k];
                $sc += $w * $c[$k];
                $sd += $w * $d[$k];
            }
            $out[$ra] = $sa;
            $out[$rb] = $sb;
            $out[$rc] = $sc;
            $out[$rd] = $sd;
        }
        for (; $i < $count; $i++) {
            $r = $rows[$i];
            $a = unpack($fmt, $buf, $r * $rowBytes);
            $s = 0.0;
            foreach ($q1 as $k => $w) {
                $s += $w * $a[$k];
            }
            $out[$r] = $s;
        }
        return $out;
    }

    /**
     * Rows of an int8 list whose sign pattern is nearest the query's (Hamming distance of sign
     * bits, computed with C string functions: strtr, ^, count_chars). Keeps ceil(rows * $keep).
     * Optional speed-up (config vector_prefilter_keep < 1); approximate, so off by default.
     * @return list<int>
     */
    public static function signPrefilter(string $buf, int $dims, string $querySigns, float $keep): array
    {
        static $from = '', $to = '';
        if ($from === '') {
            for ($b = 0; $b < 256; $b++) {
                $from .= chr($b);
                $to .= $b < 128 ? "\0" : "\1";
            }
        }
        $n = intdiv(strlen($buf), $dims);
        $diff = strtr($buf, $from, $to) ^ str_repeat($querySigns, $n);
        $dist = [];
        foreach (str_split($diff, $dims) as $r => $row) {
            $dist[$r] = count_chars($row, 1)[1] ?? 0;
        }
        asort($dist);
        return array_slice(array_keys($dist), 0, max(1, (int) ceil($n * $keep)));
    }

    /** One byte per dimension: "\1" where the query is negative. @param list<float> $q */
    public static function signs(array $q): string
    {
        $s = '';
        foreach ($q as $w) {
            $s .= $w < 0 ? "\1" : "\0";
        }
        return $s;
    }

    /** The best $nprobe IVF lists for a query. @return list<array{0:int,1:int}> [start, count] */
    private function probe(array $q1, int $nprobe): array
    {
        $dims = $this->store->dims();
        $lists = [];
        $buf = '';
        foreach ($this->store->centroids() as $c) {
            if (strlen($c['centroid']) === 4 * $dims) {
                $lists[] = [$c['start'], $c['count']];
                $buf .= $c['centroid'];
            }
        }
        $scores = self::scoreRows($q1, $buf, $dims, null, 'g');
        arsort($scores);
        $out = [];
        foreach ($scores as $r => $s) {
            if (count($out) >= $nprobe) {
                break;
            }
            if ($lists[$r][1] > 0) {
                $out[] = $lists[$r];
            }
        }
        $this->trace['lists'] = count($out);
        return $out;
    }

    /**
     * Exact scan of given rows (vec_pos => passage id, sorted). Rows close together are read
     * with one fread.
     * @return list<int>
     */
    private function scanPositions(array $q1, array $positions, int $top, array &$notes): array
    {
        $dims = $this->store->dims();
        $pos = array_keys($positions);
        $n = count($pos);
        $scores = [];
        for ($i = 0; $i < $n; ) {
            if ($this->remaining() < self::MIN_SECONDS_SCAN) {
                $notes[] = 'Meaning search was cut short to stay within the time limit.';
                break;
            }
            $start = $pos[$i];
            $j = $i;
            while ($j + 1 < $n && $pos[$j + 1] - $pos[$j] <= 16 && $pos[$j + 1] - $start < 4096) {
                $j++;
            }
            $buf = $this->store->readRows($start, $pos[$j] - $start + 1);
            $rows = [];
            for ($k = $i; $k <= $j; $k++) {
                $rows[] = $pos[$k] - $start;
            }
            foreach (self::scoreRows($q1, $buf, $dims, $rows) as $r => $s) {
                $scores[$positions[$start + $r]] = $s;
            }
            $i = $j + 1;
        }
        $this->trace['rows_scored'] = count($scores);
        arsort($scores);
        return array_slice(array_map('intval', array_keys($scores)), 0, $top);
    }

    // -------------------------------------------------------------------------------- helpers

    /**
     * Query words normalized and in modern spelling (stopwords kept).
     * @return list<string>
     */
    public function queryTokens(string $query): array
    {
        $words = array_slice(Text::words(Text::normalize($query)), 0, 4 * self::MAX_QUERY_WORDS);
        return Text::modernTokens($words, $this->store->spellings($words));
    }

    /** The query as running text in modern spelling: what is embedded and sent to the reranker. */
    public function modernQuery(string $query): string
    {
        $folded = Text::fold(trim($query));
        $words = Text::words(mb_strtolower($folded, 'UTF-8'));
        return Text::modernizeText($folded, $this->store->spellings($words));
    }

    /** Passage rows (with 'work') for ids, in the given order. @param list<int> $ids @return list<array> */
    private function load(array $ids): array
    {
        $passages = $this->store->passages($ids);
        $works = $this->store->works(array_values(array_unique(array_column($passages, 'work_id'))));
        $out = [];
        foreach ($passages as $p) {
            if (isset($works[$p['work_id']])) {
                $p['work'] = $works[$p['work_id']];
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * Adds what rerank and dedupe need: 'doc' (header line + plain text in modern spelling, at
     * most RERANK_DOC_CHARS), 'shingles' and 'author_key'.
     * @param list<array> $cands  @return list<array>
     */
    private function prepare(array $cands): array
    {
        $plain = [];
        $heads = [];
        $words = [];
        foreach ($cands as $i => $c) {
            $w = $c['work'];
            // "Author, Title (Year). Section." as in the build's embedding input
            $heads[$i] = Text::fold($w['author'] . ($w['title'] !== '' ? ', ' . $w['title'] : '')
                . ($w['year'] !== null ? " ({$w['year']})" : '') . '.' . ($c['section'] !== '' ? ' ' . $c['section'] . '.' : ''));
            $plain[$i] = Text::fold(Text::plain(mb_substr($c['text'], 0, self::PREPARE_CHARS)));
            foreach (Text::words(mb_strtolower($heads[$i] . ' ' . $plain[$i], 'UTF-8')) as $word) {
                $words[$word] = true;
            }
        }
        $spelling = $this->store->spellings(array_map('strval', array_keys($words)));
        foreach ($cands as $i => $c) {
            $w = $c['work'];
            $modern = Text::modernizeText($plain[$i], $spelling);
            $head = Text::modernizeText($heads[$i], $spelling);
            $cands[$i]['doc'] = Text::clip(ltrim($head . "\n" . $modern), self::RERANK_DOC_CHARS);
            $cands[$i]['shingles'] = Text::shingles($modern);
            $cands[$i]['author_key'] = Text::looseKey($w['author'] !== '' ? $w['author'] : $w['author_slug']);
        }
        return $cands;
    }

    /** Candidates in reranked order, followed by the ones the reranker did not return. */
    /**
     * Passages that hold the whole query word for word, if it reads like a quotation: at least
     * PHRASE_MIN_WORDS words, not only common words, and found in 1 to PHRASE_MAX passages (a
     * phrase found everywhere is not a quotation). Best match first; [] when skipped or failed.
     * @param list<string> $tokens  @param list<int>|null $workIds  @return list<int>
     */
    private function phraseHits(array $tokens, ?array $workIds): array
    {
        $counts = array_count_values($tokens);
        if (count($tokens) < self::PHRASE_MIN_WORDS || count($tokens) > self::MAX_QUERY_WORDS
            || max($counts) > self::MAX_REPEATS
            || array_filter($tokens, static fn (string $t): bool => !Text::isStopword($t)) === []
            || count(array_filter($tokens, static fn (string $t): bool => Text::isStopword($t))) > self::MAX_COMMON_TOKENS
            || $this->remaining() < self::MIN_SECONDS_PHRASE) {
            return [];
        }
        $t = microtime(true);
        try {
            $match = Text::ftsPhrase($tokens);
            $n = $this->store->countMatches($match, $workIds, self::PHRASE_MAX + 1);
            $ids = $n >= 1 && $n <= self::PHRASE_MAX
                ? array_keys($this->store->keywordSearch($match, $workIds, self::PHRASE_MAX)) : [];
            $this->trace['phrase_matches'] = $n;
            return $ids;
        } catch (\PDOException $e) {
            $this->log?->error('phrase_search_failed', ['error' => Text::logMessage($e)]);
            return [];
        } finally {
            $this->trace['phrase_ms'] = self::ms($t);
        }
    }

    /** Candidates holding the quotation first, each group in its current (reranked) order. */
    public static function phraseFirst(array $cands, array $phraseIds): array
    {
        $in = array_flip($phraseIds);
        $first = [];
        $rest = [];
        foreach ($cands as $c) {
            if (isset($in[$c['id']])) {
                $first[] = $c;
            } else {
                $rest[] = $c;
            }
        }
        return array_merge($first, $rest);
    }

    private static function reorder(array $cands, array $order): array
    {
        $out = [];
        foreach ($order as $i) {
            if (isset($cands[$i])) {
                $out[] = $cands[$i];
                unset($cands[$i]);
            }
        }
        return array_merge($out, array_values($cands));
    }

    private function remaining(): float
    {
        return $this->deadline - microtime(true);
    }

    /** @throws SearchInputException when too little of the request's time is left for a query */
    private function requireTime(): void
    {
        if ($this->remaining() < self::MIN_SECONDS_EXACT) {
            throw new SearchInputException('The search ran out of time. Try a shorter or more distinctive phrase, '
                . 'or narrow it with filters.');
        }
    }

    private static function ms(float $since): int
    {
        return (int) round((microtime(true) - $since) * 1000);
    }
}
