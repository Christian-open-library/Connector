<?php
/**
 * The public "Keep it running" card, GET /support.svg?theme=light|dark on the office server: this
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
 *    is what monthly sponsors give.
 * Arithmetic (the card makes no promise about how gifts are spent: they go to the curator personally):
 * a month the curator covers (covered_by) needs no gifts, so its gifts count toward later months;
 * otherwise gifts beyond a month's costs count toward later months, and a shortfall is not carried.
 * A coming month costs its fixed and one-time costs plus its expected usage: the highest of the month
 * before this one, this month's pace and the last 7 days' pace, with Voyage's free tokens used first.
 * So when many more people start using the connector, the coming months' costs rise within days.
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
    private const THEMES = [
        'dark' => ['bg' => '#0d1117', 'border' => '#30363d', 'text' => '#e6edf3', 'muted' => '#7d8590',
                   'accent' => '#3fb950', 'track' => '#21262d'],
        'light' => ['bg' => '#ffffff', 'border' => '#d0d7de', 'text' => '#1f2328', 'muted' => '#656d76',
                    'accent' => '#1a7f37', 'track' => '#eaeef2'],
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
        $theme = ($query['theme'] ?? '') === 'light' ? 'light' : 'dark';
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
     *   carried: int, covered_by: ?string, capped: int,
     *   ahead: list<array{month: string, costs: int, pct: int, covered_by: ?string}>, through: ?string}
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
                           'capped' => (int) ($spending['capped'][$ym] ?? 0)];
                $thisMonth = $use;
            } else {
                $before = $use;
            }
            $carry = $row['covered_by'] !== null ? $carry + $row['given'] : max(0, $carry + $row['given'] - $costs);
        }
        $days = (int) gmdate('t', $now);
        $scale = $days / max(1.0, ($now - (int) strtotime("$current-01 00:00:00 UTC")) / 86400);
        $expect = [];
        foreach ($before as $k => $v) {
            $expect[$k] = (int) ceil(max($v, $thisMonth[$k] * $scale, ($recent[$k] ?? 0) * $days / 7));
        }
        $free = max(0, self::FREE['rerank'] - $rerankSoFar);

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
                $row = ['given' => $last['given'] ?? 0, 'covered_by' => null,
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
                $have = $carry + $row['given'];
                $pct = $costs > 0 ? min(100, intdiv(100 * $have, $costs)) : 100;
                $carry = max(0, $have - $costs);
            }
            if ($i <= self::AHEAD) {
                $ahead[] = ['month' => $m, 'costs' => $costs, 'pct' => $pct, 'covered_by' => $row['covered_by']];
            }
            $funded = $funded && $pct >= 100;
            if ($funded) {
                $through = $m;
            }
        }
        return $result + ['ahead' => $ahead, 'through' => $through];
    }

    /** The card as SVG for one ledger (see ledger()). */
    public static function card(array $l, string $theme): string
    {
        $t = self::THEMES[$theme] ?? self::THEMES['dark'];
        $w = self::W;
        $x = self::PAD;
        $right = $w - self::PAD;
        $month = self::name($l['month']);
        $covered = $l['covered_by'] !== null;
        $have = $l['given'] + $l['carried'];
        $pct = $covered ? 100 : ($l['costs'] > 0 ? min(100, intdiv(100 * $have, $l['costs'])) : 100);
        if ($covered) {
            $status = explode(' ', $month)[0] . ' is covered.';
        } elseif ($have >= $l['costs']) {
            $status = 'Funded. Thank you!';
        } else {
            $status = $pct . '% funded';
        }

        $out = [];
        $y = 104;
        foreach ($l['items'] as [$name, $cents]) {
            $out[] = self::line($x, $right, $y, $name, self::money($cents), 'item');
            $y += self::ROW;
        }
        $out[] = "<line x1=\"$x\" y1=\"" . ($y - 16) . "\" x2=\"$right\" y2=\"" . ($y - 16) . '" class="rule"/>';
        $y += 8;
        $out[] = self::line($x, $right, $y, 'Costs this month', self::money($l['costs']), 'total');
        $y += self::ROW;
        if ($covered) {
            $out[] = self::line($x, $right, $y, 'Covered', self::money($l['costs']), 'given');
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

        $y += 26;
        $out[] = "<text x=\"$x\" y=\"$y\" class=\"note\">Free for everyone. Gifts support Matt Miller's work on the library.</text>";
        $pillW = 96;
        $pillX = $right - $pillW;
        $out[] = "<rect x=\"$pillX\" y=\"" . ($y - 20) . "\" width=\"$pillW\" height=\"30\" rx=\"15\" class=\"pill\"/>";
        $out[] = '<text x="' . ($pillX + $pillW / 2) . '" y="' . ($y - 1) . '" class="give">&#9829; Give</text>';
        $h = $y + 26;

        $summary = "Keep it running, $month: costs " . self::money($l['costs'])
            . ($covered ? ', covered' : ', given ' . self::money($l['given'])
                . ($l['carried'] > 0 ? ', carried ' . self::money($l['carried']) : '') . ", $status")
            . '. Coming up: ' . implode('; ', array_map(static fn (array $a): string => self::name($a['month'])
                . ' about ' . self::money($a['costs']) . ', ' . $a['pct'] . '% funded', $l['ahead'])) . '.';
        $label = self::esc($summary);
        $body = implode("\n  ", $out);
        $mono = self::MONO;
        $w1 = $w - 1;
        $h1 = $h - 1;
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 $w $h" width="$w" height="$h" role="img" aria-label="$label">
  <title>$label</title>
  <style>
    text { font-family: $mono; font-size: 15px; fill: {$t['text']}; }
    .prompt { fill: {$t['accent']}; }
    .head { font-size: 17px; font-weight: 600; }
    .month, .note, .item-n, .label { fill: {$t['muted']}; }
    .note { font-size: 13px; }
    .label { font-size: 12px; letter-spacing: 1.5px; }
    .total { font-weight: 600; }
    .given-a, .status { fill: {$t['accent']}; }
    .status { font-weight: 600; }
    .amount { text-anchor: end; }
    .rule { stroke: {$t['border']}; stroke-dasharray: 3 4; }
    .track { fill: {$t['track']}; }
    .bar { fill: {$t['accent']}; }
    .pill { fill: none; stroke: {$t['accent']}; stroke-width: 1.5; }
    .give { text-anchor: middle; font-weight: 600; fill: {$t['accent']}; }
  </style>
  <rect x="0.5" y="0.5" width="$w1" height="$h1" rx="8" fill="{$t['bg']}" stroke="{$t['border']}"/>
  <text x="$x" y="46" class="head"><tspan class="prompt">&#8250; </tspan>Keep it running</text>
  <text x="$right" y="46" class="month amount">{$month}</text>
  <text x="$x" y="72" class="note">What the AI connector costs to run, and how it's covered.</text>
  $body
</svg>
SVG;
    }

    /** @return array{given: int, covered_by: ?string, costs: list<array{0: string, 1: int, 2: string}>} */
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
        return ['given' => is_int($row['given_cents'] ?? null) ? max(0, $row['given_cents']) : 0,
                'covered_by' => is_string($by) && trim($by) !== '' ? mb_substr(trim($by), 0, 24) : null,
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
