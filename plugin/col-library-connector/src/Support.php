<?php
/**
 * The public "Keep it running" card, GET /support.svg?theme=light|dark|site on the office server: this
 * month's running costs and how they are paid for, then the coming months and how much of each the
 * gifts so far cover, so people can fund months ahead. The library's GitHub front page shows it,
 * linked to the curator's GitHub Sponsors page. Totals only: no givers' names, no single gift.
 *
 * Where the numbers come from:
 *  - searches: the Voyage tokens this server was billed for (RateLimit::countSpend), priced here;
 *  - everything else and the gifts: the "funding" object homebox adds to the member list it pushes
 *    (deploy/funding.py), for each month from the first to a few months ahead:
 *    {"months": {"2026-11": {"given_cents": 0, "covered_by": "Matt Miller",
 *                            "costs": [["Domain name", 87, "fixed"], ["Adding new books (Voyage AI)", 6, "metered"]]}}}
 *    A cost's kind is "fixed" (monthly), "once" or "metered" (by use). A coming month's given_cents
 *    is what monthly sponsors give. A month may carry "thanks": the name of someone who sponsored it
 *    and asked to be thanked by name (deploy/costs.json other_gifts); the card thanks them.
 * Arithmetic (the card makes no promise about how gifts are spent: they go to the curator personally):
 * a month the curator covers (covered_by) needs no gifts, so its gifts count toward later months; a month
 * he covers in part (covered_cents, without covered_by) needs gifts only for the rest;
 * otherwise gifts beyond a month's costs count toward later months, and a shortfall is not carried.
 * A coming month costs its fixed and one-time costs plus its expected usage: the highest of the month
 * before this one, this month's pace and the last 7 days' pace, with Voyage's free tokens used first.
 * So when many more people start using the connector, the coming months' costs rise within days.
 * The funding object may also plan for more use than measured: "plan": {"rerank_per_day": 40000000,
 * "people": "hundreds of people"} makes each coming month expect at least that many search tokens a day
 * (deploy/costs.json planned_use); the card doesn't say so (Matt, 2026-11-12).
 * This month adds the rest of the month at that same expected pace ("Rest of November (expected)"), so
 * its costs and what is still needed are what the whole month is expected to cost.
 */

namespace ColLibrary;

defined('ABSPATH') || exit;

final class Support
{
    /** US cents per million tokens: voyage-4-lite ('embed') and rerank-3 ('rerank'). */
    public const PRICE = ['embed' => 2.0, 'rerank' => 5.0];
    /** Tokens Voyage gives free once per model; the first full build used up the embedding allowance. */
    public const FREE = ['rerank' => 200_000_000];
    public const SPONSORS_URL = 'https://github.com/sponsors/mattm4444';
    public const SEARCH_LABEL = 'Searches (Voyage AI)';
    /** Coming months shown on the card. The projection runs up to PROJECT months to say how far gifts reach. */
    public const AHEAD = 3;
    private const PROJECT = 36;

    private const W = 720;
    private const PAD = 28;
    private const ROW = 28;
    private const MONO = "ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, 'Liberation Mono', monospace";
    /**
     * light and dark match GitHub (the front page); site matches christianopenlibrary.org (sans type, tile
     * background, gold bars, ink "Give" button) and follows the reader's light or dark setting by itself.
     */
    private const SANS = "system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";
    private const THEMES = [
        'dark' => ['bg' => '#0d1117', 'border' => '#30363d', 'text' => '#e6edf3', 'muted' => '#7d8590',
                   'accent' => '#3fb950', 'track' => '#21262d'],
        'light' => ['bg' => '#ffffff', 'border' => '#d0d7de', 'text' => '#1f2328', 'muted' => '#656d76',
                    'accent' => '#1a7f37', 'track' => '#eaeef2'],
        'site' => ['bg' => '#f4f4f1', 'border' => '#e3e3e3', 'text' => '#1a1a1a', 'muted' => '#555555',
                   'accent' => '#1a1a1a', 'track' => '#e3e3e3', 'bar' => '#e2bc62', 'prompt' => '#c9a45c',
                   'btn' => '#1a1a1a', 'on-btn' => '#ffffff', 'font' => self::SANS, 'rx' => 6],
        'site-dark' => ['bg' => '#1e1f23', 'border' => '#36373c', 'text' => '#f7f7f4', 'muted' => '#c2c2bb',
                        'accent' => '#f7f7f4', 'track' => '#36373c', 'bar' => '#e2bc62', 'prompt' => '#e2bc62',
                        'btn' => '#f7f7f4', 'on-btn' => '#121315'],
    ];

    /** The answer to GET or HEAD /support.svg. Never throws: a missing piece is left out and logged. */
    public static function response(string $method, array $query, ?array $oauth, ?Config $config = null): Response
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            return new Response(405, ['Allow' => 'GET, HEAD', 'Cache-Control' => 'no-store'], null);
        }
        $funding = [];
        $path = (string) ($oauth['members_file'] ?? '');
        if ($path !== '' && is_file($path)) {
            $d = json_decode((string) @file_get_contents($path, false, null, 0, 2_000_000), true, 32);
            $funding = is_array($d) && is_array($d['funding'] ?? null) ? $d['funding'] : [];
        }
        $spending = [];
        $recent = [];
        try {
            $limits = RateLimit::fromConfig($config ?? Config::load());
            $spending = $limits->spending();
            $recent = $limits->recentSpending();
        } catch (\Throwable $e) {
            error_log('col-library-connector: cost card without search costs: ' . $e->getMessage());
        }
        $theme = in_array($query['theme'] ?? '', ['light', 'site'], true) ? $query['theme'] : 'dark';
        return new Response(200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=600',   // Cloudflare keeps it 10 minutes
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
        ], self::card(self::ledger($funding, $spending, time(), $recent), $theme));
    }

    /**
     * This month's lines and totals, and the coming months.
     * @param array $funding the "funding" object (see the class comment)
     * @param array<string, array<int, int>> $spending RateLimit::spending()
     * @param array<string, int> $recent RateLimit::recentSpending()
     * @return array{month: string, items: list<array{0: string, 1: int}>, costs: int, given: int,
     *   carried: int, covered_by: ?string, covered: int, thanks: ?string, capped: int, rest: int,
     *   ahead: list<array{month: string, costs: int, pct: int, covered_by: ?string, thanks: ?string}>, through: ?string,
     *   plan: ?string}
     *   through: the last month of the unbroken run of fully funded months from next month on
     */
    public static function ledger(array $funding, array $spending, int $now, array $recent = []): array
    {
        $current = gmdate('Y-m', $now);
        $rows = [];
        foreach ((array) ($funding['months'] ?? []) as $m => $row) {
            if (is_string($m) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $m) === 1 && is_array($row)) {
                $rows[$m] = self::month($row);
            }
        }
        $past = [$current => true];
        foreach (array_keys($rows) as $m) {
            if ($m <= $current) {
                $past[$m] = true;
            }
        }
        foreach ($spending as $byMonth) {
            foreach (array_keys($byMonth) as $ym) {
                $m = sprintf('%04d-%02d', intdiv($ym, 100), $ym % 100);
                if ($m <= $current) {
                    $past[$m] = true;
                }
            }
        }
        ksort($past);

        $rerankSoFar = 0;
        $carry = 0;
        // Usage in the latest month before this one, and in this month so far: Voyage tokens by kind,
        // and homebox's metered costs in cents (new books).
        $before = $thisMonth = ['embed' => 0, 'rerank' => 0, 'other' => 0];
        $result = [];
        foreach (array_keys($past) as $m) {
            $row = $rows[$m] ?? self::month([]);
            $ym = (int) str_replace('-', '', $m);
            $embed = (int) ($spending['embed'][$ym] ?? 0);
            $rerank = (int) ($spending['rerank'][$ym] ?? 0);
            $billable = $rerank - min($rerank, max(0, self::FREE['rerank'] - $rerankSoFar));
            $rerankSoFar += $rerank;
            $search = (int) round($embed / 1e6 * self::PRICE['embed'] + $billable / 1e6 * self::PRICE['rerank']);
            $items = [[self::SEARCH_LABEL, $search]];
            $use = ['embed' => $embed, 'rerank' => $rerank, 'other' => 0];
            foreach ($row['costs'] as [$name, $cents, $kind]) {
                $items[] = [$name, $cents];
                $use['other'] += $kind === 'metered' ? $cents : 0;
            }
            $costs = array_sum(array_column($items, 1));
            if ($m === $current) {
                $result = ['month' => $m, 'items' => $items, 'costs' => $costs, 'given' => $row['given'],
                           'carried' => $carry, 'covered_by' => $row['covered_by'],
                           'covered' => $row['covered'], 'thanks' => $row['thanks'],
                           'capped' => (int) ($spending['capped'][$ym] ?? 0)];
                $thisMonth = $use;
            } else {
                $before = $use;
            }
            $carry = $row['covered_by'] !== null ? $carry + $row['given']
                : max(0, $carry + $row['given'] - max(0, $costs - $row['covered']));
        }
        $days = (int) gmdate('t', $now);
        $scale = $days / max(1.0, ($now - (int) strtotime("$current-01 00:00:00 UTC")) / 86400);
        $expect = [];
        foreach ($before as $k => $v) {
            $expect[$k] = (int) ceil(max($v, $thisMonth[$k] * $scale, ($recent[$k] ?? 0) * $days / 7));
        }
        $plan = is_array($funding['plan'] ?? null) ? $funding['plan'] : [];
        $perDay = is_int($plan['rerank_per_day'] ?? null) ? max(0, min($plan['rerank_per_day'], 1_000_000_000)) : 0;
        $expect['rerank'] = max($expect['rerank'], $perDay * $days);
        $people = is_string($plan['people'] ?? null) && $perDay > 0 && trim($plan['people']) !== ''
            ? mb_substr(trim($plan['people']), 0, 40) : null;
        $free = max(0, self::FREE['rerank'] - $rerankSoFar);

        // The rest of this month at the expected pace, then this month's carry again with it.
        $left = max(0.0, ((int) strtotime(self::next($current) . '-01 00:00:00 UTC') - $now) / 86400) / $days;
        $rerankLeft = (int) ceil($expect['rerank'] * $left);
        $rest = (int) round($expect['other'] * $left + $expect['embed'] * $left / 1e6 * self::PRICE['embed']
            + max(0, $rerankLeft - $free) / 1e6 * self::PRICE['rerank']);
        $free = max(0, $free - $rerankLeft);
        if ($result !== []) {
            if ($rest > 0) {
                $result['items'][] = ['Rest of ' . gmdate('F', $now) . ' (expected)', $rest];
                $result['costs'] += $rest;
            }
            $result['rest'] = $rest;
            $result['covered'] = min($result['covered'], $result['costs']);
            $carry = $result['covered_by'] !== null ? $result['carried'] + $result['given']
                : max(0, $result['carried'] + $result['given'] - ($result['costs'] - $result['covered']));
        }

        // The coming months: what homebox sent, then its last coming month again (fixed costs only).
        // The projection runs past the shown months only while every month is fully funded.
        $ahead = [];
        $through = null;
        $funded = true;
        $m = $current;
        $last = null;
        for ($i = 1; $i <= self::PROJECT && ($funded || $i <= self::AHEAD); $i++) {
            $m = self::next($m);
            if (isset($rows[$m])) {
                $row = $last = $rows[$m];
            } else {
                $base = $last ?? ($rows[$current] ?? self::month([]));
                $row = ['given' => $last['given'] ?? 0, 'covered_by' => null, 'covered' => 0, 'thanks' => null,
                        'costs' => array_values(array_filter($base['costs'], static fn (array $c): bool => $c[2] === 'fixed'))];
            }
            $billable = max(0, $expect['rerank'] - $free);
            $free = max(0, $free - $expect['rerank']);
            $costs = $expect['other'] + (int) round($expect['embed'] / 1e6 * self::PRICE['embed']
                + $billable / 1e6 * self::PRICE['rerank']);
            foreach ($row['costs'] as [, $cents, $kind]) {
                $costs += $kind === 'metered' ? 0 : $cents;
            }
            if ($row['covered_by'] !== null) {
                $pct = 100;
                $carry += $row['given'];
            } else {
                $have = $carry + $row['given'] + min($row['covered'], $costs);
                $pct = $costs > 0 ? min(100, intdiv(100 * $have, $costs)) : 100;
                $carry = max(0, $carry + $row['given'] - max(0, $costs - $row['covered']));
            }
            if ($i <= self::AHEAD) {
                $ahead[] = ['month' => $m, 'costs' => $costs, 'pct' => $pct, 'covered_by' => $row['covered_by'],
                            'thanks' => $row['thanks']];
            }
            $funded = $funded && $pct >= 100;
            if ($funded) {
                $through = $m;
            }
        }
        return $result + ['ahead' => $ahead, 'through' => $through, 'plan' => $people];
    }

    /** The card as SVG for one ledger (see ledger()). */
    public static function card(array $l, string $theme): string
    {
        $theme = isset(self::THEMES[$theme]) && $theme !== 'site-dark' ? $theme : 'dark';
        $t = self::THEMES[$theme];
        $w = self::W;
        $x = self::PAD;
        $right = $w - self::PAD;
        $month = self::name($l['month']);
        $covered = $l['covered_by'] !== null;
        $part = $covered ? 0 : (int) ($l['covered'] ?? 0);
        $have = $l['given'] + $l['carried'] + $part;
        $pct = $covered ? 100 : ($l['costs'] > 0 ? min(100, intdiv(100 * $have, $l['costs'])) : 100);
        if ($covered) {
            $status = explode(' ', $month)[0] . ' is covered.';
        } elseif ($have >= $l['costs']) {
            $status = 'Funded. Thank you!';
        } else {
            $status = $pct . '% funded, ' . self::money($l['costs'] - $have) . ' still needed';
        }

        $out = [];
        $y = 104;
        foreach ($l['items'] as [$name, $cents]) {
            $out[] = self::line($x, $right, $y, $name, self::money($cents), 'item');
            $y += self::ROW;
        }
        $out[] = "<line x1=\"$x\" y1=\"" . ($y - 16) . "\" x2=\"$right\" y2=\"" . ($y - 16) . '" class="rule"/>';
        $y += 8;
        $out[] = self::line($x, $right, $y, ($l['rest'] ?? 0) > 0 ? 'Costs this month (expected)' : 'Costs this month',
            self::money($l['costs']), 'total');
        $y += self::ROW;
        if ($covered || $part > 0) {
            $out[] = self::line($x, $right, $y, 'Covered', self::money($covered ? $l['costs'] : $part), 'given');
            $y += self::ROW;
        }
        if (!$covered || $l['given'] > 0) {
            $out[] = self::line($x, $right, $y, 'Given this month', self::money($l['given']), 'given');
            $y += self::ROW;
        }
        if ($l['carried'] > 0) {
            $out[] = self::line($x, $right, $y, 'Earlier gifts beyond costs', self::money($l['carried']), 'given');
            $y += self::ROW;
        }
        $out[] = self::bar($x, $y, $right - $x, 10, $pct);
        $y += 34;
        $out[] = "<text x=\"$x\" y=\"$y\" class=\"status\">" . self::esc($status) . '</text>';
        $capped = (int) ($l['capped'] ?? 0);
        if ($capped > 0) {
            $y += 24;
            $out[] = "<text x=\"$x\" y=\"$y\" class=\"note\">The search AI reached its daily spending limit on $capped "
                . ($capped === 1 ? 'day' : 'days') . ' this month.</text>';
        }
        if (($l['thanks'] ?? null) !== null) {
            $y += 24;
            $out[] = "<text x=\"$x\" y=\"$y\" class=\"note\">Thank you, " . self::esc($l['thanks']) . ', for sponsoring '
                . self::esc(explode(' ', $month)[0]) . '.</text>';
        }

        $y += 46;
        $out[] = "<text x=\"$x\" y=\"$y\" class=\"label\">COMING UP</text>";
        $y += 30;
        foreach ($l['ahead'] as $a) {
            $out[] = "<text x=\"$x\" y=\"$y\">" . self::esc(self::name($a['month'])) . '</text>'
                . '<text x="330" y="' . $y . '" class="amount item-n">about ' . self::esc(self::money($a['costs'])) . '</text>'
                . self::bar(356, $y - 9, 264, 8, $a['pct'])
                . "<text x=\"$right\" y=\"$y\" class=\"amount" . ($a['pct'] >= 100 ? ' given-a' : '') . '">'
                . $a['pct'] . '%</text>';
            $y += self::ROW;
        }
        $last = end($l['ahead']);
        if ($l['through'] !== null && $last !== false && $l['through'] > $last['month']) {
            $out[] = "<text x=\"$x\" y=\"" . ($y + 2) . '" class="given-a">Gifts so far cover the costs through '
                . self::esc(self::name($l['through'])) . '.</text>';
            $y += self::ROW;
        }
        foreach ($l['ahead'] as $a) {
            if (($a['thanks'] ?? null) !== null) {
                $out[] = "<text x=\"$x\" y=\"" . ($y + 2) . '" class="note">' . self::esc(explode(' ', self::name($a['month']))[0])
                    . ' sponsored by ' . self::esc($a['thanks']) . '. Thank you!</text>';
                $y += 24;
            }
        }

        $y += 26;
        $out[] = "<text x=\"$x\" y=\"$y\" class=\"note\">Give a month's costs and be thanked here by name.</text>";
        $y += 20;
        $out[] = "<text x=\"$x\" y=\"$y\" class=\"note\">Free for everyone. Gifts support Matt Miller's work on the library.</text>";
        $pillW = 96;
        $pillX = $right - $pillW;
        $out[] = "<rect x=\"$pillX\" y=\"" . ($y - 20) . "\" width=\"$pillW\" height=\"30\" rx=\"15\" class=\"pill\"/>";
        $out[] = '<text x="' . ($pillX + $pillW / 2) . '" y="' . ($y - 1) . '" class="give">&#9829; Give</text>';
        $h = $y + 26;

        $summary = "Keep it running, $month: costs " . self::money($l['costs'])
            . ($covered ? ', covered' : ($part > 0 ? ', covered ' . self::money($part) : '')
                . ', given ' . self::money($l['given'])
                . ($l['carried'] > 0 ? ', carried ' . self::money($l['carried']) : '') . ", $status")
            . '. Coming up: ' . implode('; ', array_map(static fn (array $a): string => self::name($a['month'])
                . ' about ' . self::money($a['costs']) . ', ' . $a['pct'] . '% funded', $l['ahead'])) . '.';
        $label = self::esc($summary);
        $body = implode("\n  ", $out);
        $font = $t['font'] ?? self::MONO;
        $rx = $t['rx'] ?? 8;
        $css = self::colors($t);
        if ($theme === 'site') {
            $css .= "\n    @media (prefers-color-scheme: dark) {\n" . self::colors(self::THEMES['site-dark']) . "\n    }";
        }
        $w1 = $w - 1;
        $h1 = $h - 1;
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 $w $h" width="$w" height="$h" role="img" aria-label="$label">
  <title>$label</title>
  <style>
    text { font-family: $font; font-size: 15px; }
    .head { font-size: 17px; font-weight: 600; }
    .note { font-size: 13px; }
    .label { font-size: 12px; letter-spacing: 1.5px; }
    .total, .status { font-weight: 600; }
    .amount { text-anchor: end; }
    .rule { stroke-dasharray: 3 4; }
    .pill { stroke-width: 1.5; }
    .give { text-anchor: middle; font-weight: 600; }
$css
  </style>
  <rect x="0.5" y="0.5" width="$w1" height="$h1" rx="$rx" class="card"/>
  <text x="$x" y="46" class="head"><tspan class="prompt">&#8250; </tspan>Keep it running</text>
  <text x="$right" y="46" class="month amount">{$month}</text>
  <text x="$x" y="72" class="note">What the AI connector costs to run, and how it's covered.</text>
  $body
</svg>
SVG;
    }

    /** The colour rules for one palette (see THEMES). */
    private static function colors(array $t): string
    {
        $bar = $t['bar'] ?? $t['accent'];
        $pill = isset($t['btn']) ? "fill: {$t['btn']}; stroke: {$t['btn']};" : "fill: none; stroke: {$t['accent']};";
        $give = $t['on-btn'] ?? $t['accent'];
        $prompt = $t['prompt'] ?? $t['accent'];
        return "    text { fill: {$t['text']}; }\n"
            . "    .card { fill: {$t['bg']}; stroke: {$t['border']}; }\n"
            . "    .prompt { fill: $prompt; }\n"
            . "    .month, .note, .item-n, .label { fill: {$t['muted']}; }\n"
            . "    .given-a, .status { fill: {$t['accent']}; }\n"
            . "    .rule { stroke: {$t['border']}; }\n"
            . "    .track { fill: {$t['track']}; }\n"
            . "    .bar { fill: $bar; }\n"
            . "    .pill { $pill }\n"
            . "    .give { fill: $give; }";
    }

    /** @return array{given: int, covered_by: ?string, covered: int, thanks: ?string, costs: list<array{0: string, 1: int, 2: string}>} */
    private static function month(array $row): array
    {
        $costs = [];
        foreach ((array) ($row['costs'] ?? []) as $c) {
            if (is_array($c) && is_string($c[0] ?? null) && is_int($c[1] ?? null) && $c[1] >= 0) {
                $kind = in_array($c[2] ?? null, ['fixed', 'once', 'metered'], true) ? $c[2] : 'fixed';
                $costs[] = [mb_substr($c[0], 0, 44), $c[1], $kind];
            }
        }
        $by = $row['covered_by'] ?? null;
        $thanks = $row['thanks'] ?? null;
        $thanks = is_string($thanks) ? trim((string) preg_replace('/\s+/u', ' ', $thanks)) : '';
        return ['thanks' => $thanks !== '' ? mb_substr($thanks, 0, 40) : null,
                'given' => is_int($row['given_cents'] ?? null) ? max(0, $row['given_cents']) : 0,
                'covered_by' => is_string($by) && trim($by) !== '' ? mb_substr(trim($by), 0, 24) : null,
                'covered' => is_int($row['covered_cents'] ?? null) ? max(0, $row['covered_cents']) : 0,
                'costs' => $costs];
    }

    private static function next(string $m): string
    {
        [$y, $mo] = [(int) substr($m, 0, 4), (int) substr($m, 5, 2)];
        return sprintf('%04d-%02d', $y + intdiv($mo, 12), $mo % 12 + 1);
    }

    private static function name(string $m): string
    {
        return gmdate('F Y', (int) strtotime("$m-01 00:00:00 UTC"));
    }

    private static function bar(int $x, int $y, int $width, int $height, int $pct): string
    {
        $r = intdiv($height, 2);
        $fill = (int) round($width * max(0, min(100, $pct)) / 100);
        return "<rect x=\"$x\" y=\"$y\" width=\"$width\" height=\"$height\" rx=\"$r\" class=\"track\"/>"
            . ($fill > 0 ? "<rect x=\"$x\" y=\"$y\" width=\"$fill\" height=\"$height\" rx=\"$r\" class=\"bar\"/>" : '');
    }

    private static function line(int $x, int $right, int $y, string $name, string $amount, string $kind): string
    {
        $n = $kind === 'item' ? ' class="item-n"' : ($kind === 'total' ? ' class="total"' : '');
        $a = $kind === 'total' ? 'amount total' : ($kind === 'given' ? 'amount given-a' : 'amount');
        return "<text x=\"$x\" y=\"$y\"$n>" . self::esc($name) . "</text><text x=\"$right\" y=\"$y\" class=\"$a\">"
            . self::esc($amount) . '</text>';
    }

    private static function money(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
