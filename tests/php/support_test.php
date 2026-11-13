<?php
/**
 * Tests for src/Support.php (the public cost card) and the spending counters in src/RateLimit.php.
 */

namespace ColLibraryTest\Support;

use ColLibrary\Config;
use ColLibrary\RateLimit;
use ColLibrary\Support;

col_src('Context', 'Config', 'Log', 'RateLimit', 'Mcp', 'Support');

const NOV_15 = 1794744000;   // 2026-11-15 12:00 UTC
const DEC_02 = 1796212800;   // 2026-12-02 12:00 UTC

function test_spend_is_counted_by_kind_and_month_and_never_pruned(): void
{
    assert_same('2026-11-15', gmdate('Y-m-d', NOV_15));
    assert_same('2026-12-02', gmdate('Y-m-d', DEC_02));
    $dir = col_tmpdir();
    $now = NOV_15;
    $limits = new RateLimit($dir, 100, 1000, 1000, null, function () use (&$now): int {
        return $now;
    });
    $limits->countSpend('rerank', 1000);
    $limits->countSpend('rerank', 500);
    $limits->countSpend('embed', 40);
    $limits->countSpend('Bad kind', 99);
    $limits->countSpend('embed', 0);
    $now = DEC_02;
    $limits->countSpend('rerank', 7);
    assert_same(null, $limits->takeCall());   // the first call of a minute prunes old counters
    $now += 8 * 86400;
    assert_same(null, $limits->takeCall());
    assert_same(['embed' => [202611 => 40], 'rerank' => [202611 => 1500, 202612 => 7]], $limits->spending());
}

function test_searches_are_priced_after_the_free_rerank_tokens(): void
{
    $spending = ['rerank' => [202611 => 150_000_000, 202612 => 100_000_000], 'embed' => [202612 => 10_000_000]];
    $nov = Support::ledger([], $spending, NOV_15);
    assert_same([Support::SEARCH_LABEL, 0], $nov['items'][0], 'within the free tokens');
    $dec = Support::ledger([], $spending, DEC_02);
    // 50M of December's rerank tokens are past the free 200M (250 cents), plus 10M embedding tokens (20).
    assert_same([Support::SEARCH_LABEL, 270], $dec['items'][0]);
    assert_same(270 + $dec['rest'], $dec['costs']);
}

function test_gifts_beyond_the_costs_carry_forward_and_a_shortfall_does_not(): void
{
    $funding = ['months' => [
        '2026-10' => ['given_cents' => 0, 'costs' => [['Domain name', 1000]]],
        '2026-11' => ['given_cents' => 2000, 'costs' => [['Domain name', 500]]],
        '2026-12' => ['given_cents' => 100, 'costs' => [['Domain name', 300], ['Electricity (estimate)', 300]]],
        '2027-01' => ['given_cents' => 999, 'costs' => []],
        'junk' => ['given_cents' => 5],
    ]];
    $dec = Support::ledger($funding, [], DEC_02);
    assert_same('2026-12', $dec['month']);
    assert_same([[Support::SEARCH_LABEL, 0], ['Domain name', 300], ['Electricity (estimate)', 300]], $dec['items']);
    assert_same(600, $dec['costs']);
    assert_same(100, $dec['given']);
    assert_same(1500, $dec['carried'], 'November\'s surplus; October\'s shortfall is not carried');
}

function test_bad_funding_entries_are_ignored(): void
{
    $funding = ['months' => ['2026-11' => ['given_cents' => '500', 'costs' => [['ok', 5], ['neg', -3], 'x', [7, 7], ['float', 1.5]]]]];
    $l = Support::ledger($funding, [], NOV_15);
    assert_same([[Support::SEARCH_LABEL, 0], ['ok', 5]], $l['items']);
    assert_same(0, $l['given']);
}

function test_card_is_well_formed_escaped_svg(): void
{
    $funding = ['months' => ['2026-11' => ['given_cents' => 250, 'costs' => [['<script>alert(1)</script> & co', 1086]]]]];
    foreach (['dark', 'light'] as $theme) {
        $svg = Support::card(Support::ledger($funding, [], NOV_15), $theme);
        if (class_exists(\DOMDocument::class)) {   // the office PC's PHP has no XML extension
            assert_true(@(new \DOMDocument())->loadXML($svg), "$theme card parses as XML");
        }
        assert_not_contains('<script', $svg);
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt; &amp; co', $svg);
        assert_contains('November 2026', $svg);
        assert_contains('$10.86', $svg);
        assert_contains('23% funded', $svg);   // 250 of 1086 cents
    }
}

function test_card_says_funded_and_what_carries_forward(): void
{
    $base = ['month' => '2026-12', 'items' => [[Support::SEARCH_LABEL, 100]], 'costs' => 100, 'covered_by' => null,
             'ahead' => [['month' => '2027-01', 'costs' => 340, 'pct' => 58, 'covered_by' => null]], 'through' => null];
    $svg = Support::card(['given' => 50, 'carried' => 300] + $base, 'dark');
    assert_contains('Funded. Thank you!</text>', $svg);
    assert_not_contains('carries', $svg);
    assert_contains('Earlier gifts beyond costs', $svg);
    assert_contains('January 2027', $svg);
    assert_contains('about $3.40', $svg);
    assert_contains('58%', $svg);
    $svg = Support::card(['given' => 100, 'carried' => 0] + $base, 'light');
    assert_contains('Funded. Thank you!</text>', $svg);
    assert_not_contains('Earlier gifts beyond costs', $svg);
}

function fixed_months(array $covered = ['2026-11' => 0]): array
{
    $months = [];
    foreach (['2026-11', '2026-12', '2027-01', '2027-02'] as $m) {
        $months[$m] = ['given_cents' => 0, 'costs' => [['Domain name', 87, 'fixed'], ['Electricity (estimate)', 250, 'fixed']]];
    }
    $months['2026-11']['costs'][] = ['Building the search index (one time)', 1086, 'once'];
    foreach ($covered as $m => $gifts) {
        $months[$m]['covered_by'] = 'Matt Miller';
        $months[$m]['given_cents'] = $gifts;
    }
    return ['months' => $months];
}

function test_a_covered_month_sends_its_gifts_to_the_coming_months(): void
{
    $l = Support::ledger(fixed_months(['2026-11' => 1000]), [], NOV_15);
    assert_same('Matt Miller', $l['covered_by']);
    assert_same(1423, $l['costs']);
    assert_same(0, $l['carried']);
    // $10 given in a covered month: December and January (about $3.37 each) are funded, February 96%.
    assert_same([['month' => '2026-12', 'costs' => 337, 'pct' => 100, 'covered_by' => null],
                 ['month' => '2027-01', 'costs' => 337, 'pct' => 100, 'covered_by' => null],
                 ['month' => '2027-02', 'costs' => 337, 'pct' => 96, 'covered_by' => null]], $l['ahead']);
    assert_same('2027-01', $l['through']);
    $svg = Support::card($l, 'dark');
    assert_contains('November is covered.</text>', $svg);
    assert_contains("Gifts support Matt Miller's work on the library.", $svg);
    assert_contains('>Covered</text>', $svg);
    assert_false(str_contains(explode('Free for everyone', $svg)[0], 'Matt Miller'), 'the name is not in the coverage line');
    assert_contains('Given this month', $svg);
    assert_contains('COMING UP', $svg);
    assert_contains('96%', $svg);
    assert_not_contains('Gifts so far cover', $svg, 'the shown months already say it');
    if (class_exists(\DOMDocument::class)) {
        assert_true(@(new \DOMDocument())->loadXML($svg), 'parses as XML');
    }

    $l = Support::ledger(fixed_months(), [], NOV_15);
    assert_same([0, 0, 0], array_column($l['ahead'], 'pct'));
    assert_same(null, $l['through']);
    assert_not_contains('Given this month', Support::card($l, 'dark'), 'nothing given in a covered month: no line');
}

function test_gifts_reaching_past_the_shown_months_say_how_far(): void
{
    // $50: 14 months at $3.37 (December 2026 to January 2028), then February 2028 falls short.
    $l = Support::ledger(fixed_months(['2026-11' => 5000]), [], NOV_15);
    assert_same([100, 100, 100], array_column($l['ahead'], 'pct'));
    assert_same('2028-01', $l['through']);
    assert_contains('Gifts so far cover the costs through January 2028.', Support::card($l, 'light'));
}

function test_coming_months_include_metered_costs_at_the_higher_of_last_month_and_this_pace(): void
{
    $f = fixed_months();
    $f['months']['2026-11']['costs'][] = ['Adding new books (Voyage AI)', 30, 'metered'];
    // 15 Nov 12:00 is 14.5 days into a 30-day month: 30 cents so far is a pace of 63.
    $l = Support::ledger($f, [], NOV_15);
    assert_same(337 + 63, $l['ahead'][0]['costs']);
    // On 2 December nothing is metered yet, so November's 30 cents is the estimate.
    $l = Support::ledger($f, [], DEC_02);
    assert_same('2026-12', $l['month']);
    assert_same(337 + 30, $l['ahead'][0]['costs']);
    assert_same('2027-01', $l['ahead'][0]['month']);
    // Beyond the months homebox sent: its last coming month's fixed costs.
    assert_same(['2027-01', '2027-02', '2027-03'], array_column($l['ahead'], 'month'));
    assert_same(337 + 30, $l['ahead'][2]['costs']);
}

function test_response_reads_the_member_list_and_refuses_other_methods(): void
{
    $dir = col_tmpdir();
    $members = "$dir/members.json";
    file_put_contents($members, json_encode(['members' => [], 'blocked' => [],
        'funding' => ['months' => [gmdate('Y-m') => ['given_cents' => 1234, 'costs' => [['Domain name', 87]]]]]]));
    $config = Config::fromArray(['url_key' => 'Zx9_-abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG', 'voyage_api_key' => 'pa-voyage-secret-0123456789', 'data_dir' => $dir, 'state_dir' => $dir]);
    $r = Support::response('GET', ['theme' => 'light'], ['members_file' => $members], $config);
    assert_same(200, $r->status);
    assert_same('image/svg+xml; charset=utf-8', $r->headers['Content-Type']);
    assert_same('public, max-age=600', $r->headers['Cache-Control']);
    assert_contains('$12.34', $r->body);
    assert_contains('#ffffff', $r->body, 'light theme');
    $r = Support::response('HEAD', [], null, $config);
    assert_same(200, $r->status);
    assert_contains('$0.00', $r->body, 'no member list: search costs only');
    $r = Support::response('POST', [], null, $config);
    assert_same(405, $r->status);
}

function test_recent_spending_is_the_last_seven_whole_days_and_cap_days_are_counted_once(): void
{
    $dir = col_tmpdir();
    $now = DEC_02;
    $limits = new RateLimit($dir, 100, 1000, 1000, null, function () use (&$now): int {
        return $now;
    });
    foreach ([8, 7, 1, 0] as $daysAgo) {
        $now = DEC_02 - $daysAgo * 86400;
        $limits->countSpend('rerank', 100);
    }
    $now = DEC_02;
    assert_same(['rerank' => 200], $limits->recentSpending(), '7 and 1 days ago; not 8 days ago or today');

    assert_true($limits->reserveVoyage(900));
    assert_false($limits->reserveVoyage(200), 'over the shared daily budget');
    assert_false($limits->reserveVoyage(200));
    assert_same([202612 => 1], $limits->spending()['capped'], 'once per day');
    $now += 86400;
    assert_true($limits->reserveVoyage(900));
    assert_false($limits->reserveVoyage(200));
    assert_same([202612 => 2], $limits->spending()['capped']);

    // One person's share running out is not the service's limit.
    $link = new RateLimit($dir, 100, 1000, 1000, null, function () use (&$now): int {
        return $now + 86400;
    }, ['id' => 'u0123456789ab', 'calls_per_day' => 500, 'calls_per_minute' => 30]);
    assert_false($link->reserveVoyage(300), 'over one person\'s fifth of the budget');
    assert_same([202612 => 2], $link->spending()['capped']);
}

function test_coming_months_follow_the_last_week_and_the_free_tokens_running_out(): void
{
    // 190M rerank tokens in November and 2M so far in December leave 8M of the 200M free tokens.
    // The last 7 days used 70M: 310M a month at December's 31 days, far above the other measures.
    $spending = ['rerank' => [202611 => 190_000_000, 202612 => 2_000_000]];
    $l = Support::ledger(fixed_months(), $spending, DEC_02, ['rerank' => 70_000_000]);
    // December so far is within the free tokens; the rest of December (29.5 of 31 days) expects 295M,
    // 287M of them past the free tokens.
    assert_same([[Support::SEARCH_LABEL, 0], ['Domain name', 87], ['Electricity (estimate)', 250],
                 ['Rest of December (expected)', 1435]], $l['items']);
    assert_same(337 + 1435, $l['costs']);
    assert_contains('Costs this month (expected)', Support::card($l, 'dark'));
    // From January all 310M are billed, plus $3.37.
    assert_same([['2027-01', 1887], ['2027-02', 1887], ['2027-03', 1887]],
        array_map(static fn (array $a): array => [$a['month'], $a['costs']], $l['ahead']));
    // Without the busy week, the months follow November's 190M.
    $l = Support::ledger(fixed_months(), $spending, DEC_02);
    assert_same(864, $l['rest']);
    assert_same([337 + 950, 337 + 950, 337 + 950], array_column($l['ahead'], 'costs'));
}

function test_card_says_when_the_daily_limit_was_reached(): void
{
    $l = Support::ledger(fixed_months(), ['capped' => [202611 => 2]], NOV_15);
    assert_same(2, $l['capped']);
    assert_contains('reached its daily spending limit on 2 days this month.', Support::card($l, 'dark'));
    $l = Support::ledger(fixed_months(), ['capped' => [202611 => 1]], NOV_15);
    assert_contains('on 1 day this month.', Support::card($l, 'dark'));
    assert_not_contains('daily spending limit', Support::card(Support::ledger(fixed_months(), [], NOV_15), 'dark'));
}

function test_coming_months_plan_for_the_planned_use(): void
{
    // 40M rerank tokens a day: 1,240M in a 31-day month. The rest of December (1,180M) uses the 200M
    // free tokens: 980M billed.
    $f = fixed_months() + ['plan' => ['rerank_per_day' => 40_000_000, 'people' => 'hundreds of people']];
    $l = Support::ledger($f, [], DEC_02);
    assert_same(4900, $l['rest']);
    assert_same(337 + 4900, $l['costs']);
    assert_same([337 + 6200, 337 + 6200, 337 + 6200], array_column($l['ahead'], 'costs'));
    assert_same('hundreds of people', $l['plan']);
    assert_not_contains('Planned for', Support::card($l, 'light'));
    // Measured use above the plan still wins; a bad or empty plan is ignored.
    $l = Support::ledger($f, [], DEC_02, ['rerank' => 700_000_000]);
    assert_true($l['ahead'][1]['costs'] > 337 + 6200);
    foreach ([['rerank_per_day' => '40000000', 'people' => 'x'], ['rerank_per_day' => 0, 'people' => 'x'], 'plan'] as $bad) {
        $l = Support::ledger(fixed_months() + ['plan' => $bad], [], DEC_02);
        assert_same(null, $l['plan']);
        assert_same([337, 337, 337], array_column($l['ahead'], 'costs'));
    }
}

function test_a_month_covered_in_part_asks_gifts_for_the_rest(): void
{
    $f = fixed_months([]);
    $f['months']['2026-11']['covered_cents'] = 1000;   // November costs $14.23: $10 covered, $4.23 to go
    $f['months']['2026-11']['given_cents'] = 300;
    $l = Support::ledger($f, [], NOV_15);
    assert_same([null, 1000, 1423], [$l['covered_by'], $l['covered'], $l['costs']]);
    $svg = Support::card($l, 'dark');
    assert_contains('>Covered</text>', $svg);
    assert_contains('>$10.00</text>', $svg);
    assert_contains('91% funded, $1.23 still needed', $svg);
    // $5 given: $0.77 beyond the rest goes to December; covering more than the costs carries nothing.
    $f['months']['2026-11']['given_cents'] = 500;
    assert_same(22, Support::ledger($f, [], NOV_15)['ahead'][0]['pct']);
    $f['months']['2026-11']['covered_cents'] = 99_999;
    $f['months']['2026-11']['given_cents'] = 0;
    $l = Support::ledger($f, [], NOV_15);
    assert_same([1423, 0], [$l['covered'], $l['ahead'][0]['pct']]);
    assert_contains('Funded. Thank you!', Support::card($l, 'dark'));
}
