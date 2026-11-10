<?php
/**
 * Look-ups in the build's dictionary table (DESIGN.md section 10; PLUGIN-SPEC.md addendum,
 * define_term): Easton, Smith, Hitchcock, Nave, Torrey, Wilson (1612, 1661) and Coles (1677).
 *
 * Headwords were stored with a normalized form, norm = norm_headword() in indexer/references.py:
 * no markup, no parenthesized parts, no trailing punctuation, old spellings modernized, lower case,
 * only letters, digits, spaces and hyphens. A term is normalized the same way (old spellings
 * through the build's spelling table) and looked up as norm = ?, then as a prefix of norm.
 * The indexer's modernizer sometimes joins different words ("Belive", Coles's word for "anon", is
 * indexed as "believe"): an entry found only through its indexed form, not its own headword, is
 * listed after those that have the headword and marked (Format::definitions).
 */

declare(strict_types=1);

namespace ColLibrary;

defined('ABSPATH') || exit;

/** What define_term found, ready for Format::definitions. */
final class DictionaryResult
{
    /**
     * @param list<array{id:int, source:string, work_id:?int, headword:string, norm:string, page:?string,
     *                   text:string, length:int, cut:bool, first:bool, alone:bool, exact:bool, indexed:bool}> $entries
     *        text cut to ENTRY_CHARS (ENTRY_ALONE_CHARS for a single entry), length = full length; first:
     *        the first entry of its source here (the same term with that source and limit 1 returns it);
     *        alone: its headword with its source and limit 1 returns it; indexed: found through its
     *        indexed (modernized) form only, not its own headword
     * @param list<string> $notes
     */
    public function __construct(
        public readonly string $term,
        public readonly string $norm,          // the normalized form looked up first
        public readonly ?string $source,       // null = all sources
        public readonly array $entries,
        public readonly bool $more,            // more entries match than were returned
        public readonly array $notes,
    ) {
    }
}

final class Dictionary
{
    /** Sources in the order results list them, with their labels (PLUGIN-SPEC.md addendum). */
    public const SOURCES = [
        'easton' => "Easton's Bible Dictionary (1897)",
        'smith' => "Smith's Bible Dictionary (1863)",
        'hitchcock' => "Hitchcock's Bible Names Dictionary (1869)",
        'wilson' => 'Thomas Wilson, A Christian Dictionarie (1612)',
        'wilson1661' => 'Thomas Wilson, A Complete Christian Dictionary (1661)',
        'coles' => 'Elisha Coles, An English Dictionary (1677)',
        'nave' => "Nave's Topical Bible (1896)",
        'torrey' => "Torrey's New Topical Textbook (1897)",
    ];
    /** The source argument's values, in the order the tool lists them. */
    public const SOURCE_ARGS = ['all', 'easton', 'smith', 'hitchcock', 'nave', 'torrey', 'wilson', 'wilson1661', 'coles'];
    public const ENTRY_CHARS = 4000;
    public const ENTRY_ALONE_CHARS = 100000;   // a single entry is shown whole (the longest has about 50,000)

    public function __construct(private readonly Store $store)
    {
    }

    /**
     * indexer/references.py norm_headword() without its modernizer step (or with $modernize in its
     * place): HTML entities decoded; *, _ and ` dropped; "(...)" parts dropped; trailing spaces and
     * punctuation dropped; lower case; anything but letters, digits, _, whitespace and "-" dropped;
     * whitespace collapsed.
     * @param \Closure(string):string|null $modernize
     */
    public static function norm(string $word, ?\Closure $modernize = null): string
    {
        $w = html_entity_decode($word, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $w = str_replace(['*', '_', '`'], '', $w);
        $w = (string) preg_replace('/\(.*?\)/u', '', $w);
        $w = (string) preg_replace('/^\s+|\s+$/u', '', $w);                    // Python str.strip()
        $w = (string) preg_replace('/[\s,;:.!?\'"’“”]+$/u', '', $w);
        if ($modernize !== null) {
            $w = $modernize($w);
        }
        $w = (string) preg_replace('/[^\p{L}\p{N}_\s-]/u', '', mb_strtolower($w, 'UTF-8'));
        return (string) preg_replace('/^\s+|\s+$/u', '', (string) preg_replace('/\s+/u', ' ', $w));
    }

    /**
     * The normalized forms of a term to look up, best first: with old spellings modernized (as the
     * headwords were), as typed, with hyphens as spaces and spaces as hyphens, and without accents.
     * @return list<string>
     */
    public function candidates(string $term): array
    {
        $store = $this->store;
        $modern = self::norm($term, static function (string $w) use ($store): string {
            $folded = Text::fold($w);
            return Text::modernizeText($folded, $store->spellings(Text::words(mb_strtolower($folded, 'UTF-8'))));
        });
        $plain = self::norm($term);
        $out = [$modern, $plain];
        foreach ([$modern, $plain] as $n) {
            $out[] = trim((string) preg_replace('/\s+/u', ' ', str_replace('-', ' ', $n)));   // "a - b" -> "a b"
            if (substr_count($n, ' ') === 1) {
                $out[] = str_replace(' ', '-', $n);
            }
            $out[] = Text::looseKey($n);
        }
        return array_values(array_unique(array_filter($out, static fn (string $n): bool => $n !== '')));
    }

    /**
     * True if an entry's own headword (not just its indexed form) is one of the term's forms, or
     * begins with one of them when $prefix.
     * @param list<string> $forms candidates()
     */
    public static function ownMatch(string $headword, array $forms, bool $prefix): bool
    {
        $own = self::norm($headword);
        foreach (array_unique([$own, Text::looseKey($own), trim((string) preg_replace('/\s+/u', ' ',
            str_replace('-', ' ', $own)))]) as $h) {
            foreach ($forms as $f) {
                if ($h === $f || ($prefix && $f !== '' && str_starts_with($h, $f))) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Entries whose headword is the term (in any of its forms), then entries whose headword begins
     * with it; each group in source order (SOURCES), then by length and position.
     * @throws ArgumentException when the term has no letters or digits
     */
    public function lookup(string $term, ?string $source, int $limit): DictionaryResult
    {
        $forms = $this->candidates($term);
        if ($forms === []) {
            throw new ArgumentException('term must contain letters or digits, for example "grace" or "conversation".');
        }
        $rows = $this->select($forms, $source, $limit);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $texts = $this->store->dictionaryTexts(array_column($rows, 'id'));
        $cap = count($rows) === 1 ? self::ENTRY_ALONE_CHARS : self::ENTRY_CHARS;
        $entries = [];
        $sources = [];
        $firstOfHead = [];   // [headword, source] => id of its first entry (one select each)
        foreach ($rows as $r) {
            $text = trim($texts[$r['id']] ?? '');
            $length = mb_strlen($text);
            $first = !isset($sources[$r['source']]);
            $sources[$r['source']] = true;
            // a shortened entry is shown whole when it comes alone: with its source and limit 1 (the
            // first of its source here), or as the first entry of its own headword in its source
            $alone = false;
            if ($length > $cap && !$first) {
                $k = $r['headword'] . "\0" . $r['source'];
                $firstOfHead[$k] ??= $this->select($this->candidates($r['headword']), $r['source'], 1)[0]['id'] ?? null;
                $alone = $firstOfHead[$k] === $r['id'];
            }
            $entries[] = $r + ['text' => Text::clip($text, $cap), 'length' => $length, 'cut' => $length > $cap,
                               'first' => $first, 'alone' => $alone];
        }
        $notes = [];
        if ($entries === [] && $source !== null) {
            $elsewhere = [];
            foreach ($this->store->dictionaryExact($forms, null) as $r) {
                $elsewhere[$r['source']] = ($elsewhere[$r['source']] ?? 0) + 1;
            }
            if ($elsewhere !== []) {
                $list = [];
                foreach ($elsewhere as $s => $n) {
                    $list[] = "$s ($n)";
                }
                $notes[] = 'Other dictionaries have it: ' . implode(', ', $list) . '; use source "all".';
            }
        }
        return new DictionaryResult($term, $forms[0], $source, $entries, $more, $notes);
    }

    /**
     * Entry rows (without text) for the forms of a term, in result order: exact matches (those with
     * the headword itself first, then those found through their indexed spelling), each group in
     * source order; then prefix matches. At least $limit + 1 rows when there are that many.
     * @param list<string> $forms candidates()
     * @return list<array> rows as Store::dictionaryExact() plus exact and indexed
     */
    private function select(array $forms, ?string $source, int $limit): array
    {
        if ($forms === []) {
            return [];
        }
        $order = array_keys(self::SOURCES);
        $only = $source === null ? null : [$source];
        $rank = array_flip($order);
        $rows = [];
        foreach ($this->store->dictionaryExact($forms, $only, $order) as $r) {
            $rows[] = $r + ['exact' => true, 'indexed' => !self::ownMatch($r['headword'], $forms, false)];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['indexed'], $rank[$a['source']] ?? 99, $a['id']]
            <=> [$b['indexed'], $rank[$b['source']] ?? 99, $b['id']]);
        $seen = array_flip(array_column($rows, 'id'));
        foreach ($forms as $form) {
            if (count($rows) > $limit) {
                break;
            }
            foreach ($this->store->dictionaryPrefix($form, $order, $only, $limit + 1 - count($rows)) as $r) {
                if (!isset($seen[$r['id']])) {
                    $seen[$r['id']] = true;
                    $rows[] = $r + ['exact' => false, 'indexed' => !self::ownMatch($r['headword'], $forms, true)];
                }
            }
        }
        return $rows;
    }
}
