<?php
/**
 * Tests for src/PublicSearch.php (POST /search, the website's library search) on the fixture build
 * (tests/php/fixture: Owen in two editions, Augustine 1886, Beza; fake Voyage).
 */

declare(strict_types=1);

namespace ColLibraryTest\PublicSearch;

use ColLibrary\Config;
use ColLibrary\PublicCheck;
use ColLibrary\PublicSearch;
use ColLibrary\Response;
use ColLibraryTest\Fixture;

require_once __DIR__ . '/fixture/testlib.php';
col_src('Mcp', 'Bible', 'KeptAnswer', 'PublicCheck', 'PublicSearch', 'Modern', 'ModernList');

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
    return PublicSearch::response($method, $headers, $body, $ip, $config, fn (): int => $now ?? TIME);
}

function data(Response $r): array
{
    return json_decode((string) $r->body, true, 512, JSON_THROW_ON_ERROR);
}

/** A copy of the fixture build in which Augustine's Confessions (work 103) is on the licensed shelf. */
function licensed_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $dir = col_tmpdir('pslicensed');
    foreach (['library.sqlite', 'vectors.bin', 'ids.bin', 'manifest.json', 'config.php'] as $f) {
        if (is_file(Fixture\DIR . "/$f")) {
            copy(Fixture\DIR . "/$f", "$dir/$f");
        }
    }
    $db = new \PDO("sqlite:$dir/library.sqlite");
    $db->exec("UPDATE works SET collection = 'licensed', license = 'Used by permission' WHERE id = 103");
    $db = null;
    return $dir;
}

function test_a_quotation_finds_both_editions_earliest_first(): void
{
    $r = post(['q' => 'be killing sin or it will be killing you']);
    assert_same(200, $r->status, (string) $r->body);
    assert_same(SITE, $r->headers['Access-Control-Allow-Origin']);
    assert_same('no-store', $r->headers['Cache-Control']);
    $d = data($r);
    assert_same(2, $d['exact']);
    assert_same('Word for word in 2 books, earliest first.', $d['note']);
    $first = $d['hits'][0];
    assert_same('P10100003', $first['ref']);
    assert_same('John Owen', $first['author']);
    assert_same('1616–1683', $first['dates']);
    assert_same(1668, $first['year'], 'the dated 1668 edition before the undated one');
    assert_same('p. 1', $first['page']);
    assert_same('https://quod.lib.umich.edu/e/eebo/A53698.0001.001', $first['link']);
    assert_false($first['scan']);
    assert_contains('[[be killing ſinne or it will be killing you]]', $first['text'], 'old spelling marked as printed');
    assert_same('Of the Mortification of Sin in Believers', $d['hits'][1]['title']);
    assert_contains('[[be killing sin or it will be killing you]]', $d['hits'][1]['text']);
    foreach ($d['hits'] as $h) {
        assert_same(['ref', 'author', 'dates', 'title', 'year', 'page', 'link', 'scan', 'text', 'cut_start', 'cut_end'],
            array_keys($h));
    }
}

function test_a_topic_marks_the_sentence_that_answers(): void
{
    $d = data(post(['q' => 'sufficiency of the Spirit']));
    assert_true($d['hits'] !== [], 'found');
    assert_false(isset($d['note']), 'not a quotation');
    $marked = array_filter($d['hits'], static fn (array $h): bool => str_contains($h['text'], '[['));
    assert_true($marked !== [], 'a sentence is marked');
    foreach ($d['hits'] as $h) {
        assert_same(substr_count($h['text'], '[['), substr_count($h['text'], ']]'));
        assert_true(substr_count($h['text'], '[[') <= 1);
    }
}

function test_licensed_documents_are_never_shown(): void
{
    $config = cfg(['data_dir' => licensed_dir()]);
    $d = data(post(['q' => 'our heart is restless until it repose in thee'], [], '203.0.113.7', $config));
    foreach ($d['hits'] as $h) {
        assert_true($h['author'] !== 'Augustine', 'the licensed work is left out');
        assert_not_contains('restless', $h['text']);
    }
    $open = data(post(['q' => 'our heart is restless until it repose in thee']));
    assert_same('Augustine', $open['hits'][0]['author'], 'shown when it is not on the licensed shelf');
    assert_contains('[[our heart is restless, until it repose in Thee]]', $open['hits'][0]['text'],
        'the phrase as printed, punctuation kept');
}

function test_marking_and_excerpts(): void
{
    $text = 'One two three. Four five six seven. Be killing sin or it will be killing you. Eight nine. Ten.';
    $span = PublicSearch::phraseSpan($text, ['be', 'killing', 'sin', 'or', 'it', 'will', 'be', 'killing', 'you'], null);
    assert_same('Be killing sin or it will be killing you', substr($text, $span[0], $span[1] - $span[0]));
    assert_same(null, PublicSearch::phraseSpan($text, ['killing', 'sin'], null), 'two words are a topic');
    $ex = PublicSearch::excerpt($text, $span);
    assert_same('One two three. Four five six seven. [[Be killing sin or it will be killing you]]. Eight nine. Ten.', $ex['text']);
    assert_false($ex['cut_start']);
    assert_false($ex['cut_end']);

    $long = str_repeat('Filler sentence about nothing in particular here. ', 20) . 'The sin is mortified daily by the Spirit. '
        . str_repeat('More filler words follow on and on. ', 20);
    $s = PublicSearch::bestSentence($long, ['sin', 'mortified', 'spirit'], null);
    assert_same('The sin is mortified daily by the Spirit.', substr($long, $s[0], $s[1] - $s[0]));
    $ex = PublicSearch::excerpt($long, $s);
    assert_contains('[[The sin is mortified daily by the Spirit.]]', $ex['text']);
    assert_true($ex['cut_start'] && $ex['cut_end']);
    assert_true(mb_strlen($ex['text']) <= PublicSearch::EXCERPT + 10, 'about EXCERPT characters');
    assert_same(null, PublicSearch::bestSentence($long, ['grace', 'glory', 'sin'], null), 'one shared word is too weak');
    $head = 'Only a Prayer-meeting! Brethren, the prayer-meeting is the pulse of the church.';
    $s = PublicSearch::bestSentence($head, ['prayer', 'meeting'], null);
    assert_same('Brethren, the prayer-meeting is the pulse of the church.', substr($head, $s[0], $s[1] - $s[0]),
        'not a running head');

    $runOn = str_repeat('word ', 300) . 'be killing sin or it will be killing you ' . str_repeat('word ', 300);
    $span = PublicSearch::phraseSpan(trim($runOn), ['be', 'killing', 'sin', 'or', 'it', 'will', 'be', 'killing', 'you'], null);
    $ex = PublicSearch::excerpt(trim($runOn), $span);
    assert_contains('[[be killing sin or it will be killing you]]', $ex['text']);
    assert_true(mb_strlen($ex['text']) < 600, 'a text without stops is cut at words');
    assert_true(!str_starts_with($ex['text'], 'ord') && !str_ends_with($ex['text'], 'wor'), 'whole words');

    assert_same('a [b] c', PublicSearch::clean("a [[b]]  *c*"), "the passage's own brackets cannot pass for marks");
    assert_same('Text without page marks.', PublicSearch::clean('Text [p. 23] without page marks.'));
}

function test_input_errors(): void
{
    assert_same(400, post(['q' => ''])->status);
    assert_same('Type a question, a topic or a line you remember.', data(post(['q' => ' ']))['error']);
    assert_same(400, post(['q' => 'grace', 'mode' => 'exact'])->status, 'unknown fields');
    assert_same(400, post('not json')->status);
    assert_same(400, post(['q' => ['x']])->status);
    assert_same(400, post(['q' => str_repeat('a ', 260)])->status, 'over 500 characters');
    assert_same(413, post(str_repeat(' ', PublicSearch::MAX_BODY + 1))->status);
    assert_same(415, post(['q' => 'grace'], ['Content-Type' => 'application/x-www-form-urlencoded'])->status);
    assert_same(200, post(['q' => 'grace'], ['Content-Type' => 'application/json'])->status);
    assert_same(400, post(['q' => '!!! ???'])->status, 'no words');
}

function test_only_the_website_may_call_it(): void
{
    $foreign = post(['q' => 'grace'], ['Origin' => 'https://example.com']);
    assert_same(403, $foreign->status);
    assert_false(isset($foreign->headers['Access-Control-Allow-Origin']));
    assert_same(403, PublicSearch::response('POST', ['Content-Type' => 'text/plain'], '{"q":"grace"}', '1.2.3.4', cfg())->status);
    $pre = post('', [], '1.2.3.4', null, 'OPTIONS');
    assert_same(204, $pre->status);
    assert_same(SITE, $pre->headers['Access-Control-Allow-Origin']);
    assert_same(405, post('', [], '1.2.3.4', null, 'GET')->status);
    assert_same(404, post(['q' => 'grace'], [], '1.2.3.4', cfg(['public_search' => false]))->status);
    assert_same(200, post(['q' => 'grace'], [], '1.2.3.4', cfg(['public_check' => false]))->status, 'apart from the checker');
    assert_throws(\ColLibrary\ConfigException::class, fn () => cfg(['public_search' => 'yes']));
    assert_throws(\ColLibrary\ConfigException::class, fn () => cfg(['public_search_voyage_tokens_per_day' => 0]));
}

function test_limits_say_searches_and_the_smoke_test_has_its_own(): void
{
    $config = cfg(['public_check_visitor_per_minute' => 2, 'public_check_visitor_per_day' => 3,
        'public_check_cache_hours' => 0]);
    $q = ['q' => 'grace'];
    assert_same(200, post($q, [], '198.51.100.1', $config)->status);
    assert_same(200, post($q, [], '198.51.100.1', $config)->status);
    $r = post($q, [], '198.51.100.1', $config);
    assert_same(429, $r->status);
    assert_same('visitor_minute', data($r)['limit']);
    assert_contains('a lot of searches', data($r)['error']);
    assert_same(SITE, $r->headers['Access-Control-Allow-Origin']);
    assert_same(200, post($q, [], '198.51.100.1', $config, 'POST', TIME + 60)->status);
    $r = post($q, [], '198.51.100.1', $config, 'POST', TIME + 120);
    assert_contains("today's limit of 3 searches", data($r)['error']);
    $smoke = ['X-Library-Smoke' => PublicCheck::smokeCode($config)];
    assert_same(200, post($q, $smoke, '198.51.100.1', $config, 'POST', TIME + 180)->status, 'the smoke test counts apart');
}

function test_busy(): void
{
    $config = cfg();
    $a = fopen($config->stateDir() . '/probe.lock', 'c');
    $b = fopen($config->stateDir() . '/probe.lock', 'c');
    flock($a, LOCK_EX | LOCK_NB);
    if (flock($b, LOCK_EX | LOCK_NB)) {
        col_skip('this PHP runtime (php-wasm) does not enforce flock between handles; PHP-FPM on the server does');
    }
    $held = [];
    for ($i = 0; $i < PublicSearch::SLOTS; $i++) {
        $h = fopen($config->stateDir() . "/public-search-$i.lock", 'c');
        assert_true(flock($h, LOCK_EX | LOCK_NB));
        $held[] = $h;
    }
    $r = post(['q' => 'grace'], [], '1.2.3.4', $config);
    assert_same(429, $r->status);
    assert_same('busy', data($r)['limit']);
    fclose($held[0]);
    assert_same(200, post(['q' => 'grace'], [], '1.2.3.4', $config)->status);
}

function test_the_words_are_not_logged_and_repeats_are_kept(): void
{
    $dir = col_tmpdir('colb');
    $config = cfg(['log_level' => 'debug', 'log_queries' => true, 'state_dir' => $dir, 'public_check_per_minute' => 1,
        'public_check_per_day' => 1, 'public_check_visitor_per_minute' => 5]);
    $q = ['q' => 'be killing sin or it will be killing you'];
    $first = post($q, [], '1.2.3.4', $config);
    assert_same(200, $first->status);
    $log = '';
    foreach (glob($dir . '/log-*.jsonl') ?: [] as $f) {
        $log .= (string) file_get_contents($f);
    }
    assert_contains('public_search', $log);
    assert_not_contains('killing', $log, 'not the words');
    assert_not_contains('1.2.3.4', $log);
    $files = glob($dir . '/public-search-answers/*.bin') ?: [];
    assert_same(1, count($files), 'the answer is kept');
    assert_not_contains('killing', (string) file_get_contents($files[0]), 'sealed');
    $again = post($q, [], '198.51.100.2', $config);
    assert_same(200, $again->status, 'a kept answer skips the overall limit');
    assert_same(data($first), data($again));
    assert_same(429, post(['q' => 'grace'], [], '198.51.100.3', $config)->status, 'new words do not');
}
