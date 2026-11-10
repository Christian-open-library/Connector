<?php
/**
 * Tests for src/Voyage.php: the fake mode matches indexer/ivf.py fake_embed (values computed in
 * Python by make_fixture.py), the request shapes, retries, timeouts, the token budget, and that
 * errors never contain the API key. HTTP is replaced by a recorded transport; nothing goes out.
 */

declare(strict_types=1);

namespace ColLibraryTest\Voyage;

use ColLibrary\Voyage;
use ColLibrary\VoyageException;
use ColLibraryTest\Fixture;

require_once __DIR__ . '/fixture/testlib.php';

function test_fake_embed_matches_python(): void
{
    $cases = Fixture\expected()['fake_embed'];
    assert_true(count($cases) >= 10, 'expected.json has fake_embed cases');
    foreach ($cases as $case) {
        $php = Voyage::fakeEmbed($case['text'], $case['dims']);
        assert_same($case['dims'], count($php), 'length');
        $worst = 0.0;
        foreach ($case['vec'] as $i => $x) {
            $worst = max($worst, abs($x - $php[$i]));
        }
        // Python accumulates and normalizes in float32, PHP in float64.
        assert_true($worst < 1e-6, 'fake_embed(' . json_encode($case['text']) . ", {$case['dims']}) differs by $worst");
    }
}

function test_fake_mode_is_deterministic(): void
{
    $a = Voyage::fakeEmbed('assurance of salvation', 512);
    assert_same($a, Voyage::fakeEmbed('assurance of salvation', 512));
    assert_same(array_fill(0, 8, 0.0), Voyage::fakeEmbed('!!! 123', 8), 'no [a-z] words: zero vector');
    $docs = ['the killing of sin', 'grace and peace', 'sin and grace together'];
    $r1 = Voyage::fakeRerank('sin grace', $docs);
    assert_same($r1, Voyage::fakeRerank('sin grace', $docs));
    assert_same([2, 0, 1], array_column($r1, 'index'), 'both words, then the first doc with one word (ties keep order)');
    assert_same(1.0, $r1[0]['score']);
}

function test_fake_mode_makes_no_requests(): void
{
    $store = Fixture\store_or_skip();
    $calls = [];
    $v = new Voyage(true, '', Voyage::DEFAULT_BASE_URL, 'm', 8, 'r', null, null, Fixture\voyage_like($calls));
    assert_same(8, count($v->embedQuery('grace', microtime(true) + 10)));
    assert_same(2, count($v->rerank('grace', ['a', 'grace'], 5, microtime(true) + 10)));
    assert_same([], $calls, 'the fake never uses the transport');
    assert_true($v->isFake() && $v->configured());
}

function test_fake_mode_needs_explicit_true_in_config(): void
{
    $store = Fixture\store_or_skip();
    assert_true(Voyage::forBuild(Fixture\context(), $store)->isFake(), 'fixture config sets voyage_fake => true');
    assert_false(Voyage::forBuild(Fixture\context(['voyage_fake' => false]), $store)->isFake());
    // Config itself refuses anything but a real bool, e.g. "1" or 1.
    foreach (['1', 1, 'true'] as $bad) {
        assert_throws(\ColLibrary\ConfigException::class, fn () => Fixture\config(['voyage_fake' => $bad]));
    }
}

function test_for_build_takes_models_from_the_build(): void
{
    $store = Fixture\store_or_skip();
    $calls = [];
    $ctx = Fixture\context(['voyage_fake' => false, 'voyage_api_key' => Fixture\VOYAGE_KEY, 'embed_model' => 'cfg-model',
                            'rerank_model' => 'cfg-rerank', 'embed_dims' => 1024]);
    $v = Voyage::forBuild($ctx, $store, Fixture\voyage_like($calls));
    $v->embedQuery('grace', microtime(true) + 10);
    $v->rerank('grace', ['a', 'b'], 2, microtime(true) + 10);
    assert_same('voyage-4-lite', $calls[0]['body']['model'], 'meta query_model wins over the config');
    assert_same(8, $calls[0]['body']['output_dimension'], 'meta embed_dims');
    assert_same('rerank-3', $calls[1]['body']['model'], 'meta rerank_model');
}

function test_embed_request_and_response(): void
{
    $store = Fixture\store_or_skip();
    $calls = [];
    $limits = Fixture\limits();
    $v = Fixture\real_voyage($store, Fixture\voyage_like($calls), $limits);
    $vec = $v->embedQuery('the chief end of man', microtime(true) + 20);
    assert_same(1, count($calls));
    assert_same('/embeddings', $calls[0]['path']);
    assert_same(['input' => ['the chief end of man'], 'model' => 'voyage-4-lite', 'input_type' => 'query',
                 'output_dimension' => 8, 'output_dtype' => 'float', 'encoding_format' => 'base64', 'truncation' => true],
        $calls[0]['body']);
    assert_true($calls[0]['timeout'] <= Voyage::EMBED_TIMEOUT, 'embed timeout at most 8 s');
    $expected = Voyage::fakeEmbed('the chief end of man', 8);
    foreach ($expected as $i => $x) {
        assert_true(abs($x - $vec[$i]) < 1e-6, 'float32 round trip');   // base64 float32 LE decoded
    }
    assert_same(7, $limits->usage()['voyage_tokens'], 'usage.total_tokens added to the daily count');
}

function test_rerank_request_and_response(): void
{
    $store = Fixture\store_or_skip();
    $calls = [];
    $transport = function (string $url, string $body) use (&$calls): array {
        $calls[] = json_decode($body, true);
        return ['status' => 200, 'body' => json_encode(['data' => [
            ['index' => 2, 'relevance_score' => 0.9], ['index' => 0, 'relevance_score' => 0.5],
            ['index' => 9, 'relevance_score' => 0.99], ['index' => 2, 'relevance_score' => 0.1],   // out of range, repeated
            ['index' => '1', 'relevance_score' => 0.7]], 'usage' => ['total_tokens' => 50]])];
    };
    $v = Fixture\real_voyage($store, \Closure::fromCallable($transport));
    $out = $v->rerank('q', ['d0', 'd1', 'd2'], 2, microtime(true) + 20);
    assert_same(['query' => 'q', 'documents' => ['d0', 'd1', 'd2'], 'model' => 'rerank-3', 'top_k' => 2, 'truncation' => true],
        $calls[0]);
    assert_same([['index' => 2, 'score' => 0.9], ['index' => 0, 'score' => 0.5]], $out, 'bad rows ignored, best first');
    assert_same([], $v->rerank('q', [], 5, microtime(true) + 20), 'no documents: no call');
    assert_same(1, count($calls));
}

function test_one_retry_on_429_and_5xx(): void
{
    $store = Fixture\store_or_skip();
    foreach ([429, 500, 503] as $status) {
        $calls = [];
        $v = Fixture\real_voyage($store, Fixture\voyage_like($calls, ['/embeddings' => [['status' => $status, 'retryAfter' => 0.0]]]));
        $v->embedQuery('grace', microtime(true) + 20);
        assert_same(2, count($calls), "HTTP $status is retried once");
    }
    $calls = [];
    $fail = ['status' => 502, 'body' => '{"detail":"bad gateway"}'];
    $v = Fixture\real_voyage($store, Fixture\voyage_like($calls, ['/rerank' => [$fail, $fail, $fail]]));
    $e = assert_throws(VoyageException::class, fn () => $v->rerank('q', ['a', 'b'], 2, microtime(true) + 20));
    assert_contains('HTTP 502', $e->getMessage());
    assert_same(2, count($calls), 'only one retry');
}

function test_no_retry_on_client_errors_and_timeouts(): void
{
    $store = Fixture\store_or_skip();
    foreach ([['status' => 400, 'body' => '{"detail":"bad input"}'], ['status' => 401], ['status' => 403],
              ['status' => 0, 'error' => 'timed out', 'timeout' => true]] as $resp) {
        $calls = [];
        $v = Fixture\real_voyage($store, Fixture\voyage_like($calls, ['/embeddings' => [$resp, $resp]]));
        assert_throws(VoyageException::class, fn () => $v->embedQuery('grace', microtime(true) + 20));
        assert_same(1, count($calls), 'no retry for ' . json_encode($resp));
    }
    $calls = [];
    $refused = ['status' => 0, 'error' => 'curl error 7 (connection refused)'];
    $v = Fixture\real_voyage($store, Fixture\voyage_like($calls, ['/embeddings' => [$refused]]));
    $v->embedQuery('grace', microtime(true) + 20);
    assert_same(2, count($calls), 'a failed connection is retried');
}

function test_bad_responses_are_errors(): void
{
    $store = Fixture\store_or_skip();
    $bodies = ['not json', '[]', '{"data":[]}', '{"data":[{"embedding":"!!!"}]}',
               '{"data":[{"embedding":"' . base64_encode(pack('g*', 1.0, 2.0)) . '"}]}',          // 2 dims, not 8
               '{"data":[{"embedding":[1,2,3,4,5,6,7,"x"]}]}', '{"data":[{"embedding":[1,2,3,4,5,6,7,1e999]}]}'];
    foreach ($bodies as $body) {
        $calls = [];
        $v = Fixture\real_voyage($store, Fixture\voyage_like($calls, ['/embeddings' => [['status' => 200, 'body' => $body]]]));
        assert_throws(VoyageException::class, fn () => $v->embedQuery('grace', microtime(true) + 20), $body);
    }
    $calls = [];
    $v = Fixture\real_voyage($store, Fixture\voyage_like($calls, ['/embeddings' => [['status' => 200,
        'body' => '{"data":[{"embedding":[0,1,0,0,0,0,0,0]}]}']]]));
    assert_same([0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0], $v->embedQuery('grace', microtime(true) + 20),
        'a JSON list (encoding_format ignored) is accepted');
}

function test_deadline_caps_timeouts(): void
{
    $store = Fixture\store_or_skip();
    $calls = [];
    $v = Fixture\real_voyage($store, Fixture\voyage_like($calls));
    $v->rerank('q', ['a', 'b'], 2, microtime(true) + 4.0);
    assert_true($calls[0]['timeout'] <= 3.5 && $calls[0]['timeout'] > 3.0, 'timeout cut to the deadline: ' . $calls[0]['timeout']);
    $calls = [];
    $v->rerank('q', ['a', 'b'], 2, microtime(true) + 30.0);
    assert_same(Voyage::RERANK_TIMEOUT, $calls[0]['timeout'], 'rerank timeout 10 s');
    $calls = [];
    $e = assert_throws(VoyageException::class, fn () => $v->embedQuery('q', microtime(true) + 1.2));
    assert_contains('no time left', $e->getMessage());
    assert_same([], $calls, 'no request when the deadline is too close');
}

function test_budget_and_missing_key(): void
{
    $store = Fixture\store_or_skip();
    $calls = [];
    $v = Fixture\real_voyage($store, Fixture\voyage_like($calls), Fixture\limits(0));
    $e = assert_throws(VoyageException::class, fn () => $v->embedQuery('grace', microtime(true) + 20));
    assert_contains('budget', $e->getMessage());
    $limits = Fixture\limits(100);
    $v = Fixture\real_voyage($store, Fixture\voyage_like($calls), $limits);
    assert_throws(VoyageException::class, fn () => $v->rerank('q', [str_repeat('word ', 200)], 1, microtime(true) + 20),
        'a 1,000-character document is over a 100-token budget');
    $v = Fixture\real_voyage($store, Fixture\voyage_like($calls), null, '');
    assert_false($v->configured());
    assert_contains('no Voyage API key', assert_throws(VoyageException::class, fn () => $v->embedQuery('grace', microtime(true) + 20))->getMessage());
    assert_same([], $calls, 'nothing was sent');
    $bad = new Voyage(false, Fixture\VOYAGE_KEY, 'http://api.voyageai.com/v1', 'm', 8, 'r', null, null, Fixture\voyage_like($calls));
    assert_false($bad->configured(), 'plain http base URL refused');
    assert_throws(VoyageException::class, fn () => $bad->embedQuery('grace', microtime(true) + 20));
    assert_same([], $calls);
}

function test_errors_never_contain_the_key(): void
{
    $store = Fixture\store_or_skip();
    $key = Fixture\VOYAGE_KEY;
    $logDir = col_tmpdir('voylog');
    $log = new \ColLibrary\Log('debug', $logDir, false, [$key]);
    $echo = ['status' => 401, 'body' => json_encode(['detail' => "Provided API key $key is invalid"])];
    $calls = [];
    $v = Fixture\real_voyage($store, Fixture\voyage_like($calls, ['/embeddings' => [$echo]]), null, $key, $log);
    $e = assert_throws(VoyageException::class, fn () => $v->embedQuery('grace', microtime(true) + 20));
    assert_not_contains($key, $e->getMessage());
    $logged = implode('', array_map('file_get_contents', glob("$logDir/*.jsonl") ?: []));
    assert_contains('voyage_error', $logged, 'the failure is logged');
    assert_not_contains($key, $logged, 'but never the key');
    assert_contains('[redacted]', $logged, 'the echoed key was replaced');
}
