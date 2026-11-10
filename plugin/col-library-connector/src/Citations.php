<?php
/**
 * Passages that cite a Bible reference (PLUGIN-SPEC.md addendum, find_scripture_citations), from
 * the build's scripture_refs table: one row per reference found in a passage's text (in_note 0) or
 * in its footnotes and margin notes (in_note 1); verse_start NULL means a whole chapter.
 *
 * A citation matches when it is in the same book and chapter and its verses overlap the requested
 * ones (Rom 8:26-30 matches Rom 8:28); a request for a whole chapter matches every citation in it.
 * Citations of the whole chapter ("Rom. 8.") are included when asked for, after the others.
 * Order: within each of the two groups, works by year (unknown years last), one passage per work
 * per round; at most CAP citation rows are read (chapter by chapter in verse order, whole-chapter
 * citations last).
 * Memory: the rows read, and three small values per passage; what a passage cites is listed only
 * for the passages of the page shown.
 */

declare(strict_types=1);

namespace ColLibrary;

defined('ABSPATH') || exit;

/** What find_scripture_citations found, ready for Format::citations. */
final class CitationResult
{
    /**
     * @param list<array{label:string, lines:list<string>, more:bool}> $texts the reference in the BSB and KJV
     * @param list<array> $hits passage rows (Store::passages) with 'work', 'cites' (what the passage cites,
     *                          e.g. "Rom 8:28-30") and 'in_note' (only in margin notes)
     * @param list<string> $notes
     */
    public function __construct(
        public readonly string $label,          // "Romans 8:28"
        public readonly string $heading,        // filters, e.g. 'author contains "owen"'
        public readonly array $texts,
        public readonly array $hits,
        public readonly int $passages,          // distinct passages that cite it
        public readonly int $works,
        public readonly int $inNotes,           // passages that cite it only in margin notes
        public readonly int $chapterOnly,       // passages that cite only the whole chapter
        public readonly string $chapterLabel,   // "Romans 8"
        public readonly int $offset,
        public readonly bool $capped,
        public readonly array $notes,
    ) {
    }
}

final class Citations
{
    public const CAP = 20000;              // citation rows read at most
    public const MAX_OFFSET = 5000;        // the offset argument's maximum
    public const MAX_CHAPTERS = 10;        // chapters per request
    public const TEXT_VERSES = 5;          // verses of the reference shown per translation
    private const MAX_CITES_SHOWN = 3;     // citations listed after each passage

    public function __construct(
        private readonly Store $store,
        private readonly Bible $bible,
        private readonly int $cap = self::CAP,   // lower in tests
    ) {
    }

    /**
     * @throws SearchInputException when the filters match no work
     * @throws ArgumentException when the reference spans too many chapters
     */
    public function find(BibleRef $ref, bool $chapterRefs, Filters $filters, int $limit, int $offset): CitationResult
    {
        if ($ref->endChapter - $ref->chapter + 1 > self::MAX_CHAPTERS) {
            $first = new BibleRef($ref->book, $ref->chapter, null, $ref->chapter + self::MAX_CHAPTERS - 1, null);
            throw new ArgumentException('Ask for at most ' . self::MAX_CHAPTERS . ' chapters at a time (for example "'
                . $this->bible->label($first) . '").');
        }
        ['ids' => $workIds, 'notes' => $notes] = $filters->resolve($this->store);
        $rows = [];
        $capped = false;
        foreach ($ref->segments() as [$chapter, $from, $to]) {
            $whole = $from === null && $to === null;
            array_push($rows, ...$this->store->citations($ref->book, $chapter, $whole ? null : ($from ?? 1), $to,
                $chapterRefs, $workIds, $this->cap + 1 - count($rows)));
            if (count($rows) > $this->cap) {
                array_splice($rows, $this->cap);
                $capped = true;
                break;
            }
        }
        $passages = self::byPassage($rows);
        $works = [];
        $inNotes = 0;
        $chapterOnly = 0;
        foreach ($passages as $p) {
            $works[$p['work_id']] = true;
            $inNotes += $p['in_note'] ? 1 : 0;
            $chapterOnly += $p['group'] === 2 ? 1 : 0;
        }
        $years = [];
        foreach ($this->store->works(array_map('intval', array_keys($works))) as $id => $w) {
            $years[$id] = $w['year'];
        }
        $order = self::order($passages, $years);
        $hits = [];
        $page = array_slice($order, $offset, $limit);
        $cites = self::cites($rows, $passages, $page, $ref);
        unset($rows, $order);
        $loaded = $this->store->passages($page);
        $workRows = $this->store->works(array_values(array_unique(array_column($loaded, 'work_id'))));
        foreach ($page as $id) {
            if (!isset($loaded[$id], $workRows[$loaded[$id]['work_id']])) {
                continue;   // a citation row without its passage: skip it rather than fail
            }
            $p = $loaded[$id];
            $p['work'] = $workRows[$p['work_id']];
            $p['cites'] = $this->citesLabel($ref->book, $passages[$id]['group'], $cites[$id] ?? []);
            $p['in_note'] = $passages[$id]['in_note'];
            $hits[] = $p;
        }
        if ($capped) {
            $notes[] = 'This is cited more than ' . number_format($this->cap) . ' times; only the first '
                . number_format($this->cap) . ' citations (chapter by chapter in verse order, those of the whole chapter '
                . 'last) were counted and ordered. Narrow the reference, or filter by author, collection or years, to see '
                . 'the rest.';
        }
        $label = $this->bible->label($ref);
        $chapterLabel = $this->bible->label(new BibleRef($ref->book, $ref->chapter, null, $ref->endChapter, null));
        return new CitationResult($label, $filters->active() ? $filters->describe() : '', $this->texts($ref),
            $hits, count($passages), count($works), $inNotes, $chapterOnly, $chapterLabel, $offset, $capped, $notes);
    }

    /**
     * Citation rows grouped by passage. A passage is in group 1 if it cites any of the verses (for
     * a whole-chapter request: any verse of the chapter), else in group 2 (it cites only the whole
     * chapter). 'in_note': every citation of its group is in a footnote or margin note.
     * @param list<array{0:int,1:int,2:int,3:?int,4:?int,5:int}> $rows [chapter, passage, work, from, to, in_note]
     * @return array<int, array{work_id:int, group:int, in_note:bool}> by passage id
     */
    public static function byPassage(array $rows): array
    {
        $bits = [];   // passage id => 1: cites verses, 2: ... in the text; 4: cites the whole chapter, 8: ... in the text
        $work = [];
        foreach ($rows as [, $pid, $wid, $from, , $note]) {
            $bit = $from === null ? 4 : 1;
            $bits[$pid] = ($bits[$pid] ?? 0) | $bit | ($note ? 0 : $bit << 1);
            $work[$pid] = $wid;
        }
        $out = [];
        foreach ($bits as $pid => $b) {
            $g = ($b & 1) !== 0 ? 1 : 2;
            $out[$pid] = ['work_id' => $work[$pid], 'group' => $g, 'in_note' => ($b & ($g === 1 ? 2 : 8)) === 0];
        }
        return $out;
    }

    /**
     * What some passages cite in their group, [chapter, from, to] without repeats: the requested
     * verses first, then the shortest spans.
     * @param list<array{0:int,1:int,2:int,3:?int,4:?int,5:int}> $rows as for byPassage()
     * @param array<int, array{group:int}> $passages byPassage()  @param list<int> $ids
     * @return array<int, list<array{0:int,1:?int,2:?int}>> by passage id
     */
    public static function cites(array $rows, array $passages, array $ids, BibleRef $ref): array
    {
        $want = array_flip($ids);
        $out = [];
        foreach ($rows as [$chapter, $pid, , $from, $to]) {
            if (isset($want[$pid]) && ($from === null ? 2 : 1) === $passages[$pid]['group']) {
                $out[$pid]["$chapter:$from:$to"] = [$chapter, $from, $to];
            }
        }
        $closest = static fn (array $x, array $y): int => [self::distance($x, $ref), $x[0], $x[1] ?? 0, $x[2] ?? 0]
            <=> [self::distance($y, $ref), $y[0], $y[1] ?? 0, $y[2] ?? 0];
        foreach ($out as $pid => $list) {
            $list = array_values($list);
            usort($list, $closest);   // the same verses first, then the shortest span
            $out[$pid] = $list;
        }
        return $out;
    }

    /**
     * Passage ids in result order: group 1, then group 2; within each, works by year (unknown years
     * last, ties by their first passage id), one passage per work per round, each work's passages
     * in id order.
     * @param array<int, array{work_id:int, group:int}> $passages by id
     * @param array<int, ?int> $years by work id
     * @return list<int>
     */
    public static function order(array $passages, array $years): array
    {
        ksort($passages);
        $byWork = [1 => [], 2 => []];
        foreach ($passages as $id => $p) {
            $byWork[$p['group']][$p['work_id']][] = (int) $id;
        }
        $keys = [];   // passage id => (group, round, rank of its work) as one integer
        foreach ($byWork as $group => $works) {
            uksort($works, static function (int $a, int $b) use ($works, $years): int {
                $ya = $years[$a] ?? null;
                $yb = $years[$b] ?? null;
                return [$ya === null ? 1 : 0, $ya ?? 0, $works[$a][0]] <=> [$yb === null ? 1 : 0, $yb ?? 0, $works[$b][0]];
            });
            $rank = 0;
            foreach ($works as $ids) {
                foreach ($ids as $round => $id) {
                    $keys[$id] = ($group * Store::SEQ_MULT + $round) * Store::SEQ_MULT + $rank;
                }
                $rank++;
            }
        }
        asort($keys);
        return array_map('intval', array_keys($keys));
    }

    /**
     * "Rom 8:28, 8:26-30" or "Romans 8, whole chapter" (with "…" after MAX_CITES_SHOWN).
     * @param list<array{0:int,1:?int,2:?int}> $cites Citations::cites() of one passage
     */
    private function citesLabel(int $book, int $group, array $cites): string
    {
        if ($group === 2) {
            $chapters = array_map(fn (array $c): string => $this->bible->label(new BibleRef($book, $c[0], null, $c[0], null)),
                $cites);
            return implode('; ', array_slice($chapters, 0, self::MAX_CITES_SHOWN)) . ', whole chapter'
                . (count($chapters) > 1 ? 's' : '');
        }
        $parts = [];
        foreach (array_slice($cites, 0, self::MAX_CITES_SHOWN) as $i => [$c, $from, $to]) {
            $label = $this->bible->label(new BibleRef($book, $c, $from, $c, $to ?? $from), true);
            if ($i > 0) {
                $label = (string) preg_replace('/^.*? (?=\d)/', '', $label);   // "Rom 8:28, 8:30"
            }
            $parts[] = $label;
        }
        return implode(', ', $parts) . (count($cites) > self::MAX_CITES_SHOWN ? ', …' : '');
    }

    /**
     * 0 for a citation of exactly the requested verses, else 1 + the length of its span.
     * @param array{0:int,1:?int,2:?int} $c [chapter, from, to]
     */
    private static function distance(array $c, BibleRef $ref): int
    {
        if ($c[1] === null) {
            return 1000;
        }
        $from = $c[0] === $ref->chapter ? $ref->verse : null;
        $to = $c[0] === $ref->endChapter ? $ref->endVerse : null;
        $same = $from !== null && $to !== null && $c[1] === $from && ($c[2] ?? $c[1]) === $to;
        return $same ? 0 : 1 + ($c[2] ?? $c[1]) - $c[1];
    }

    /**
     * The reference in the BSB and the KJV (first TEXT_VERSES verses of each), or in the
     * translation that has it when neither does (some books only the Douay-Rheims has).
     * @return list<array{label:string, lines:list<string>, more:bool}>
     */
    private function texts(BibleRef $ref): array
    {
        $out = [];
        $have = $this->bible->translations();
        $wanted = array_values(array_intersect(Bible::CITATION_TRANSLATIONS, $have));
        foreach ([$wanted, array_values(array_diff($have, $wanted))] as $round) {
            foreach ($round as $t) {
                $rows = $this->bible->verses($ref, $t, self::TEXT_VERSES + 1);
                if ($rows === []) {
                    continue;
                }
                $cross = $ref->chapter !== $ref->endChapter;
                $lines = [];
                foreach (array_slice($rows, 0, self::TEXT_VERSES) as $r) {
                    $lines[] = ($cross ? "{$r['chapter']}:" : '') . $r['verse'] . ' ' . Text::oneLine($r['text'], 2000);
                }
                $out[] = ['label' => $this->bible->label($ref) . ' (' . Bible::TRANSLATIONS[$t] . ')', 'lines' => $lines,
                          'more' => count($rows) > self::TEXT_VERSES];
                if ($round !== $wanted && $out !== []) {
                    return $out;   // a fallback translation: one is enough
                }
            }
            if ($out !== []) {
                return $out;
            }
        }
        return $out;
    }
}
