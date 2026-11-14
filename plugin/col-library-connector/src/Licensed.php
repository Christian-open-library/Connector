<?php
/**
 * The licensed shelf's limits (DESIGN.md section 12; THREAT-MODEL.md): present-day documents the library
 * holds by permission of their owners, on the terms that it quotes them and does not hand out whole
 * documents. Every tool that returns passage text passes its results through here, so the cap holds in
 * each of them and not only in read_passage (security sweep 2026-11-14, finding 17):
 *  - at most PER_WORK passages of one licensed work in one search or citations call, also when the
 *    call names that work;
 *  - exact mode returns no licensed passage for a phrase of only common words, or beyond the first
 *    EXACT_DEPTH matches (so a document cannot be paged through in book order);
 *  - at most max_licensed_passages_per_person_per_day licensed passages a day for one person
 *    (RateLimit::takeLicensed), whatever the tool.
 * What is left out is said in a note with the document's "Official copy" link: the owner's own copy is
 * public. Works that are not licensed pass through untouched, in the same order.
 */

declare(strict_types=1);

namespace ColLibrary;

defined('ABSPATH') || exit;

final class Licensed
{
    public const PER_WORK = 3;
    public const EXACT_DEPTH = 20;
    /** Default max_licensed_passages_per_person_per_day: about a dozen questions' worth of quotations. */
    public const PER_DAY_DEFAULT = 100;
    private const LINKS_SHOWN = 3;

    /**
     * @param list<array> $hits passage rows with 'work', in the order they will be shown
     * @param int $offset the position of the first hit among all matches (exact mode, citations)
     * @param bool $exact exact mode: apply the depth rule
     * @param bool $commonOnly exact mode: the phrase has only very common words
     * @return array{hits: list<array>, notes: list<string>, dropped: int}
     */
    public static function limit(array $hits, RateLimit $limits, int $offset = 0, bool $exact = false,
                                 bool $commonOnly = false): array
    {
        $perWork = [];
        $out = ['exact' => [], 'work' => [], 'day' => []];
        $candidates = [];
        foreach ($hits as $i => $h) {
            if (!Format::isLicensed($h['work'])) {
                continue;
            }
            $id = (int) $h['work']['id'];
            if ($exact && ($commonOnly || $offset + $i >= self::EXACT_DEPTH)) {
                $out['exact'][$i] = $h['work'];
            } elseif (($perWork[$id] ?? 0) >= self::PER_WORK) {
                $out['work'][$i] = $h['work'];
            } else {
                $perWork[$id] = ($perWork[$id] ?? 0) + 1;
                $candidates[] = $i;
            }
        }
        if ($candidates === [] && $out['exact'] === []) {
            return ['hits' => $hits, 'notes' => [], 'dropped' => 0];
        }
        $granted = $candidates === [] ? 0 : $limits->takeLicensed(count($candidates));
        foreach (array_slice($candidates, $granted) as $i) {
            $out['day'][$i] = $hits[$i]['work'];
        }
        $notes = [];
        if ($out['work'] !== []) {
            $notes[] = 'Documents used by permission: at most ' . self::PER_WORK . ' passages of one such document are '
                . 'returned per call, so ' . self::count($out['work']) . ' left out. Quote what answers the question; '
                . 'the rest is in the ' . self::links($out['work']) . '.';
        }
        if ($out['exact'] !== []) {
            $notes[] = 'Documents used by permission are not paged through in exact mode: a phrase of only common words, '
                . 'and matches after the first ' . self::EXACT_DEPTH . ', leave them out (' . self::count($out['exact'])
                . ' left out). Search for a distinctive phrase, or read the ' . self::links($out['exact']) . '.';
        }
        if ($out['day'] !== []) {
            $notes[] = self::dayNote($limits) . ' ' . ucfirst(self::count($out['day'])) . ' left out. The full text is '
                . 'public in the ' . self::links($out['day']) . '.';
        }
        $dropped = $out['exact'] + $out['work'] + $out['day'];
        return ['hits' => array_values(array_diff_key($hits, $dropped)), 'notes' => $notes, 'dropped' => count($dropped)];
    }

    /** The sentence that says the daily limit was reached. */
    public static function dayNote(RateLimit $limits): string
    {
        return 'Documents used by permission: this account has reached today\'s limit of '
            . number_format($limits->licensedPerDay()) . ' passages from them (the library quotes these documents; it '
            . 'does not hand out whole ones). The limit resets at 00:00 UTC.';
    }

    /** 'Official copy: <link>' for one work, or "" when it has no link. */
    public static function link(array $work): string
    {
        return ($work['source_url'] ?? '') !== ''
            ? 'Official copy: ' . Format::field((string) $work['source_url'], 500) : '';
    }

    /** @param array<int, array> $works */
    private static function count(array $works): string
    {
        return count($works) === 1 ? '1 passage was' : count($works) . ' passages were';
    }

    /** '"Official copy" (*Title*: link; ...)' for the distinct works. @param array<int, array> $works */
    private static function links(array $works): string
    {
        $seen = [];
        foreach ($works as $w) {
            $seen[(int) $w['id']] ??= '*' . Format::field($w['title'] !== '' ? (string) $w['title'] : 'Untitled', 120) . '*'
                . (($w['source_url'] ?? '') !== '' ? ': ' . Format::field((string) $w['source_url'], 500) : '');
        }
        $more = count($seen) - self::LINKS_SHOWN;
        return '"Official copy" (' . implode('; ', array_slice($seen, 0, self::LINKS_SHOWN))
            . ($more > 0 ? "; and $more more" : '') . ')';
    }
}
