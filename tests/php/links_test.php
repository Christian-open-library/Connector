<?php
/**
 * Tests for src/Links.php (personal links) and how Http, RateLimit and Log use them.
 */

namespace ColLibraryTest\Links {

use ColLibrary\Config;
use ColLibrary\ConfigException;
use ColLibrary\Context;
use ColLibrary\Http;
use ColLibrary\Links;
use ColLibrary\Mcp;
use ColLibrary\RateLimit;
use ColLibrary\Response;

col_src('Context', 'Config', 'Log', 'RateLimit', 'Mcp', 'Http', 'Links');

const OWNER = 'Own3r_-abcdefghijklmnopqrstuvwxyz0123456789ABCD';
const LINK_A = 'LinkA_-abcdefghijklmnopqrstuvwxyz0123456789ABCD';
const LINK_B = 'LinkB_-abcdefghijklmnopqrstuvwxyz0123456789ABCD';
const OTHER = 'Other_-abcdefghijklmnopqrstuvwxyz0123456789ABCD';
const PING = '{"jsonrpc":"2.0","id":1,"method":"ping"}';
const CALL = '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"echo_text","arguments":{"text":"hi"}}}';

/** Stands in for ColLibrary\Tools. */
final class StubTools
{
    public static function definitions(): array
    {
        return [[
            'name' => 'echo_text',
            'title' => 'Echo text',
            'description' => 'Returns its text.',
            'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string', 'description' => 'Text']],
                              'additionalProperties' => false],
            'annotations' => ['title' => 'Echo text', 'readOnlyHint' => true, 'destructiveHint' => false,
                              'idempotentHint' => true, 'openWorldHint' => false],
        ]];
    }

    public static function call(string $name, array $arguments, Context $ctx): array
    {
        return ['content' => [['type' => 'text', 'text' => 'echo: ' . ($arguments['text'] ?? '')]], 'isError' => false];
    }
}

function entry(string $id, string $key, int $perDay = 500, int $perMinute = 30): array
{
    return ['id' => $id, 'sha256' => hash('sha256', $key), 'calls_per_day' => $perDay, 'calls_per_minute' => $perMinute];
}

/** Writes links.json (raw text or a list of entries) into a new temp dir and returns its path. */
function links_file(array|string $links): string
{
    $path = col_tmpdir('links') . '/links.json';
    file_put_contents($path, is_string($links) ? $links : json_encode(['links' => $links]));
    return $path;
}

function config(?string $linksFile, array $extra = []): Config
{
    $dir = col_tmpdir('state');
    return Config::fromArray($extra + ['url_key' => OWNER, 'data_dir' => $dir, 'state_dir' => $dir]
        + ($linksFile !== null ? ['links_file' => $linksFile] : []));
}

function call(Config|string $config, string $key, string $body = PING): Response
{
    return Http::process('POST', $key, [], ['Content-Type' => 'application/json'], $body, $config, new Mcp(StubTools::class));
}

function test_owner_and_link_keys_work(): void
{
    $c = config(links_file([entry('la00001', LINK_A), entry('lb00002', LINK_B)]));
    foreach ([OWNER, LINK_A, LINK_B] as $key) {
        assert_same(200, call($c, $key)->status, 'key works');
    }
    foreach ([OTHER, 'short', '', LINK_A . 'x', hash('sha256', LINK_A)] as $key) {
        $r = call($c, $key);
        assert_same(404, $r->status, "refused: $key");
        assert_same('{}', $r->body);
    }
}

function test_without_links_only_the_owner_key_works(): void
{
    $c = config(null);
    assert_same(null, $c->linksPath(), 'no file path and no links_file');
    assert_same(404, call($c, LINK_A)->status);
    assert_same(200, call($c, OWNER)->status);
    $c = config(col_tmpdir('none') . '/links.json');   // named but missing
    assert_same(404, call($c, LINK_A)->status);
    assert_same(200, call($c, OWNER)->status);
}

function test_links_json_next_to_config_file_is_the_default(): void
{
    $dir = col_tmpdir('cfg');
    file_put_contents("$dir/config.php", "<?php return ['url_key' => '" . OWNER . "'];");
    file_put_contents("$dir/links.json", json_encode(['links' => [entry('la00001', LINK_A)]]));
    assert_same("$dir/links.json", Config::load("$dir/config.php")->linksPath());
    assert_same(200, call("$dir/config.php", LINK_A)->status);
    assert_same(404, call("$dir/config.php", LINK_B)->status);
}

function test_a_bad_links_file_refuses_every_link_but_not_the_owner(): void
{
    $good = entry('la00001', LINK_A);
    $cases = [
        'not json' => '{"links": [',
        'no list' => '{"links": {"a": 1}}',
        'not an object' => '[1, 2]',
        'bad id' => [['id' => 'x1'] + $good],
        'bad sha256' => [['sha256' => strtoupper($good['sha256'])] + $good],
        'string limit' => [['calls_per_day' => '500'] + $good],
        'zero limit' => [['calls_per_minute' => 0] + $good],
        'repeated id' => [$good, entry('la00001', LINK_B)],
        'repeated key' => [$good, ['id' => 'lb00002'] + $good],
    ];
    foreach ($cases as $name => $links) {
        $before = strlen(col_error_log());
        $c = config(links_file($links));
        assert_same(404, call($c, LINK_A)->status, "$name: link refused");
        assert_same(200, call($c, OWNER)->status, "$name: owner still works");
        assert_contains('personal links refused', substr(col_error_log(), $before), "$name: reason logged");
    }
    $big = links_file(str_repeat(' ', Links::MAX_BYTES + 1));
    assert_same([], Links::load($big), 'oversized file refused');
}

function test_links_file_must_be_an_absolute_path(): void
{
    assert_throws(ConfigException::class, fn () => config('links.json'));
    assert_throws(ConfigException::class, fn () => config(null, ['links_file' => 5]));
}

function test_link_limits_are_separate_from_the_shared_ones(): void
{
    $now = 1_800_000_040;
    $clock = function () use (&$now): int {
        return $now;
    };
    $dir = col_tmpdir('rl');
    $a = new RateLimit($dir, 100, 1000, 0, null, $clock, ['id' => 'la00001', 'calls_per_day' => 3, 'calls_per_minute' => 2]);
    $b = new RateLimit($dir, 100, 1000, 0, null, $clock, ['id' => 'lb00002', 'calls_per_day' => 3, 'calls_per_minute' => 2]);
    $owner = new RateLimit($dir, 100, 1000, 0, null, $clock);
    assert_same(null, $a->takeCall());
    assert_same(null, $a->takeCall());
    assert_contains('this link allows at most 2 tool calls per minute', $a->takeCall());
    assert_same(null, $b->takeCall(), 'another link has its own counters');
    assert_same(null, $owner->takeCall(), 'the owner has no per-link limit');
    $now += 60;
    assert_same(null, $a->takeCall());
    assert_contains('this link allows at most 3 tool calls per day', $a->takeCall());
    assert_same(5, $owner->usage()['calls_day'], 'link calls count toward the shared daily total');
    $now += 86400;
    assert_same(null, $a->takeCall(), 'a new UTC day resets the link');
}

function test_a_link_gets_a_fifth_of_the_voyage_budget(): void
{
    $dir = col_tmpdir('rl');
    $clock = fn () => 1_800_000_000;
    $a = new RateLimit($dir, 100, 1000, 1000, null, $clock, ['id' => 'la00001', 'calls_per_day' => 500, 'calls_per_minute' => 30]);
    $b = new RateLimit($dir, 100, 1000, 1000, null, $clock, ['id' => 'lb00002', 'calls_per_day' => 500, 'calls_per_minute' => 30]);
    $owner = new RateLimit($dir, 100, 1000, 1000, null, $clock);
    assert_same(true, $a->reserveVoyage(150));
    assert_same(true, $a->voyageAllowed(50));
    assert_same(false, $a->voyageAllowed(51), 'the pre-check knows the link share');
    assert_same(false, $a->reserveVoyage(51), 'a link may reserve at most 1000 / 5 tokens a day');
    $a->adjustVoyage(-100);                     // used 50 of the 150 reserved
    assert_same(true, $a->reserveVoyage(150), 'the correction frees the link share too');
    assert_same(true, $b->reserveVoyage(200), 'another link has its own share');
    assert_same(true, $owner->reserveVoyage(400), 'the owner has only the global budget');
    assert_same(800, $owner->usage()['voyage_tokens'], 'link reservations count toward the global total');
    assert_same(false, $owner->reserveVoyage(201), 'the global budget still applies');
}

function test_shared_limit_still_applies_to_links(): void
{
    $rl = new RateLimit(col_tmpdir('rl'), 1, 1000, 0, null, fn () => 1_800_000_000,
        ['id' => 'la00001', 'calls_per_day' => 500, 'calls_per_minute' => 30]);
    assert_same(null, $rl->takeCall());
    assert_contains('this library answers at most 1 tool calls per minute', $rl->takeCall());
}

function test_link_limit_reaches_the_ai_as_a_tool_error(): void
{
    $c = config(links_file([entry('la00001', LINK_A, 500, 1)]));
    assert_contains('echo: hi', call($c, LINK_A, CALL)->body);
    $r = call($c, LINK_A, CALL);
    assert_same(200, $r->status);
    assert_contains('this link allows at most 1 tool calls per minute', $r->body);
    assert_contains('"isError":true', $r->body);
    assert_contains('echo: hi', call($c, OWNER, CALL)->body, 'the owner is not limited by the link');
}

function test_link_minute_counters_are_pruned(): void
{
    $now = 1_800_000_000;
    $dir = col_tmpdir('rl');
    $rl = new RateLimit($dir, 10, 1000, 0, null, function () use (&$now): int {
        return $now;
    }, ['id' => 'la00001', 'calls_per_day' => 500, 'calls_per_minute' => 30]);
    for ($i = 0; $i < 5; $i++) {
        assert_same(null, $rl->takeCall());
        $now += 60;
    }
    $db = new \PDO("sqlite:$dir/state.sqlite");
    assert_same(1, (int) $db->query("SELECT count(*) FROM counters WHERE name = 'link_minute:la00001'")->fetchColumn());
    assert_same(5, (int) $db->query("SELECT value FROM counters WHERE name = 'link_day:la00001'")->fetchColumn());
}

function test_log_names_the_link_id_never_the_key(): void
{
    $c = config(links_file([entry('la00001', LINK_A)]), ['log_level' => 'info']);
    call($c, LINK_A, CALL);
    call($c, OWNER, CALL);
    $text = file_get_contents(glob($c->stateDir() . '/log-*.jsonl')[0]);
    assert_not_contains(LINK_A, $text);
    assert_not_contains(hash('sha256', LINK_A), $text);
    $events = array_map(fn ($l) => json_decode($l, true), explode("\n", trim($text)));
    assert_same(['la00001', 'owner'], array_column($events, 'link'));
}

}
