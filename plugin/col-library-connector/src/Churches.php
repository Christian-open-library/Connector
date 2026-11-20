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
 *   3. a tradition ("Lutheran", "Methodists"): every body of the tradition;
 *   4. 1 and 2 again with a trailing "church"/"churches" and a plural "s" left out on both sides
 *      ("United Methodist" = "United Methodist Church", "Reformed Baptist" = "Reformed Baptists",
 *      "Episcopal" = "The Episcopal Church");
 *   5. else words of a body's names ("Methodist Episcopal"): those bodies;
 * and the union of 1 and 2 (or of 4) is searched. Nothing found is an error that lists what the library has,
 * never a silent empty result. Builds made before these tables existed refuse the filters.
 *
 * The body a name means itself (not the bodies it descends from) is its "own" body. When an own body has
 * present-day documents in the library (status current or record, used by permission), every tool lists
 * them first (DESIGN.md section 11): Filters::resolve gives their ids as 'own'.
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
        'anglicans' => 'Anglican', 'romancatholic' => 'Catholic', 'quakers' => 'Quaker', 'friends' => 'Quaker',
        'mennonite' => 'Anabaptist', 'mennonites' => 'Anabaptist', 'easternorthodox' => 'Orthodox',
        'presbyterians' => 'Presbyterian', 'baptists' => 'Baptist', 'methodists' => 'Methodist',
        'lutherans' => 'Lutheran', 'catholics' => 'Catholic', 'pentecostals' => 'Pentecostal',
        'congregationalist' => 'Congregational', 'congregationalists' => 'Congregational',
        'reformedchurch' => 'Reformed', 'restorationmovement' => 'Restorationist',
    ];

    /** Kinds of document that are a body's own records (the rest: histories and other writings filed with them). */
    public const OFFICIAL_KINDS = ['constitution', 'discipline', 'canons', 'digest', 'minutes', 'journal', 'report',
        'confession', 'catechism', 'liturgy', 'manual', 'church_order', 'acts', 'procedure', 'study_report'];

    /** How a kind of document is named in a label ("church_order" -> "church order", "acts" -> "acts of synod or assembly"). */
    private const KIND_WORDS = ['church_order' => 'church order', 'study_report' => 'study committee report',
        'acts' => 'acts of synod or assembly', 'procedure' => 'rules of procedure'];

    public const NAME_MAX = 120;   // characters of a denomination filter

    /**
     * Bodies that let the library link to their current documents but not copy them: when a denomination
     * filter names one of these bodies itself (not a body descended from it), its note goes with the results.
     * The CRCNA (Paul Faber, 2026-11-18): no reposting; links to crcna.org welcome if each says it is
     * included with the CRCNA's permission and that the material is copyrighted and maintained by the CRCNA.
     */
    private const OFFICIAL_SITES = [
        'Christian Reformed Church in North America' => 'The library has only historical editions of the Christian '
            . 'Reformed Church\'s records (before 1931), not its current Church Order. For the rules in force today, '
            . 'give the CRCNA\'s own page for its current Church Order, https://www.crcna.org/synod/church-order-and-forms '
            . ', and with the link say, in these words: "This link is included with the permission of the Christian '
            . 'Reformed Church in North America. The material on crcna.org is copyrighted and maintained by the '
            . 'Christian Reformed Church in North America."',
    ];

    /** "PC(USA)" -> "pcusa": lower case, no accents, letters and digits only. */
    public static function key(string $text): string
    {
        return str_replace(' ', '', Text::looseKey($text));
    }

    /**
     * The bodies a denomination filter means, with the years of records allowed for each, notes that say
     * what was searched, and the body or bodies the name means itself ('own': not the bodies it descends from).
     * @return array{bodies: array<string, list<array{0:?int,1:?int}>>, notes: list<string>, own: list<string>}
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

        // 1 and 2: exact names.
        $m = self::byNames($store, $bodies, static fn (string $n): bool => in_array(self::key($n), $keys, true));
        if ($m['found'] !== []) {
            return self::named($m, $echo, $bodies);
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
                return ['bodies' => array_fill_keys($names, [[null, null]]), 'own' => [],
                        'notes' => ['Denomination "' . $echo . '": the records of all ' . count($names) . ' '
                            . $tradition . ' bodies in the library (the tradition filter does the same).']];
            }
        }

        // 4. Names without a trailing "church" and a plural "s".
        $loose = self::looseKey($text);
        if (strlen($loose) >= 3) {
            $m = self::byNames($store, $bodies, static fn (string $n): bool => self::looseKey($n) === $loose);
            if ($m['found'] !== []) {
                return self::named($m, $echo, $bodies);
            }
        }

        // 5. Words of a body's names.
        $words = ' ' . Text::looseKey($text) . ' ';
        if (strlen(trim($words)) >= 4) {
            $found = [];
            foreach ($bodies as $name => $b) {
                foreach (array_merge([$name], $b['tags'], $b['aliases']) as $n) {
                    if (str_contains(' ' . Text::looseKey($n) . ' ', $words)) {
                        $found[] = $name;
                        break;
                    }
                }
            }
            if ($found !== []) {
                return ['bodies' => array_fill_keys($found, [[null, null]]), 'own' => count($found) === 1 ? $found : [],
                        'notes' => array_merge(['No church body is named exactly "' . $echo . '"; searching the records of the '
                            . count($found) . ' whose names contain it: ' . self::bodyList($found, $bodies) . '.'],
                            count($found) === 1 ? self::officialSites($found) : [])];
            }
        }
        throw new SearchInputException(self::unknown($store, $echo));
    }

    /** "The United Methodist Church" -> "unitedmethodist": key() without a leading "the", a trailing church(es) and plural s. */
    public static function looseKey(string $text): string
    {
        $k = self::key(preg_replace('/^\s*the\s+/i', '', $text) ?? $text);
        $k = preg_replace('/(church|churches)$/', '', $k) ?? $k;
        return preg_replace('/s$/', '', $k) ?? $k;
    }

    /**
     * Bodies (by name, tag or other name) and present-day names (by name or other name) that $is accepts.
     * @return array{found: array<string, list<array{0:?int,1:?int}>>, direct: list<string>, named: array<string, array>}
     */
    private static function byNames(Store $store, array $bodies, \Closure $is): array
    {
        $found = [];     // body => ranges
        $direct = [];
        foreach ($bodies as $name => $b) {
            foreach (array_merge([$name], $b['tags'], $b['aliases']) as $n) {
                if ($is($n)) {
                    $direct[] = $name;
                    $found[$name] = [[null, null]];
                    break;
                }
            }
        }
        $named = [];
        foreach ($store->churchNames() as $name => $c) {
            foreach (array_merge([$name], $c['aliases']) as $n) {
                if ($is($n)) {
                    $named[$name] = $c;
                    if (isset($bodies[$name])) {
                        $found[$name] = [[null, null]];     // a present-day body whose own records the library holds
                    }
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
        return ['found' => $found, 'direct' => $direct, 'named' => $named];
    }

    /** match()'s answer for names found by byNames(): bodies, notes, and the own bodies (named directly, or a present-day name that is a body). */
    private static function named(array $m, string $echo, array $bodies): array
    {
        ['found' => $found, 'direct' => $direct, 'named' => $named] = $m;
        $notes = [];
        foreach ($named as $name => $c) {
            $notes[] = self::lineageNote($echo, (string) $name, $c, $bodies, $direct);
        }
        if ($direct !== [] && $named === []) {
            $notes[] = 'Denomination "' . $echo . '": the records of the ' . self::bodyList($direct, $bodies) . '.';
        }
        $notes = array_merge($notes, self::officialSites(array_merge($direct, array_map('strval', array_keys($named)))));
        $own = $direct;
        foreach (array_keys($named) as $name) {
            if (isset($bodies[$name]) && !in_array($name, $own, true)) {
                $own[] = (string) $name;
            }
        }
        return ['bodies' => $found, 'notes' => $notes, 'own' => $own];
    }

    /**
     * Ids of the works a denomination and/or tradition filter allows, out of $works (all works by id), and
     * notes. Works outside the year ranges of their body, or without a year when a range applies, are left
     * out (counted in the notes). 'own': the allowed works that are present-day documents (current editions and
     * official records, used by permission) of the body the name means itself, which the tools list first.
     * @return array{ids: array<int,true>, notes: list<string>, own: list<int>, own_body: ?string}
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
        $ownBodies = array_flip($match['own'] ?? []);
        $own = [];
        foreach (array_keys($ids) as $id) {
            if (isset($ownBodies[$bodyOf[$records[$id]['denomination']] ?? ''])
                && in_array($records[$id]['status'], ['current', 'record'], true)) {
                $own[] = $id;
            }
        }
        $ownBody = $own !== [] ? ($bodyOf[$records[$own[0]]['denomination']] ?? null) : null;
        $present = 0;     // records used by permission (current editions and official records), the rest historical
        foreach (array_keys($ids) as $id) {
            if (in_array($records[$id]['status'], ['current', 'record'], true)) {
                $present++;
            }
        }
        if ($outside + $undated > 0) {
            $notes[] = ($outside + $undated) . ' record' . ($outside + $undated === 1 ? '' : 's')
                . ' of those bodies from outside the years in that line of descent ' . ($outside + $undated === 1 ? 'was' : 'were')
                . ' left out' . ($undated > 0 ? " ($undated without a known year)" : '') . '.';
        }
        if ($ids !== []) {
            $notes[] = $present === 0 ? self::HISTORICAL_RULE : ($present === count($ids) ? self::PRESENT_RULE : self::MIXED_RULE);
        }
        return ['ids' => $ids, 'notes' => $notes, 'own' => $own, 'own_body' => $ownBody];
    }

    /** Start of every note about a body's own present-day documents (Tools drops them when the daily cap left those out). */
    public const OWN_NOTE = 'Own documents: ';

    /** The note when a body's own present-day documents are listed first (search_library, citations, find_works). */
    public static function ownFirstNote(string $body, int $shown): string
    {
        return self::OWN_NOTE . 'the present-day documents of the ' . self::plainName($body) . ' itself (used by '
            . 'permission) are listed first' . ($shown > 0 ? " ($shown of the results)" : '') . '; the historical records '
            . 'of the bodies it descends from follow.';
    }

    /** The note when none of a body's own present-day documents match the question well enough to come first. */
    public static function ownNoneNote(string $body, bool $nearShown): string
    {
        $b = self::plainName($body);
        return self::OWN_NOTE . "no present-day document of the $b itself matches this question well"
            . ($nearShown ? '; its weaker matches are marked so, and the other results are' : ', so the results are')
            . " historical records of the bodies it descends from. Do not present those as the present position of the $b.";
    }

    /** Exact mode: how many of the body's own matches are listed first, out of how many it has. */
    public static function ownExactNote(string $body, int $listed, int $all): string
    {
        $b = self::plainName($body);
        return self::OWN_NOTE . "the first matches in the present-day documents of the $b itself (used by permission) "
            . "are listed first: $listed passage" . ($listed === 1 ? '' : 's') . ', at most ' . Licensed::PER_WORK
            . ' from each document' . ($all > $listed ? ', of the ' . ($all > 1000 ? 'more than 1,000' : number_format($all))
                . ' that hold the phrase (the rest are in each document\'s "Official copy")' : '')
            . '. The historical records of the bodies it descends from follow, in book order.';
    }

    /** The note when a person's documents used by permission are used up for today, so a body's own are not put first. */
    public static function ownCappedNote(string $body): string
    {
        return 'Documents used by permission: this account has reached today\'s limit of passages from them, so the '
            . 'present-day documents of the ' . self::plainName($body) . ' itself are not listed first until 00:00 UTC; '
            . 'the results are historical records of the bodies it descends from. Do not present those as its present '
            . 'position.';
    }

    /** The note when a body's own present-day documents are mixed in without re-ranking (no scores to judge them by). */
    public static function ownMixedNote(string $body): string
    {
        return self::OWN_NOTE . 'the present-day documents of the ' . self::plainName($body) . ' itself (used by '
            . 'permission) take every other place from the second. Re-ranking was unavailable, so how closely each result '
            . 'matches is not known.';
    }

    /** True for the rule lines that allowed() adds to the notes. */
    public static function isRule(string $note): bool
    {
        return in_array($note, [self::HISTORICAL_RULE, self::PRESENT_RULE, self::MIXED_RULE], true);
    }

    /** Shown when every record searched is a present-day document used by permission. */
    public const PRESENT_RULE = 'These church records are present-day documents used by permission: say whether each '
        . 'is the current edition or the record of a past synod or assembly (whose decisions later ones may have changed), '
        . 'give the body and year, quote only what answers the question, and give the "Official copy" link.';

    /** Shown when the records searched are partly historical editions and partly present-day documents. */
    public const MIXED_RULE = 'Each church record\'s label says what it is. "Current edition" and "official record" '
        . 'are present-day documents used by permission: give the body and year, quote only what answers the question, '
        . 'and give the "Official copy" link; an official record of a past synod or assembly may since have been changed. '
        . '"Historical edition" records are from before 1931: say that the body\'s current rules may differ and that it '
        . 'may since have merged or divided, and never present them as a present-day church\'s current position.';

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
        $kind = self::KIND_WORDS[$c['doc_kind']] ?? $c['doc_kind'];
        if (Format::isLicensed($w)) {
            $year = $w['year'] !== null ? ' (' . $w['year'] . ')' : '';
            return $c['status'] === 'current'
                ? "Church record: $who · $kind · CURRENT EDITION$year, the body's own present text (later amendments "
                    . 'may not be in the library yet; the official copy is the authority).'
                : "Church record: $who · $kind · official record$year of the body. Later synods or assemblies may have "
                    . 'changed what it decided: for the rules in force, see the body\'s current church order.';
        }
        if ($c['status'] === 'current') {
            return "Church record: $who · $kind · edition$year, marked current in the library; the body may "
                . 'have amended it since.';
        }
        if (!in_array($c['doc_kind'], self::OFFICIAL_KINDS, true)) {
            return "Filed with the church records of: $who · $kind · historical$year; not a statement of "
                . 'that body\'s current position.';
        }
        return "Church record: $who · $kind · historical edition$year. Its current rules may differ, and "
            . 'the body may since have merged or divided.';
    }

    // ------------------------------------------------------------------------------------ notes

    /** @param list<string> $named bodies the filter named themselves @return list<string> their OFFICIAL_SITES notes */
    private static function officialSites(array $named): array
    {
        return array_values(array_intersect_key(self::OFFICIAL_SITES, array_flip($named)));
    }

    /** @param array<string,array> $bodies @param list<string> $direct bodies matched by their own names */
    private static function lineageNote(string $echo, string $name, array $c, array $bodies, array $direct): string
    {
        $ancestors = array_column($c['ancestors'], 0);
        $own = isset($bodies[$name]) || array_intersect($direct, $ancestors) !== [];
        $shown = self::plainName($name);     // names come from the books repo's lists: made inert like all library text
        $parts = [];
        foreach ($c['ancestors'] as [$body, $from, $to]) {
            if (!isset($bodies[$body]) || $body === $name || ($own && in_array($body, $direct, true))) {
                continue;
            }
            $limit = $from !== null && $to !== null ? ", records of $from–$to" : ($to !== null ? ", records to $to"
                : ($from !== null ? ", records from $from" : ''));
            $parts[] = self::bodyName($body, $bodies) . $limit;
        }
        $bare = self::plainName(preg_replace('/^The\s+/', '', $name) ?? $name);
        $label = self::key($echo) === self::key($bare) || self::key($echo) === self::key($name) ? $shown
            : "$echo (the $bare)";
        $as = array_values(array_filter($direct, static fn (string $b): bool => $b !== $name));
        $lead = $own
            ? "Denomination \"$label\": its own records" . ($as !== [] ? ' (as the ' . self::bodyList($as, $bodies) . ')' : '')
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
            return self::plainName($name);
        }
        return self::plainName($name) . ' (' . ($b['founded'] ?? '') . '–' . ($b['ended'] ?? '') . ')';
    }

    /** A body's name as printed in a note: one line, and inert for Markdown and HTML (Text::inert). */
    private static function plainName(string $name): string
    {
        return Text::inert(Text::oneLine($name, 200));
    }

    /** The error for a denomination the library does not know: what it has, by tradition, and the names it knows. */
    private static function unknown(Store $store, string $echo): string
    {
        $present = [];    // bodies with present-day documents used by permission
        $bodyOf = $store->churchBodyOfTag();
        foreach ($store->churchRecords() as $r) {
            if (in_array($r['status'], ['current', 'record'], true) && isset($bodyOf[$r['denomination']])) {
                $present[$bodyOf[$r['denomination']]] = true;
            }
        }
        $by = [];
        foreach ($store->churchBodies() as $name => $b) {
            $by[$b['tradition']][] = self::plainName((string) $name) . (isset($present[$name]) ? ' (present-day documents, used by permission)' : '');
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
            $known[] = $abbr !== [] ? $abbr[0] : self::plainName((string) $name);
        }
        return "NOT FOUND: the library has no church records filed under \"$echo\". Do not search other bodies' records "
            . 'as if they were this one\'s. The church bodies whose records the library holds (historical editions '
            . "before 1931, and present-day documents used by permission where a body is marked so), by tradition:\n" . implode("\n", $lines)
            . ($known !== [] ? "\nPresent-day names it also understands (searching the bodies they descend from): "
                . implode(', ', array_unique($known)) . '.' : '')
            . "\nSearch again with one of these as denomination, or use the tradition filter, or search without a "
            . 'filter and say the library has no records of this body.';
    }
}
