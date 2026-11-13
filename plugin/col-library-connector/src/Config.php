<?php
/**
 * Server config: a PHP file outside the web root that returns an array (PLUGIN-SPEC.md, "Server
 * config"; documented in deploy/server-config.example.php). Loaded once per request.
 */

namespace ColLibrary;

defined('ABSPATH') || exit;

/** Missing or invalid config. The message is safe to log (it never contains key values). */
final class ConfigException extends \RuntimeException
{
}

final class Config
{
    public const DEFAULT_ORIGINS = ['https://claude.ai', 'https://claude.com', 'https://www.claude.ai', 'https://chatgpt.com', 'https://chat.openai.com', 'https://gemini.google.com', 'https://copilot.microsoft.com', 'https://chat.mistral.ai'];
    public const LOG_LEVELS = ['off', 'info', 'debug'];

    /** Values used when a key is absent. data_dir and state_dir default relative to the file. */
    private const DEFAULTS = [
        'voyage_api_key'            => '',
        'embed_model'               => 'voyage-4-lite',
        'embed_dims'                => 512,
        'rerank_model'              => 'rerank-3',
        'nprobe'                    => 32,
        'max_calls_per_minute'      => 120,
        'max_calls_per_day'         => 10000,
        'max_voyage_tokens_per_day' => 15_000_000,
        // One person's daily re-ranking allowance (RateLimit; at most a fifth of the line above).
        'max_voyage_tokens_per_person_per_day' => 1_000_000,
        // Passages sent to the reranker per search, and characters of each (Search). These set what a
        // search costs: about rerank_candidates x rerank_doc_chars / 3.7 tokens (50 x 800: ~10,700, measured 2026-11-13).
        'rerank_candidates'         => 50,
        'rerank_doc_chars'          => 800,
        'allowed_origins'           => self::DEFAULT_ORIGINS,
        'log_level'                 => 'off',
        'log_queries'               => false,
        'voyage_fake'               => false,
    ];

    /** Integer keys and their allowed ranges. */
    private const INT_RANGES = [
        'embed_dims'                => [1, 4096],
        'nprobe'                    => [1, 65536],
        'max_calls_per_minute'      => [1, 1_000_000],
        'max_calls_per_day'         => [1, 1_000_000_000],
        'max_voyage_tokens_per_day' => [0, 1_000_000_000_000],
        'max_voyage_tokens_per_person_per_day' => [1, 1_000_000_000_000],
        'rerank_candidates'         => [2, 1000],
        'rerank_doc_chars'          => [200, 20000],
    ];

    private function __construct(private readonly array $values, private readonly string $path)
    {
    }

    /**
     * Where the config file is: the COL_LIBRARY_CONFIG constant (wp-config.php) if defined, else
     * <home>/col-library/config.php for the user PHP runs as. The home directory comes from posix,
     * else $HOME, else the parent of the WordPress root (PHP-FPM often clears $HOME; managed WordPress hosts
     * keep the site in <home>/<domain>/, and deploy/deploy.sh defines COL_LIBRARY_CONFIG when the
     * layout differs). Null if no home directory is known.
     */
    public static function defaultPath(): ?string
    {
        if (defined('COL_LIBRARY_CONFIG')) {
            return (string) constant('COL_LIBRARY_CONFIG');
        }
        $home = null;
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            if (is_array($pw) && is_string($pw['dir'] ?? null) && $pw['dir'] !== '') {
                $home = $pw['dir'];
            }
        }
        if ($home === null) {
            $env = getenv('HOME');
            $home = is_string($env) && $env !== '' ? $env : null;
        }
        if ($home === null && defined('ABSPATH')) {
            $parent = dirname(rtrim((string) constant('ABSPATH'), '/'));
            $home = str_starts_with($parent, '/') && $parent !== '/' ? $parent : null;
        }
        return $home === null ? null : rtrim($home, '/') . '/col-library/config.php';
    }

    /** Reads and validates the config file. @throws ConfigException */
    public static function load(?string $path = null): self
    {
        $path ??= self::defaultPath();
        if ($path === null || $path === '') {
            throw new ConfigException('config path unknown: COL_LIBRARY_CONFIG is not defined and there is no home directory');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigException("config file missing or unreadable: $path");
        }
        if (function_exists('opcache_invalidate')) {
            // An edited file (a rotated url_key) takes effect at once, whatever OPcache's
            // revalidation settings are.
            @opcache_invalidate($path, true);
        }
        try {
            // A static closure, so the file sees no variables from this scope.
            $values = (static fn (string $file): mixed => include $file)($path);
        } catch (\Throwable $e) {
            throw new ConfigException("config file failed to load: $path: " . get_class($e) . ' on line ' . $e->getLine());
        }
        if (!is_array($values)) {
            throw new ConfigException("config file does not return an array: $path");
        }
        return self::fromArray($values, $path);
    }

    /**
     * Validates config values (as returned by the file) and fills in defaults.
     * @throws ConfigException listing every problem found
     */
    public static function fromArray(array $values, string $path = ''): self
    {
        $errors = [];
        $v = $values + self::DEFAULTS;
        $base = $path !== '' ? dirname($path) : '';

        $key = $values['url_key'] ?? null;
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $key)) {
            $errors[] = 'url_key must be a string of 43 to 128 characters from A-Z a-z 0-9 _ -';
        }
        foreach (['voyage_api_key', 'embed_model', 'rerank_model'] as $k) {
            if (!is_string($v[$k])) {
                $errors[] = "$k must be a string";
            }
        }
        foreach (['embed_model', 'rerank_model'] as $k) {
            if (is_string($v[$k]) && $v[$k] === '') {
                $errors[] = "$k must not be empty";
            }
        }
        foreach (['data_dir' => 'current', 'state_dir' => ''] as $k => $sub) {
            if (!array_key_exists($k, $values)) {
                if ($base === '') {
                    $errors[] = "$k is required";
                    continue;
                }
                $v[$k] = $sub === '' ? $base : "$base/$sub";
            }
            if (!is_string($v[$k]) || !str_starts_with($v[$k], '/') || str_contains($v[$k], "\0")) {
                $errors[] = "$k must be an absolute path";
            } else {
                $v[$k] = rtrim($v[$k], '/') ?: '/';
            }
        }
        if (array_key_exists('links_file', $values)) {
            $l = $values['links_file'];
            if (!is_string($l) || !str_starts_with($l, '/') || str_contains($l, "\0")) {
                $errors[] = 'links_file must be an absolute path';
            }
        }
        if (array_key_exists('wp_root', $values)) {
            $r = $values['wp_root'];
            if (!is_string($r) || !str_starts_with($r, '/') || str_contains($r, "\0")) {
                $errors[] = 'wp_root must be an absolute path';
            } else {
                $v['wp_root'] = rtrim($r, '/') ?: '/';
            }
        }
        foreach (self::INT_RANGES as $k => [$min, $max]) {
            if (!is_int($v[$k]) || $v[$k] < $min || $v[$k] > $max) {
                $errors[] = "$k must be an integer from $min to $max";
            }
        }
        foreach (['log_queries', 'voyage_fake'] as $k) {
            // Strictly a bool: voyage_fake must never switch on by accident (e.g. from "0").
            if (!is_bool($v[$k])) {
                $errors[] = "$k must be true or false";
            }
        }
        if (!in_array($v['log_level'], self::LOG_LEVELS, true)) {
            $errors[] = "log_level must be one of: " . implode(', ', self::LOG_LEVELS);
        }
        $origins = $v['allowed_origins'];
        if (!is_array($origins) || !array_is_list($origins)) {
            $errors[] = 'allowed_origins must be a list of origins such as https://claude.ai';
        } else {
            $normalized = [];
            foreach ($origins as $o) {
                $n = is_string($o) ? self::normalizeOrigin($o) : null;
                if ($n === null) {
                    $errors[] = 'allowed_origins entries must look like https://host or https://host:port';
                    break;
                }
                $normalized[] = $n;
            }
            $v['allowed_origins'] = $normalized;
        }

        if ($errors) {
            throw new ConfigException('invalid config' . ($path !== '' ? " ($path)" : '') . ': ' . implode('; ', $errors));
        }
        return new self($v, $path);
    }

    /**
     * Canonical form of an Origin value for comparison: lower-case scheme and host, no trailing
     * slash, no default port. Null if it is not an http(s) origin (e.g. "null" or a full URL).
     */
    public static function normalizeOrigin(string $origin): ?string
    {
        $o = strtolower(trim($origin));
        $o = rtrim($o, '/');   // one report saw "https://claude.ai/"
        if (!preg_match('#^(https?)://([a-z0-9.-]+|\[[0-9a-f:.]+\])(?::(\d{1,5}))?$#D', $o, $m)) {
            return null;
        }
        $port = $m[3] ?? '';
        if (($m[1] === 'https' && $port === '443') || ($m[1] === 'http' && $port === '80')) {
            $port = '';
        }
        return $m[1] . '://' . $m[2] . ($port !== '' ? ":$port" : '');
    }

    /**
     * True if absolute path $path is $root or inside it, after resolving "." and ".." and the
     * symlinks of the part that exists. Used to refuse data and state directories in the web root.
     */
    public static function isInside(string $path, string $root): bool
    {
        $p = self::resolve($path);
        $r = rtrim(self::resolve($root), '/');
        return $r === '' || $p === $r || str_starts_with($p, $r . '/');
    }

    private static function resolve(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') {
                array_pop($parts);
            } elseif ($seg !== '' && $seg !== '.') {
                $parts[] = $seg;
            }
        }
        // The longest existing prefix goes through realpath (symlinks); the rest is appended.
        $missing = [];
        while (($real = realpath('/' . implode('/', $parts))) === false && $parts !== []) {
            array_unshift($missing, array_pop($parts));
        }
        return rtrim((string) $real, '/') . ($missing !== [] ? '/' . implode('/', $missing) : '');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function urlKey(): string
    {
        return $this->values['url_key'];
    }

    /** The personal-links file (links_file, else links.json next to the config file); null if neither is known. */
    public function linksPath(): ?string
    {
        if (isset($this->values['links_file'])) {
            return $this->values['links_file'];
        }
        return $this->path !== '' ? dirname($this->path) . '/links.json' : null;
    }

    public function voyageKey(): string
    {
        return $this->values['voyage_api_key'];
    }

    public function dataDir(): string
    {
        return $this->values['data_dir'];
    }

    public function stateDir(): string
    {
        return $this->values['state_dir'];
    }

    /** The WordPress root allowed to serve the endpoint (wp_root; written by deploy.sh), or null. */
    public function wpRoot(): ?string
    {
        return $this->values['wp_root'] ?? null;
    }

    /** True if two absolute paths name the same place (after "..", "." and symlinks). */
    public static function samePath(string $a, string $b): bool
    {
        return rtrim(self::resolve($a), '/') === rtrim(self::resolve($b), '/');
    }

    public function embedModel(): string
    {
        return $this->values['embed_model'];
    }

    public function embedDims(): int
    {
        return $this->values['embed_dims'];
    }

    public function rerankModel(): string
    {
        return $this->values['rerank_model'];
    }

    public function nprobe(): int
    {
        return $this->values['nprobe'];
    }

    public function maxCallsPerMinute(): int
    {
        return $this->values['max_calls_per_minute'];
    }

    public function maxCallsPerDay(): int
    {
        return $this->values['max_calls_per_day'];
    }

    public function maxVoyageTokensPerDay(): int
    {
        return $this->values['max_voyage_tokens_per_day'];
    }

    public function maxVoyageTokensPerPersonPerDay(): int
    {
        return $this->values['max_voyage_tokens_per_person_per_day'];
    }

    public function rerankCandidates(): int
    {
        return $this->values['rerank_candidates'];
    }

    public function rerankDocChars(): int
    {
        return $this->values['rerank_doc_chars'];
    }

    /** @return list<string> normalized origins (see normalizeOrigin) */
    public function allowedOrigins(): array
    {
        return $this->values['allowed_origins'];
    }

    public function logLevel(): string
    {
        return $this->values['log_level'];
    }

    public function logQueries(): bool
    {
        return $this->values['log_queries'];
    }

    /** True only when the config file sets 'voyage_fake' => true (local tests). */
    public function voyageFake(): bool
    {
        return $this->values['voyage_fake'] === true;
    }

    /** Any config value (validated, defaults applied); unknown keys are returned as written. */
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    /** @return list<string> secret values that must never appear in logs or responses */
    public function secrets(): array
    {
        return array_values(array_filter([$this->urlKey(), $this->voyageKey()], fn ($s) => $s !== ''));
    }
}
