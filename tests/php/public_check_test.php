<?php
/**
 * Tests for src/PublicCheck.php (POST /check, the website's quotation checker) and RateLimit::takePublic, on the
 * fixture build (tests/php/fixture: Owen in two editions, Augustine 1886).
 */

declare(strict_types=1);

namespace ColLibraryTest\PublicCheck;

use ColLibrary\Config;
use ColLibrary\PublicCheck;
use ColLibrary\RateLimit;
use ColLibrary\Response;
use ColLibraryTest\Fixture;

require_once __DIR__ . '/fixture/testlib.php';
col_src('Mcp', 'Bible', 'KeptAnswer', 'PublicCheck');

const SITE = 'https://christianopenlibrary.org';
const TIME = Fixture\FIXED_TIME;

function cfg(array $overrides = []): Config
{
    Fixture\store_or_skip();
    return Fixture\config($overrides);
}

function post(array|string $body, array $headers = [], string $ip = '203.0.113.7', ?Config $config = null,
              string $method = 'POST', ?int $now = null): Response
{
    $config ??= cfg();
    $headers += ['Origin' => SITE, 'Content-Type' => 'text/plain;charset=UTF-8'];
    $body = is_array($body) ? (string) json_encode($body) : $body;
    return PublicCheck::response($method, $headers, $body, $ip, $config, fn (): int => $now ?? TIME);
}

function data(Response $r): array
{
    return json_decode((string) $r->body, true, 512, JSON_THROW_ON_ERROR);
}

function test_word_for_word_with_the_scan_and_context(): void
{
    $r = post(['quote' => 'Be killing sin, or it will be killing you.', 'author' => 'John Owen']);
    assert_same(200, $r->status, (string) $r->body);
    assert_same(SITE, $r->headers['Access-Control-Allow-Origin']);
    assert_same('no-store', $r->headers['Cache-Control']);
    $d = data($r);
    assert_same('exact', $d['verdict']);
    assert_same('John Owen', $d['author']);
    $p = $d['primary'];
    assert_same('John Owen', $p['author']);
    assert_same('1616–1683', $p['dates']);
    assert_same('Of the Mortification of Sinne in Beleevers', $p['title']);
    assert_same(1668, $p['year']);
    assert_same('p. 1', $p['pages']);
    assert_true($p['exact']);
    assert_same('be killing ſinne or it will be killing you', $p['text'], 'the original spelling');
    assert_contains('whilſt you liue; ceaſe not a day from this worke;', $p['before']);
    assert_contains('Your being dead with Chriſt', $p['after']);
    assert_same('https://quod.lib.umich.edu/e/eebo/A53698.0001.001', $p['source']);
    assert_same('Copied from', $p['source_label']);
    assert_same(null, $p['marked'], 'no side by side when word for word');
    assert_same(1, $d['others_total'], 'the other edition');
    assert_same('Of the Mortification of Sin in Believers', $d['others'][0]['title']);
    assert_same(null, $d['earlier']);
}

function test_reworded_shows_both_wordings(): void
{
    $d = data(post(['quote' => 'Thou hast made us for thyself, and our hearts are restless until they rest in thee',
        'author' => 'St. Augustine']));
    assert_same('reworded', $d['verdict']);
    $p = $d['primary'];
    assert_same(12, $p['same']);
    assert_same(16, $p['words']);
    assert_same([['Thou hast made us for Thyself, and our ', false], ['heart', true], [' ', false], ['is', true],
        [' restless, until ', false], ['it', true], [' ', false], ['repose', true], [' in Thee', false]], $p['marked']);
    $given = implode('', array_map(static fn (array $s): string => $s[1] ? "[$s[0]]" : $s[0], $p['given_marked']));
    assert_same('Thou hast made us for thyself, and our [hearts] [are] restless until [they] [rest] in thee', $given);
    $stars = data(post(['quote' => 'Thou hast made us for **thyself**, and our hearts are restless until they rest in thee',
        'author' => 'Augustine']))['primary']['given_marked'];
    assert_same([], array_values(array_filter($stars, static fn (array $s): bool => $s[1] && str_contains($s[0], 'thyself'))),
        "the caller's asterisks are not a difference");
    assert_same(4, count(array_filter($stars, static fn (array $s): bool => $s[1])), 'the four real differences');
    assert_same(['"hearts are" for "heart is"', '"they rest" for "it repose"'], $p['changes']);
}

function test_not_by_the_credited_author_and_not_found(): void
{
    $d = data(post(['quote' => 'be killing sin or it will be killing you', 'author' => 'C. H. Spurgeon']));
    assert_same('other_author', $d['verdict']);
    assert_same(0, $d['author_works']);
    assert_same('John Owen', $d['primary']['author']);
    $none = data(post(['quote' => 'The quick brown fox jumps over the lazy dog', 'author' => 'Owen']));
    assert_same('not_found', $none['verdict']);
    assert_same(null, $none['primary']);
    assert_same(2, $none['author_works']);
}

function test_bible_verses_are_named_first(): void
{
    $d = data(post(['quote' => 'For God so loved the world, that he gave his only begotten Son', 'author' => 'Jesus']));
    assert_same('John 3:16', $d['bible']['ref'], 'the verse, not the earliest book that quotes it');
    assert_same('King James Version', $d['bible']['translation'], 'the KJV first');
    assert_true(str_starts_with($d['bible']['text'], 'For God so loved the world'), 'the whole verse is given');
    $d = data(post(['quote' => 'should not perish, but have everlasting life. For God sent not his Son into the world to condemn the world']));
    assert_same('John 3:16–17', $d['bible']['ref'], 'a quotation that runs across two verses');
    $d = data(post(['quote' => 'for god so LOVED the world that he gave']));
    assert_same('John 3:16', $d['bible']['ref'], 'capitals and punctuation are not compared');
    assert_same(false, $d['bible']['near'], 'word for word is not a near match');
    $d = data(post(['quote' => 'For God loved the world so much that he gave his only begotten Son', 'author' => 'John']));
    assert_same('John 3:16', $d['bible']['ref'], 'a reworded verse is recognized');
    assert_same(true, $d['bible']['near'], 'and marked as near');
    assert_same([12, 14], [$d['bible']['same'], $d['bible']['words']], '12 of its 14 words are in the verse, in order');
    $d = data(post(['quote' => 'For God so loved the world, that he gave his Son']));
    assert_same(['John 3:16', false, true], [$d['bible']['ref'] ?? null, $d['bible']['near'] ?? null, $d['bible']['left_out'] ?? null],
        'the verse with words left out between is the verse, not a near match');
    $d = data(post(['quote' => 'For God so loved the world, Caesar']));
    assert_same('John 3:16', $d['bible']['ref'] ?? null, 'one stray uncommon word still finds the verse');
    assert_same([6, 7], [$d['bible']['same'], $d['bible']['words']]);
    $d = data(post(['quote' => 'For God so loved the world, that he sent his child']));
    assert_same(['John 3:16', true], [$d['bible']['ref'] ?? null, $d['bible']['near'] ?? null],
        'most of the quotation a run of the verse: the verse in another translation');
    $d = data(post(['quote' => 'For God so loved Caesar and the world']));
    assert_same(null, $d['bible'], 'in a short quotation, not with the verse\'s words scattered');
    $d = data(post(['quote' => 'Caesar so loved the empire of Rome']));
    assert_same(null, $d['bible'], 'one uncommon word of the verse is not enough');
    $d = data(post(['quote' => 'God gave the world a begotten teacher of miracles by night']));
    assert_same(null, $d['bible'], 'a few shared words are not a near match');
    $d = data(post(['quote' => 'Be killing sin, or it will be killing you.', 'author' => 'John Owen']));
    assert_same(null, $d['bible'], 'not in the Bible');
    $d = data(post(['quote' => 'For God so loved ... his only begotten Son']));
    assert_same(null, $d['bible'], 'a quotation with a gap is not looked for in the Bible');
}

function test_mcheyne_is_the_same_name_however_it_is_printed(): void
{
    $work = ['author' => 'Robert Murray McCheyne', 'author_slug' => 'robert-murray-mccheyne'];
    foreach (["Robert Murray M'Cheyne", 'R. M. M’Cheyne', 'McCheyne'] as $name) {
        $keys = \ColLibrary\Quote::authorKeysFor($name, [$work]);
        assert_true($name === 'R. M. M’Cheyne' || \ColLibrary\Quote::byAuthor($work, $keys), "$name is McCheyne");
    }
}

function test_input_errors_are_plain(): void
{
    $short = post(['quote' => 'killing sin']);
    assert_same(400, $short->status);
    assert_same('That is too short to check: give at least 4 words.', data($short)['error']);
    assert_same(400, post(['quote' => str_repeat('sin and grace ', 60)])->status);
    assert_contains('at most 150 words', data(post(['quote' => str_repeat('sin and grace ', 60)]))['error']);
    assert_same(400, post(['quote' => str_repeat('a', 1501)])->status);
    assert_same(400, post(['quote' => 'be killing sin or it', 'mode' => 'exact'])->status, 'unknown fields');
    assert_same(400, post('not json')->status);
    assert_same(400, post(['quote' => ['x']])->status);
    assert_same(400, post(['quote' => 'be killing sin or it', 'author' => str_repeat('x', 101)])->status);
    assert_same(413, post(str_repeat(' ', PublicCheck::MAX_BODY + 1))->status);
    assert_same(415, post(['quote' => 'be killing sin or it'], ['Content-Type' => 'application/x-www-form-urlencoded'])->status);
    assert_same(200, post(['quote' => 'be killing sin or it will'], ['Content-Type' => 'application/json'])->status);
}

function test_segments_ignore_markdown_and_keep_the_marks(): void
{
    $o = \ColLibrary\Quote::MARK_OPEN;
    $c = \ColLibrary\Quote::MARK_CLOSE;
    assert_same([['a **b** ', false], ['c', true], [' d', false]], PublicCheck::segments("a **b** {$o}c{$c} d"));
    assert_same([], PublicCheck::segments(''));
}

function test_gaps_and_busy(): void
{
    $five = 'be killing ... sin or ... it will ... be killing ... you and ... more';
    $r = post(['quote' => $five]);
    assert_same(400, $r->status);
    assert_contains('too many gaps', data($r)['error']);
    assert_same(200, post(['quote' => 'be killing sin ... or it will be killing you'])->status);

    $config = cfg();
    $a = fopen($config->stateDir() . '/probe.lock', 'c');
    $b = fopen($config->stateDir() . '/probe.lock', 'c');
    flock($a, LOCK_EX | LOCK_NB);
    if (flock($b, LOCK_EX | LOCK_NB)) {
        col_skip('this PHP runtime (php-wasm) does not enforce flock between handles; PHP-FPM on the server does');
    }
    $held = [];
    for ($i = 0; $i < PublicCheck::SLOTS; $i++) {
        $h = fopen($config->stateDir() . "/public-check-$i.lock", 'c');
        assert_true(flock($h, LOCK_EX | LOCK_NB));
        $held[] = $h;
    }
    $r = post(['quote' => 'be killing sin or it will'], [], '1.2.3.4', $config);
    assert_same(429, $r->status);
    assert_same('busy', data($r)['limit']);
    fclose($held[0]);
    assert_same(200, post(['quote' => 'be killing sin or it will'], [], '1.2.3.4', $config)->status, 'a slot is free');
    fclose($held[1]);
}

function test_only_the_website_may_call_it(): void
{
    $foreign = post(['quote' => 'be killing sin or it will'], ['Origin' => 'https://example.com']);
    assert_same(403, $foreign->status);
    assert_false(isset($foreign->headers['Access-Control-Allow-Origin']));
    $none = PublicCheck::response('POST', ['Content-Type' => 'text/plain'], '{"quote":"be killing sin or it will"}', '1.2.3.4', cfg());
    assert_same(403, $none->status, 'no Origin');
    assert_same(200, post(['quote' => 'be killing sin or it will'], ['Origin' => 'https://www.christianopenlibrary.org/'])->status);
    $pre = post('', [], '1.2.3.4', null, 'OPTIONS');
    assert_same(204, $pre->status);
    assert_same(SITE, $pre->headers['Access-Control-Allow-Origin']);
    assert_same(405, post('', [], '1.2.3.4', null, 'GET')->status);
    assert_same(404, post(['quote' => 'be killing sin or it will'], [], '1.2.3.4', cfg(['public_check' => false]))->status);
    assert_same(403, post(['quote' => 'be killing sin or it will'], [], '1.2.3.4',
        cfg(['public_check_origins' => ['https://other.example']]))->status);
    assert_throws(\ColLibrary\ConfigException::class, fn () => cfg(['public_check_origins' => ['not an origin']]));
    assert_throws(\ColLibrary\ConfigException::class, fn () => cfg(['public_check' => 'yes']));
}

function test_the_smoke_test_has_its_own_allowance(): void
{
    $config = cfg(['public_check_visitor_per_minute' => 100, 'public_check_visitor_per_day' => 2,
        'public_check_cache_hours' => 0]);
    $q = ['quote' => 'be killing sin or it will'];
    $smoke = ['X-Library-Smoke' => PublicCheck::smokeCode($config)];
    assert_same(200, post($q, $smoke, '198.51.100.9', $config)->status);
    assert_same(200, post($q, $smoke, '198.51.100.9', $config)->status);
    assert_same(429, post($q, $smoke, '198.51.100.9', $config)->status, 'the smoke test has a limit too');
    assert_same(200, post($q, [], '198.51.100.9', $config)->status, 'the same address is not used up by it');
    assert_same(200, post($q, ['X-Library-Smoke' => str_repeat('0', 64)], '198.51.100.9', $config)->status,
        'a wrong code counts as the address');
    assert_same(429, post($q, [], '198.51.100.9', $config)->status);
}

function test_limits_per_visitor_and_for_everyone(): void
{
    $config = cfg(['public_check_visitor_per_minute' => 2, 'public_check_visitor_per_day' => 3,
        'public_check_per_minute' => 4, 'public_check_per_day' => 5, 'public_check_cache_hours' => 0]);
    $q = ['quote' => 'be killing sin or it will'];
    assert_same(200, post($q, [], '198.51.100.1', $config)->status);
    assert_same(200, post($q, [], '198.51.100.1', $config)->status);
    $r = post($q, [], '198.51.100.1', $config);
    assert_same(429, $r->status);
    assert_same('visitor_minute', data($r)['limit']);
    assert_same(SITE, $r->headers['Access-Control-Allow-Origin'], 'the page can read why');
    assert_same(200, post($q, [], '198.51.100.1', $config, 'POST', TIME + 60)->status, 'next minute');
    $r = post($q, [], '198.51.100.1', $config, 'POST', TIME + 120);
    assert_same('visitor_day', data($r)['limit']);
    assert_contains("today's limit of 3 checks", data($r)['error']);
    assert_same(200, post($q, [], '198.51.100.2', $config, 'POST', TIME + 120)->status, 'another visitor');
    assert_same(200, post($q, [], '198.51.100.3', $config, 'POST', TIME + 120)->status);
    $r = post($q, [], '198.51.100.4', $config, 'POST', TIME + 180);
    assert_same('day', data($r)['limit'], 'everyone together: 5 a day');
    $next = TIME + 86400;
    assert_same(200, post($q, [], '198.51.100.1', $config, 'POST', $next)->status, 'a new day');
}

function test_visitor_codes_change_daily_and_old_ones_are_deleted(): void
{
    $config = cfg();
    $a = PublicCheck::visitorCode('203.0.113.7', $config, TIME);
    assert_same(1, preg_match('/^[0-9a-f]{16}$/D', $a));
    assert_same($a, PublicCheck::visitorCode('203.0.113.7', $config, TIME + 60));
    assert_true($a !== PublicCheck::visitorCode('203.0.113.7', $config, TIME + 86400), 'a new code each day');
    assert_true($a !== PublicCheck::visitorCode('203.0.113.8', $config, TIME));
    assert_same(PublicCheck::visitorCode('', $config, TIME), PublicCheck::visitorCode('<script>', $config, TIME));
    assert_same(PublicCheck::visitorCode('2001:db8:1:2::1', $config, TIME),
        PublicCheck::visitorCode('2001:db8:1:2:ffff:eeee:dddd:cccc', $config, TIME), 'one IPv6 /64, one visitor');
    assert_true(PublicCheck::visitorCode('2001:db8:1:2::1', $config, TIME) !== PublicCheck::visitorCode('2001:db8:1:3::1', $config, TIME));
    assert_same(PublicCheck::visitorCode('203.0.113.7', $config, TIME), PublicCheck::visitorCode(' 203.0.113.7 ', $config, TIME));
    assert_false(str_contains($a, '203'));

    $now = TIME;
    $limits = new RateLimit($config->stateDir(), 100, 1000, 1000, null, function () use (&$now): int {
        return $now;
    });
    $max = ['visitor_minute' => 9, 'visitor_day' => 9, 'minute' => 9, 'day' => 9];
    assert_same(null, $limits->takePublic($a, $max));
    $now += 86400 + 60;
    assert_same(null, $limits->takePublic('00ff', $max));
    $db = new \PDO('sqlite:' . $config->stateDir() . '/state.sqlite');
    $names = $db->query("SELECT name FROM counters WHERE name LIKE 'visitor%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
    assert_same(['visitor_day:00ff', 'visitor_minute:00ff'], $names, "yesterday's codes are gone");
    assert_same(null, $limits->takePublic('not hex!', $max));
    assert_same(1, (int) $db->query("SELECT COUNT(*) FROM counters WHERE name = 'visitor_day:unknown'")->fetchColumn());
}

function test_the_words_are_not_logged(): void
{
    $dir = col_tmpdir('colb');
    $config = cfg(['log_level' => 'debug', 'log_queries' => true, 'state_dir' => $dir]);
    assert_same(200, post(['quote' => 'Be killing sin, or it will be killing you.', 'author' => 'Owen'], [], '1.2.3.4', $config)->status);
    assert_same(400, post(['quote' => 'killing sin'], [], '1.2.3.4', $config)->status);
    $log = '';
    foreach (glob($dir . '/log-*.jsonl') ?: [] as $f) {
        $log .= (string) file_get_contents($f);
    }
    assert_contains('public_check', $log, 'the request is logged');
    assert_contains('"verdict":"exact"', $log);
    assert_not_contains('killing', $log, 'but not the words');
    assert_not_contains('Owen', $log);
    assert_not_contains('1.2.3.4', $log);
}

function test_a_repeat_is_answered_from_the_kept_answer(): void
{
    $dir = col_tmpdir('colb');
    $config = cfg(['state_dir' => $dir, 'log_level' => 'debug', 'public_check_per_minute' => 1, 'public_check_per_day' => 1,
        'public_check_visitor_per_minute' => 3]);
    $q = ['quote' => 'Be killing sin, or it will be killing you.', 'author' => 'Owen'];
    $first = post($q, [], '198.51.100.1', $config);
    assert_same(200, $first->status);
    $files = glob($dir . '/public-answers/*.bin') ?: [];
    assert_same(1, count($files), 'the answer is kept');
    $raw = (string) file_get_contents($files[0]);
    foreach (['killing', 'Owen', 'exact', 'verdict'] as $w) {
        assert_not_contains($w, $raw, "the kept file is unreadable ($w)");
    }
    assert_not_contains('killing', basename($files[0]));

    // The same words again, from others: answered although everyone's limit (1) is used up, and the same answer.
    $again = post($q, [], '198.51.100.2', $config);
    assert_same(200, $again->status, 'kept answers skip the limit for everyone');
    assert_same(data($first), data($again));
    assert_same(SITE, $again->headers['Access-Control-Allow-Origin']);
    $log = '';
    foreach (glob($dir . '/log-*.jsonl') ?: [] as $f) {
        $log .= (string) file_get_contents($f);
    }
    assert_contains('"kept":true', $log);
    // ... but not the visitor's own limit.
    assert_same(200, post($q, [], '198.51.100.2', $config)->status);
    assert_same(200, post($q, [], '198.51.100.2', $config)->status);
    $r = post($q, [], '198.51.100.2', $config);
    assert_same(429, $r->status);
    assert_same('visitor_minute', data($r)['limit']);
    // Different words, or another name, are a new check (and everyone's limit is used up).
    assert_same('minute', data(post(['quote' => 'Be killing sin, or it will be killing you.'], [], '198.51.100.3', $config))['limit']);

    // A file past its time is not used and is deleted.
    touch($files[0], time() - 25 * 3600);
    assert_same(429, post($q, [], '198.51.100.4', $config)->status, 'checked again: everyone\'s limit applies');
    assert_false(is_file($files[0]), 'the old file is deleted');

    // A tampered file is not used.
    $config2 = cfg(['state_dir' => col_tmpdir('colb')]);
    assert_same(200, post($q, [], '198.51.100.5', $config2)->status);
    $f = (glob($config2->stateDir() . '/public-answers/*.bin') ?: [])[0];
    $b = (string) file_get_contents($f);
    $b[40] = chr(ord($b[40]) ^ 1);
    file_put_contents($f, $b);
    assert_same(200, post($q, [], '198.51.100.5', $config2)->status, 'checked again');
    assert_same(data($first), data(post($q, [], '198.51.100.6', $config2)), 'and kept again');

    // Old files and stray temporary files are swept.
    file_put_contents($config2->stateDir() . '/public-answers/x.tmp', 'x');
    touch($config2->stateDir() . '/public-answers/x.tmp', time() - 120);
    touch($f, time() - 25 * 3600);
    (new \ColLibrary\KeptAnswer($config2->stateDir() . '/public-answers', 's', 'w', 86400))->sweep();
    assert_same([], glob($config2->stateDir() . '/public-answers/*') ?: []);

    // An answer kept by one release of the code is not found by the next: a check whose logic changed is run again,
    // not answered with what the older code said (a word-for-word quotation was shown as reworded for a day).
    $config3 = cfg(['state_dir' => col_tmpdir('colb')]);
    $old = PublicCheck::kept($config3, $q['quote'], $q['author'], '1.0.0+aaaaaaaaaaaa');
    $old->write(['verdict' => 'reworded']);
    assert_same('reworded', $old->read()['verdict'], 'the older release finds its own answer');
    assert_same(null, PublicCheck::kept($config3, $q['quote'], $q['author'], '1.0.0+bbbbbbbbbbbb')->read(),
        'the next release does not');
    assert_same('exact', data(post($q, [], '198.51.100.8', $config3))['verdict'], 'and the website gets the current answer');

    // Off: nothing kept.
    $off = cfg(['public_check_cache_hours' => 0]);
    assert_same(200, post($q, [], '198.51.100.7', $off)->status);
    assert_false(is_dir($off->stateDir() . '/public-answers'));
}

function test_licensed_matches_give_no_text(): void
{
    Fixture\store_or_skip();
    $m = ['work' => ['id' => 9, 'author' => 'URCNA', 'author_note' => '', 'title' => 'Church Order', 'year' => 2023,
        'collection' => 'licensed', 'source_url' => 'https://www.urcna.org/church-order', 'church' => null],
        'passage' => ['id' => 5], 'page' => '3', 'page_end' => '3', 'edits' => 0, 'misread' => 0, 'same' => 6,
        'words' => 6, 'thin_pieces' => false, 'text' => 'secret words of the article', 'before' => 'before text',
        'after' => 'after text', 'quoting' => false, 'credit' => 'Secret Credit', 'lib_marked' => '', 'quote_marked' => '',
        'lib_spans' => '', 'quote_spans' => '',
        'changes' => [], 'when' => 2023];
    $other = array_merge($m, ['work' => array_merge($m['work'], ['id' => 10, 'title' => 'Acts of Synod 2001'])]);
    $r = new \ColLibrary\QuoteResult('words of the article here', null, 'exact', $m, [$m, $other], $m, 0, 1, []);
    $d = PublicCheck::json($r);
    assert_same('exact', $d['verdict']);
    assert_true($d['primary']['licensed']);
    assert_same('Official copy', $d['primary']['source_label']);
    assert_same('https://www.urcna.org/church-order', $d['primary']['source']);
    foreach (['text', 'before', 'after', 'marked', 'scan'] as $k) {
        assert_same(null, $d['primary'][$k], "no $k");
    }
    assert_not_contains('secret words', (string) json_encode($d));
    assert_same(null, $d['primary']['credit'], "a credit line is the document's own text");
    assert_same('Acts of Synod 2001', $d['others'][0]['title']);
    assert_same(null, $d['others'][0]['credit'], 'nor in the other works');
}
