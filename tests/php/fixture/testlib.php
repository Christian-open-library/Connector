<?php
/**
 * Helpers shared by the search/tools tests (search_test, text_test, tools_test, voyage_test,
 * xref_test). Uses tests/php/run.php's helpers (col_src, col_tmpdir, assert_*). The fixture data in
 * this folder is built by tests/php/make_fixture.py (4 works, 28 passages, 8-dim fake vectors, and
 * cross-reference tables from tests/php/make_xref_fixture.py).
 */

declare(strict_types=1);

namespace ColLibraryTest\Fixture;

use ColLibrary\Config;
use ColLibrary\Context;
use ColLibrary\Log;
use ColLibrary\RateLimit;
use ColLibrary\Store;
use ColLibrary\Voyage;

col_src('Context', 'Config', 'Log', 'RateLimit', 'Text', 'Store', 'Voyage', 'Search', 'Churches', 'Format', 'Tools', 'Bible',
    'Citations', 'Dictionary', 'Quote');

const DIR = __DIR__;
const REALMODE_DIR = __DIR__ . '/realmode';   // same build, meta embed_fake = 0
const VOYAGE_KEY = 'pa-test-SECRET-voyage-key-0123456789';
const FIXED_TIME = 1_800_000_000;

/** Values computed by make_fixture.py in Python (fake_embed vectors, vector and bm25 rankings). */
function expected(): array
{
    static $e = null;
    return $e ??= json_decode((string) file_get_contents(DIR . '/expected.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** The fixture's test config (voyage_fake on) with overrides and a fresh state dir. */
function config(array $overrides = []): Config
{
    $values = require DIR . '/config.php';
    return Config::fromArray($overrides + ['state_dir' => col_tmpdir('colb')] + $values, DIR . '/config.php');
}

function context(array $overrides = [], float $seconds = 25.0, ?int $voyageTokensPerDay = null): Context
{
    $cfg = config($overrides);
    $log = Log::fromConfig($cfg);
    $limits = new RateLimit($cfg->stateDir(), 100000, 1000000, $voyageTokensPerDay ?? $cfg->maxVoyageTokensPerDay(),
        $log, fn () => FIXED_TIME);
    return new Context($cfg, $limits, $log, microtime(true) + $seconds);
}

function limits(int $voyageTokensPerDay = 3_000_000): RateLimit
{
    return new RateLimit(col_tmpdir('colb'), 100000, 1000000, $voyageTokensPerDay, null, fn () => FIXED_TIME);
}

/** The fixture build; skips the test if make_fixture.py has not been run. */
function store_or_skip(string $dir = DIR): Store
{
    if (!is_file($dir . '/library.sqlite')) {
        col_skip('fixture missing: run .venv/bin/python tests/php/make_fixture.py');
    }
    return new Store($dir);
}

function fake_voyage(Store $store): Voyage
{
    return new Voyage(true, '', Voyage::DEFAULT_BASE_URL, 'voyage-4-lite', $store->dims(), 'rerank-3');
}

/** A Voyage client in real mode whose HTTP calls go to $transport. */
function real_voyage(Store $store, \Closure $transport, ?RateLimit $limits = null, string $key = VOYAGE_KEY,
                     ?Log $log = null): Voyage
{
    return new Voyage(false, $key, Voyage::DEFAULT_BASE_URL, 'voyage-4-lite', $store->dims(), 'rerank-3',
        $limits ?? limits(), $log, $transport);
}

/**
 * A transport that answers like the Voyage API (vectors from the fake embedding, rerank by word
 * overlap) and records every call in $calls. $script optionally lists responses to give first,
 * per path: ['/embeddings' => [['status' => 500], ...], '/rerank' => [...]].
 */
function voyage_like(array &$calls, array $script = []): \Closure
{
    return function (string $url, string $body, float $timeout) use (&$calls, &$script): array {
        $path = substr($url, strlen(Voyage::DEFAULT_BASE_URL));
        $req = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $calls[] = ['path' => $path, 'body' => $req, 'timeout' => $timeout];
        if (!empty($script[$path])) {
            return array_shift($script[$path]);
        }
        if ($path === '/embeddings') {
            $vec = Voyage::fakeEmbed($req['input'][0], (int) $req['output_dimension']);
            return ['status' => 200, 'body' => json_encode([
                'data' => [['embedding' => base64_encode(pack('g*', ...$vec)), 'index' => 0]],
                'model' => $req['model'], 'usage' => ['total_tokens' => 7]])];
        }
        if ($path === '/rerank') {
            $data = [];
            foreach (array_slice(Voyage::fakeRerank($req['query'], $req['documents']), 0, $req['top_k']) as $r) {
                $data[] = ['index' => $r['index'], 'relevance_score' => $r['score']];
            }
            return ['status' => 200, 'body' => json_encode(['data' => $data, 'usage' => ['total_tokens' => 1234]])];
        }
        return ['status' => 404, 'body' => '{"detail":"Not found"}'];
    };
}

/** Text of a tool result. */
function text_of(array $result): string
{
    assert_true(is_array($result['content'] ?? null) && count($result['content']) === 1, 'one content block');
    assert_same('text', $result['content'][0]['type'] ?? null, 'content type');
    return $result['content'][0]['text'];
}

/** Passage ids in the "[n] P<id>" header lines of a search result, in order. @return list<int> */
function result_ids(string $text): array
{
    preg_match_all('/^\[\d+\] P(\d+) · /m', $text, $m);
    return array_map('intval', $m[1]);
}
