<?php
/**
 * Tests for src/Mcp.php: JSON-RPC handling and both MCP protocol eras, against a stub Tools class
 * (StubTools below), so they do not depend on the search code or the library data.
 */

namespace ColLibraryTest\Mcp;

use ColLibrary\Config;
use ColLibrary\Context;
use ColLibrary\Log;
use ColLibrary\Mcp;
use ColLibrary\RateLimit;
use ColLibrary\Response;
use ColLibrary\UnknownToolException;

col_src('Context', 'Config', 'Log', 'RateLimit', 'Mcp');

const KEY = 'test-key-0123456789abcdefghijklmnopqrstuvwxyzABCD';
const MODERN = '2026-07-28';
const LEGACY = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
const ANNOTATIONS = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];

/** Stands in for ColLibrary\Tools. */
final class StubTools
{
    /** @var list<array{0: string, 1: array}> calls received */
    public static array $calls = [];

    public static function definitions(): array
    {
        $tool = fn (string $name, string $title, array $properties, array $required = []) => [
            'name' => $name,
            'title' => $title,
            'description' => "$title (test tool).",
            'inputSchema' => ['type' => 'object', 'properties' => $properties, 'required' => $required,
                              'additionalProperties' => false],
            'annotations' => ['title' => $title] + ANNOTATIONS,
        ];
        $text = ['text' => ['type' => 'string', 'description' => 'Some text', 'maxLength' => 100]];
        return [
            $tool('echo_text', 'Echo text', $text, ['text']),
            $tool('no_args', 'No arguments', []),
            $tool('fails', 'Always fails', []),
            $tool('explodes', 'Throws', []),
            $tool('vanishes', 'Unknown to call()', []),
        ];
    }

    public static function call(string $name, array $arguments, Context $ctx): array
    {
        self::$calls[] = [$name, $arguments];
        return match ($name) {
            'echo_text' => ['content' => [['type' => 'text', 'text' => 'echo: ' . ($arguments['text'] ?? '')]], 'isError' => false],
            'no_args' => ['content' => [['type' => 'text', 'text' => 'args: ' . json_encode($arguments)]], 'isError' => false],
            'fails' => ['content' => [['type' => 'text', 'text' => 'Nothing found. Try another phrasing.']], 'isError' => true],
            'explodes' => throw new \RuntimeException('database /home/secret/col-library/current is locked'),
            'vanishes' => throw new UnknownToolException('vanishes'),
        };
    }
}

/** A Context with test config, a fresh state dir and a fixed clock (no minute-boundary flakiness). */
function ctx(array $config = [], ?\Closure $clock = null): Context
{
    $dir = col_tmpdir('mcp');
    $cfg = Config::fromArray($config + ['url_key' => KEY, 'data_dir' => $dir, 'state_dir' => $dir]);
    $log = Log::fromConfig($cfg);
    $limits = new RateLimit($dir, $cfg->maxCallsPerMinute(), $cfg->maxCallsPerDay(), $cfg->maxVoyageTokensPerDay(),
        $log, $clock ?? fn () => 1_800_000_000);
    return new Context($cfg, $limits, $log, microtime(true) + 25);
}

/** POSTs a body (encoded from an array, or a raw string) to Mcp::handle. */
function post(mixed $body, array $headers = [], ?Context $ctx = null, string $method = 'POST'): Response
{
    $raw = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return (new Mcp(StubTools::class))->handle($method, $headers + ['Content-Type' => 'application/json'], $raw, $ctx ?? ctx());
}

function req(string $method, ?array $params = null, mixed $id = 1): array
{
    $m = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
    if ($params !== null) {
        $m['params'] = $params;
    }
    return $m;
}

function decoded(Response $r): mixed
{
    assert_true($r->body !== null, 'response has a body');
    return json_decode($r->body, true, 512, JSON_THROW_ON_ERROR);
}

/** Asserts a JSON-RPC error response and returns the error object. */
function error_of(Response $r, int $status, int $code, mixed $id = 1): array
{
    $d = decoded($r);
    assert_same($status, $r->status, 'HTTP status of ' . $r->body);
    assert_same('application/json', $r->headers['Content-Type'] ?? null, 'content type');
    assert_same('2.0', $d['jsonrpc'] ?? null, 'jsonrpc');
    assert_same($code, $d['error']['code'] ?? null, 'error code in ' . $r->body);
    assert_true(is_string($d['error']['message'] ?? null), 'error message');
    if ($id === 'absent') {
        assert_false(array_key_exists('id', $d), 'id must be omitted');
    } else {
        assert_true(array_key_exists('id', $d), 'id present');
        assert_same($id, $d['id'], 'id');
    }
    return $d['error'];
}

/** Asserts a JSON-RPC result response (HTTP 200) and returns the result. */
function result_of(Response $r, mixed $id = 1): array
{
    $d = decoded($r);
    assert_same(200, $r->status, 'HTTP status of ' . $r->body);
    assert_same('application/json', $r->headers['Content-Type'] ?? null, 'content type');
    assert_same('2.0', $d['jsonrpc'] ?? null, 'jsonrpc');
    assert_true(array_key_exists('id', $d), 'id present');
    assert_same($id, $d['id'], 'id');
    assert_false(array_key_exists('error', $d), 'no error expected in ' . $r->body);
    return $d['result'];
}

function meta(array $extra = []): array
{
    return $extra + [
        'io.modelcontextprotocol/protocolVersion' => MODERN,
        'io.modelcontextprotocol/clientInfo' => ['name' => 'tests', 'version' => '1.0'],
        'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
    ];
}

/** Headers a modern client sends for a request. */
function modern_headers(string $method, ?string $name = null): array
{
    $h = ['MCP-Protocol-Version' => MODERN, 'Mcp-Method' => $method];
    if ($name !== null) {
        $h['Mcp-Name'] = $name;
    }
    return $h;
}

/** A complete, valid modern request (headers derived from the body unless overridden). */
function modern(string $method, array $params = [], mixed $id = 1, array $headers = [], ?Context $ctx = null): Response
{
    $params['_meta'] ??= meta();
    $name = $method === 'tools/call' && is_string($params['name'] ?? null) ? $params['name'] : null;
    return post(req($method, $params, $id), $headers + modern_headers($method, $name), $ctx);
}

// ------------------------------------------------------------------------------------------------
// Legacy era

function test_initialize_echoes_each_legacy_version(): void
{
    foreach (LEGACY as $v) {
        $r = post(req('initialize', ['protocolVersion' => $v, 'capabilities' => new \stdClass(),
                                     'clientInfo' => ['name' => 'c', 'version' => '1']]));
        $res = result_of($r);
        assert_same($v, $res['protocolVersion'], "negotiated version for $v");
        assert_same(['tools' => ['listChanged' => false]], $res['capabilities']);
        assert_same(['name' => 'christian-open-library', 'title' => 'Christian Open Library', 'version' => Mcp::SERVER_VERSION,
            'websiteUrl' => Mcp::WEBSITE, 'icons' => [['src' => Mcp::ICON, 'mimeType' => 'image/png', 'sizes' => ['512x512']]]],
            $res['serverInfo']);
        assert_true(strlen($res['instructions']) > 200, 'instructions present');
        assert_contains('"capabilities":{"tools":{"listChanged":false}}', $r->body);
        assert_false(isset($res['resultType']), 'legacy results carry no resultType');
        foreach (array_keys($r->headers) as $h) {
            assert_false(strtolower($h) === 'mcp-session-id', 'never mint a session id');
        }
    }
}

function test_initialize_unknown_version_gets_latest_legacy(): void
{
    foreach (['1999-01-01', '2026-07-28', '2025-11-26', 'latest'] as $v) {
        $res = result_of(post(req('initialize', ['protocolVersion' => $v, 'capabilities' => new \stdClass()])));
        assert_same('2025-11-25', $res['protocolVersion'], "answer to $v");
    }
}

function test_initialize_needs_protocol_version(): void
{
    error_of(post(req('initialize', [])), 200, -32602);
    error_of(post(req('initialize', ['protocolVersion' => 20250618])), 200, -32602);
    error_of(post(req('initialize')), 200, -32602);
}

function test_initialize_ignores_session_header(): void
{
    $r = post(req('initialize', ['protocolVersion' => '2025-06-18']), ['Mcp-Session-Id' => 'abc123']);
    result_of($r);
    assert_not_contains('abc123', $r->body . json_encode($r->headers));
}

function test_notifications_get_202_with_empty_body(): void
{
    $notes = [
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 1]],
        ['jsonrpc' => '2.0', 'method' => 'notifications/whatever'],
    ];
    foreach ($notes as $n) {
        foreach ([[], ['MCP-Protocol-Version' => '2025-11-25']] as $h) {
            $r = post($n, $h);
            assert_same(202, $r->status, $n['method']);
            assert_same(null, $r->body, 'empty body');
        }
    }
}

function test_client_responses_get_202_in_legacy_era(): void
{
    $r = post(['jsonrpc' => '2.0', 'id' => 5, 'result' => new \stdClass()], ['MCP-Protocol-Version' => '2025-06-18']);
    assert_same(202, $r->status);
    assert_same(null, $r->body);
    $r = post(['jsonrpc' => '2.0', 'id' => 6, 'error' => ['code' => -1, 'message' => 'no']]);
    assert_same(202, $r->status);
}

function test_ping_returns_empty_object(): void
{
    $r = post(req('ping', null, 7), ['MCP-Protocol-Version' => '2025-11-25']);
    assert_same(200, $r->status);
    assert_same('{"jsonrpc":"2.0","id":7,"result":{}}', $r->body);
}

function test_tools_list_shape(): void
{
    $r = post(req('tools/list'), ['MCP-Protocol-Version' => '2025-06-18']);
    $res = result_of($r);
    assert_same(['tools'], array_keys($res), 'legacy tools/list has only tools (no nextCursor, no cache hints)');
    $names = array_column($res['tools'], 'name');
    assert_same(['echo_text', 'no_args', 'fails', 'explodes', 'vanishes'], $names, 'definition order kept');
    foreach ($res['tools'] as $t) {
        assert_true(is_string($t['title']) && $t['title'] !== '', "title of {$t['name']}");
        assert_same($t['title'], $t['annotations']['title'], "annotations.title of {$t['name']}");
        foreach (ANNOTATIONS as $k => $v) {
            assert_same($v, $t['annotations'][$k], "{$t['name']} annotations.$k");
        }
        assert_same('object', $t['inputSchema']['type']);
        assert_same(false, $t['inputSchema']['additionalProperties'], "{$t['name']} additionalProperties");
    }
    // An empty properties map must be a JSON object, not a list.
    assert_contains('"name":"no_args","title":"No arguments","description":"No arguments (test tool).","inputSchema":{"type":"object","properties":{}', $r->body);
    assert_not_contains('"properties":[]', $r->body);
}

function test_tools_list_rejects_unknown_cursor(): void
{
    error_of(post(req('tools/list', ['cursor' => 'page-2'])), 200, -32602);
    result_of(post(req('tools/list', ['cursor' => null])));
    result_of(post(req('tools/list', [])));
}

function test_tools_call_happy_path(): void
{
    StubTools::$calls = [];
    $r = post(req('tools/call', ['name' => 'echo_text', 'arguments' => ['text' => 'grace ſinne/é']], 'call-1'),
        ['MCP-Protocol-Version' => '2025-11-25']);
    $res = result_of($r, 'call-1');
    assert_same(['content' => [['type' => 'text', 'text' => 'echo: grace ſinne/é']], 'isError' => false], $res);
    assert_contains('echo: grace ſinne/é', $r->body, 'UTF-8 and slashes unescaped');
    assert_same([['echo_text', ['text' => 'grace ſinne/é']]], StubTools::$calls);
}

function test_tools_call_arguments_become_arrays(): void
{
    StubTools::$calls = [];
    result_of(post(req('tools/call', ['name' => 'no_args', 'arguments' => ['a' => ['b' => [1, ['c' => true]]]]])));
    result_of(post(req('tools/call', ['name' => 'no_args'])));
    result_of(post(req('tools/call', ['name' => 'no_args', 'arguments' => []])));
    result_of(post(req('tools/call', ['name' => 'no_args', 'arguments' => null])));
    assert_same([
        ['no_args', ['a' => ['b' => [1, ['c' => true]]]]],
        ['no_args', []],
        ['no_args', []],
        ['no_args', []],
    ], StubTools::$calls);
}

function test_tools_call_unknown_tool(): void
{
    $e = error_of(post(req('tools/call', ['name' => 'nope', 'arguments' => new \stdClass()])), 200, -32602);
    assert_same('Unknown tool: nope', $e['message']);
    // Tools::call may also report an unknown tool by exception.
    error_of(post(req('tools/call', ['name' => 'vanishes'])), 200, -32602);
}

function test_tools_call_bad_params(): void
{
    error_of(post(req('tools/call', ['arguments' => new \stdClass()])), 200, -32602);
    error_of(post(req('tools/call', ['name' => 42])), 200, -32602);
    error_of(post(req('tools/call', ['name' => 'echo_text', 'arguments' => 'text'])), 200, -32602);
    error_of(post(req('tools/call', ['name' => 'echo_text', 'arguments' => ['x', 'y']])), 200, -32602);
    error_of(post(req('tools/call')), 200, -32602);
}

function test_tools_call_tool_error_is_a_result(): void
{
    $res = result_of(post(req('tools/call', ['name' => 'fails'])));
    assert_same(true, $res['isError']);
    assert_same('Nothing found. Try another phrasing.', $res['content'][0]['text']);
}

function test_tools_call_exception_is_internal_error_without_details(): void
{
    $r = post(req('tools/call', ['name' => 'explodes']));
    $e = error_of($r, 200, -32603);
    assert_same('Internal error', $e['message']);
    assert_false(isset($e['data']), 'no error data');
    assert_not_contains('secret', $r->body);
    assert_not_contains('RuntimeException', $r->body);
    // The details go to the server's log instead (PHP's error log while log_level is off).
    $log = col_error_log();
    assert_contains('"event":"internal_error"', $log);
    assert_contains('"tool":"explodes","exception":"RuntimeException","message":"database /home/secret/', $log);
}

function test_parse_error(): void
{
    foreach (['{"jsonrpc": "2.0", "id": 1, "method": "ping"', '', 'nul', "{\"a\":\"\xff\"}"] as $body) {
        $e = error_of(post($body), 400, -32700, null);
        assert_contains('Parse error', $e['message']);
    }
    // Modern clients get no "id" at all when it is unknown.
    error_of(post('{oops', ['MCP-Protocol-Version' => MODERN]), 400, -32700, 'absent');
}

function test_parse_error_when_nested_too_deep(): void
{
    $deep = str_repeat('[', 40) . str_repeat(']', 40);
    error_of(post('{"jsonrpc":"2.0","id":1,"method":"ping","params":{"x":' . $deep . '}}'), 400, -32700, null);
    // 30 levels are fine.
    result_of(post('{"jsonrpc":"2.0","id":1,"method":"ping","params":{"x":' . str_repeat('[', 28) . str_repeat(']', 28) . '}}'));
}

function test_invalid_requests(): void
{
    error_of(post(['id' => 1, 'method' => 'ping']), 400, -32600, 1);
    error_of(post(['jsonrpc' => '1.0', 'id' => 7, 'method' => 'ping']), 400, -32600, 7);
    error_of(post(['jsonrpc' => '2.0', 'id' => 7, 'method' => 5]), 400, -32600, 7);
    error_of(post(['jsonrpc' => '2.0', 'id' => 7, 'method' => '']), 400, -32600, 7);
    error_of(post(['jsonrpc' => '2.0', 'id' => 7]), 400, -32600, 7);
    error_of(post(['jsonrpc' => '2.0', 'id' => null, 'method' => 'ping']), 400, -32600, null);
    error_of(post('{"jsonrpc":"2.0","id":1.5,"method":"ping"}'), 400, -32600, null);
    error_of(post('{"jsonrpc":"2.0","id":true,"method":"ping"}'), 400, -32600, null);
    error_of(post('{"jsonrpc":"2.0","id":{"a":1},"method":"ping"}'), 400, -32600, null);
    error_of(post('42'), 400, -32600, null);
    error_of(post('"ping"'), 400, -32600, null);
    error_of(post('null'), 400, -32600, null);
    error_of(post(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping', 'params' => 'x']), 400, -32600, 3);
    // By-position params are valid JSON-RPC but not MCP.
    error_of(post(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping', 'params' => [1, 2]]), 200, -32602, 3);
    // An empty list is taken as an empty object (some clients cannot tell them apart).
    result_of(post(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping', 'params' => []]), 3);
}

function test_method_not_found_in_legacy_era(): void
{
    foreach (['resources/list', 'prompts/list', 'logging/setLevel', 'completion/complete', 'server/discover',
              'subscriptions/listen', 'Tools/List', 'tasks/list'] as $method) {
        $e = error_of(post(req($method, [])), 200, -32601);
        assert_contains($method, $e['message']);
    }
}

function test_batch_in_2025_03_26(): void
{
    StubTools::$calls = [];
    $batch = [
        req('ping', null, 1),
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        req('tools/list', null, 'two'),
        req('tools/call', ['name' => 'echo_text', 'arguments' => ['text' => 'hi']], 3),
        req('nope', null, 4),
        ['jsonrpc' => '2.0', 'id' => 9, 'result' => new \stdClass()],
    ];
    foreach ([[], ['MCP-Protocol-Version' => '2025-03-26']] as $h) {
        $r = post($batch, $h);
        assert_same(200, $r->status);
        $d = decoded($r);
        assert_true(array_is_list($d), 'array of responses');
        assert_same([1, 'two', 3, 4], array_column($d, 'id'), 'one response per request, in order');
        assert_same([], $d[0]['result']);
        assert_contains('"id":1,"result":{}', $r->body);
        assert_same('echo: hi', $d[2]['result']['content'][0]['text']);
        assert_same(-32601, $d[3]['error']['code']);
    }
    assert_same(2, count(StubTools::$calls));
}

function test_batch_of_notifications_gets_202(): void
{
    $r = post([['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
               ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 1]]]);
    assert_same(202, $r->status);
    assert_same(null, $r->body);
}

function test_batch_rejected_in_other_versions(): void
{
    foreach (['2025-06-18', '2025-11-25', '2024-11-05'] as $v) {
        error_of(post([req('ping')], ['MCP-Protocol-Version' => $v]), 400, -32600, null);
    }
    // Modern requests are never batches.
    error_of(post([req('ping')], ['MCP-Protocol-Version' => MODERN]), 400, -32600, 'absent');
    $e = error_of(post([req('ping')], ['MCP-Protocol-Version' => '2030-01-01']), 400, -32022, 'absent');
    assert_same('2030-01-01', $e['data']['requested']);
}

function test_batch_edge_cases(): void
{
    error_of(post('[]'), 400, -32600, null);
    error_of(post(array_fill(0, Mcp::MAX_BATCH + 1, req('ping'))), 400, -32600, null);
    $d = decoded(post('[1, {"jsonrpc":"2.0","id":3,"method":"ping"}]'));
    assert_same(-32600, $d[0]['error']['code']);
    assert_same(null, $d[0]['id']);
    assert_same(3, $d[1]['id']);
    // initialize must not be batched.
    $d = decoded(post([req('initialize', ['protocolVersion' => '2025-03-26'], 1), req('ping', null, 2)]));
    assert_same(-32600, $d[0]['error']['code']);
    assert_same(1, $d[0]['id']);
    assert_same(2, $d[1]['id']);
}

function test_id_is_echoed_exactly(): void
{
    $cases = [0, -5, 42, PHP_INT_MAX, 'abc', '', 'ünï/cødé "quoted"', '0042', '123456789012345678901234567890'];
    foreach ($cases as $id) {
        $r = post(req('ping', null, $id));
        assert_same($id, decoded($r)['id'], 'id ' . var_export($id, true));
        assert_same('{"jsonrpc":"2.0","id":' . json_encode($id, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ',"result":{}}', $r->body);
    }
    // A number too big for a PHP int is echoed as the same number, not as a string.
    $r = post('{"jsonrpc":"2.0","id":123456789012345678901234567890,"method":"ping"}');
    assert_same('{"jsonrpc":"2.0","id":123456789012345678901234567890,"result":{}}', $r->body);
    $r = post('{"jsonrpc":"2.0","id":-99999999999999999999,"method":"tools/list","params":{"cursor":"x"}}');
    assert_contains('"id":-99999999999999999999,"error"', $r->body);
    $r = post('[{"jsonrpc":"2.0","id":"99999999999999999999","method":"ping"},{"jsonrpc":"2.0","id":99999999999999999999,"method":"ping"}]');
    assert_same('[{"jsonrpc":"2.0","id":"99999999999999999999","result":{}},{"jsonrpc":"2.0","id":99999999999999999999,"result":{}}]', $r->body);
    // Errors echo the id too, in both eras.
    error_of(post(req('nope', null, 'x-1')), 200, -32601, 'x-1');
    error_of(modern('nope', [], 'm-1'), 404, -32601, 'm-1');
}

function test_unsupported_version_header(): void
{
    foreach (['2024-01-01', '2026-07-29', 'garbage', ''] as $v) {
        $e = error_of(post(req('tools/list'), ['MCP-Protocol-Version' => $v]), 400, -32022);
        assert_same(['supported' => array_merge([MODERN], LEGACY), 'requested' => $v], $e['data']);
    }
}

function test_non_post_is_405(): void
{
    foreach (['GET', 'DELETE', 'PUT', 'OPTIONS'] as $m) {
        $r = post('', [], null, $m);
        assert_same(405, $r->status, $m);
        assert_same('POST', $r->headers['Allow'] ?? null);
    }
}

// ------------------------------------------------------------------------------------------------
// Modern era (2026-07-28)

function test_server_discover(): void
{
    $r = modern('server/discover', [], 'discover-1');
    $res = result_of($r, 'discover-1');
    assert_same('complete', $res['resultType']);
    assert_same(array_merge([MODERN], LEGACY), $res['supportedVersions']);
    assert_same(['tools' => ['listChanged' => false]], $res['capabilities']);
    assert_true(strlen($res['instructions']) > 200, 'instructions');
    assert_same(3600000, $res['ttlMs']);
    assert_same('public', $res['cacheScope']);
    assert_same(['name' => 'christian-open-library', 'title' => 'Christian Open Library', 'version' => Mcp::SERVER_VERSION,
        'websiteUrl' => Mcp::WEBSITE, 'icons' => [['src' => Mcp::ICON, 'mimeType' => 'image/png', 'sizes' => ['512x512']]]],
        $res['_meta']['io.modelcontextprotocol/serverInfo']);
}

function test_modern_tools_list(): void
{
    $r = modern('tools/list');
    $res = result_of($r);
    assert_same('complete', $res['resultType']);
    assert_same(3600000, $res['ttlMs']);
    assert_same('public', $res['cacheScope']);
    assert_same('christian-open-library', $res['_meta']['io.modelcontextprotocol/serverInfo']['name']);
    assert_same(['echo_text', 'no_args', 'fails', 'explodes', 'vanishes'], array_column($res['tools'], 'name'));
    assert_false(isset($res['nextCursor']), 'single page');
    assert_contains('"properties":{}', $r->body);
}

function test_modern_tools_call(): void
{
    $res = result_of(modern('tools/call', ['name' => 'echo_text', 'arguments' => ['text' => 'hi']]));
    assert_same('complete', $res['resultType']);
    assert_same([['type' => 'text', 'text' => 'echo: hi']], $res['content']);
    assert_same(false, $res['isError']);
    assert_true(isset($res['_meta']['io.modelcontextprotocol/serverInfo']), 'serverInfo in _meta');
    $res = result_of(modern('tools/call', ['name' => 'fails']));
    assert_same(true, $res['isError']);
    assert_same('complete', $res['resultType']);
    error_of(modern('tools/call', ['name' => 'nope']), 200, -32602);
    error_of(modern('tools/call', ['name' => 'explodes']), 200, -32603);
}

function test_modern_protocol_version_header_checks(): void
{
    $p = ['_meta' => meta()];
    // Missing header: the body says 2026-07-28, so this is a modern request.
    $e = error_of(post(req('tools/list', $p), ['Mcp-Method' => 'tools/list']), 400, -32020);
    assert_contains('MCP-Protocol-Version', $e['message']);
    // Header and body disagree.
    error_of(post(req('tools/list', $p), ['MCP-Protocol-Version' => '2025-11-25', 'Mcp-Method' => 'tools/list']), 400, -32020);
    error_of(post(req('tools/list', ['_meta' => meta(['io.modelcontextprotocol/protocolVersion' => '2025-11-25'])]),
        modern_headers('tools/list')), 400, -32020);
}

function test_modern_method_header_checks(): void
{
    $p = ['_meta' => meta()];
    $e = error_of(post(req('tools/list', $p), ['MCP-Protocol-Version' => MODERN]), 400, -32020);
    assert_contains('Mcp-Method', $e['message']);
    error_of(post(req('tools/list', $p), modern_headers('tools/call')), 400, -32020);
    error_of(post(req('tools/list', $p), modern_headers('Tools/List')), 400, -32020, 1);
    // Header names are case-insensitive.
    result_of(post(req('tools/list', $p), ['mcp-protocol-version' => MODERN, 'MCP-METHOD' => 'tools/list']));
}

function test_modern_name_header_checks(): void
{
    $p = ['name' => 'echo_text', 'arguments' => ['text' => 'x']];
    $e = error_of(modern('tools/call', $p, 1, ['Mcp-Name' => null] + modern_headers('tools/call')), 400, -32020);
    assert_contains('Mcp-Name', $e['message']);
    error_of(modern('tools/call', $p, 1, ['Mcp-Name' => 'no_args']), 400, -32020);
    error_of(modern('tools/call', $p, 1, ['Mcp-Name' => 'Echo_Text']), 400, -32020);
    // Base64 sentinel form.
    $res = result_of(modern('tools/call', $p, 1, ['Mcp-Name' => '=?base64?' . base64_encode('echo_text') . '?=']));
    assert_same('echo: x', $res['content'][0]['text']);
    error_of(modern('tools/call', $p, 1, ['Mcp-Name' => '=?base64?' . base64_encode('no_args') . '?=']), 400, -32020);
    error_of(modern('tools/call', $p, 1, ['Mcp-Name' => '=?base64?not*base64?=']), 400, -32020);
    error_of(modern('tools/call', $p, 1, ['Mcp-Name' => '=?base64?' . base64_encode("\xff\xfe") . '?=']), 400, -32020);
    error_of(modern('tools/call', $p, 1, ['Mcp-Name' => "echo\x01text"]), 400, -32020);
    // A non-ASCII name: decoded and compared, then reported as an unknown tool.
    $u = ['name' => 'lire_passagé', 'arguments' => new \stdClass()];
    $e = error_of(modern('tools/call', $u, 1, ['Mcp-Name' => '=?base64?' . base64_encode('lire_passagé') . '?=']), 200, -32602);
    assert_same('Unknown tool: lire_passagé', $e['message']);
    // A missing body name is invalid params.
    error_of(modern('tools/call', ['arguments' => new \stdClass()], 1, ['Mcp-Name' => 'echo_text']), 200, -32602);
}

function test_modern_unsupported_version(): void
{
    $p = ['_meta' => meta(['io.modelcontextprotocol/protocolVersion' => '2027-01-01'])];
    $e = error_of(post(req('tools/list', $p), ['MCP-Protocol-Version' => '2027-01-01', 'Mcp-Method' => 'tools/list']), 400, -32022);
    assert_same('Unsupported protocol version', $e['message']);
    assert_same(['supported' => array_merge([MODERN], LEGACY), 'requested' => '2027-01-01'], $e['data']);
    // Only in the body.
    $e = error_of(post(req('tools/list', $p), ['Mcp-Method' => 'tools/list']), 400, -32022);
    assert_same('2027-01-01', $e['data']['requested']);
}

function test_modern_missing_meta_fields(): void
{
    $h = modern_headers('tools/list');
    $e = error_of(post(req('tools/list', []), $h), 400, -32602);
    assert_contains('protocolVersion', $e['message']);
    error_of(post(req('tools/list'), $h), 400, -32602);
    $noCaps = meta();
    unset($noCaps['io.modelcontextprotocol/clientCapabilities']);
    $e = error_of(post(req('tools/list', ['_meta' => $noCaps]), $h), 400, -32602);
    assert_contains('clientCapabilities', $e['message']);
    error_of(post(req('tools/list', ['_meta' => meta(['io.modelcontextprotocol/clientCapabilities' => 'all'])]), $h), 400, -32602);
    error_of(post(req('tools/list', ['_meta' => meta(['io.modelcontextprotocol/protocolVersion' => 2026])]), $h), 400, -32602);
    // clientInfo is optional.
    $noInfo = meta();
    unset($noInfo['io.modelcontextprotocol/clientInfo']);
    result_of(post(req('tools/list', ['_meta' => $noInfo]), $h));
}

function test_modern_unknown_methods_get_404(): void
{
    foreach (['resources/list', 'prompts/get', 'ping', 'initialize', 'logging/setLevel', 'tasks/get', 'nope'] as $method) {
        $e = error_of(modern($method, [], 5), 404, -32601, 5);
        assert_contains($method, $e['message']);
    }
}

function test_modern_log_level(): void
{
    result_of(modern('tools/list', ['_meta' => meta(['io.modelcontextprotocol/logLevel' => 'warning'])]));
    error_of(modern('tools/list', ['_meta' => meta(['io.modelcontextprotocol/logLevel' => 'loud'])]), 400, -32602);
}

function test_modern_notifications_and_responses(): void
{
    $n = ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['_meta' => meta()]];
    $r = post($n, ['MCP-Protocol-Version' => MODERN, 'Mcp-Method' => 'notifications/cancelled']);
    assert_same(202, $r->status);
    assert_same(null, $r->body);
    $r = post(['jsonrpc' => '2.0', 'method' => 'notifications/x'], ['MCP-Protocol-Version' => MODERN]);
    assert_same(202, $r->status);
    // Clients must not send responses in this version.
    error_of(post(['jsonrpc' => '2.0', 'id' => 4, 'result' => new \stdClass()], ['MCP-Protocol-Version' => MODERN]), 400, -32600, 4);
}

function test_modern_errors_keep_known_ids(): void
{
    error_of(post(req('tools/list', [], 'abc'), ['MCP-Protocol-Version' => MODERN]), 400, -32602, 'abc');
    error_of(post(['jsonrpc' => '2.0', 'id' => null, 'method' => 'tools/list'], ['MCP-Protocol-Version' => MODERN]), 400, -32600, 'absent');
    error_of(post('[1', ['MCP-Protocol-Version' => MODERN]), 400, -32700, 'absent');
}

function test_subscriptions_listen(): void
{
    foreach ([7, 'sub-1'] as $id) {
        $r = modern('subscriptions/listen', ['notifications' => ['toolsListChanged' => true, 'resourceSubscriptions' => ['file:///x']]], $id);
        assert_same(200, $r->status);
        assert_same('text/event-stream', $r->headers['Content-Type']);
        assert_same('no', $r->headers['X-Accel-Buffering']);
        preg_match_all('/^data: (.*)$/m', $r->body, $m);
        assert_same(2, count($m[1]), 'two SSE events');
        assert_same(2, substr_count($r->body, "event: message\n"));
        assert_true(str_ends_with($r->body, "\n\n"), 'events end with a blank line');
        $ack = json_decode($m[1][0], true, 512, JSON_THROW_ON_ERROR);
        $done = json_decode($m[1][1], true, 512, JSON_THROW_ON_ERROR);
        assert_same('notifications/subscriptions/acknowledged', $ack['method']);
        assert_false(isset($ack['id']), 'a notification has no id');
        assert_same($id, $ack['params']['_meta']['io.modelcontextprotocol/subscriptionId']);
        assert_same([], $ack['params']['notifications'], 'no notification type is honoured');
        assert_contains('"notifications":{}', $m[1][0], 'empty filter is an object');
        assert_same($id, $done['id']);
        assert_same('complete', $done['result']['resultType']);
        assert_same($id, $done['result']['_meta']['io.modelcontextprotocol/subscriptionId']);
        assert_same('christian-open-library', $done['result']['_meta']['io.modelcontextprotocol/serverInfo']['name']);
    }
    error_of(modern('subscriptions/listen', []), 200, -32602);
}

function test_modern_rate_limit_result(): void
{
    $ctx = ctx(['max_calls_per_minute' => 1]);
    result_of(modern('tools/call', ['name' => 'echo_text', 'arguments' => ['text' => 'a']], 1, [], $ctx));
    $res = result_of(modern('tools/call', ['name' => 'echo_text', 'arguments' => ['text' => 'b']], 2, [], $ctx), 2);
    assert_same(true, $res['isError']);
    assert_same('complete', $res['resultType']);
    assert_contains('Rate limit reached', $res['content'][0]['text']);
}

// ------------------------------------------------------------------------------------------------
// Rate limiting (legacy requests)

function test_rate_limit_per_minute(): void
{
    StubTools::$calls = [];
    $ctx = ctx(['max_calls_per_minute' => 2, 'max_calls_per_day' => 100]);
    $call = fn (int $id) => post(req('tools/call', ['name' => 'echo_text', 'arguments' => ['text' => "n$id"]], $id), [], $ctx);
    assert_same(false, result_of($call(1), 1)['isError']);
    // Unknown tools and other methods do not count.
    error_of(post(req('tools/call', ['name' => 'nope']), [], $ctx), 200, -32602);
    result_of(post(req('tools/list'), [], $ctx));
    assert_same(false, result_of($call(2), 2)['isError']);
    $r = $call(3);
    $res = result_of($r, 3);
    assert_same(true, $res['isError']);
    assert_contains('at most 2 tool calls per minute', $res['content'][0]['text']);
    assert_same(2, count(StubTools::$calls), 'the limited call never reached the tool');
    // Still limited, and listing tools still works.
    assert_same(true, result_of($call(4), 4)['isError']);
    result_of(post(req('tools/list'), [], $ctx));
}

function test_rate_limit_per_day_and_window_reset(): void
{
    $now = 1_800_000_000;   // 2027-01-15 08:00:00 UTC
    $clock = function () use (&$now): int {
        return $now;
    };
    $ctx = ctx(['max_calls_per_minute' => 2, 'max_calls_per_day' => 3], $clock);
    $call = fn () => result_of(post(req('tools/call', ['name' => 'no_args']), [], $ctx))['isError'];
    assert_same(false, $call());
    assert_same(false, $call());
    assert_same(true, $call(), 'third call in the same minute');
    $now += 60;
    assert_same(false, $call(), 'next minute');
    $res = result_of(post(req('tools/call', ['name' => 'no_args']), [], $ctx));
    assert_same(true, $res['isError']);
    assert_contains('Daily limit reached', $res['content'][0]['text']);
    assert_contains('00:00 UTC, in about 16 hours', $res['content'][0]['text']);
    $now += 16 * 3600;   // next UTC day
    assert_same(false, $call(), 'next day');
}

function test_no_tool_call_starts_after_the_deadline(): void
{
    StubTools::$calls = [];
    $fresh = ctx();
    // Same request state, but with the deadline (shared by a whole batch) nearly used up.
    $late = new Context($fresh->config, $fresh->limits, $fresh->log, microtime(true) + 1.0);
    $r = post([req('ping', null, 1), req('tools/call', ['name' => 'echo_text', 'arguments' => ['text' => 'x']], 2)], [], $late);
    $d = decoded($r);
    assert_same([], $d[0]['result']);
    assert_same(true, $d[1]['result']['isError']);
    assert_contains('ran out of time', $d[1]['result']['content'][0]['text']);
    assert_same([], StubTools::$calls, 'the tool was not called');
    assert_same(0, $late->limits->usage()['calls_minute'], 'not counted against the rate limit');
}

// ------------------------------------------------------------------------------------------------
// The real tool definitions (agent B's src/Tools.php), checked against the connector rules.

function test_real_tool_definitions_conform(): void
{
    $src = COL_PLUGIN_DIR . '/src';
    if (!is_file("$src/Tools.php")) {
        col_skip('src/Tools.php does not exist yet');
    }
    foreach (glob("$src/*.php") as $file) {
        require_once $file;
    }
    $r = (new Mcp())->handle('POST', ['Content-Type' => 'application/json'],
        json_encode(req('tools/list')), ctx());
    $tools = result_of($r)['tools'];
    assert_true(count($tools) >= 3, 'at least the three core tools');
    $names = array_column($tools, 'name');
    foreach (['search_library', 'read_passage', 'find_works'] as $core) {
        assert_true(in_array($core, $names, true), "tool $core present");
    }
    assert_same(count($names), count(array_unique($names)), 'unique names');
    foreach ($tools as $t) {
        $n = $t['name'];
        assert_true((bool) preg_match('/^[a-z0-9_]{1,64}$/', $n), "name $n: lower snake case, <= 64 chars");
        assert_true(is_string($t['title'] ?? null) && $t['title'] !== '', "$n: title");
        assert_same($t['title'], $t['annotations']['title'] ?? null, "$n: annotations.title equals title");
        foreach (ANNOTATIONS as $k => $v) {
            assert_same($v, $t['annotations'][$k] ?? null, "$n: annotations.$k");
        }
        assert_true(is_string($t['description'] ?? null) && strlen($t['description']) >= 40, "$n: description");
        $s = $t['inputSchema'] ?? [];
        assert_same('object', $s['type'] ?? null, "$n: inputSchema.type");
        assert_same(false, $s['additionalProperties'] ?? null, "$n: additionalProperties false");
        foreach ($s['properties'] ?? [] as $prop => $ps) {
            assert_true(is_string($ps['description'] ?? null) && $ps['description'] !== '', "$n.$prop: description");
        }
        foreach ($s['required'] ?? [] as $req) {
            assert_true(isset($s['properties'][$req]), "$n: required property $req is defined");
        }
    }
    assert_not_contains('"properties":[]', $r->body);
}
