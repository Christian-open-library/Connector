<?php
/**
 * Personal connector links, so each person gets their own URL with its own daily limit and any
 * one link can be switched off without touching the others.
 *
 * The list is links.json next to config.php (or the config's links_file), mode 600, written by
 * deploy/links.py on homebox:
 *
 *   {"links": [{"id": "l3f9a1c", "sha256": "<64 hex digits>", "calls_per_day": 500, "calls_per_minute": 30}]}
 *
 * The server keeps only the SHA-256 of each link's key, so a copy of the file gives no working URL.
 * Names stay on homebox; the server and its log know only the id. A key that matches no link gets
 * the same 404 as any wrong key. No file means no personal links. An unreadable or invalid file
 * refuses every personal link (the owner's url_key still works) and PHP's error log says why.
 */

namespace ColLibrary;

defined('ABSPATH') || exit;

final class Links
{
    public const MAX_BYTES = 2_000_000;
    public const MAX_LINKS = 10_000;
    public const PER_DAY_RANGE = [1, 1_000_000];
    public const PER_MINUTE_RANGE = [1, 100_000];

    /**
     * The link whose key this is, or null.
     * @return array{id: string, sha256: string, calls_per_day: int, calls_per_minute: int}|null
     */
    public static function find(?string $path, string $key): ?array
    {
        if ($path === null || !preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $key) || !is_file($path)) {
            return null;
        }
        $hash = hash('sha256', $key);
        $found = null;
        foreach (self::load($path) as $link) {
            // Every entry is compared, in constant time, whatever matches.
            if (hash_equals($link['sha256'], $hash)) {
                $found = $link;
            }
        }
        return $found;
    }

    /**
     * Every link in the file; [] (with the reason in PHP's error log) if the file is unusable.
     * @return list<array{id: string, sha256: string, calls_per_day: int, calls_per_minute: int}>
     */
    public static function load(string $path): array
    {
        try {
            return self::parse($path);
        } catch (\Throwable $e) {
            error_log('col-library-connector: personal links refused: ' . $e->getMessage());
            return [];
        }
    }

    /** @throws \RuntimeException naming the first problem */
    private static function parse(string $path): array
    {
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new \RuntimeException("$path is unreadable or larger than " . self::MAX_BYTES . ' bytes');
        }
        $json = @file_get_contents($path);
        if (!is_string($json)) {
            throw new \RuntimeException("$path is unreadable");
        }
        $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        $list = is_array($data) ? ($data['links'] ?? null) : null;
        if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_LINKS) {
            throw new \RuntimeException("$path must hold {\"links\": [...]} with at most " . self::MAX_LINKS . ' links');
        }
        $links = [];
        $ids = [];
        $hashes = [];
        foreach ($list as $i => $e) {
            $id = is_array($e) ? ($e['id'] ?? null) : null;
            $sha = is_array($e) ? ($e['sha256'] ?? null) : null;
            $day = is_array($e) ? ($e['calls_per_day'] ?? null) : null;
            $min = is_array($e) ? ($e['calls_per_minute'] ?? null) : null;
            if (!is_string($id) || !preg_match('/^l[0-9a-f]{6,16}$/D', $id)
                || !is_string($sha) || !preg_match('/^[0-9a-f]{64}$/D', $sha)
                || !is_int($day) || $day < self::PER_DAY_RANGE[0] || $day > self::PER_DAY_RANGE[1]
                || !is_int($min) || $min < self::PER_MINUTE_RANGE[0] || $min > self::PER_MINUTE_RANGE[1]) {
                throw new \RuntimeException("$path: link $i needs an id (l + 6-16 hex digits), a sha256 (64 hex digits) "
                    . 'and whole-number calls_per_day and calls_per_minute in range');
            }
            if (isset($ids[$id]) || isset($hashes[$sha])) {
                throw new \RuntimeException("$path: link $i repeats an id or key");
            }
            $ids[$id] = $hashes[$sha] = true;
            $links[] = ['id' => $id, 'sha256' => $sha, 'calls_per_day' => $day, 'calls_per_minute' => $min];
        }
        return $links;
    }
}
