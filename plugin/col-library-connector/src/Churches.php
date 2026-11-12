<?php
/**
 * Church records: the "denomination" and "tradition" search filters and the label each church
 * record carries (DESIGN.md section 11).
 *
 * The books repository files its church records under Denominations/<tradition>/<body>/ with
 * front-matter tags (denomination, tradition, doc_kind, status); the indexer copies them into the
 * church_records table, and Denominations/lineage.csv and present-day.csv into church_bodies (one row
 * per body whose records the library holds, with the tag values that belong to it and other names)
 * and church_names (present-day bodies and the historical bodies they descend from, some limited to
 * records of certain years: the PCA descends from the PCUSA only up to 1861).
 *
 * A denomination given by a user is matched, ignoring case, accents, punctuation and spaces
 * ("PC(USA)" = "pcusa"), against
 *   1. the bodies' names, tags and other names: that body;
 *   2. present-day names and their abbreviations: the bodies it descends from;
 *   3. a tradition ("Lutheran", "Episcopalian"): every body of the tradition;
 *   4. else words of a body's names ("Methodist Episcopal"): those bodies;
 * and the union of 1 and 2 is searched. Nothing found is an error that lists what the library has,
 * never a silent empty result. Builds made before these tables existed refuse the filters.
 */

declare(strict_types=1);

namespace ColLibrary;

defined('ABSPATH') || exit;

final class Churches
{
    /** The traditions of the books repository's Denominations shelf (CONTRIBUTING.md). */
    public const TRADITIONS = ['Presbyterian', 'Reformed', 'Baptist', 'Methodist', 'Holiness', 'Pentecostal',
        'Anglican', 'Lutheran', 'Catholic', 'Orthodox', 'Congregational', 'Anabaptist', 'Quaker', 'Restorationist',
        'Moravian', 'Other'];

    /** Other words for a tradition (compact keys). */
    private const TRADITION_WORDS = [
        'episcopal' => 'Anglican', 'episcopalian' => 'Anglican', 'episcopalians' => 'Anglican',
        'anglicans' => 'Anglican', 'romancatholic' => 'Catholic', 'quakers' => 'Quaker', 'friends' => 'Quaker',
        'mennonite' => 'Anabaptist', 'mennonites' => 'Anabaptist', 'easternorthodox' => 'Orthodox',
        'presbyterians' => 'Presbyterian', 'baptists' => 'Baptist', 'methodists' => 'Methodist',
        'lutherans' => 'Lutheran', 'catholics' => 'Catholic', 'pentecostals' => 'Pentecostal',
        'congregationalist' => 'Congregational', 'congregationalists' => 'Congregational',
        'reformedchurch' => 'Reformed', 'restorationmovement' => 'Restorationist',
    ];

    /** Kinds of document that are a body's own records (the rest: histories and other writings filed with them). */
    public const OFFICIAL_KINDS = ['constitution', 'discipline', 'canons', 'digest', 'minutes', 'journal', 'report',
        'confession', 'catechism', 'liturgy', 'manual'];

    public const NAME_MAX = 120;   // characters of a denomination filter

    /** "PC(USA)" -> "pcusa": lower case, no accents, letters and digits only. */
    public static function key(string $text): string
    {
        return str_replace(' ', '', Text::looseKey($text));
    }

    /**
     * The bodies a denomination filter means, with the years of records allowed for each, and notes
     * that say what was searched.
     * @return array{bodies: array<string, list<array{0:?int,1:?int}>>, notes: list<string>}
     *         body name => year ranges (one [null, null] range = all its records)
     * @throws SearchInputException when nothing matches (the message lists what the library has)
     */
    public static function match(Store $store, string $text): array
    {
        $bodies = $store->churchBodies();
        $q = self::key(preg_replace('/^\s*the\s+/i', '', $text) ?? $text);
        $short = preg_replace('/(church|churches|denomination)$/', '', $q) ?? $q;
        $keys = array_values(array_unique(array_filter([$q, $short], static fn (string $k): bool => $k !== '')));
        if ($keys === []) {
            throw new SearchInputException('denomination must contain letters, e.g. "PCA", "Southern Baptist '
                . 'Convention" or "Methodist Episcopal Church".');
        }
        $echo = Text::echo($text, 80);
        $found = [];     // body => ranges
        $notes = [];

        // 1. A body by its name, a tag value or another name.
        $direct = [];
        foreach ($bodies as $name => $b) {
            foreach (array_merge([$name], $b['tags'], $b['aliases']) as $n) {
                if (in_array(self::key($n), $keys, true)) {
                    $direct[] = $name;
                    $found[$name] = [[null, null]];
                    break;
                }
            }
        }
        // 2. A present-day name: the bodies it descends from.
        $named = [];
        foreach ($store->churchNames() as $name => $c) {
            foreach (array_merge([$name], $c['aliases']) as $n) {
                if (in_array(self::key($n), $keys, true)) {
                    $named[$name] = $c;
                    foreach ($c['ancestors'] as [$body, $from, $to]) {
                        if (isset($bodies[$body]) && ($found[$body] ?? null) !== [[null, null]]) {
                            $found[$body][] = [$from, $to];
                        }
                    }
                    break;
                }
            }
        }
        foreach ($found as $body => $ranges) {
            if (in_array([null, null], $ranges, true)) {
                $found[$body] = [[null, null]];     // all its records
            }
        }
        foreach ($named as $name => $c) {
            $notes[] = self::lineageNote($echo, $name, $c, $bodies, $direct);
        }
        if ($direct !== [] && $named === []) {
            $notes[] = 'Denomination "' . $echo . '": the records of the ' . self::bodyList($direct, $bodies) . '.';
        }
        if ($found !== []) {
            return ['bodies' => $found, 'notes' => $notes];
        }

        // 3. A tradition.
        $tradition = null;
        foreach (self::TRADITIONS as $t) {
            $k = self::key($t);
            if (in_array($k, $keys, true) || in_array($k . 's', $keys, true) || in_array($k . 'ism', $keys, true)) {
                $tradition = $t;
            }
        }
        $tradition ??= self::TRADITION_WORDS[$q] ?? self::TRADITION_WORDS[$short] ?? null;
        if ($tradition !== null) {
            $names = array_keys(array_filter($bodies, static fn (array $b): bool => $b['tradition'] === $tradition));
            if ($names !== []) {
                return ['bodies' => array_fill_keys($names, [[null, null]]),
                        'notes' => ['Denomination "' . $echo . '": the records of all ' . count($names) . ' '
                            . $tradition . ' bodies in the library (the tradition filter does the same).']];
            }
        }

        // 4. Words of a body's names.
        $words = ' ' . Text::looseKey($text) . ' ';
        if (strlen(trim($words)) >= 4) {
            $loose = [];
            foreach ($bodies as $name => $b) {
                foreach (array_merge([$name], $b['tags'], $b['aliases']) as $n) {
                    if (str_contains(' ' . Text::looseKey($n) . ' ', $words)) {
                        $loose[] = $name;
                        break;
                    }
                }
            }
            if ($loose !== []) {
                return ['bodies' => array_fill_keys($loose, [[null, null]]),
                        'notes' => ['No church body is named exactly "' . $echo . '"; searching the records of the '
                            . count($loose) . ' whose names contain it: ' . self::bodyList($loose, $bodies) . '.']];
            }
        }
        throw new SearchInputException(self::unknown($store, $echo));
    }

    /**
     * Ids of the works a denomination and/or tradition filter allows, out of $works (all works by id), and
     * notes. Works outside the year ranges of their body, or without a year when a range applies, are left
     * out (counted in the notes).
     * @return array{ids: array<int,true>, notes: list<string>}
     * @throws SearchInputException
     */
    public static function allowed(Store $store, array $works, ?string $denomination, ?string $tradition): array
    {
        if (!$store->hasChurchRecords()) {
            throw new SearchInputException('Searching by denomination or tradition is not available in this copy of '
                . 'the library yet (it comes with the next library update). For now, search with the church\'s name '
                . 'among the words, e.g. "Presbyterian Church in the United States ruling elders".');
        }
        $records = $store->churchRecords();
        $bodyOf = $store->churchBodyOfTag();
        $match = $denomination !== null ? self::match($store, $denomination) : null;
        $ids = [];
        $outside = 0;
        $undated = 0;
        foreach ($records as $id => $r) {
            if (!isset($works[$id])) {
                continue;
            }
            if ($tradition !== null && $r['tradition'] !== $tradition) {
                continue;
            }
            if ($match !== null) {
                $ranges = $match['bodies'][$bodyOf[$r['denomination']] ?? ''] ?? null;
                if ($ranges === null) {
                    continue;
                }
                if ($ranges !== [[null, null]]) {
                    $year = self::recordYear($works[$id]);
                    if ($year === null) {
                        $undated++;
                        continue;
                    }
                    $inside = false;
                    foreach ($ranges as [$from, $to]) {
                        if (($from === null || $year >= $from) && ($to === null || $year <= $to)) {
                            $inside = true;
                            break;
                        }
                    }
                    if (!$inside) {
                        $outside++;
                        continue;
                    }
                }
            }
            $ids[$id] = true;
        }
        $notes = $match['notes'] ?? [];
        if ($outside + $undated > 0) {
            $notes[] = ($outside + $undated) . ' record' . ($outside + $undated === 1 ? '' : 's')
                . ' of those bodies from outside the years in that line of descent ' . ($outside + $undated === 1 ? 'was' : 'were')
                . ' left out' . ($undated > 0 ? " ($undated without a known year)" : '') . '.';
        }
        if ($ids !== []) {
            $notes[] = self::HISTORICAL_RULE;
        }
        return ['ids' => $ids, 'notes' => $notes];
    }

    /** Shown in the notes of a search limited to church records. */
    public const HISTORICAL_RULE = 'These church records are historical editions (before 1931): in the answer give '
        . 'each one\'s body, edition and year, and say that the body\'s current rules may differ and that it may since '
        . 'have merged or divided. Never present them as a present-day church\'s current position.';

    /**
     * The year a record speaks for, for the year limits of a line of descent: the latest year in its title
     * ("Minutes of the General Assembly, 1838-1858" -> 1858; a reprint keeps the years of its contents), else
     * the year of the edition.
     */
    public static function recordYear(array $w): ?int
    {
        if (preg_match_all('/(?<!\d)(1[5-9]\d\d|20\d\d)(?!\d)/', (string) ($w['title'] ?? ''), $m) > 0) {
            return max(array_map('intval', $m[1]));
        }
        return isset($w['year']) ? (int) $w['year'] : null;
    }

    /**
     * The line printed with a church record (search results, read_passage, find_works):
     * "Church record: Presbyterian Church in the United States (1861–1983) · constitution · historical edition of
     * 1925. Its current rules may differ, and the body may since have merged or divided." '' for other works.
     */
    public static function label(array $w): string
    {
        $c = $w['church'] ?? null;
        if (!is_array($c)) {
            return '';
        }
        $body = $c['body'] ?? null;
        $years = '';
        if (is_array($body)) {
            $span = $body['founded'] !== null && $body['ended'] !== null ? $body['founded'] . '–' . $body['ended']
                : ($body['founded'] !== null ? 'from ' . $body['founded'] : ($body['ended'] !== null ? 'to ' . $body['ended'] : ''));
            $years = $body['name'] !== $c['denomination']
                ? ' (' . $body['name'] . ($span !== '' ? ", $span" : '') . ')'
                : ($span !== '' ? " ($span)" : '');
        }
        $who = Text::oneLine($c['denomination'] . $years, 300);
        $year = $w['year'] !== null ? ' of ' . $w['year'] : '';
        if ($c['status'] === 'current') {
            return "Church record: $who · {$c['doc_kind']} · edition$year, marked current in the library; the body may "
                . 'have amended it since.';
        }
        if (!in_array($c['doc_kind'], self::OFFICIAL_KINDS, true)) {
            return "Filed with the church records of: $who · {$c['doc_kind']} · historical$year; not a statement of "
                . 'that body\'s current position.';
        }
        return "Church record: $who · {$c['doc_kind']} · historical edition$year. Its current rules may differ, and "
            . 'the body may since have merged or divided.';
    }

    // ------------------------------------------------------------------------------------ notes

    /** @param array<string,array> $bodies @param list<string> $direct bodies matched by their own names */
    private static function lineageNote(string $echo, string $name, array $c, array $bodies, array $direct): string
    {
        $ancestors = array_column($c['ancestors'], 0);
        $own = isset($bodies[$name]) || array_intersect($direct, $ancestors) !== [];
        $parts = [];
        foreach ($c['ancestors'] as [$body, $from, $to]) {
            if (!isset($bodies[$body]) || $body === $name || ($own && in_array($body, $direct, true))) {
                continue;
            }
            $limit = $from !== null && $to !== null ? ", records of $from–$to" : ($to !== null ? ", records to $to"
                : ($from !== null ? ", records from $from" : ''));
            $parts[] = self::bodyName($body, $bodies) . $limit;
        }
        $bare = preg_replace('/^The\s+/', '', $name) ?? $name;
        $label = self::key($echo) === self::key($bare) || self::key($echo) === self::key($name) ? $name
            : "$echo (the $bare)";
        $as = array_values(array_filter($direct, static fn (string $b): bool => $b !== $name));
        $lead = $own
            ? "Denomination \"$label\": its own historical records" . ($as !== [] ? ' (as the ' . self::bodyList($as, $bodies) . ')' : '')
                . ($parts !== [] ? ' and those of its parent bodies: ' : '')
            : "Denomination \"$label\": the library has no records of that body itself; searching the historical records "
                . 'of its parent bodies: ';
        return $lead . implode('; ', $parts) . '.' . ($c['verified'] ? '' : ' (These links between bodies have not yet '
            . 'been checked by the library\'s curator.)');
    }

    /** "Presbyterian Church in the United States (1861–1983); ..." */
    private static function bodyList(array $names, array $bodies): string
    {
        return implode('; ', array_map(static fn (string $n): string => self::bodyName($n, $bodies), $names));
    }

    private static function bodyName(string $name, array $bodies): string
    {
        $b = $bodies[$name] ?? null;
        if ($b === null || ($b['founded'] === null && $b['ended'] === null)) {
            return $name;
        }
        return $name . ' (' . ($b['founded'] ?? '') . '–' . ($b['ended'] ?? '') . ')';
    }

    /** The error for a denomination the library does not know: what it has, by tradition, and the names it knows. */
    private static function unknown(Store $store, string $echo): string
    {
        $by = [];
        foreach ($store->churchBodies() as $name => $b) {
            $by[$b['tradition']][] = $name;
        }
        $lines = [];
        foreach (self::TRADITIONS as $t) {
            if (isset($by[$t])) {
                $lines[] = "$t: " . implode('; ', $by[$t]);
            }
        }
        $known = [];
        foreach ($store->churchNames() as $name => $c) {
            $abbr = array_values(array_filter($c['aliases'], static fn (string $a): bool => preg_match('/^[A-Z][A-Z()]{1,7}$/', $a) === 1));
            $known[] = $abbr !== [] ? $abbr[0] : $name;
        }
        return "NOT FOUND: the library has no church records filed under \"$echo\". Do not search other bodies' records "
            . 'as if they were this one\'s. The church bodies whose records the library holds (historical editions, '
            . "before 1931), by tradition:\n" . implode("\n", $lines)
            . ($known !== [] ? "\nPresent-day names it also understands (searching the bodies they descend from): "
                . implode(', ', array_unique($known)) . '.' : '')
            . "\nSearch again with one of these as denomination, or use the tradition filter, or search without a "
            . 'filter and say the library has no records of this body.';
    }
}
