<?php
/**
 * Tests for src/OAuth.php (AuthKit bearer tokens, member list, metadata) and Http::processBearer.
 * Tokens are signed here with a throwaway RSA key; the JWKS comes from a closure, not the network.
 */

namespace ColLibraryTest\OAuth {

use ColLibrary\Config;
use ColLibrary\Context;
use ColLibrary\Http;
use ColLibrary\Mcp;
use ColLibrary\OAuth;
use ColLibrary\OAuthError;

col_src('Context', 'Config', 'Log', 'RateLimit', 'Mcp', 'Http', 'Links', 'OAuth');

const ISS = 'https://example-auth.authkit.app';
const RES = 'https://mcp.example.org/mcp';
const NOW = 1_800_000_000;
const SUB = 'user_01ABCDEFGHJKMNPQRSTVWXYZ';
const PING = '{"jsonrpc":"2.0","id":1,"method":"ping"}';
const CALL = '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"echo_text","arguments":{"text":"hi"}}}';

final class StubTools
{
    public static function definitions(): array
    {
        return [['name' => 'echo_text', 'title' => 'Echo', 'description' => 'Returns its text.',
                 'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string', 'description' => 'T']],
                                   'additionalProperties' => false],
                 'annotations' => ['title' => 'Echo', 'readOnlyHint' => true, 'destructiveHint' => false,
                                   'idempotentHint' => true, 'openWorldHint' => false]]];
    }

    public static function call(string $name, array $arguments, Context $ctx): array
    {
        return ['content' => [['type' => 'text', 'text' => 'echo: ' . ($arguments['text'] ?? '')]], 'isError' => false];
    }
}

function b64u(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/** A throwaway signing key: [OpenSSL key, JWKS JSON with kid "k1"]. */
function keypair(string $kid = 'k1'): array
{
    static $keys = [];
    if (!isset($keys[$kid])) {
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($k === false) {
            col_skip('this PHP cannot create RSA keys (run these tests with a native PHP, e.g. on the office server)');
        }
        $d = openssl_pkey_get_details($k)['rsa'];
        $keys[$kid] = [$k, ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid, 'n' => b64u($d['n']), 'e' => b64u($d['e'])]];
    }
    return $keys[$kid];
}

function token(array $claims = [], array $header = [], string $kid = 'k1'): string
{
    [$key] = keypair($kid);
    $h = b64u(json_encode($header + ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid]));
    $p = b64u(json_encode($claims + ['iss' => ISS, 'sub' => SUB, 'exp' => NOW + 300, 'iat' => NOW - 10, 'aud' => RES]));
    openssl_sign("$h.$p", $sig, $key, OPENSSL_ALGO_SHA256);
    return "$h.$p." . b64u($sig);
}

function settings(string $dir): array
{
    return ['issuer' => ISS, 'jwks_uri' => ISS . '/oauth2/jwks', 'resource' => RES,
            'resource_metadata' => 'https://mcp.example.org/.well-known/oauth-protected-resource',
            'members_file' => "$dir/members.json", 'calls_per_day' => 500, 'calls_per_minute' => 2,
            'request_access_url' => 'https://github.com/Example-org'];
}

/** OAuth with a fixed clock and a JWKS closure that counts its calls. */
function oauth(string $dir, array &$fetches, array $kids = ['k1'], ?int &$now = null): OAuth
{
    $now ??= NOW;
    return new OAuth(settings($dir), $dir, function (string $url) use (&$fetches, $kids): string {
        $fetches[] = $url;
        return json_encode(['keys' => array_map(fn ($k) => keypair($k)[1], $kids)]);
    }, function () use (&$now): int {
        return $now;
    });
}

function members(string $dir, array $subs): void
{
    file_put_contents("$dir/members.json", json_encode(['members' => $subs]));
}

function test_valid_token_is_accepted_and_jwks_cached(): void
{
    $dir = col_tmpdir('oauth');
    $fetches = [];
    $o = oauth($dir, $fetches);
    assert_same(SUB, $o->verify(token())['sub']);
    assert_same(SUB, $o->verify(token())['sub']);
    assert_same(1, count($fetches), 'the keys are fetched once and cached');
    assert_true(is_file("$dir/oauth-jwks.json"), 'cache file');
    assert_same('600', substr(sprintf('%o', fileperms("$dir/oauth-jwks.json")), -3));
    $fetches2 = [];
    assert_same(SUB, oauth($dir, $fetches2)->verify(token())['sub'], 'another request uses the cache file');
    assert_same(0, count($fetches2));
}

function test_bad_tokens_are_refused(): void
{
    $dir = col_tmpdir('oauth');
    $fetches = [];
    $o = oauth($dir, $fetches);
    $good = token();
    [$h, $p, $s] = explode('.', $good);
    $cases = [
        'malformed' => 'abc',
        'two parts' => "$h.$p",
        'tampered claims' => $h . '.' . b64u(json_encode(['iss' => ISS, 'sub' => 'user_01ZZZZZZZZZZZZZZZZZZZZZZZZ', 'exp' => NOW + 300])) . ".$s",
        'alg none' => b64u(json_encode(['alg' => 'none', 'kid' => 'k1'])) . ".$p.",
        'HS256' => b64u(json_encode(['alg' => 'HS256', 'kid' => 'k1'])) . ".$p.$s",
        'wrong issuer' => token(['iss' => 'https://evil.authkit.app']),
        'expired' => token(['exp' => NOW - 120]),
        'not yet valid' => token(['nbf' => NOW + 600]),
        'issued in the future' => token(['iat' => NOW + 600]),
        'other audience' => token(['aud' => 'https://other.example/mcp']),
        'no user' => token(['sub' => 'someone']),
        'unknown key' => token([], [], 'k2'),
        'oversized' => str_repeat('a', 9000) . '.b.c',
    ];
    foreach ($cases as $name => $jwt) {
        assert_throws(OAuthError::class, fn () => $o->verify($jwt), $name);
    }
    assert_same(SUB, $o->verify(token(['aud' => [RES, 'other']]))['sub'], 'audience list containing ours');
    assert_same(SUB, $o->verify(token())['sub'], 'audience naming this resource');
}

/** A token signed by the test key with exactly these claims (no defaults added). */
function token_exact(array $claims, string $kid = 'k1'): string
{
    [$key] = keypair($kid);
    $h = b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid]));
    $p = b64u(json_encode($claims));
    openssl_sign("$h.$p", $sig, $key, OPENSSL_ALGO_SHA256);
    return "$h.$p." . b64u($sig);
}

function test_token_without_an_audience_is_refused(): void
{
    $dir = col_tmpdir('oauth');
    $fetches = [];
    $o = oauth($dir, $fetches);
    $t = token_exact(['iss' => ISS, 'sub' => SUB, 'exp' => NOW + 300, 'iat' => NOW - 10]);
    $e = assert_throws(OAuthError::class, fn () => $o->verify($t));
    assert_contains('no audience', $e->getMessage());
}

function test_failing_jwks_is_tried_at_most_once_a_minute(): void
{
    $dir = col_tmpdir('oauth');
    $fetches = [];
    $now = NOW;
    oauth($dir, $fetches, ['k1'], $now)->verify(token());
    $now += 120;
    $tries = 0;
    $o = new OAuth(settings($dir), $dir, function () use (&$tries): string {
        $tries++;
        throw new \RuntimeException('down');
    }, function () use (&$now): int {
        return $now;
    });
    $unknown = token([], [], 'zz');
    for ($i = 0; $i < 5; $i++) {
        assert_throws(OAuthError::class, fn () => $o->verify($unknown));
    }
    assert_same(1, $tries, 'five unknown-key tokens while AuthKit is down: one fetch');
    assert_same(SUB, $o->verify(token(['exp' => $now + 300, 'iat' => $now]))['sub'], 'the old key still works');
    $now += 61;
    assert_throws(OAuthError::class, fn () => $o->verify($unknown));
    assert_same(2, $tries, 'a minute later: one more try');
}

function test_one_unusable_key_does_not_block_the_others(): void
{
    $dir = col_tmpdir('oauth');
    $o = new OAuth(settings($dir), $dir, function (): string {
        return json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'bad', 'use' => 'sig', 'n' => 'AQAB', 'e' => 'AQAB'], keypair('k1')[1]]]);
    }, fn (): int => NOW);
    assert_same(SUB, $o->verify(token())['sub']);
}

function test_unknown_key_refetches_at_most_once_a_minute(): void
{
    $dir = col_tmpdir('oauth');
    $fetches = [];
    $now = NOW;
    $o = oauth($dir, $fetches, ['k1'], $now);
    $o->verify(token());
    assert_throws(OAuthError::class, fn () => $o->verify(token([], [], 'k2')));
    assert_same(1, count($fetches), 'within a minute of the last fetch: no refetch');
    $now += 61;
    assert_throws(OAuthError::class, fn () => $o->verify(token([], [], 'k2')));
    assert_same(2, count($fetches), 'after a minute: one refetch');
    // AuthKit rotated its keys: the new key is found by the refetch
    $now += 61;
    $fetches3 = [];
    $o2 = oauth($dir, $fetches3, ['k1', 'k2'], $now);
    assert_same(SUB, $o2->verify(token([], [], 'k2'))['sub']);
    assert_same(1, count($fetches3));
}

function test_unreachable_jwks_keeps_the_old_keys(): void
{
    $dir = col_tmpdir('oauth');
    $fetches = [];
    $now = NOW;
    oauth($dir, $fetches, ['k1'], $now)->verify(token());
    $now += OAuth::JWKS_TTL + 1;
    $o = new OAuth(settings($dir), $dir, function (): string {
        throw new \RuntimeException('down');
    }, function () use (&$now): int {
        return $now;
    });
    $before = strlen(col_error_log());
    assert_same(SUB, $o->verify(token(['exp' => $now + 300, 'iat' => $now]))['sub'], 'old keys still verify');
    assert_contains('JWKS fetch failed', substr(col_error_log(), $before));
}

function test_jwk_to_pem_round_trip(): void
{
    [, $jwk] = keypair('k1');
    $pem = OAuth::jwkToPem($jwk['n'], $jwk['e']);
    assert_contains('-----BEGIN PUBLIC KEY-----', $pem);
    $d = openssl_pkey_get_details(openssl_pkey_get_public($pem));
    assert_same(b64u($d['rsa']['n']), $jwk['n']);
    assert_throws(\RuntimeException::class, fn () => OAuth::jwkToPem('AQAB', 'AQAB'));
}

function test_metadata_and_challenge(): void
{
    $fetches = [];
    $o = oauth(col_tmpdir('oauth'), $fetches);
    $m = json_decode($o->metadata(), true);
    assert_same(RES, $m['resource']);
    assert_same([ISS], $m['authorization_servers']);
    assert_same(['header'], $m['bearer_methods_supported']);
    assert_same('Bearer resource_metadata="https://mcp.example.org/.well-known/oauth-protected-resource"', $o->challenge());
    assert_contains('error="invalid_token"', $o->challenge(true));
}

function test_member_list(): void
{
    $dir = col_tmpdir('oauth');
    $fetches = [];
    $o = oauth($dir, $fetches);
    $before = strlen(col_error_log());
    assert_false($o->isMember(SUB), 'no list: nobody');
    assert_contains('no member list', substr(col_error_log(), $before));
    members($dir, [SUB]);
    assert_true($o->isMember(SUB));
    assert_false($o->isMember('user_01OTHERxxxxxxxxxxxxxxxxx'));
    file_put_contents("$dir/members.json", '{"members": ');
    touch("$dir/members.json", time() + 5);
    assert_false($o->isMember(SUB), 'a broken list lets nobody in');
    $p = $o->principal(SUB);
    assert_same('u' . substr(hash('sha256', SUB), 0, 12), $p['id']);
    assert_same(2, $p['calls_per_minute']);
}

function config(string $dir, array $extra = []): Config
{
    return Config::fromArray($extra + ['url_key' => 'Own3r_-abcdefghijklmnopqrstuvwxyz0123456789ABCD',
                                       'data_dir' => $dir, 'state_dir' => $dir]);
}

function bearer(Config $c, OAuth $o, ?string $auth, string $body = PING): \ColLibrary\Response
{
    $h = ['Content-Type' => 'application/json'] + ($auth !== null ? ['Authorization' => $auth] : []);
    return Http::processBearer('POST', [], $h, $body, $o, $c, new Mcp(StubTools::class));
}

function test_signed_in_endpoint(): void
{
    $dir = col_tmpdir('oauth');
    $fetches = [];
    $o = oauth($dir, $fetches);
    $c = config($dir, ['log_level' => 'info']);
    $r = bearer($c, $o, null);
    assert_same(401, $r->status, 'no token');
    assert_contains('resource_metadata=', $r->headers['WWW-Authenticate']);
    assert_not_contains('invalid_token', $r->headers['WWW-Authenticate']);
    $r = bearer($c, $o, 'Basic abc');
    assert_same(401, $r->status);
    $r = bearer($c, $o, 'Bearer ' . token(['exp' => NOW - 600]));
    assert_same(401, $r->status, 'expired token');
    assert_contains('error="invalid_token"', $r->headers['WWW-Authenticate']);
    $r = bearer($c, $o, 'Bearer ' . token());
    assert_same(403, $r->status, 'valid, but not a member');
    assert_contains('https://github.com/Example-org', $r->body);
    members($dir, [SUB]);
    $r = bearer($c, $o, 'Bearer ' . token());
    assert_same(200, $r->status, 'member');
    assert_same('{"jsonrpc":"2.0","id":1,"result":{}}', $r->body);
    // per-user limit (2 a minute in these settings)
    assert_contains('echo: hi', bearer($c, $o, 'Bearer ' . token(), CALL)->body);
    assert_contains('echo: hi', bearer($c, $o, 'Bearer ' . token(), CALL)->body);
    assert_contains('this link allows at most 2 tool calls per minute', bearer($c, $o, 'Bearer ' . token(), CALL)->body);
    $text = file_get_contents(glob("$dir/log-*.jsonl")[0]);
    assert_not_contains(SUB, $text, 'the log never names the user');
    assert_contains('"reason":"not a member"', $text);
    assert_contains('"link":"u' . substr(hash('sha256', SUB), 0, 12) . '"', $text);
}

}

namespace ColLibraryTest\OAuthOpen {

use ColLibrary\OAuth;
use function ColLibraryTest\OAuth\{bearer, config, keypair, settings, token};
use const ColLibraryTest\OAuth\{NOW, SUB};

function test_open_to_anyone_signed_in_except_blocked(): void
{
    $dir = col_tmpdir('oauth');
    $o = new OAuth(['members_only' => false] + settings($dir), $dir,
        fn (): string => json_encode(['keys' => [keypair('k1')[1]]]), fn (): int => NOW);
    $c = config($dir);
    assert_false($o->membersOnly());
    assert_same(200, bearer($c, $o, 'Bearer ' . token())->status, 'anyone signed in, no list needed');
    file_put_contents("$dir/members.json", json_encode(['members' => [], 'blocked' => [SUB]]));
    $r = bearer($c, $o, 'Bearer ' . token());
    assert_same(403, $r->status, 'blocked');
    assert_contains("can't use the Christian Open Library connector", $r->body);
    file_put_contents("$dir/members.json", '{"members": ');
    touch("$dir/members.json", time() + 5);
    $before = strlen(col_error_log());
    assert_same(200, bearer($c, $o, 'Bearer ' . token())->status, 'a broken list blocks nobody in open mode');
    assert_contains('unreadable member list', substr(col_error_log(), $before));
}

}
