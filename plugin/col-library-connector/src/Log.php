<?php
/**
 * Optional request log: one JSON object per line in <state_dir>/log-YYYY-MM.jsonl.
 * Off by default (log_level 'off'). Never logs the URL key or the Voyage key: both are replaced
 * by "[redacted]" in every line. Query text is logged only when log_queries is true; otherwise
 * fields named in QUERY_FIELDS are replaced by "[omitted]". Logging never breaks a request: write
 * errors are ignored.
 */

namespace ColLibrary;

defined('ABSPATH') || exit;

final class Log
{
    /** Field names that carry user query text (dropped unless log_queries is true). */
    public const QUERY_FIELDS = ['query', 'arguments', 'reference', 'term', 'phrase', 'text', 'q'];

    private const LEVELS = ['off' => 0, 'info' => 1, 'debug' => 2];
    private const MAX_FILE_BYTES = 50_000_000;   // a month's log stops growing beyond this
    /** Info and debug lines stop here, so a flood of them (anyone can cause 401s) never crowds out errors. */
    private const MAX_INFO_BYTES = 40_000_000;

    /** @param list<string> $secrets values to redact from every line */
    public function __construct(
        private readonly string $level = 'off',
        private readonly ?string $dir = null,
        private readonly bool $logQueries = false,
        private readonly array $secrets = [],
        private readonly array $base = [],
    ) {
    }

    /** The same log, adding $fields to every line (e.g. which link made the request). */
    public function with(array $fields): self
    {
        return new self($this->level, $this->dir, $this->logQueries, $this->secrets, $fields + $this->base);
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->logLevel(), $config->stateDir(), $config->logQueries(), $config->secrets());
    }

    public static function off(): self
    {
        return new self('off');
    }

    /** True if messages of this level ('info' or 'debug') are written. */
    public function enabled(string $level = 'info'): bool
    {
        return (self::LEVELS[$this->level] ?? 0) >= (self::LEVELS[$level] ?? 99) && $this->dir !== null;
    }

    /** True if query text may be logged (config log_queries). */
    public function queriesAllowed(): bool
    {
        return $this->logQueries;
    }

    public function info(string $event, array $fields = []): void
    {
        if ($this->enabled('info')) {
            $this->write('info', $event, $fields);
        }
    }

    public function debug(string $event, array $fields = []): void
    {
        if ($this->enabled('debug')) {
            $this->write('debug', $event, $fields);
        }
    }

    /**
     * Errors go to the JSONL log when logging is on, else to PHP's error log, so internal errors
     * are always recorded somewhere on the server.
     */
    public function error(string $event, array $fields = []): void
    {
        if ($this->enabled('info')) {
            $this->write('error', $event, $fields);
            return;
        }
        $line = $this->line('error', $event, $fields);
        if ($line !== null) {
            error_log('col-library-connector: ' . $line);
        }
    }

    private function write(string $level, string $event, array $fields): void
    {
        $line = $this->line($level, $event, $fields);
        if ($line === null) {
            return;
        }
        $dir = (string) $this->dir;
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir . '/log-' . gmdate('Y-m') . '.jsonl';
        $size = @filesize($file);
        if ($size !== false && $size > ($level === 'error' ? self::MAX_FILE_BYTES : self::MAX_INFO_BYTES)) {
            if ($level === 'error') {
                error_log('col-library-connector: ' . $line);   // the month's log is full: errors go to syslog
            }
            return;
        }
        if (@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) !== false && $size === false) {
            @chmod($file, 0600);   // new file: may hold query text, so owner-only
        }
    }

    /** The JSON line for an entry, with query fields dropped and secrets redacted. */
    private function line(string $level, string $event, array $fields): ?string
    {
        if (!$this->logQueries) {
            foreach (self::QUERY_FIELDS as $k) {
                if (array_key_exists($k, $fields)) {
                    $fields[$k] = '[omitted]';
                }
            }
        }
        $now = microtime(true);
        $entry = ['ts' => gmdate('Y-m-d\TH:i:s', (int) $now) . sprintf('.%03dZ', (int) (fmod($now, 1) * 1000)),
                  'level' => $level, 'event' => $event] + $fields + $this->base;
        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_string($json)) {
            return null;
        }
        foreach ($this->secrets as $secret) {
            if ($secret !== '') {
                $json = str_replace($secret, '[redacted]', $json);
            }
        }
        return $json;
    }
}
