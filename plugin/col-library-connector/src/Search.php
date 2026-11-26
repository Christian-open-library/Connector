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

    /** True for a denomination or tradition filter: church records only, and with a tradition alone also the works
     *  of writers of that tradition (Churches::allowed). */
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
            $parts[] = $this->denomination === null ? 'the ' . $this->tradition . ' tradition (church records and writers)'
                : 'church records of the ' . $this->tradition . ' tradition';
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
     * Ids of the works these filters allow (null when there are no filters), plus notes, and the allowed works
     * that are the named body's own present-day documents ('own', Churches::allowed; listed first by the tools).
     * @return array{ids: list<int>|null, notes: list<string>, own: list<int>, own_body: ?string}
     * @throws SearchInputException when a work_id does not exist or nothing matches
     */
    public function resolve(Store $store): array
    {
        if (!$this->active()) {
            return ['ids' => null, 'notes' => [], 'own' => [], 'own_body' => null];
        }
        $works = $store->allWorks();
        if ($this->workId !== null && !isset($works[$this->workId])) {
            throw new SearchInputException("No work has work_id {$this->workId}. Work ids come from the "
                . 'library\'s own results (a ref such as P1234500017 belongs to work 12345).');
        }
        $authorKey = $this->author !== null ? Text::looseKey($this->author) : null;
        $authorKeys = null;
        $authorNotes = [];
        $ambiguousAuthors = [];
        if ($this->author !== null) {
            ['keys' => $authorKeys, 'notes' => $authorNotes, 'ambiguous' => $ambiguousAuthors] = self::authorKeys($store, $works, $this->author);
        }
        $church = $this->churchOnly() ? Churches::allowed($store, $works, $this->denomination, $this->tradition) : null;
        $ids = [];
        $noYear = 0;
        $byLife = 0;
        $lives = $this->hasYears() ? $store->authorLives() : [];
        // A church record is dated by itself (a digest of 1888 is not of its compiler's earlier years).
        $records = $this->hasYears() && $store->hasChurchRecords() ? $store->churchRecords() : [];
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
            if ($authorKeys !== null && !self::authorMatches($w, $authorKeys)) {
                continue;
            }
            if ($this->hasYears()) {
                $y = $w['year'];
                $inside = $y !== null && ($this->yearFrom === null || $y >= $this->yearFrom)
                    && ($this->yearTo === null || $y <= $this->yearTo);
                if (!$inside) {
                    if (!isset($records[$id]) && self::livedThen($lives[$w['author']] ?? null, $y, $this->yearFrom, $this->yearTo)) {
                        $byLife++;
                    } else {
                        if ($y === null) {
                            $noYear++;
                        }
                        continue;
                    }
                }
            }
            $ids[] = $id;
        }
        $notes = array_merge(['Filters: ' . $this->describe() . ' (' . count($ids) . ' work' . (count($ids) === 1 ? '' : 's') . ').'],
            $ids !== [] ? $authorNotes : []);
        if ($church !== null) {
            $notes = array_merge($notes, $church['notes']);
        }
        if ($byLife > 0) {
            $notes[] = "Years: $byLife later edition" . ($byLife === 1 ? '' : 's') . ' or translation' . ($byLife === 1 ? '' : 's')
                . ' of writers who lived in those years ' . ($byLife === 1 ? 'is' : 'are') . ' included (by the writer\'s '
                . 'dates, not the edition\'s); for editions printed in those years only, give collection eebo-tcp, '
                . 'evans-tcp or ecco-tcp.';
        }
        if ($noYear > 0) {
            $notes[] = "Works without a known year are excluded by a year filter ($noYear of them matched the other filters).";
        }
        if ($ids === []) {
            $msg = 'No works in the library match these filters: ' . $this->describe() . '.';
            if ($ambiguousAuthors !== []) {
                $msg .= ' Writers with that name in the library: ' . implode('; ', array_map(
                    static fn (string $n): string => Text::inert(Text::oneLine($n, 80)), $ambiguousAuthors))
                    . '. Give the full name of the one meant.';
            } elseif ($authorKey !== null) {
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
                    $msg .= ' The ' . ($this->denomination === null ? 'tradition' : 'church records') . ' alone match'
                        . (count($church['ids']) === 1 ? 'es 1 work' : ' ' . count($church['ids']) . ' works')
                        . ': loosen the other filters.';
                }
            }
            throw new SearchInputException($msg);
        }
        $own = $church !== null ? array_values(array_intersect($church['own'], $ids)) : [];
        return ['ids' => $ids, 'notes' => $notes, 'own' => $own, 'own_body' => $own !== [] ? $church['own_body'] : null];
    }

    /**
     * True when a work outside the year range is a later edition or translation (or undated) of a writer who
     * wrote in the range: Athanasius (296–373) in an 1892 translation for years 300-400, Owen's Works (1850) for
     * up to 1700. Not an edition from the writer's own lifetime: its own year says when it was written.
     * @param array{0: int, 1: int}|null $life [first year of writing, year of death] (Store::authorLives)
     */
    public static function livedThen(?array $life, ?int $year, ?int $from, ?int $to): bool
    {
        if ($life === null || ($year !== null && $year <= $life[1])) {
            return false;
        }
        // Wrote in the range for a good part of it: at least LIVED_YEARS of overlap, or half of a shorter range
        // (Sibbes, writing 1597-1635, is not a writer of 1550-1600; Luther is one of 1517-1530).
        $overlap = min($life[1], $to ?? PHP_INT_MAX) - max($life[0], $from ?? PHP_INT_MIN);
        $need = $from !== null && $to !== null ? min(self::LIVED_YEARS, intdiv($to - $from, 2)) : self::LIVED_YEARS;
        return $overlap >= $need;
    }

    private const LIVED_YEARS = 15;

    /**
     * Words in front of a name that the library's author names leave out ("St. Augustine", "Saint John
     * Chrysostom", "Pope Gregory"), tried only when the name as given matches no author.
     */
    private const NAME_TITLES = ['st', 'saint', 's', 'ste', 'san', 'santo', 'sancta', 'sanctus', 'blessed', 'bl',
        'venerable', 'ven', 'the', 'pope', 'rev', 'revd', 'reverend', 'dr', 'doctor', 'father', 'fr', 'bishop',
        'archbishop', 'abbot', 'brother', 'sister', 'mother', 'sir', 'mr', 'mrs', 'cardinal', 'rabbi', 'apostle'];

    /**
     * What an author filter matches: the name as given, contained in a work's author name (or its slug), as
     * always; when that matches nobody, the name without titles in front ("St.", "Saint", "Pope") and without
     * a place or epithet after it ("of Hippo", "the Great"), as whole words; and then the other names the same
     * writer is filed under (Store::sameWriter: "John Calvin" also finds "Jean Calvin"). Notes say what was done.
     * Keys padded with spaces match whole words only (authorMatches).
     * @param array<int,array> $works
     * @return array{keys: list<string>, notes: list<string>}
     */
    public static function authorKeys(Store $store, array $works, string $author): array
    {
        $key = Text::looseKey($author);
        $keys = [$key];
        $notes = [];
        $ambiguous = [];
        $names = self::authorNames($works, $keys);
        if ($names === [] && $key !== '') {
            foreach (self::plainNames($key) as $alt) {
                $found = self::authorNames($works, [" $alt "]);
                if ($found === []) {
                    continue;
                }
                // Only one writer: the name itself ("St. Basil" -> Basil, not Basil Manly), or names that are one
                // person's ("Rev. Spurgeon" -> Charles Spurgeon, Charles H. Spurgeon). Several writers ("St. John",
                // "Gregory of Nazianzus" -> every Gregory) stay an error that lists them: an author filter vouches
                // for whose words these are.
                $exact = array_values(array_filter(array_map('strval', array_keys($found)),
                    static fn (string $n): bool => Text::looseKey($n) === $alt));
                if (count($exact) === 1) {
                    $keys = ['=' . $alt];
                    $names = [$exact[0] => true];
                    $others = array_values(array_diff(array_map('strval', array_keys($found)), $exact));
                } elseif (self::oneWriter(array_map('strval', array_keys($found)))) {
                    $keys = [" $alt "];
                    $names = $found;
                    $others = [];
                } else {
                    $ambiguous = array_slice(array_map('strval', array_keys($found)), 0, 12);
                    break;
                }
                $notes[] = 'Author "' . Text::echo($author, 60) . '" matched as "' . $alt . '": the library files '
                    . 'names without titles such as "St." or places such as "of Hippo".'
                    . ($others !== [] ? ' Other writers with this name: ' . implode('; ', array_map(
                        static fn (string $o): string => Text::inert(Text::oneLine($o, 80)), array_slice($others, 0, 6))) . '.' : '');
                break;
            }
        }
        $more = [];
        $same = $store->sameWriter();
        foreach (array_keys($names) as $name) {
            foreach ($same[$name] ?? [] as $other) {
                if (!isset($names[$other])) {
                    $more[$other] = true;
                }
            }
        }
        if ($more !== []) {
            $other = array_keys($more);
            foreach ($other as $o) {
                $keys[] = ' ' . Text::looseKey($o) . ' ';
            }
            $shown = array_map(static fn (string $o): string => '"' . Text::inert(Text::oneLine($o, 80)) . '"',
                array_slice($other, 0, 5));
            $notes[] = 'Also included: works filed under ' . implode(', ', $shown)
                . (count($other) > 5 ? ' and others' : '') . ' (the same writer, with the same dates).';
        }
        return ['keys' => $keys, 'notes' => $notes, 'ambiguous' => $ambiguous];
    }

    /** Names that are one person's: the same first and last word ("Charles Spurgeon", "Charles H. Spurgeon"). */
    private static function oneWriter(array $names): bool
    {
        $ends = [];
        foreach ($names as $n) {
            $w = explode(' ', Text::looseKey($n));
            $ends[$w[0] . ' ' . end($w)] = true;
        }
        return count($ends) === 1;
    }

    /**
     * True when the work's author (or slug) holds one of the keys (Filters::authorKeys): keys padded with spaces
     * match whole words, keys starting with "=" the whole name, others any part of it.
     */
    public static function authorMatches(array $w, array $keys): bool
    {
        $a = ' ' . Text::looseKey($w['author']) . ' ';
        $s = ' ' . Text::looseKey($w['author_slug']) . ' ';
        foreach ($keys as $k) {
            if ($k === '') {
                continue;
            }
            if ($k[0] === '=') {
                if (trim($a) === substr($k, 1) || trim($s) === substr($k, 1)) {
                    return true;
                }
            } elseif (str_contains($a, $k) || str_contains($s, $k)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,true> distinct author names of the works the keys match */
    private static function authorNames(array $works, array $keys): array
    {
        $names = [];
        foreach ($works as $w) {
            if (!isset($names[$w['author']]) && self::authorMatches($w, $keys)) {
                $names[$w['author']] = true;
            }
        }
        return $names;
    }

    /**
     * A loose name without titles in front, without "of ..." or "the Great/Elder/Younger" after, and both:
     * "st augustine of hippo" -> ["augustine of hippo", "st augustine", "augustine"] (only forms that differ).
     * @return list<string>
     */
    public static function plainNames(string $key): array
    {
        $words = explode(' ', $key);
        $front = $words;
        while (count($front) > 1 && in_array($front[0], self::NAME_TITLES, true)) {
            array_shift($front);
        }
        $cut = static function (array $w): array {
            $i = array_search('of', $w, true);
            if ($i !== false && $i > 0) {
                $w = array_slice($w, 0, (int) $i);
            }
            $n = count($w);
            if ($n > 2 && $w[$n - 2] === 'the' && in_array($w[$n - 1], ['great', 'elder', 'younger', 'confessor',
                    'theologian', 'venerable'], true)) {
                $w = array_slice($w, 0, $n - 2);
            }
            return $w;
        };
        $out = [];
        foreach ([$front, $cut($words), $cut($front)] as $w) {
            $alt = implode(' ', $w);
            if ($alt !== '' && $alt !== $key && !in_array($alt, $out, true)) {
                $out[] = $alt;
            }
        }
        return $out;
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
    /**
     * A named body's own present-day documents (Filters::resolve 'own'; DESIGN.md section 11) are searched on
     * their own as well, and their best OWN_TOP passages join the passages sent to the reranker (in place of the
     * last of the others, so a search costs the same). After re-ranking, own passages scoring at least OWN_FLOOR
     * (the "partial" match label) come first. The reranker scores a body's present-day documents lower than the
     * long digests of its history (a small body's own answers 0.39-0.65 against 0.75-0.86, 2026-11-19), which is why their
     * place is a rule and not a score. Measured on tests/eval/denominations.jsonl: DESIGN.md section 11.
     */
    public const OWN_TOP = 10;
    /**
     * Works a question names by title ("Shorter Catechism ...", "Nicene Creed ...", "Apostles' Creed"): their best
     * NAMED_TOP passages are added to the passages sent to the reranker (in place of the last of the others, so a
     * search costs the same), and the reranker places them like any other. Short primary texts named in a question
     * were often not among the 50 at all, though the reranker rates them as high as anything once there (the
     * Shorter Catechism's first answer 0.94, the Nicene Creed 0.94; 2026-11-19 testers). A pair of neighbouring
     * words of the question names the works whose titles hold both (at most two words apart), when there are
     * 1 to NAMED_MAX_WORKS of them.
     */
    public const NAMED_TOP = 10;
    public const NAMED_MAX_WORKS = 12;
    private const NAMED_PAIRS = 12;
    private const NAMED_TITLE_WORDS = 4;
    public const OWN_FLOOR = self::MATCH_LOOSE;
    public const KEYWORD_TOP = 100;
    public const VECTOR_TOP = 100;
    public const RELAX_BELOW = 30;
    public const RELAX_QUERIES = 4;
    public const EXACT_SCAN_MAX = 20000;
    public const DUPLICATE_JACCARD = 0.5;
    public const RERANK_DOC_CHARS = 800;    // 2026-11-13: 800 matched or beat 1800 on the eval at 62% of the tokens
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

    /**
     * False when this person may be given no more passages of documents used by permission today: then a
     * named body's own documents are not put first (Licensed::limit would leave them out, and the places they
     * took would be empty), and a note says so.
     */
    public bool $promoteOwn = true;

    public function __construct(
        private readonly Store $store,
        private readonly Voyage $voyage,
        private readonly float $deadline,
        private readonly int $nprobe = 32,
        private readonly ?Log $log = null,
        private readonly float $prefilterKeep = 1.0,
        private readonly int $exactScanMax = self::EXACT_SCAN_MAX,   // lower in tests
        // The reranker sees the first $rerankCandidates fused passages, $rerankDocChars of each (config
        // rerank_candidates, rerank_doc_chars); the rest follow in fused order.
        private readonly int $rerankCandidates = self::FUSE_TOP,
        private readonly int $rerankDocChars = self::RERANK_DOC_CHARS,
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
        ['ids' => $workIds, 'notes' => $notes, 'own' => $own, 'own_body' => $ownBody] = $filters->resolve($this->store);
        // Only when the filter also allows other works (a body's own documents among its history).
        $own = $workIds !== null && count($own) < count($workIds) ? $own : [];
        if ($own !== [] && !$this->promoteOwn) {
            $notes[] = Churches::ownCappedNote((string) $ownBody);
            $own = [];
        }

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
        $qv = null;
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

        $pool = max(self::FUSE_TOP, $this->rerankCandidates);
        $fused = self::rrf([$keyword, $vector], self::RRF_K, $pool);
        $ownList = $own !== [] ? $this->ownList($tokens, $qv, $own) : [];
        $namedList = [];
        if (!$filters->churchOnly() && ($workIds === null || count($workIds) > self::FEW_WORKS)) {
            $named = $this->namedWorks($tokens, $workIds);
            $this->trace['named_works'] = count($named);
            if ($named !== []) {
                $namedList = self::takeTurns($this->ownList($tokens, $qv, $named, 6 * self::NAMED_TOP, 'named'), self::NAMED_TOP);
            }
        }
        if ($ownList !== []) {
            // At the front: only the first rerank_candidates (after any quotation hits) are sent to the reranker.
            $rest = array_values(array_diff($fused, $ownList));
            $fused = array_merge($ownList, array_slice($rest, 0, max(0, $pool - count($ownList))));
        }
        if ($phrase !== []) {
            $fused = array_values(array_unique(array_merge($phrase, $fused)));
        }
        if ($namedList !== []) {
            // Inside the first rerank_candidates, at the end of them (without re-ranking they stay low).
            $cap = max(2, $this->rerankCandidates);
            $missing = array_values(array_diff($namedList, array_slice($fused, 0, $cap)));
            if ($missing !== []) {
                $keep = max(0, $cap - count($missing));
                $fused = array_values(array_unique(array_merge(array_slice($fused, 0, $keep), $missing,
                    array_slice($fused, $keep))));
            }
            $this->trace['named_added'] = count($missing);
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
                    $docs = array_column(array_slice($cands, 0, max(2, $this->rerankCandidates)), 'doc');
                    // With own documents every candidate is scored (the price is the same: Voyage bills the
                    // documents sent, not the results asked for), so their scores can be compared with OWN_FLOOR.
                    $order = $this->voyage->rerank($modern, $docs,
                        $ownList !== [] ? count($docs) : min(count($docs), $limit * 2), $this->deadline);
                    $this->trace['rerank_ms'] = self::ms($t);
                    foreach ($order as $o) {
                        if (isset($cands[$o['index']])) {
                            $cands[$o['index']]['score'] = $o['score'];
                        }
                    }
                    $cands = self::reorder($cands, array_column($order, 'index'));
                    $reranked = true;
                } catch (VoyageException $e) {
                    if ($e->getCode() === VoyageException::PERSON_BUDGET) {
                        $this->trace['rerank'] = 'person budget';
                        $notes[] = self::personBudgetNote();
                    } else {
                        $this->trace['rerank'] = 'failed';
                        $this->log?->error('voyage_rerank_failed', ['error' => $e->getMessage()]);
                        $notes[] = 'Re-ranking unavailable right now; results are in fused order.';
                    }
                }
            }
        }

        $ownWorks = array_flip($own);
        if ($ownList !== []) {
            $scores = array_column(array_filter($cands, static fn (array $c): bool => isset($ownWorks[$c['work_id']])
                && isset($c['score'])), 'score');
            $this->trace['own_best'] = $scores !== [] ? round(max($scores), 3) : null;
            $cands = $reranked ? self::ownFirst($cands, $ownWorks) : self::ownInterleaved($cands, $ownWorks);
        }
        if ($phrase !== []) {
            $cands = self::phraseFirst($cands, $phrase);
        }
        // Breadth: at most PER_WORK passages from one work, unless the search is limited to a few works.
        $perWork = $workIds !== null && count($workIds) <= self::FEW_WORKS ? PHP_INT_MAX : self::PER_WORK;
        $hits = $this->withRetellings(self::withMatch(self::collapse($cands, $limit, $perWork), $phrase), $tokens);
        if ($ownList !== [] && $ownBody !== null) {
            $shown = count(array_filter($hits, static fn (array $h): bool => isset($ownWorks[$h['work_id']])
                && (!$reranked || ($h['score'] ?? -1.0) >= self::OWN_FLOOR)));
            $near = count(array_filter($hits, static fn (array $h): bool => isset($ownWorks[$h['work_id']])));
            $this->trace['own_shown'] = $shown;
            $notes[] = !$reranked ? Churches::ownMixedNote($ownBody)
                : ($shown > 0 ? Churches::ownFirstNote($ownBody, $shown)
                    : Churches::ownNoneNote($ownBody, $near > 0));
        }
        $sides = array_keys(array_filter(['meaning' => $vectorRan, 'keyword' => $keywordRan]));
        $method = ($sides !== [] ? implode(' + ', $sides) : 'no') . ' search' . ($reranked ? ', re-ranked' : '');
        return new SearchResult($query, 'best', $method, $hits, array_values(array_unique($notes)));
    }

    /**
     * A question about what a group of writers held ("what did the Puritans say about assurance") finds mostly
     * later books ABOUT the group, which use its name: the writers seldom used it, and the reranker rated Thomas
     * Brooks on assurance 0.72 with "what did the Puritans say about" and 0.86 without (2026-11-19 testers,
     * findings/doctrine-01..05). The note tells the AI how to reach the writers' own words (search_library only, when
     * no author, work, collection or church filter is set: Tools::searchLibrary; not the public search, whose kept
     * answers are those without notes).
     * @param list<string> $tokens
     */
    public static function groupHint(array $tokens): ?string
    {
        $t = array_flip($tokens);
        if (isset($t['puritan']) || isset($t['puritans']) || isset($t['puritanism'])) {
            return 'For what the Puritans themselves wrote, search the subject again with author set to Puritan writers '
                . '(for example "Thomas Brooks", "John Owen", "Richard Baxter", "Thomas Watson", "Richard Sibbes") or with '
                . 'collection "eebo-tcp": they seldom called themselves Puritans, so results that use the word are mostly '
                . 'later histories about them.';
        }
        if ((isset($t['fathers']) && (isset($t['church']) || isset($t['early']))) || isset($t['patristic'])) {
            return 'For the Church Fathers\' own words, search the subject again with collection "ccel-schaff" (the '
                . 'Fathers in Schaff\'s English translation; "fathers-other" for Greek, Latin and Syriac editions) or with author set to a Father (for example "Augustine", "Chrysostom", '
                . '"Athanasius").';
        }
        if (isset($t['reformers'])) {
            return 'For the Reformers\' own words, search the subject again with author set to one of them (for example '
                . '"Calvin", "Luther", "Zwingli", "Bullinger", "Beza").';
        }
        return null;
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
        // "God's" is searched as "god", which also finds "Gods" and "God" (Text::unpossessive).
        $kw = Text::keywordTokens(array_map([Text::class, 'unpossessive'], $tokens));   // "what's" -> "what": a stopword
        if (array_filter($kw, static fn (string $t): bool => !Text::isStopword($t)) === []) {
            // Only very common words ("to be or not to be"): ranking every passage that has them
            // all would score most of the library, so they are matched as a phrase instead, and a
            // single common word is left to the meaning search.
            $this->trace['keyword'] = 'common words: phrase';
            $phrase = array_slice($tokens, 0, self::MAX_COMMON_PHRASE);
            return count($phrase) < 2 ? []
                : array_map('intval', array_keys($this->store->keywordSearch(Text::ftsPhraseAny($phrase), $workIds, self::KEYWORD_TOP)));
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
        ['ids' => $workIds, 'notes' => $notes, 'own' => $own, 'own_body' => $ownBody] = $filters->resolve($this->store);
        $match = Text::ftsPhraseAny($tokens);
        $method = 'exact phrase, in book order';
        $t = microtime(true);
        $this->requireTime();
        $commonOnly = array_filter($tokens, static fn (string $t): bool => !Text::isStopword($t)) === [];
        if ($own !== [] && $workIds !== null && count($own) < count($workIds) && !$this->promoteOwn) {
            $notes[] = Churches::ownCappedNote((string) $ownBody);
            $own = [];
        }
        if ($own !== [] && $workIds !== null && count($own) < count($workIds) && !$commonOnly) {
            // A named body's own present-day documents first (DESIGN.md section 11): the first Licensed::PER_WORK
            // matches of each, current editions first, then official records newest first, at most
            // Licensed::EXACT_DEPTH in all (exact mode gives no more of these documents, so the rest are not
            // listed and no page comes back empty); then the other records in book order.
            $ownAll = $this->store->matchInOrder($match, $own, self::EXACT_COUNT_CAP, 0);
            $byWork = [];
            foreach ($ownAll as $id) {
                $w = intdiv((int) $id, Store::SEQ_MULT);
                if (count($byWork[$w] ?? []) < Licensed::PER_WORK) {
                    $byWork[$w][] = (int) $id;
                }
            }
            $works = $this->store->works(array_keys($byWork));
            $records = $this->store->churchRecords();
            uksort($byWork, static fn (int $a, int $b): int
                => [($records[$a]['status'] ?? '') === 'current' ? 0 : 1, -($works[$a]['year'] ?? 0), $a]
                <=> [($records[$b]['status'] ?? '') === 'current' ? 0 : 1, -($works[$b]['year'] ?? 0), $b]);
            $head = array_slice(array_merge(...array_values($byWork ?: [[]])), 0, Licensed::EXACT_DEPTH);
            $others = array_values(array_diff($workIds, $own));
            $total = min(self::EXACT_COUNT_CAP + 1,
                count($head) + $this->store->countMatches($match, $others, self::EXACT_COUNT_CAP + 1));
            $ids = array_slice($head, $offset, $limit);
            if (count($ids) < $limit && $total > $offset) {
                $this->requireTime();
                $ids = array_merge($ids, $this->store->matchInOrder($match, $others, $limit - count($ids),
                    max(0, $offset - count($head))));
            }
            if ($head !== [] && $ownBody !== null) {
                $method = 'exact phrase, its own present-day documents first, then in book order';
                $notes[] = Churches::ownExactNote($ownBody, count($head), count($ownAll));
            }
        } else {
            $total = $this->store->countMatches($match, $workIds, self::EXACT_COUNT_CAP + 1);
            if ($total > $offset) {
                $this->requireTime();
            }
            $ids = $total > $offset ? $this->store->matchInOrder($match, $workIds, $limit, $offset) : [];
        }
        $this->trace['exact_ms'] = self::ms($t);
        $hits = [];
        foreach ($this->load($ids) as $p) {
            $p['also'] = [];
            $hits[] = $p;
        }
        $capped = $total > self::EXACT_COUNT_CAP;
        return new SearchResult($query, 'exact', $method, $hits, $notes, $offset,
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
            $cands[$i]['doc'] = Text::clip(ltrim($head . "\n" . $modern), $this->rerankDocChars);
            $cands[$i]['shingles'] = Text::shingles($modern);
            $cands[$i]['author_key'] = Text::looseKey($w['author'] !== '' ? $w['author'] : $w['author_slug']);
        }
        return $cands;
    }

    /**
     * Works the question names by title (NAMED_TOP above): for each pair of neighbouring words of the question
     * (stopwords, numbers and words under 3 letters left out), the works whose title holds both at most two words
     * apart, when 1 to NAMED_MAX_WORKS works do. Only works the filters allow.
     * @param list<string> $tokens  @param list<int>|null $workIds  @return list<int>
     */
    public function namedWorks(array $tokens, ?array $workIds): array
    {
        $content = array_values(array_filter(array_map([Text::class, 'unpossessive'], $tokens),
            static fn (string $t): bool => !Text::isStopword($t) && mb_strlen($t) >= 3 && !ctype_digit($t)));
        $named = [];
        $seen = [];
        for ($i = 0; $i + 1 < count($content) && count($seen) < self::NAMED_PAIRS; $i++) {
            [$a, $b] = [$content[$i], $content[$i + 1]];
            if ($a === $b || isset($seen["$a $b"])) {
                continue;
            }
            $seen["$a $b"] = true;
            try {
                $hits = $this->store->workSearch('title : NEAR(' . Text::quote($a) . ' ' . Text::quote($b) . ', 2)',
                    self::NAMED_MAX_WORKS + 1);
            } catch (\PDOException $e) {
                $this->log?->error('named_works_failed', ['error' => Text::logMessage($e)]);
                return [];
            }
            if ($hits !== [] && count($hits) <= self::NAMED_MAX_WORKS) {
                foreach ($this->store->works(array_map('intval', array_keys($hits))) as $id => $w) {
                    // The pair is at least half the title's words: "Westminster Shorter Catechism", "The City of
                    // God", not "A Treatise of the Lords Supper in Two Sermons" (a title only about the subject).
                    if (count(self::titleWords((string) $w['title'])) <= self::NAMED_TITLE_WORDS) {
                        $named[(int) $id] = true;
                    }
                }
            }
        }
        $ids = array_map('intval', array_keys($named));
        if ($workIds !== null) {
            $ids = array_values(array_intersect($ids, $workIds));
        }
        // Smallest first, and no more passages in all than an exact meaning scan takes (no extra index scan):
        // the short primary texts are what this is for.
        $works = $this->store->works($ids);
        usort($ids, static fn (int $a, int $b): int => [$works[$a]['n_passages'] ?? 0, $a] <=> [$works[$b]['n_passages'] ?? 0, $b]);
        $out = [];
        $passages = 0;
        foreach (array_slice($ids, 0, 2 * self::NAMED_MAX_WORKS) as $id) {
            $passages += (int) ($works[$id]['n_passages'] ?? 0);
            if ($passages > $this->exactScanMax) {
                break;
            }
            $out[] = $id;
        }
        return $out;
    }

    /**
     * The best OWN_TOP (or $top) passages of the named body's own present-day documents ($own work ids; also the works a
     * question names, namedWorks), by keyword and,
     * when the query vector is there, meaning (an exact scan: these are a few documents), fused.
     * @param list<string> $tokens  @param list<float>|null $qv  @param list<int> $own  @return list<int>
     */
    /**
     * The first $n passage ids, each work taking a turn: the best of every work (in the order of their best), then
     * the second of each, and so on. A one-passage creed named beside a 1,774-passage book on it still gets its place.
     * @param list<int> $ids best first  @return list<int>
     */
    public static function takeTurns(array $ids, int $n): array
    {
        $byWork = [];
        foreach ($ids as $id) {
            $byWork[intdiv((int) $id, Store::SEQ_MULT)][] = (int) $id;
        }
        $out = [];
        for ($round = 0; count($out) < $n; $round++) {
            $added = false;
            foreach ($byWork as $list) {
                if (isset($list[$round]) && count($out) < $n) {
                    $out[] = $list[$round];
                    $added = true;
                }
            }
            if (!$added) {
                break;
            }
        }
        return $out;
    }

    /** Distinct words of a title that count for naming it: no stopwords, numbers or words under 3 letters. @return list<string> */
    public static function titleWords(string $title): array
    {
        return array_values(array_unique(array_filter(explode(' ', Text::looseKey(mb_substr($title, 0, 300))),
            static fn (string $t): bool => mb_strlen($t) >= 3 && !Text::isStopword($t) && !ctype_digit($t))));
    }

    private function ownList(array $tokens, ?array $qv, array $own, int $top = self::OWN_TOP, string $what = 'own'): array
    {
        $lists = [];
        try {
            $lists[] = $this->keywordList($tokens, $own);
        } catch (\PDOException $e) {
            $this->log?->error('keyword_search_failed', ['error' => Text::logMessage($e), $what => true]);
        }
        if ($qv !== null && $this->remaining() >= self::MIN_SECONDS_SCAN) {
            try {
                $ignored = [];
                $lists[] = $this->vectorSearch($qv, $own, self::VECTOR_TOP, $ignored);
            } catch (\RuntimeException | \LogicException $e) {
                $this->log?->error('vector_scan_failed', ['error' => Text::logMessage($e), $what => true]);
            }
        }
        $out = self::rrf($lists, self::RRF_K, $top);
        $this->trace[$what . '_candidates'] = count($out);
        return $out;
    }

    /**
     * Re-ranked candidates with the own documents' passages scoring at least OWN_FLOOR first, each group in
     * its re-ranked order. @param array<int,mixed> $ownWorks own work ids as keys
     */
    public static function ownFirst(array $cands, array $ownWorks): array
    {
        $first = [];
        $rest = [];
        foreach ($cands as $c) {
            if (isset($ownWorks[$c['work_id']]) && ($c['score'] ?? -1.0) >= self::OWN_FLOOR) {
                $first[] = $c;
            } else {
                $rest[] = $c;
            }
        }
        return array_merge($first, $rest);
    }

    /**
     * Without re-ranking there is no score to judge the own documents' passages by: they take every other
     * place from the second, in their own order, so they are seen but not put above everything else
     * (2026-11-19, re-ranking refused: own first lost every history question, 0/6; second keeps them).
     */
    public static function ownInterleaved(array $cands, array $ownWorks): array
    {
        $own = [];
        $rest = [];
        foreach ($cands as $c) {
            if (isset($ownWorks[$c['work_id']])) {
                $own[] = $c;
            } else {
                $rest[] = $c;
            }
        }
        $out = [];
        for ($i = 0; $i < max(count($own), count($rest)); $i++) {
            if (isset($rest[$i])) {
                $out[] = $rest[$i];
            }
            if (isset($own[$i])) {
                $out[] = $own[$i];
            }
        }
        return $out;
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
            $match = Text::ftsPhraseAny($tokens);
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

    /** The note for a person past their daily re-ranking allowance (RateLimit, 'person'). */
    public static function personBudgetNote(): string
    {
        $hours = max(1, (int) ceil((86400 - time() % 86400) / 3600));
        return 'You have used today\'s re-ranked searches (each person has a daily share, so the library can stay '
            . 'free for everyone). Results are in meaning and keyword order, without re-ranking or match labels, '
            . "until 00:00 UTC (in about $hours hour" . ($hours === 1 ? '' : 's') . ').';
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
