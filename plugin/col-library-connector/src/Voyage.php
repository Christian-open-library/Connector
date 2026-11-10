<?php
/**
 * Voyage AI client for the two live calls a search makes: embedding the query and re-ranking the
 * candidates (DESIGN.md section 6, PLUGIN-SPEC.md "Search pipeline").
 *
 * - POST {base}/embeddings  input [query], input_type "query", output_dtype "float",
 *   encoding_format "base64" (little-endian float32), output_dimension = the build's dims.
 * - POST {base}/rerank      query, documents, top_k, truncation true -> data[].index/relevance_score.
 * Timeouts: connect 3 s, embed 8 s, rerank 10 s, always cut to the request's deadline. One retry
 * on 429/5xx or a failed connection. Every failure is a VoyageException whose message is safe to
 * log (it never contains the key or the query). Every attempt first reserves its estimated tokens
 * in RateLimit's daily budget (none is sent when that fails); after a decoded answer the
 * reservation is corrected to usage.total_tokens, and a failed attempt keeps its reservation.
 *
 * Fake mode (config 'voyage_fake' => true, nothing else turns it on) answers without the network:
 * query vectors are the exact PHP port of indexer/ivf.py fake_embed, rerank scores are the share of
 * query words found in each document. It exists for tests and local development only.
 */

declare(strict_types=1);

namespace ColLibrary;

defined('ABSPATH') || exit;

/** Voyage could not be used (no key, budget, network, HTTP error, bad response). Safe to log. */
final class VoyageException extends \RuntimeException
{
}

final class Voyage
{
    public const DEFAULT_BASE_URL = 'https://api.voyageai.com/v1';
    public const CONNECT_TIMEOUT = 3.0;
    public const EMBED_TIMEOUT = 8.0;
    public const RERANK_TIMEOUT = 10.0;
    private const MAX_RESPONSE_BYTES = 4_000_000;
    private const CHARS_PER_TOKEN = 3.0;   // conservative for budget estimates (old spelling)

    /**
     * @param \Closure|null $transport replaces the HTTP call in tests:
     *   fn(string $url, string $jsonBody, float $timeoutSeconds): array{status:int, body:string,
     *   error?:string, timeout?:bool, retryAfter?:float|null}
     */
    public function __construct(
        private readonly bool $fake,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $embedModel,
        private readonly int $dims,
        private readonly string $rerankModel,
        private readonly ?RateLimit $limits = null,
        private readonly ?Log $log = null,
        private readonly ?\Closure $transport = null,
    ) {
    }

    /**
     * A client for the build in $store: query model, dims and rerank model come from the build's
     * meta table (what its vectors were made with), falling back to the config.
     */
    public static function forBuild(Context $ctx, Store $store, ?\Closure $transport = null): self
    {
        $config = $ctx->config;
        $base = $config->get('voyage_base_url', self::DEFAULT_BASE_URL);
        return new self(
            fake: $config->get('voyage_fake', false) === true,
            apiKey: $config->voyageKey(),
            baseUrl: is_string($base) ? rtrim($base, '/') : '',
            embedModel: $store->meta('query_model') ?? $store->meta('embed_model') ?? $config->embedModel(),
            dims: (int) ($store->meta('embed_dims') ?? $config->embedDims()),
            rerankModel: $store->meta('rerank_model') ?? $config->rerankModel(),
            limits: $ctx->limits,
            log: $ctx->log,
            transport: $transport,
        );
    }

    public function isFake(): bool
    {
        return $this->fake;
    }

    /** True if calls can be attempted at all (fake mode, or a key and an https base URL). */
    public function configured(): bool
    {
        return $this->fake || ($this->apiKey !== '' && self::validBaseUrl($this->baseUrl));
    }

    /**
     * Query embedding (float, length = dims).
     * @return list<float>
     * @throws VoyageException
     */
    public function embedQuery(string $text, float $deadline): array
    {
        if ($this->dims < 1) {
            throw new VoyageException('embeddings: the index has no vector dimensions');
        }
        if ($this->fake) {
            return self::fakeEmbed($text, $this->dims);
        }
        $this->requireConfigured();
        $estimate = self::estimateTokens($text) + 8;
        $data = $this->post('/embeddings', [
            'input' => [$text],
            'model' => $this->embedModel,
            'input_type' => 'query',
            'output_dimension' => $this->dims,
            'output_dtype' => 'float',
            'encoding_format' => 'base64',
            'truncation' => true,
        ], self::EMBED_TIMEOUT, $deadline, $estimate);
        $emb = $data['data'][0]['embedding'] ?? null;
        if (is_string($emb)) {
            $bytes = base64_decode($emb, true);
            $vec = is_string($bytes) && strlen($bytes) === $this->dims * 4 ? unpack('g*', $bytes) : false;
            $vec = $vec === false ? null : array_values($vec);
        } else {
            $vec = is_array($emb) ? array_values($emb) : null;   // encoding_format ignored: a JSON list
        }
        if ($vec === null || count($vec) !== $this->dims) {
            throw new VoyageException('embeddings: response has no vector of the expected size');
        }
        foreach ($vec as $i => $x) {
            if ((!is_int($x) && !is_float($x)) || !is_finite((float) $x)) {
                throw new VoyageException('embeddings: response vector has invalid numbers');
            }
            $vec[$i] = (float) $x;
        }
        return $vec;
    }

    /**
     * Re-ranks documents for a query.
     * @param list<string> $documents
     * @return list<array{index:int, score:float}> best first, at most $topK
     * @throws VoyageException
     */
    public function rerank(string $query, array $documents, int $topK, float $deadline): array
    {
        $documents = array_values($documents);
        $topK = max(1, min($topK, count($documents)));
        if ($documents === []) {
            return [];
        }
        if ($this->fake) {
            return array_slice(self::fakeRerank($query, $documents), 0, $topK);
        }
        $this->requireConfigured();
        $queryTokens = self::estimateTokens($query);
        $estimate = $queryTokens * count($documents);
        foreach ($documents as $d) {
            $estimate += self::estimateTokens($d);
        }
        $data = $this->post('/rerank', [
            'query' => $query,
            'documents' => $documents,
            'model' => $this->rerankModel,
            'top_k' => $topK,
            'truncation' => true,
        ], self::RERANK_TIMEOUT, $deadline, $estimate);
        $out = [];
        $seen = [];
        foreach (is_array($data['data'] ?? null) ? $data['data'] : [] as $row) {
            $i = $row['index'] ?? null;
            $score = $row['relevance_score'] ?? null;
            if (is_int($i) && $i >= 0 && $i < count($documents) && !isset($seen[$i])
                && (is_float($score) || is_int($score))) {
                $seen[$i] = true;
                $out[] = ['index' => $i, 'score' => (float) $score];
            }
        }
        if ($out === []) {
            throw new VoyageException('rerank: response has no results');
        }
        usort($out, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($out, 0, $topK);
    }

    // ------------------------------------------------------------------------------ fake mode

    /**
     * Port of indexer/ivf.py fake_embed: lower-case, tokens [a-z]+, each adds +-1 at crc32 % dims
     * (sign from bit 16 of the crc), then L2-normalized. Must stay identical to the Python.
     * @return list<float>
     */
    public static function fakeEmbed(string $text, int $dims): array
    {
        $v = array_fill(0, $dims, 0.0);
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        preg_match_all('/[a-z]+/', $lower, $m);
        foreach ($m[0] as $tok) {
            $h = crc32($tok);   // unsigned on 64-bit PHP, like zlib.crc32
            $v[$h % $dims] += (($h >> 16) & 1) ? 1.0 : -1.0;
        }
        $norm = 0.0;
        foreach ($v as $x) {
            $norm += $x * $x;
        }
        if ($norm > 0.0) {
            $norm = sqrt($norm);
            foreach ($v as $i => $x) {
                $v[$i] = $x / $norm;
            }
        }
        return $v;
    }

    /**
     * Deterministic stand-in for the reranker: the share of the query's words (stopwords left out
     * unless that leaves none) that occur in each document; ties keep the input order.
     * @param list<string> $documents  @return list<array{index:int, score:float}>
     */
    public static function fakeRerank(string $query, array $documents): array
    {
        $words = self::fakeWords($query);
        $keep = array_values(array_filter($words, static fn (string $w): bool => !Text::isStopword($w)));
        $q = array_values(array_unique($keep !== [] ? $keep : $words));
        $out = [];
        foreach (array_values($documents) as $i => $doc) {
            $d = array_flip(self::fakeWords($doc));
            $hits = 0;
            foreach ($q as $w) {
                $hits += isset($d[$w]) ? 1 : 0;
            }
            $out[] = ['index' => $i, 'score' => $q === [] ? 0.0 : (float) $hits / count($q)];
        }
        usort($out, static fn (array $a, array $b): int => [$b['score'], $a['index']] <=> [$a['score'], $b['index']]);
        return $out;
    }

    /** @return list<string> */
    private static function fakeWords(string $text): array
    {
        preg_match_all('/[a-z]+/', mb_strtolower(Text::fold($text), 'UTF-8'), $m);
        return $m[0];
    }

    // ---------------------------------------------------------------------------------- HTTP

    public static function validBaseUrl(string $url): bool
    {
        return (bool) preg_match('#^https://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9._~/-]*)?$#D', $url);
    }

    private function requireConfigured(): void
    {
        if ($this->apiKey === '') {
            throw new VoyageException('no Voyage API key is configured');
        }
        if (!self::validBaseUrl($this->baseUrl)) {
            throw new VoyageException('voyage_base_url must be an https:// URL');
        }
    }

    /** Reserves one attempt's estimate in the daily budget. @throws VoyageException when it cannot */
    private function reserve(int $estimate): void
    {
        if ($this->limits !== null && !$this->limits->reserveVoyage($estimate)) {
            throw new VoyageException('the daily Voyage token budget is used up');
        }
    }

    /** Corrects a reservation to the tokens the answer reports (the estimate if it reports none). */
    private function settle(array $data, int $estimate): void
    {
        $used = $data['usage']['total_tokens'] ?? null;
        $this->limits?->adjustVoyage((is_int($used) && $used > 0 ? $used : $estimate) - max(1, $estimate));
    }

    private static function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * POSTs JSON and returns the decoded response; one retry for 429, 5xx and failed connections.
     * Each attempt reserves $estimate tokens before it is sent (see the class comment).
     * @throws VoyageException
     */
    private function post(string $path, array $payload, float $timeout, float $deadline, int $estimate): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($body)) {
            throw new VoyageException("$path: request could not be encoded");
        }
        for ($attempt = 1; ; $attempt++) {
            $limit = min($timeout, $deadline - microtime(true) - 0.5);   // keep time to answer
            if ($limit < 1.0) {
                throw new VoyageException("$path: no time left before the request deadline");
            }
            $this->reserve($estimate);
            $t0 = microtime(true);
            $res = $this->send($this->baseUrl . $path, $body, $limit);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            $status = (int) ($res['status'] ?? 0);
            if ($status === 200) {
                $data = json_decode((string) ($res['body'] ?? ''), true, 64);
                if (!is_array($data)) {
                    throw new VoyageException("$path: response is not a JSON object");
                }
                $this->log?->debug('voyage', ['path' => $path, 'ms' => $ms, 'attempt' => $attempt,
                    'tokens' => $data['usage']['total_tokens'] ?? null]);
                $this->settle($data, $estimate);
                $used = $data['usage']['total_tokens'] ?? null;
                $this->limits?->countSpend($path === '/rerank' ? 'rerank' : 'embed',
                    is_int($used) && $used > 0 ? $used : $estimate);
                return $data;
            }
            $what = $status === 0 ? 'network error: ' . self::safe((string) ($res['error'] ?? 'unknown')) : "HTTP $status";
            $retry = $attempt === 1 && ($status === 429 || $status >= 500 || ($status === 0 && empty($res['timeout'])));
            $this->log?->debug('voyage_error', ['path' => $path, 'status' => $status, 'ms' => $ms, 'attempt' => $attempt,
                'detail' => $this->detail((string) ($res['body'] ?? ''))]);
            if (!$retry) {
                throw new VoyageException("$path failed: $what");
            }
            $wait = min(2.0, max(0.25, (float) ($res['retryAfter'] ?? 0.5)));
            if ($deadline - microtime(true) - $wait < 2.0) {
                throw new VoyageException("$path failed: $what (no time left to retry)");
            }
            usleep((int) ($wait * 1_000_000));
        }
    }

    /** @return array{status:int, body:string, error:string, timeout:bool, retryAfter:float|null} */
    private function send(string $url, string $body, float $timeout): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($url, $body, $timeout) + ['body' => '', 'error' => '', 'timeout' => false, 'retryAfter' => null];
        }
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'the curl extension is missing', 'timeout' => false, 'retryAfter' => null];
        }
        $received = '';
        $retryAfter = null;
        $ch = curl_init($url);
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json',
                                   'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT_MS => (int) (min(self::CONNECT_TIMEOUT, $timeout) * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'col-library-connector',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                if (stripos($line, 'retry-after:') === 0 && is_numeric(trim(substr($line, 12)))) {
                    $retryAfter = (float) trim(substr($line, 12));
                }
                return strlen($line);
            },
            // Collect the body ourselves so a runaway response cannot use up memory.
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$received): int {
                if (strlen($received) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;   // aborts the transfer
                }
                $received .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $error = $errno !== 0 ? curl_error($ch) : '';
        if ($ok === false || $errno !== 0) {
            $timeout = $errno === 28;   // CURLE_OPERATION_TIMEDOUT
            return ['status' => 0, 'body' => '', 'error' => $timeout ? 'timed out' : "curl error $errno ($error)",
                    'timeout' => $timeout, 'retryAfter' => null];
        }
        return ['status' => $status, 'body' => $received, 'error' => '', 'timeout' => false, 'retryAfter' => $retryAfter];
    }

    /** Voyage's error text ({"detail": ...}) for the log: short, key removed. */
    private function detail(string $body): string
    {
        $data = json_decode($body, true);
        $detail = is_array($data) && is_string($data['detail'] ?? null) ? $data['detail'] : '';
        return self::safe($this->apiKey !== '' ? str_replace($this->apiKey, '[redacted]', $detail) : $detail);
    }

    private static function safe(string $text): string
    {
        return mb_substr((string) preg_replace('/\s+/u', ' ', $text), 0, 200);
    }
}
