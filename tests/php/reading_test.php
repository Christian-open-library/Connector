<?php
/**
 * The daily reading limit (RateLimit::readRefusal before a reading request, countRead after it: read_passage and
 * search_library in mode "exact") and the owner key's reading report (RateLimit::readingReport, MCP method
 * library/reading). Added 2026-11-19: one person must not be able to copy the library through the connector.
 */

namespace ColLibraryTest\Reading;

use ColLibrary\Context;
use ColLibrary\Log;
use ColLibrary\Mcp;
use ColLibrary\RateLimit;
use ColLibrary\Store;
use ColLibrary\Tools;
use ColLibraryTest\Fixture;

const DAY = 86400;

/** A context for one person (a signed-in account's id) with a small reading limit, sharing $state. */
function person(string $id, string $state, int $readPerDay, ?int &$clock = null, bool $owner = false,
                array $config = [], int $licensedPerDay = 100): Context
{
    $clock ??= Fixture\FIXED_TIME;
    $cfg = Fixture\config(['state_dir' => $state] + $config);
    $log = Log::fromConfig($cfg);
    $link = $owner ? null : ['id' => $id, 'calls_per_day' => 1000, 'calls_per_minute' => 1000];
    $limits = new RateLimit($state, 100000, 1000000, $cfg->maxVoyageTokensPerDay(), $log,
        function () use (&$clock): int { return $clock; }, $link, RateLimit::PERSON_VOYAGE_DEFAULT, $licensedPerDay,
        $readPerDay);
    return new Context($cfg, $limits, $log, microtime(true) + 25.0, $owner);
}

/** Refs printed in full ("P10100005 ·" in read_passage, "[1] P10100005 ·" in a search). */
function refs(string $text): array
{
    preg_match_all('/^(?:\[\d+\] )?P(\d+)\b/m', $text, $m);
    return array_map('intval', $m[1]);
}

function today(): int
{
    return intdiv(Fixture\FIXED_TIME, DAY);
}

function test_the_reading_limit_default_is_a_full_day_of_ordinary_reads(): void
{
    // 500 calls a day (a signed-in account) x read_passage's default 1 before + the passage + 2 after.
    assert_same(500 * (1 + 1 + 2), RateLimit::READ_DEFAULT);
    assert_same(RateLimit::READ_DEFAULT, Fixture\config()->maxReadPassagesPerPersonPerDay());
}

function test_read_passage_stops_at_the_daily_reading_limit_for_that_person_only(): void
{
    Fixture\store_or_skip();
    $state = col_tmpdir('read');
    $clock = Fixture\FIXED_TIME;
    $a = person('uaaaaaaaaaaaa', $state, 12, $clock);
    $ask = ['ref' => 'P10100005', 'before' => 2, 'after' => 3];
    $r = Tools::call('read_passage', $ask, $a);
    assert_false($r['isError'], Fixture\text_of($r));
    assert_same(6, count(refs(Fixture\text_of($r))));
    // 6 of 12 read: the next call is served whole and reaches the limit exactly. Reaching it is not a "capped"
    // day: only a refusal marks one (so 500 ordinary reads, exactly 2,000 passages, never do).
    $r = Tools::call('read_passage', $ask, $a);
    assert_false($r['isError']);
    assert_same(6, count(refs(Fixture\text_of($r))));
    assert_same([], (array) $a->limits->readingReport()['capped'], 'reaching the limit is not a refusal');
    $r = Tools::call('read_passage', $ask, $a);
    $text = Fixture\text_of($r);
    assert_true($r['isError'], $text);
    assert_same([], refs($text), 'no passage after the limit');
    assert_contains('Daily reading limit reached: this account has read 12 passages in full today, and one account may read 12 a day.', $text);
    assert_contains('Searching still works.', $text);
    assert_contains('The limit resets at 00:00 UTC', $text);
    assert_contains('hello@christianopenlibrary.org', $text);
    assert_same(['uaaaaaaaaaaaa' => [today()]], (array) $a->limits->readingReport()['capped'], 'the refusal marks the day');
    assert_same(12, $a->limits->readingReport()['read'][6], 'the refused call counted nothing');
    // Search in the normal mode is not counted and still works.
    $s = Tools::call('search_library', ['query' => 'God'], $a);
    assert_false($s['isError'], Fixture\text_of($s));
    // Another person has their own count.
    $b = person('ubbbbbbbbbbbb', $state, 12, $clock);
    assert_false(Tools::call('read_passage', $ask, $b)['isError'], 'another account');
    // The next UTC day starts again.
    $clock += DAY;
    assert_false(Tools::call('read_passage', $ask, $a)['isError'], 'a new day');
}

function test_exact_search_paging_through_a_work_shares_the_reading_limit(): void
{
    Fixture\store_or_skip();
    $state = col_tmpdir('read');
    $clock = Fixture\FIXED_TIME;
    $a = person('uaaaaaaaaaaaa', $state, 5, $clock);
    // A common word, one work, in book order, a page at a time: the way to walk a book through search.
    $page = fn (int $offset): array => Tools::call('search_library',
        ['query' => 'the', 'mode' => 'exact', 'work_id' => 101, 'limit' => 3, 'offset' => $offset], $a);
    $r = $page(0);
    assert_false($r['isError'], Fixture\text_of($r));
    assert_same(3, count(refs(Fixture\text_of($r))));
    $r = $page(3);
    assert_false($r['isError'], Fixture\text_of($r));
    assert_same(3, count(refs(Fixture\text_of($r))), '3 of 5 read: still served');
    assert_same(6, $a->limits->readingReport()['read'][6]);
    $r = $page(6);
    $text = Fixture\text_of($r);
    assert_true($r['isError'], $text);
    assert_same([], refs($text));
    assert_contains('Daily reading limit reached', $text);
    assert_contains('exact-mode search counts too', $text);
    assert_contains('Searching in the normal mode ("best") still works.', $text);
    // read_passage shares the same allowance; the normal mode doesn't use it.
    assert_true(Tools::call('read_passage', ['ref' => 'P10100005'], $a)['isError']);
    $best = Tools::call('search_library', ['query' => 'mortification of sin', 'work_id' => 101], $a);
    assert_false($best['isError'], Fixture\text_of($best));
    assert_same(6, $a->limits->readingReport()['read'][6], 'best mode is not counted');
    assert_same(['uaaaaaaaaaaaa' => [today()]], (array) $a->limits->readingReport()['capped']);
}

function test_a_refused_or_trimmed_licensed_read_counts_only_what_was_given(): void
{
    $dir = \ColLibraryTest\Church\licensed_dir();
    $range = (new Store($dir))->passageRange(103);
    if ($range === null || $range[1] - $range[0] < 6) {
        col_skip('the licensed fixture work is too short');
    }
    $mid = $range[0] + 3;
    $state = col_tmpdir('read');
    $clock = Fixture\FIXED_TIME;
    // No licensed passages left today: the read is refused and uses none of the reading allowance.
    $none = person('uaaaaaaaaaaaa', $state, 100, $clock, false, ['data_dir' => $dir], 0);
    assert_true(Tools::call('read_passage', ['ref' => "P$mid", 'before' => 2, 'after' => 3], $none)['isError']);
    assert_same(0, $none->limits->readingReport()['read'][6]);
    // Two licensed passages left: two are given and two are counted.
    $two = person('ubbbbbbbbbbbb', $state, 100, $clock, false, ['data_dir' => $dir], 2);
    $r = Tools::call('read_passage', ['ref' => "P$mid", 'before' => 2, 'after' => 3], $two);
    assert_false($r['isError'], Fixture\text_of($r));
    assert_same(2, count(refs(Fixture\text_of($r))));
    assert_same(2, $two->limits->readingReport()['read'][6]);
}

function test_the_owner_key_is_not_limited_and_only_it_gets_the_report(): void
{
    Fixture\store_or_skip();
    $state = col_tmpdir('read');
    $clock = Fixture\FIXED_TIME;
    $owner = person('', $state, 1, $clock, true);
    for ($i = 0; $i < 3; $i++) {
        assert_false(Tools::call('read_passage', ['ref' => 'P10100005', 'before' => 2, 'after' => 3], $owner)['isError']);
    }
    $a = person('uaaaaaaaaaaaa', $state, 5, $clock);
    Tools::call('read_passage', ['ref' => 'P10100005', 'before' => 2, 'after' => 3], $a);   // 6: past 5, still served
    assert_true(Tools::call('read_passage', ['ref' => 'P10100005'], $a)['isError'], 'refused: marks the day');
    // A heavy day of tool calls for another account.
    $b = person('ubbbbbbbbbbbb', $state, 5, $clock);
    for ($i = 0; $i < RateLimit::HEAVY_CALLS; $i++) {
        assert_same(null, $b->limits->takeCall());
    }
    $report = $owner->limits->readingReport();
    $today = today();
    assert_same(range($today - 6, $today), $report['days']);
    assert_same([0, 0, 0, 0, 0, 0, 18 + 6], $report['read'], 'every caller\'s passages, the owner\'s too');
    assert_same(1, $report['read_limit'], 'the owner context\'s own setting');
    assert_same(['uaaaaaaaaaaaa' => [$today]], (array) $report['capped']);
    assert_same(['ubbbbbbbbbbbb' => [$today]], (array) $report['heavy']);
    // Through MCP: the owner's key gets it; a signed-in account gets the same answer as an unknown method.
    $mcp = new Mcp();
    $body = '{"jsonrpc":"2.0","id":7,"method":"library/reading"}';
    $r = $mcp->handle('POST', ['Content-Type' => 'application/json'], $body, $owner);
    $d = json_decode((string) $r->body, true);
    assert_same(200, $r->status, (string) $r->body);
    assert_same(['uaaaaaaaaaaaa' => [$today]], $d['result']['capped']);
    $r = $mcp->handle('POST', ['Content-Type' => 'application/json'], $body, $a);
    $d = json_decode((string) $r->body, true);
    assert_same(-32601, $d['error']['code'] ?? null, (string) $r->body);
    assert_not_contains('capped', (string) $r->body);
    $unknown = $mcp->handle('POST', ['Content-Type' => 'application/json'],
        '{"jsonrpc":"2.0","id":7,"method":"library/nothing"}', $a);
    assert_same(str_replace('library/nothing', 'library/reading', (string) $unknown->body), (string) $r->body,
        'no hint that the method exists');
    // The modern protocol path: the same.
    $d = json_decode((string) \ColLibraryTest\Mcp\modern('library/reading', [], 9, [], $owner)->body, true);
    assert_same(['uaaaaaaaaaaaa' => [$today]], $d['result']['capped'] ?? null, 'modern path, owner');
    \ColLibraryTest\Mcp\error_of(\ColLibraryTest\Mcp\modern('library/reading', [], 9, [], $a), 404, -32601, 9);
}
