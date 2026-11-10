<?php
/**
 * Sign-in for the connector on the office server: OAuth 2.1 bearer tokens issued by WorkOS AuthKit
 * (GitHub login), checked here as an MCP resource server (RFC 9728 metadata, RS256 JWTs), plus the
 * lists homebox pushes (/srv/library/links/members.json: {"members": [...], "blocked": [...]}, WorkOS
 * user ids). With members_only off (the default since 2026-11-10) anyone signed in may use it, except
 * blocked accounts; with it on, only the members.
 *
 * Settings come from the release's oauth.php: issuer, jwks_uri, resource, resource_metadata,
 * members_file, calls_per_day, calls_per_minute, request_access_url. The JWKS is fetched through the
 * PHP-FPM proxy and cached in the state folder. Nothing here holds a secret.
 */

namespace ColLibrary;

defined('ABSPATH') || exit;

final class OAuthError extends \RuntimeException
{
}

final class OAuth
{
    public const LEEWAY = 60;          // seconds of clock difference allowed for exp, nbf and iat
    public const JWKS_TTL = 21600;     // refresh the signing keys every 6 hours
    public const JWKS_RETRY = 60;      // an unknown key id refetches at most once a minute
    public const MAX_TOKEN = 8192;
    public const SUB = '/^user_[A-Za-z0-9]{10,64}$/D';

    /** @param \Closure|null $fetch fn(string $url): string, for tests; @param \Closure|null $clock fn(): int */
    public function __construct(
        private readonly array $s,
        private readonly string $stateDir,
        private readonly ?\Closure $fetch = null,
        private readonly ?\Closure $clock = null,
    ) {
        foreach (['issuer', 'jwks_uri', 'resource', 'resource_metadata', 'members_file'] as $k) {
            if (!is_string($s[$k] ?? null) || $s[$k] === '') {
                throw new \InvalidArgumentException("oauth setting $k is missing");
            }
        }
    }

    /** Protected resource metadata (RFC 9728), as served at /.well-known/oauth-protected-resource. */
    public function metadata(): string
    {
        return json_encode([
            'resource' => $this->s['resource'],
            'authorization_servers' => [$this->s['issuer']],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Christian Open Library',
            'resource_documentation' => $this->s['request_access_url'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /** The WWW-Authenticate value for a 401. */
    public function challenge(bool $invalidToken = false): string
    {
        return 'Bearer resource_metadata="' . $this->s['resource_metadata'] . '"'
            . ($invalidToken ? ', error="invalid_token"' : '');
    }

    /**
     * The claims of a valid token: RS256 signature by a current AuthKit key, the right issuer, not
     * expired, for this resource (aud must name it; AuthKit always sets aud, to the resource indicator or
     * to the environment's default), and a WorkOS user as subject.
     * @throws OAuthError with a short reason (logged, never shown to the caller)
     */
    public function verify(string $jwt): array
    {
        if (strlen($jwt) > self::MAX_TOKEN || substr_count($jwt, '.') !== 2) {
            throw new OAuthError('malformed token');
        }
        [$h64, $p64, $s64] = explode('.', $jwt);
        $header = json_decode((string) self::b64url($h64), true);
        $claims = json_decode((string) self::b64url($p64), true);
        $sig = self::b64url($s64);
        if (!is_array($header) || !is_array($claims) || $sig === null || $sig === '') {
            throw new OAuthError('malformed token');
        }
        if (($header['alg'] ?? '') !== 'RS256') {
            throw new OAuthError('unsupported algorithm');
        }
        $kid = $header['kid'] ?? '';
        if (!is_string($kid) || $kid === '' || strlen($kid) > 200) {
            throw new OAuthError('no key id');
        }
        $key = openssl_pkey_get_public($this->key($kid));
        if ($key === false || openssl_verify("$h64.$p64", $sig, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new OAuthError('bad signature');
        }
        $now = $this->now();
        if (($claims['iss'] ?? null) !== $this->s['issuer']) {
            throw new OAuthError('wrong issuer');
        }
        if (!is_int($claims['exp'] ?? null) || $claims['exp'] < $now - self::LEEWAY) {
            throw new OAuthError('expired');
        }
        foreach (['nbf', 'iat'] as $k) {
            if (array_key_exists($k, $claims) && (!is_int($claims[$k]) || $claims[$k] > $now + self::LEEWAY)) {
                throw new OAuthError("$k in the future");
            }
        }
        if (!array_key_exists('aud', $claims)) {
            throw new OAuthError('token names no audience');
        }
        $aud = is_array($claims['aud']) ? $claims['aud'] : [$claims['aud']];
        if (!in_array($this->s['resource'], $aud, true)) {
            throw new OAuthError('token is for another resource');
        }
        if (!is_string($claims['sub'] ?? null) || !preg_match(self::SUB, $claims['sub'])) {
            throw new OAuthError('no user');
        }
        return $claims;
    }

    /** May this signed-in user use the connector? Blocked: never. members_only: only members. Else: yes. */
    public function allowed(string $sub): bool
    {
        $lists = $this->lists();
        if ($lists !== null && isset($lists['blocked'][$sub])) {
            return false;
        }
        if (!$this->membersOnly()) {
            return true;   // a missing or broken list only matters for the members-only check
        }
        return $lists !== null && isset($lists['members'][$sub]);
    }

    public function membersOnly(): bool
    {
        return ($this->s['members_only'] ?? true) !== false;
    }

    /** True if homebox's member list has this WorkOS user (whatever members_only says). */
    public function isMember(string $sub): bool
    {
        $lists = $this->lists();
        return $lists !== null && isset($lists['members'][$sub]);
    }

    /** {members, blocked} as sets from members.json, or null (with a log line) if missing or unreadable. */
    private function lists(): ?array
    {
        $path = $this->s['members_file'];
        static $cache = [];
        $mtime = @filemtime($path);
        if ($mtime === false) {
            if ($this->membersOnly()) {
                error_log('col-library-connector: no member list at ' . $path);
            }
            return null;
        }
        if (!isset($cache[$path]) || $cache[$path][0] !== $mtime) {
            $d = json_decode((string) @file_get_contents($path, false, null, 0, 2_000_000), true);
            if (!is_array($d) || !is_array($d['members'] ?? null) || !is_array($d['blocked'] ?? [])) {
                error_log('col-library-connector: unreadable member list at ' . $path);
                return null;
            }
            $set = static fn (array $l): array => array_fill_keys(array_filter($l, 'is_string'), true);
            $cache[$path] = [$mtime, ['members' => $set($d['members']), 'blocked' => $set($d['blocked'] ?? [])]];
        }
        return $cache[$path][1];
    }

    public function blockedMessage(): string
    {
        return 'This account can\'t use the Christian Open Library connector. If you think this is a mistake, '
            . 'ask at ' . ($this->s['request_access_url'] ?? 'https://github.com/Christian-open-library') . '.';
    }

    /** The rate-limit and log identity of a signed-in user (no name or email). */
    public function principal(string $sub): array
    {
        return ['id' => 'u' . substr(hash('sha256', $sub), 0, 12),
                'calls_per_day' => (int) ($this->s['calls_per_day'] ?? 500),
                'calls_per_minute' => (int) ($this->s['calls_per_minute'] ?? 30)]
            // Non-members share one "open" pool, capped below the global limits (RateLimit::pool).
            + ($this->isMember($sub) ? [] : ['pool' => 'open', 'pool_share' => (float) ($this->s['open_share'] ?? 0.8)]);
    }

    public function notMemberMessage(): string
    {
        return 'This GitHub account isn\'t a member of the Christian Open Library yet. Request access at '
            . ($this->s['request_access_url'] ?? 'https://github.com/Christian-open-library')
            . ' (members can use the connector from the night after they are invited).';
    }

    /** PEM of the signing key with this id, from the cached JWKS (refetched when stale or unknown). */
    private function key(string $kid): string
    {
        $jwks = $this->jwks(false);
        if (!isset($jwks['pem'][$kid]) && $this->now() - self::lastTry($jwks) >= self::JWKS_RETRY) {
            $jwks = $this->jwks(true);
        }
        if (!isset($jwks['pem'][$kid])) {
            throw new OAuthError('unknown key id');
        }
        return $jwks['pem'][$kid];
    }

    /** When the keys were last fetched, or a fetch last attempted, whichever is later. */
    private static function lastTry(array $jwks): int
    {
        return max((int) ($jwks['fetched_at'] ?? 0), (int) ($jwks['tried_at'] ?? 0));
    }

    /**
     * The cached signing keys, refetched when older than JWKS_TTL or when $refresh (an unknown kid).
     * A failed fetch is recorded too (tried_at), so there is at most one attempt per JWKS_RETRY even
     * while AuthKit is unreachable; one worker fetches at a time; an unusable key is skipped.
     * @return array{fetched_at: int, tried_at?: int, pem: array<string,string>}
     */
    private function jwks(bool $refresh): array
    {
        $file = $this->stateDir . '/oauth-jwks.json';
        $cached = json_decode((string) @file_get_contents($file), true);
        $cached = is_array($cached) && is_array($cached['pem'] ?? null) ? $cached : null;
        $now = $this->now();
        if ($cached !== null && !$refresh && $now - (int) ($cached['fetched_at'] ?? 0) < self::JWKS_TTL) {
            return $cached;
        }
        if ($cached !== null && $now - self::lastTry($cached) < self::JWKS_RETRY) {
            return $cached;   // fetched or tried less than a minute ago
        }
        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return $cached ?? ['fetched_at' => 0, 'pem' => []];   // another worker is fetching
        }
        try {
            $raw = $this->fetch !== null ? ($this->fetch)($this->s['jwks_uri']) : self::get($this->s['jwks_uri']);
            $keys = json_decode($raw, true)['keys'] ?? null;
            if (!is_array($keys)) {
                throw new \RuntimeException('no keys in the JWKS');
            }
            $pem = [];
            foreach ($keys as $k) {
                if (is_array($k) && ($k['kty'] ?? '') === 'RSA' && is_string($k['kid'] ?? null)
                    && (($k['use'] ?? 'sig') === 'sig') && is_string($k['n'] ?? null) && is_string($k['e'] ?? null)) {
                    try {
                        $pem[$k['kid']] = self::jwkToPem($k['n'], $k['e']);
                    } catch (\Throwable $e) {
                        error_log('col-library-connector: skipped an unusable JWKS key: ' . $e->getMessage());
                    }
                }
            }
            if ($pem === []) {
                throw new \RuntimeException('no usable keys in the JWKS');
            }
            $fresh = ['fetched_at' => $now, 'pem' => $pem];
            self::save($file, $fresh);
            return $fresh;
        } catch (\Throwable $e) {
            error_log('col-library-connector: JWKS fetch failed: ' . $e->getMessage());
            // Keep using the old keys rather than locking everyone out while AuthKit is unreachable,
            // and remember the attempt so the next one waits JWKS_RETRY.
            $keep = $cached ?? ['fetched_at' => 0, 'pem' => []];
            $keep['tried_at'] = $now;
            self::save($file, $keep);
            return $keep;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function save(string $file, array $data): void
    {
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($data)) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $file);
        }
    }

    private static function get(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => 200_000]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (!is_string($body) || $code !== 200) {
            throw new \RuntimeException("HTTP $code " . curl_error($ch));
        }
        return $body;
    }

    /** An RSA public key (base64url n and e) as PEM SubjectPublicKeyInfo. */
    public static function jwkToPem(string $n64, string $e64): string
    {
        $n = self::b64url($n64);
        $e = self::b64url($e64);
        if ($n === null || $e === null || strlen($n) < 256 || strlen($n) > 1024 || strlen($e) > 8) {
            throw new \RuntimeException('unusable RSA key');
        }
        $int = static fn (string $b): string => "\x02" . self::len(ltrim($b, "\0") === '' ? "\0" : (ord(ltrim($b, "\0")[0]) > 127 ? "\0" . ltrim($b, "\0") : ltrim($b, "\0")));
        $rsa = "\x30" . self::len($int($n) . $int($e));
        $alg = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";   // rsaEncryption, NULL
        $spki = "\x30" . self::len($alg . "\x03" . self::len("\x00" . $rsa));
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** DER length prefix followed by the content. */
    private static function len(string $content): string
    {
        $l = strlen($content);
        if ($l < 128) {
            return chr($l) . $content;
        }
        $bytes = ltrim(pack('N', $l), "\0");
        return chr(0x80 | strlen($bytes)) . $bytes . $content;
    }

    public static function b64url(string $s): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]*$/D', $s)) {
            return null;
        }
        $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $d === false ? null : $d;
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
