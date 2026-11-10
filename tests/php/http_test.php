<?php
/**
 * Tests for src/Http.php (HTTP rules, and the WordPress glue against small WordPress stand-ins
 * defined at the end of this file), src/Config.php, src/Log.php and src/RateLimit.php.
 */

namespace ColLibraryTest\Http {

use ColLibrary\Config;
use ColLibrary\ConfigException;
use ColLibrary\Context;
use ColLibrary\Http;
use ColLibrary\Log;
use ColLibrary\Mcp;
use ColLibrary\RateLimit;
use ColLibrary\Response;

col_src('Context', 'Config', 'Log', 'RateLimit', 'Mcp', 'Http', 'Links');

const KEY = 'Zx9_-abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG';
const VOYAGE_KEY = 'pa-voyage-secret-0123456789';

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

function config(array $values = []): Config
{
    $dir = col_tmpdir('http');
    return Config::fromArray($values + ['url_key' => KEY, 'voyage_api_key' => VOYAGE_KEY, 'data_dir' => $dir, 'state_dir' => $dir]);
}

const PING = '{"jsonrpc":"2.0","id":1,"method":"ping"}';

/** Http::process with sensible defaults: a valid POST of PING with the right key. */
function call(array $o = []): Response
{
    return Http::process(
        $o['method'] ?? 'POST',
        $o['key'] ?? KEY,
        $o['query'] ?? [],
        ($o['headers'] ?? []) + ['Content-Type' => 'application/json'],
        $o['body'] ?? PING,
        array_key_exists('config', $o) ? $o['config'] : config(),
        new Mcp(StubTools::class),
        $o['webRoot'] ?? null,
    );
}

/** Writes a config file into a new temp dir and returns its path. */
function config_file(string $php): string
{
    $path = col_tmpdir('cfg') . '/config.php';
    file_put_contents($path, $php);
    return $path;
}

function rpc_error(Response $r): array
{
    $d = json_decode((string) $r->body, true, 512, JSON_THROW_ON_ERROR);
    assert_false(array_key_exists('id', $d), 'HTTP-level errors carry no id');
    return $d['error'];
}

// ------------------------------------------------------------------------------------------------
// HTTP rules

function test_valid_request_reaches_mcp(): void
{
    $r = call();
    assert_same(200, $r->status);
    assert_same('{"jsonrpc":"2.0","id":1,"result":{}}', $r->body);
    assert_same('application/json', $r->headers['Content-Type']);
    assert_same('no-store', $r->headers['Cache-Control']);
}

function test_wrong_key_is_404_with_empty_object(): void
{
    foreach ([substr(KEY, 0, -1) . 'H', strtolower(KEY), 'short', '', KEY . 'x'] as $key) {
        foreach (['POST', 'GET', 'DELETE'] as $method) {
            $r = call(['key' => $key, 'method' => $method]);
            assert_same(404, $r->status, "key '$key' $method");
            assert_same('{}', $r->body);
            assert_same('application/json', $r->headers['Content-Type']);
        }
    }
}

function test_missing_or_invalid_config_is_404(): void
{
    $cases = [
        col_tmpdir('nocfg') . '/config.php',                                   // missing
        config_file('<?php return "not an array";'),
        config_file('<?php return ["url_key" => "too-short", "data_dir" => "/x"];'),
        config_file('<?php return [' . "'url_key' => '" . KEY . "', 'nprobe' => 'many'];"),
        config_file('<?php this is not php'),
    ];
    foreach ($cases as $path) {
        $r = call(['config' => $path]);
        assert_same(404, $r->status, $path);
        assert_same('{}', $r->body);
        assert_contains("col-library-connector: ", col_error_log(), 'the reason goes to the PHP error log');
        assert_contains($path, col_error_log());
    }
    assert_contains('nprobe must be an integer from 1 to 65536', col_error_log());
    assert_not_contains(KEY, col_error_log());
}

function test_dirs_inside_web_root_are_refused(): void
{
    $root = col_tmpdir('www');
    $outside = col_tmpdir('data');
    mkdir("$root/wp-content");
    symlink("$root/wp-content", "$outside/link-into-root");
    $cases = [
        ['state_dir' => "$root/col-state", 'data_dir' => $outside],
        ['state_dir' => $outside, 'data_dir' => "$root/wp-content"],
        ['state_dir' => $outside, 'data_dir' => $root],
        ['state_dir' => $outside, 'data_dir' => "$outside/link-into-root"],   // symlink resolved
    ];
    foreach ($cases as $dirs) {
        $r = call(['config' => config($dirs), 'webRoot' => $root]);
        assert_same(404, $r->status, json_encode($dirs));
        assert_same('{}', $r->body);
    }
    assert_contains('is inside the web root', col_error_log());
    assert_same(200, call(['config' => config(['state_dir' => $outside, 'data_dir' => $outside]), 'webRoot' => $root])->status);
    assert_same(200, call(['config' => config(['state_dir' => "$root-sibling", 'data_dir' => $outside]), 'webRoot' => $root])->status,
        'a sibling whose name starts with the root name is outside');
    assert_true(Config::isInside('/srv/www/a/b', '/srv/www/'));
    assert_false(Config::isInside('/srv/www2', '/srv/www'));
    assert_true(Config::isInside('/srv/x/../www/a', '/srv/www'), '".." resolved');
    assert_false(Config::isInside('/srv/www/../x', '/srv/www'), '".." resolved');
    // A directory that does not exist yet, below a symlink that points into the root.
    assert_true(Config::isInside("$outside/link-into-root/not-yet/state", $root), 'symlinked parent resolved');
}

function test_config_file_is_loaded(): void
{
    $path = config_file("<?php\nreturn ['url_key' => '" . KEY . "', 'voyage_api_key' => '', 'max_calls_per_minute' => 5];\n");
    $r = call(['config' => $path]);
    assert_same(200, $r->status);
    $c = Config::load($path);
    assert_same(dirname($path), $c->stateDir(), 'state_dir defaults to the config directory');
    assert_same(dirname($path) . '/current', $c->dataDir(), 'data_dir defaults to <config dir>/current');
    assert_same(5, $c->maxCallsPerMinute());
}

function test_origin_check(): void
{
    foreach (['https://claude.ai', 'https://claude.ai/', 'https://CLAUDE.ai', 'https://claude.com', 'https://www.claude.ai',
              'https://claude.ai:443'] as $origin) {
        assert_same(200, call(['headers' => ['Origin' => $origin]])->status, "allowed $origin");
    }
    foreach (['https://evil.example', 'null', 'http://claude.ai', 'https://claude.ai.evil.example', 'https://claude.ai/x',
              'file://', ''] as $origin) {
        $r = call(['headers' => ['Origin' => $origin]]);
        assert_same(403, $r->status, "rejected '$origin'");
        assert_contains('Origin', rpc_error($r)['message']);
    }
    // Rejections are recorded even with logging off, so an unexpected Origin from Claude shows up.
    assert_contains('"event":"origin_rejected","origin":"https://claude.ai.evil.example"', col_error_log());
    // No Origin header: allowed (servers do not send one).
    assert_same(200, call()->status);
    // Configured origins replace the defaults.
    $c = config(['allowed_origins' => ['http://127.0.0.1:9400']]);
    assert_same(200, call(['config' => $c, 'headers' => ['Origin' => 'http://127.0.0.1:9400']])->status);
    assert_same(403, call(['config' => $c, 'headers' => ['Origin' => 'https://claude.ai']])->status);
    // Wrong key wins over a bad origin: never reveal that the endpoint works.
    assert_same(404, call(['key' => 'nope', 'headers' => ['Origin' => 'https://evil.example']])->status);
}

function test_get_and_delete_are_405(): void
{
    foreach (['GET', 'DELETE', 'HEAD', 'OPTIONS', 'PUT'] as $method) {
        $r = call(['method' => $method, 'body' => '', 'headers' => ['Content-Type' => '']]);
        assert_same(405, $r->status, $method);
        assert_same('POST', $r->headers['Allow']);
        rpc_error($r);
    }
}

function test_query_parameters_rejected(): void
{
    foreach (['_jsonp' => 'cb', '_envelope' => '1', '_fields' => 'id', '_embed' => '1', '_method' => 'GET', 'key' => KEY,
              'token' => 'x'] as $k => $v) {
        $r = call(['query' => [$k => $v]]);
        assert_same(400, $r->status, "?$k");
        assert_contains('query parameters', rpc_error($r)['message']);
    }
    // WordPress routes with ?rest_route= when pretty permalinks are off.
    assert_same(200, call(['query' => ['rest_route' => '/col/v1/mcp/' . KEY]])->status);
}

function test_body_size_limit(): void
{
    $max = Http::MAX_BODY;
    $padded = PING . str_repeat(' ', $max - strlen(PING));
    assert_same($max, strlen($padded));
    assert_same(200, call(['body' => $padded])->status, 'exactly 64 KB is fine');
    $r = call(['body' => $padded . ' ']);
    assert_same(413, $r->status);
    rpc_error($r);
}

function test_content_type_must_be_json(): void
{
    foreach (['text/plain', 'application/x-www-form-urlencoded', 'multipart/form-data; boundary=x', '', 'application/jsonx',
              'text/json'] as $type) {
        $r = call(['headers' => ['Content-Type' => $type]]);
        assert_same(415, $r->status, "'$type'");
        rpc_error($r);
    }
    $r = Http::process('POST', KEY, [], [], PING, config(), new Mcp(StubTools::class));
    assert_same(415, $r->status, 'no Content-Type');
    foreach (['application/json; charset=utf-8', 'Application/JSON', ' application/json ;charset=UTF-8'] as $type) {
        assert_same(200, call(['headers' => ['Content-Type' => $type]])->status, "'$type'");
    }
}

function test_route_key(): void
{
    assert_same(KEY, Http::routeKey('/col/v1/mcp/' . KEY));
    assert_same(KEY, Http::routeKey('/COL/v1/MCP/' . KEY));
    assert_same(null, Http::routeKey('/col/v1/mcp/' . KEY . '/x'));
    assert_same(null, Http::routeKey('/col/v1/mcp/short'));
    assert_same(null, Http::routeKey('/col/v1/mcp/' . KEY . "\n"));
    assert_same(null, Http::routeKey('/col/v1/mcp/' . str_repeat('a', 129)));
    assert_same(null, Http::routeKey('/wp/v2/posts'));
}

function test_secrets_never_in_responses(): void
{
    $bodies = [PING, '{bad', '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"x"}}',
               '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25"}}'];
    foreach ($bodies as $b) {
        $r = call(['body' => $b]);
        assert_not_contains(KEY, $r->body . json_encode($r->headers));
        assert_not_contains(VOYAGE_KEY, $r->body . json_encode($r->headers));
    }
}

// ------------------------------------------------------------------------------------------------
// Config

function test_config_defaults(): void
{
    $c = Config::fromArray(['url_key' => KEY], '/home/u/col-library/config.php');
    assert_same('/home/u/col-library/current', $c->dataDir());
    assert_same('/home/u/col-library', $c->stateDir());
    assert_same('', $c->voyageKey());
    assert_same('voyage-4-lite', $c->embedModel());
    assert_same(512, $c->embedDims());
    assert_same('rerank-3', $c->rerankModel());
    assert_same(32, $c->nprobe());
    assert_same(120, $c->maxCallsPerMinute());
    assert_same(10000, $c->maxCallsPerDay());
    assert_same(15_000_000, $c->maxVoyageTokensPerDay());
    assert_same(['https://claude.ai', 'https://claude.com', 'https://www.claude.ai', 'https://chatgpt.com', 'https://chat.openai.com', 'https://gemini.google.com', 'https://copilot.microsoft.com', 'https://chat.mistral.ai'], $c->allowedOrigins());
    assert_same('off', $c->logLevel());
    assert_same(false, $c->logQueries());
    assert_same(false, $c->voyageFake());
    assert_same('fallback', $c->get('no_such_key', 'fallback'));
    assert_same([KEY], $c->secrets());
}

function test_config_validation(): void
{
    $base = ['url_key' => KEY, 'data_dir' => '/d', 'state_dir' => '/s'];
    $bad = [
        'url_key' => [str_repeat('a', 42), KEY . '!', str_repeat('a', 129), 123, null],
        'voyage_api_key' => [123, null],
        'data_dir' => ['', 5, "/x\0y", 'relative/dir'],
        'embed_model' => ['', 5],
        'embed_dims' => ['512', 0, 5000, 1.5],
        'nprobe' => [0, '32'],
        'max_calls_per_minute' => [0, -1, '60'],
        'max_calls_per_day' => [0, null],
        'max_voyage_tokens_per_day' => [-1, 1.0],
        'allowed_origins' => ['https://claude.ai', ['claude.ai'], ['https://claude.ai/path'], ['a' => 'https://claude.ai']],
        'log_level' => ['verbose', true],
        'log_queries' => [1, 'yes'],
        'voyage_fake' => [1, '1', 'true', 'yes'],
    ];
    foreach ($bad as $key => $values) {
        foreach ($values as $v) {
            $e = assert_throws(ConfigException::class, fn () => Config::fromArray([$key => $v] + $base),
                "$key = " . var_export($v, true));
            assert_contains($key, $e->getMessage());
            assert_not_contains(KEY, $e->getMessage(), 'messages never contain the key');
        }
    }
    // Without a file path, data_dir and state_dir are required.
    assert_throws(ConfigException::class, fn () => Config::fromArray(['url_key' => KEY]));
    // voyage_fake turns on only with a real true; unknown keys are kept for get().
    $c = Config::fromArray($base + ['voyage_fake' => true, 'my_extra' => [1, 2], 'max_voyage_tokens_per_day' => 0]);
    assert_same(true, $c->voyageFake());
    assert_same([1, 2], $c->get('my_extra'));
    assert_same(0, $c->maxVoyageTokensPerDay());
    assert_same('/d', Config::fromArray(['data_dir' => '/d/'] + $base)->dataDir(), 'trailing slash removed');
}

function test_config_default_path(): void
{
    // tools/php passes only COL_* variables, so HOME may be unset here; either way the path ends the same.
    $p = Config::defaultPath();
    if (defined('COL_LIBRARY_CONFIG')) {
        assert_same(COL_LIBRARY_CONFIG, $p);
    } elseif ($p !== null) {
        assert_true(str_ends_with($p, '/col-library/config.php'), "default path $p");
    }
    assert_throws(ConfigException::class, fn () => Config::load(col_tmpdir('x') . '/missing.php'));
}

function test_normalize_origin(): void
{
    assert_same('https://claude.ai', Config::normalizeOrigin('https://Claude.AI/'));
    assert_same('https://claude.ai', Config::normalizeOrigin('https://claude.ai:443'));
    assert_same('http://127.0.0.1:9400', Config::normalizeOrigin('http://127.0.0.1:9400'));
    assert_same('http://[::1]:8080', Config::normalizeOrigin('http://[::1]:8080'));
    foreach (['null', 'claude.ai', 'https://claude.ai/x', 'ftp://claude.ai', 'https://user@claude.ai', ''] as $bad) {
        assert_same(null, Config::normalizeOrigin($bad), $bad);
    }
}

// ------------------------------------------------------------------------------------------------
// Log

function test_log_redacts_secrets_and_queries(): void
{
    $dir = col_tmpdir('log');
    $log = new Log('debug', $dir, false, [KEY, VOYAGE_KEY]);
    $log->info('request', ['path' => '/wp-json/col/v1/mcp/' . KEY, 'query' => 'assurance of salvation', 'n' => 3]);
    $log->debug('voyage', ['auth' => 'Bearer ' . VOYAGE_KEY, 'arguments' => ['query' => 'x']]);
    $log->error('boom', ['message' => 'failed for ' . KEY]);
    $files = glob("$dir/log-*.jsonl");
    assert_same(1, count($files), 'one monthly file');
    assert_true((bool) preg_match('#/log-\d{4}-\d{2}\.jsonl$#', $files[0]), 'file name');
    clearstatcache();
    assert_same('600', decoct(fileperms($files[0]) & 0777), 'log file is owner-only');
    $lines = file($files[0], FILE_IGNORE_NEW_LINES);
    assert_same(3, count($lines));
    $text = implode("\n", $lines);
    assert_not_contains(KEY, $text);
    assert_not_contains(VOYAGE_KEY, $text);
    assert_not_contains('assurance', $text);
    $first = json_decode($lines[0], true);
    assert_same('request', $first['event']);
    assert_same('info', $first['level']);
    assert_same('/wp-json/col/v1/mcp/[redacted]', $first['path']);
    assert_same('[omitted]', $first['query']);
    assert_same(3, $first['n']);
    assert_true((bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $first['ts']), 'timestamp');
    assert_same('[omitted]', json_decode($lines[1], true)['arguments']);
    assert_same('error', json_decode($lines[2], true)['level']);
}

function test_log_queries_when_allowed_and_levels(): void
{
    $dir = col_tmpdir('log');
    $log = new Log('info', $dir, true, [KEY]);
    $log->info('search', ['query' => 'assurance of salvation']);
    $log->debug('detail', ['x' => 1]);   // below the level: not written
    $lines = file(glob("$dir/log-*.jsonl")[0], FILE_IGNORE_NEW_LINES);
    assert_same(1, count($lines));
    assert_same('assurance of salvation', json_decode($lines[0], true)['query']);
    assert_true($log->queriesAllowed());

    $dir2 = col_tmpdir('log');
    $off = new Log('off', $dir2, true, []);
    $off->info('x');
    $off->debug('y');
    assert_same([], glob("$dir2/*"), 'level off writes nothing');
    assert_false($off->enabled('info'));
}

function test_requests_are_logged_without_key(): void
{
    $dir = col_tmpdir('log');
    $c = Config::fromArray(['url_key' => KEY, 'voyage_api_key' => VOYAGE_KEY, 'data_dir' => $dir, 'state_dir' => $dir,
                            'log_level' => 'debug']);
    call(['config' => $c, 'body' => '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","clientInfo":{"name":"claude-ai","version":"0.1"}}}']);
    call(['config' => $c, 'body' => '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"echo_text","arguments":{"text":"' . KEY . ' private words"}}}']);
    call(['config' => $c, 'headers' => ['Origin' => 'https://evil.example']]);
    $text = file_get_contents(glob("$dir/log-*.jsonl")[0]);
    assert_not_contains(KEY, $text);
    assert_not_contains('private words', $text);
    $events = array_map(fn ($l) => json_decode($l, true), explode("\n", trim($text)));
    $init = $events[0];
    assert_same('mcp', $init['event']);
    assert_same('legacy', $init['era']);
    assert_same('initialize', $init['method']);
    assert_same('2025-06-18', $init['negotiated']);
    assert_same('claude-ai/0.1', $init['client']);
    assert_same(200, $init['status']);
    assert_true(is_int($init['ms']), 'duration');
    $call = array_values(array_filter($events, fn ($e) => ($e['event'] ?? '') === 'mcp' && ($e['method'] ?? '') === 'tools/call'))[0];
    assert_same('echo_text', $call['tool']);
    assert_same(false, $call['is_error']);
    $reject = end($events);
    assert_same(403, $reject['status']);
}

// ------------------------------------------------------------------------------------------------
// RateLimit

function test_ratelimit_state_file(): void
{
    $dir = col_tmpdir('rl') . '/state';   // created on first use
    $rl = new RateLimit($dir, 10, 100, 1000, null, fn () => 1_800_000_000);
    assert_false(is_dir($dir), 'nothing is created before first use');
    assert_same(null, $rl->takeCall());
    assert_true(is_file("$dir/state.sqlite"), 'state.sqlite created');
    $db = new \PDO("sqlite:$dir/state.sqlite");
    assert_same('wal', $db->query('PRAGMA journal_mode')->fetchColumn());
    assert_same(['calls_minute' => 1, 'calls_day' => 1, 'voyage_tokens' => 0], $rl->usage());
    // A second instance (another PHP request) sees the same counters.
    $rl2 = new RateLimit($dir, 10, 100, 1000, null, fn () => 1_800_000_000);
    assert_same(null, $rl2->takeCall());
    assert_same(['calls_minute' => 2, 'calls_day' => 2, 'voyage_tokens' => 0], $rl->usage());
}

function test_ratelimit_old_minutes_are_pruned(): void
{
    $now = 1_800_000_000;
    $dir = col_tmpdir('rl');
    $rl = new RateLimit($dir, 10, 1000, 1000, null, function () use (&$now): int {
        return $now;
    });
    for ($i = 0; $i < 5; $i++) {
        $rl->takeCall();
        $now += 60;
    }
    $db = new \PDO("sqlite:$dir/state.sqlite");
    $rows = $db->query("SELECT count(*) FROM counters WHERE name = 'calls_minute'")->fetchColumn();
    assert_same(1, (int) $rows, 'only the current minute is kept');
    assert_same(5, $rl->usage()['calls_day']);
}

function test_ratelimit_voyage_budget(): void
{
    $now = 1_800_000_000;
    $rl = new RateLimit(col_tmpdir('rl'), 10, 100, 1000, null, function () use (&$now): int {
        return $now;
    });
    assert_true($rl->voyageAllowed(1000));
    assert_false($rl->voyageAllowed(1001));
    $rl->addVoyageTokens(900);
    $rl->addVoyageTokens(0);
    $rl->addVoyageTokens(-50);
    assert_same(900, $rl->usage()['voyage_tokens']);
    assert_true($rl->voyageAllowed(100));
    assert_false($rl->voyageAllowed(101));
    $rl->addVoyageTokens(200);   // actual usage may exceed the estimate
    assert_false($rl->voyageAllowed(0));
    $now += 86400;
    assert_true($rl->voyageAllowed(1000), 'a new UTC day starts from zero');
    $zero = new RateLimit(col_tmpdir('rl'), 10, 100, 0);
    assert_false($zero->voyageAllowed(1), 'a cap of 0 disables Voyage');
}

function test_ratelimit_fails_open_for_calls_and_closed_for_voyage(): void
{
    $file = col_tmpdir('rl') . '/not-a-dir';
    file_put_contents($file, 'x');
    $logDir = col_tmpdir('rl');
    $rl = new RateLimit("$file/state", 1, 1, 1000, new Log('info', $logDir));
    assert_same(null, $rl->takeCall(), 'calls are allowed when the state store is unusable');
    assert_same(null, $rl->takeCall());
    assert_false($rl->voyageAllowed(1), 'Voyage is refused when spending cannot be counted');
    $rl->addVoyageTokens(5);   // must not throw
    $log = file_get_contents(glob("$logDir/log-*.jsonl")[0]);
    assert_contains('ratelimit_unavailable', $log);
}

// ------------------------------------------------------------------------------------------------
// WordPress glue (Http::registerRoute, preDispatch, handleRest, serve) with stand-ins for the few
// WordPress classes and functions it uses (see the global namespace block below).

/** A request as WordPress would build it: header names lower-case with "_" for "-". */
function wp_request(string $method, string $route, array $headers = [], string $body = PING, array $query = []): \WP_REST_Request
{
    $h = [];
    foreach ($headers + ['Content-Type' => 'application/json'] as $k => $v) {
        $h[str_replace('-', '_', strtolower($k))] = [$v];
    }
    return new \WP_REST_Request($method, $route, $h, $body, $query);
}

/** Runs the request through preDispatch-like handling and serve(); returns [carrier, printed output]. */
function wp_roundtrip(\WP_REST_Request $req, Config|string $config): array
{
    $carrier = Http::handleRest($req, $config);
    ob_start();
    $served = Http::serve(false, $carrier, $req, null);
    $out = (string) ob_get_clean();
    assert_same(true, $served, 'served by the plugin');
    return [$carrier, $out];
}

function test_wp_registration(): void
{
    $GLOBALS['col_wp'] = [];
    Http::register();
    assert_same([['add_action', 'rest_api_init', [Http::class, 'registerRoute'], 10, 1]], $GLOBALS['col_wp']);
    $GLOBALS['col_wp'] = [];
    Http::registerRoute();
    [$route, $filter1, $filter2] = $GLOBALS['col_wp'];
    assert_same('register_rest_route', $route[0]);
    assert_same('col/v1', $route[1]);
    assert_same('/mcp/(?P<key>[A-Za-z0-9_-]{20,128})', $route[2]);
    assert_same(['POST', 'GET', 'DELETE'], $route[3]['methods']);
    assert_same('__return_true', $route[3]['permission_callback']);
    assert_same(false, $route[3]['show_in_index']);
    assert_same(['add_filter', 'rest_pre_dispatch', [Http::class, 'preDispatch'], 0, 3], $filter1);
    assert_same(['add_filter', 'rest_pre_serve_request', [Http::class, 'serve'], 0, 4], $filter2);
}

function test_main_plugin_file_bootstraps(): void
{
    // Loads every src/*.php (both agents' files) the way WordPress does, then registers on rest_api_init.
    $GLOBALS['col_wp'] = [];
    require_once COL_PLUGIN_DIR . '/col-library-connector.php';
    assert_same([['add_action', 'rest_api_init', [Http::class, 'registerRoute'], 10, 1]], $GLOBALS['col_wp']);
    foreach (glob(COL_PLUGIN_DIR . '/src/*.php') as $file) {
        assert_true(in_array(realpath($file), array_map('realpath', get_included_files()), true), basename($file) . ' loaded');
    }
    $header = file_get_contents(COL_PLUGIN_DIR . '/col-library-connector.php');
    assert_true((bool) preg_match('/^ \* Version:\s+(\S+)$/m', $header, $m), 'Version header');
    assert_same(Mcp::SERVER_VERSION, $m[1], 'plugin Version header equals Mcp::SERVER_VERSION');
    assert_true((bool) preg_match('/^ \* Plugin Name:\s+Christian Open Library Connector$/m', $header), 'Plugin Name');
}

function test_wp_glue_prints_the_exact_body(): void
{
    $GLOBALS['col_wp'] = [];
    $req = wp_request('POST', '/col/v1/mcp/' . KEY, ['MCP-Protocol-Version' => '2025-11-25']);
    [$carrier, $out] = wp_roundtrip($req, config());
    assert_same(200, $carrier->get_status());
    assert_same('{"jsonrpc":"2.0","id":1,"result":{}}', $out);
    assert_same(['remove_filter', 'rest_pre_serve_request', 'rest_send_cors_headers', 10], $GLOBALS['col_wp'][0],
        'no CORS headers on this route');
    assert_same(['remove_filter', 'rest_post_dispatch', 'rest_filter_response_fields', 10], $GLOBALS['col_wp'][1],
        'no _fields filtering (it reads the JSON body too)');
    assert_same([], (fn () => $this->data)->call($carrier), 'carrier data is an empty array');
    // Headers arrive with WordPress's "_" names and still count (here: a modern request).
    $meta = '"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}';
    $req = wp_request('POST', '/col/v1/mcp/' . KEY, ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list'],
        '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{' . $meta . '}}');
    [$carrier, $out] = wp_roundtrip($req, config());
    // Whether tools/list itself works depends on whether the real Tools class is loaded by now; what
    // matters here is that the modern headers were read (a missing header would be 400 with -32020).
    assert_same(200, $carrier->get_status());
    assert_contains('{"jsonrpc":"2.0","id":2,', $out);
    assert_not_contains('-32020', $out);
}

function test_wp_glue_rejections_and_head(): void
{
    $route = '/col/v1/mcp/' . KEY;
    [$c, $out] = wp_roundtrip(wp_request('POST', '/col/v1/mcp/' . strrev(KEY)), config());
    assert_same([404, '{}'], [$c->get_status(), $out]);
    [$c, $out] = wp_roundtrip(wp_request('GET', $route, [], ''), config());
    assert_same(405, $c->get_status());
    assert_same('POST', $c->get_headers()['Allow']);
    [$c, $out] = wp_roundtrip(wp_request('HEAD', $route, [], ''), config());
    assert_same([405, ''], [$c->get_status(), $out], 'HEAD: no body');
    [$c, $out] = wp_roundtrip(wp_request('POST', $route, [], PING, ['_jsonp' => 'cb']), config());
    assert_same(400, $c->get_status());
    assert_contains('query parameters', $out);
    [$c, $out] = wp_roundtrip(wp_request('POST', $route, [], '{"jsonrpc":'), config());
    assert_same(400, $c->get_status());
    assert_contains('"code":-32700', $out);
}

function test_wp_glue_drops_stray_output(): void
{
    // A config file that prints something stands in for any code that prints (e.g. a PHP warning).
    $dir = col_tmpdir('stray');
    $path = config_file("<?php echo 'Warning: something odd'; return ['url_key' => '" . KEY . "', 'data_dir' => '$dir', 'state_dir' => '$dir'];");
    [$c, $out] = wp_roundtrip(wp_request('POST', '/col/v1/mcp/' . KEY), $path);
    assert_same('{"jsonrpc":"2.0","id":1,"result":{}}', $out, 'only the JSON is printed');
    assert_contains('dropped output printed during the request: Warning: something odd', col_error_log());
}

function test_wp_glue_ignores_other_routes(): void
{
    $other = wp_request('POST', '/wp/v2/posts');
    assert_same(null, Http::preDispatch(null, null, $other));
    assert_same('kept', Http::preDispatch('kept', null, wp_request('POST', '/col/v1/mcp/' . KEY)), 'an earlier result wins');
    assert_same(false, Http::serve(false, null, $other, null));
    // Our route, but nothing pending (another plugin answered it first): leave it alone.
    assert_same(false, Http::serve(false, null, wp_request('POST', '/col/v1/mcp/' . KEY), null));
    $carrier = Http::preDispatch(null, null, wp_request('POST', '/col/v1/mcp/' . KEY));
    assert_true($carrier instanceof \WP_REST_Response, 'our route is taken over in rest_pre_dispatch');
    ob_start();
    Http::serve(false, $carrier, wp_request('POST', '/col/v1/mcp/' . KEY), null);
    ob_end_clean();   // whatever the default config gives (here: 404, no config file); clears pending
}


function test_a_full_log_still_records_errors(): void
{
    $dir = col_tmpdir();
    $file = $dir . '/log-' . gmdate('Y-m') . '.jsonl';
    $h = fopen($file, 'c');
    ftruncate($h, 45_000_000);   // past the info cap, under the hard cap
    fclose($h);
    $log = new \ColLibrary\Log('info', $dir);
    $log->info('unauthorized', ['status' => 401]);
    clearstatcache();
    assert_same(45_000_000, filesize($file), 'info line dropped once the log is nearly full');
    $log->error('tool_error', ['tool' => 'x']);
    clearstatcache();
    assert_true(filesize($file) > 45_000_000, 'error line still written');
}
}   // namespace ColLibraryTest\Http

namespace {

/*
 * Minimal stand-ins for the WordPress API used by Http.php's glue, defined only if real
 * WordPress (or another test's stand-ins) has not defined them. Calls are recorded in
 * $GLOBALS['col_wp'].
 */

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        public function __construct(private string $method, private string $route, private array $headers = [],
                                    private string $body = '', private array $query = [])
        {
        }

        public function get_method(): string { return $this->method; }
        public function get_route(): string { return $this->route; }
        public function get_headers(): array { return $this->headers; }
        public function get_body(): string { return $this->body; }
        public function get_query_params(): array { return $this->query; }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        private array $headers = [];

        public function __construct(private mixed $data = null, private int $status = 200)
        {
        }

        public function get_status(): int { return $this->status; }
        public function set_headers(array $headers): void { $this->headers = $headers; }
        public function get_headers(): array { return $this->headers; }
    }
}

foreach (['add_action', 'add_filter', 'remove_filter', 'register_rest_route'] as $fn) {
    if (function_exists($fn)) {
        continue;
    }
    switch ($fn) {
        case 'add_action':
            function add_action($hook, $cb, $priority = 10, $args = 1) { $GLOBALS['col_wp'][] = ['add_action', $hook, $cb, $priority, $args]; return true; }
            break;
        case 'add_filter':
            function add_filter($hook, $cb, $priority = 10, $args = 1) { $GLOBALS['col_wp'][] = ['add_filter', $hook, $cb, $priority, $args]; return true; }
            break;
        case 'remove_filter':
            function remove_filter($hook, $cb, $priority = 10) { $GLOBALS['col_wp'][] = ['remove_filter', $hook, $cb, $priority]; return true; }
            break;
        case 'register_rest_route':
            function register_rest_route($ns, $route, $args = [], $override = false) { $GLOBALS['col_wp'][] = ['register_rest_route', $ns, $route, $args]; return true; }
            break;
    }
}

}   // global namespace
