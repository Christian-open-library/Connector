<?php
/**
 * The public library search: POST /search on the office server, called by the library's website (the "Search the
 * books" box on every page) without signing in. It runs the same search as the search_library tool (Search::best:
 * keyword + meaning, re-ranked; a pasted quotation's word-for-word matches first) and answers with short excerpts for
 * the page to show: the book's own words, who wrote them, the book, year and page, and a link to the source or scan.
 * No verdicts: the reader sees where the words are and judges.
 *
 * It shares the checker's guards (PublicCheck): only the website's origins, the checker's per-visitor and overall
 * limits (RateLimit::takePublic, its own counters apart from signed-in people), a few searches at once, answers kept
 * for public_check_cache_hours sealed with a key made from the words, and a log line without the words.
 * Re-ranking for all public searches together is counted as one person ('public-search') with its own daily
 * allowance (public_search_voyage_tokens_per_day); past it, searches still answer, in keyword + meaning order.
 *
 * Documents used by permission (the licensed shelf) are left out entirely: they are for signed-in AI apps only.
 */

declare(strict_types=1);

namespace ColLibrary;

defined('ABSPATH') || exit;

final class PublicSearch
{
    public const MAX_BODY = 2048;
    public const MAX_CHARS = 500;
    public const SECONDS = 12.0;
    /** Searches that may run at once (a search takes ~1.5 s; PHP-FPM has 8 workers for everything). */
    public const SLOTS = 3;
    public const LIMIT = 8;
    /** Excerpt length: up to this many characters of the passage around the marked words. */
    public const EXCERPT = 460;
    /** A sentence longer than this is not marked whole for a meaning match. */
    private const MARK_MAX = 320;
    /** Nor one shorter than this many words. */
    private const MARK_MIN_WORDS = 6;

    /** @param array<string,string> $headers */
    public static function response(string $method, array $headers, string $body, string $visitor,
                                    Config|string|null $config = null, ?\Closure $clock = null): Response
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        try {
            $config = $config instanceof Config ? $config : Config::load($config);
        } catch (\Throwable $e) {
            error_log('col-library-connector: ' . $e->getMessage());
            return Response::json(404, '{}');
        }
        if (!$config->publicSearch()) {
            return Response::json(404, '{}');
        }
        if (hash_equals(PublicCheck::smokeCode($config), (string) ($headers['x-library-smoke'] ?? ''))) {
            $visitor = PublicCheck::SMOKE;
        }
        $log = Log::fromConfig($config)->with(['link' => 'public']);
        $origin = Config::normalizeOrigin((string) ($headers['origin'] ?? ''));
        if ($origin === null || !in_array($origin, $config->publicCheckOrigins(), true)) {
            $log->info('public_search', ['status' => 403, 'origin' => mb_substr((string) ($headers['origin'] ?? ''), 0, 100)]);
            return PublicCheck::answer(403, ['error' => 'This search answers only the library\'s own website.'], null);
        }
        $method = strtoupper($method);
        if ($method === 'OPTIONS') {
            return new Response(204, [
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Methods' => 'POST',
                'Access-Control-Allow-Headers' => 'Content-Type',
                'Access-Control-Max-Age' => '86400',
                'Vary' => 'Origin',
            ], null);
        }
        if ($method !== 'POST') {
            return PublicCheck::answer(405, ['error' => 'Use POST.'], $origin, ['Allow' => 'POST, OPTIONS']);
        }
        if (strlen($body) > self::MAX_BODY) {
            return PublicCheck::answer(413, ['error' => 'That is too long to search for.'], $origin);
        }
        $type = strtolower(trim(explode(';', (string) ($headers['content-type'] ?? ''), 2)[0]));
        if (!in_array($type, ['text/plain', 'application/json'], true)) {
            return PublicCheck::answer(415, ['error' => 'Send the search as JSON.'], $origin);
        }
        try {
            $in = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $in = null;
        }
        $q = is_array($in) && is_string($in['q'] ?? null) ? trim((string) preg_replace('/[\p{Cc}\p{Cf}\s]+/u', ' ', $in['q'])) : null;
        if ($q === null || $q === '' || array_diff(array_keys($in), ['q']) !== []) {
            return PublicCheck::answer(400, ['error' => 'Type a question, a topic or a line you remember.'], $origin);
        }
        if (mb_strlen($q) > self::MAX_CHARS) {
            return PublicCheck::answer(400, ['error' => 'That is too long to search for (at most ' . self::MAX_CHARS
                . ' characters). Try its most distinctive sentence.'], $origin);
        }

        $now = $clock !== null ? (int) $clock() : time();
        $code = PublicCheck::visitorCode($visitor, $config, $now);
        $kept = self::kept($config, $q, Mcp::version());
        if ($kept !== null && ($answer = $kept->read()) !== null) {
            $refusal = (new RateLimit($config->stateDir(), $config->maxCallsPerMinute(), $config->maxCallsPerDay(),
                $config->maxVoyageTokensPerDay(), $log, $clock))->takePublic($code, $config->publicCheckLimits(), true,
                RateLimit::PUBLIC_SEARCH_WORDS);
            if ($refusal !== null) {
                $log->info('public_search', ['status' => 429, 'limit' => $refusal[0]]);
                return PublicCheck::answer(429, ['error' => $refusal[1], 'limit' => $refusal[0]], $origin,
                    ['Retry-After' => (string) $refusal[2]]);
            }
            $log->info('public_search', ['status' => 200, 'hits' => count($answer['hits'] ?? []), 'kept' => true]);
            return PublicCheck::answer(200, $answer, $origin);
        }

        $slot = PublicCheck::slot($config->stateDir(), 'public-search', self::SLOTS);
        if ($slot === null) {
            $log->info('public_search', ['status' => 429, 'limit' => 'busy']);
            return PublicCheck::answer(429, ['error' => 'The search is busy right now. Wait a few seconds, then try again.',
                'limit' => 'busy'], $origin, ['Retry-After' => '5']);
        }
        try {
            $limits = new RateLimit($config->stateDir(), $config->maxCallsPerMinute(), $config->maxCallsPerDay(),
                $config->maxVoyageTokensPerDay(), $log, $clock,
                ['id' => 'public-search', 'calls_per_minute' => 1, 'calls_per_day' => 1],
                $config->publicSearchVoyageTokensPerDay(), $config->maxLicensedPassagesPerPersonPerDay());
            $refusal = $limits->takePublic($code, $config->publicCheckLimits(), false, RateLimit::PUBLIC_SEARCH_WORDS);
            if ($refusal !== null) {
                $log->info('public_search', ['status' => 429, 'limit' => $refusal[0]]);
                return PublicCheck::answer(429, ['error' => $refusal[1], 'limit' => $refusal[0]], $origin,
                    ['Retry-After' => (string) $refusal[2]]);
            }
            $started = microtime(true);
            $ctx = new Context($config, $limits, $log, $started + self::SECONDS);
            try {
                $store = new Store($config->dataDir());
                $search = new Search($store, Voyage::forBuild($ctx, $store), $ctx->deadline, $config->nprobe(), $log,
                    1.0, Search::EXACT_SCAN_MAX, $config->rerankCandidates(), $config->rerankDocChars());
                $result = $search->best($q, new Filters(), self::LIMIT);
                $out = self::json($result, $search->queryTokens($q), $store);
            } catch (SearchInputException) {
                return PublicCheck::answer(400, ['error' => 'Use words to search for, for example "assurance of salvation".'], $origin);
            } catch (StoreException $e) {
                $log->error('store_unavailable', ['tool' => 'public_search', 'error' => Text::logMessage($e)]);
                return PublicCheck::answer(503, ['error' => 'The library is not available right now. Please try again later.'], $origin);
            } catch (\Throwable $e) {
                $log->error('tool_failed', ['tool' => 'public_search', 'error' => Text::logMessage($e),
                    'at' => basename($e->getFile()) . ':' . $e->getLine()]);
                return PublicCheck::answer(500, ['error' => 'The search failed because of a problem on the server. Please '
                    . 'try again in a moment.'], $origin);
            }
            $whole = $result->notes === [];   // not cut short, and re-ranked
            $log->info('public_search', ['status' => 200, 'hits' => count($out['hits']), 'exact' => $out['exact'],
                'method' => $result->method, 'ms' => (int) round((microtime(true) - $started) * 1000)]);
            if ($whole && $out['hits'] !== []) {   // a search cut short (time, Voyage down) may do better next time
                $kept?->write($out);
            }
            return PublicCheck::answer(200, $out, $origin);
        } finally {
            fclose($slot);
        }
    }

    /** Where the answer to these words is kept, or null if answers are not kept (or the build is missing). */
    public static function kept(Config $config, string $q, string $code): ?KeptAnswer
    {
        $hours = $config->publicCheckCacheHours();
        $db = $config->dataDir() . '/library.sqlite';
        $stat = $hours > 0 ? @stat($db) : false;
        if ($stat === false) {
            return null;
        }
        $build = ($stat['ino'] ?? 0) . ':' . ($stat['size'] ?? 0) . ':' . ($stat['mtime'] ?? 0);
        $secret = hash('sha256', 'public-search-kept|' . $config->urlKey(), true);
        return new KeptAnswer($config->stateDir() . '/public-search-answers', $secret, "$build\0$code\0$q", $hours * 3600, 'hits');
    }

    /**
     * The result as the website shows it: word-for-word matches first, oldest first, then the rest in ranked order.
     * Every string is plain text (the page escapes it); [[ ]] marks the words to highlight.
     * @param list<string> $tokens the query's tokens (Search::queryTokens)
     * @return array{hits: list<array>, exact: int, note?: string}
     */
    public static function json(SearchResult $r, array $tokens, ?Store $store = null): array
    {
        $exact = [];
        $rest = [];
        $seen = [];
        foreach ($r->hits as $h) {
            if (Format::isLicensed($h['work'])) {
                continue;
            }
            // Word for word as the search judged it (Search::phraseHits: 5 words or more, in 30 passages at most).
            $isExact = ($h['match'] ?? null) === 'exact';
            if ($isExact) {
                $exact[] = $h;
            } else {
                $rest[] = $h;
            }
            $seen[$h['id']] = true;
            // Another edition folded in as a near-duplicate that also has the words: shown too, so the earliest
            // printing in the library is visible.
            if ($isExact) {
                foreach ($h['also'] ?? [] as $a) {
                    if (!isset($seen[$a['id']]) && !Format::isLicensed($a['work'])
                        && self::phraseSpan(self::clean((string) $a['text']), $tokens, $store) !== null) {
                        $seen[$a['id']] = true;
                        $exact[] = $a + ['match' => 'exact'];
                    }
                }
            }
        }
        usort($exact, static fn (array $a, array $b): int => [Quote::when($a['work']), $a['id']] <=> [Quote::when($b['work']), $b['id']]);
        $hits = [];
        foreach (array_merge($exact, $rest) as $h) {
            $hits[] = self::hit($h, $tokens, $store, in_array($h, $exact, true));
            if (count($hits) >= self::LIMIT + 4) {
                break;
            }
        }
        $out = ['hits' => $hits, 'exact' => count($exact)];
        $n = min(count($exact), count($hits));
        if ($n > 0) {
            $out['note'] = $n === 1 ? 'Word for word in 1 book.' : "Word for word in $n books, earliest first.";
        }
        return $out;
    }

    /** One passage as the page shows it. */
    private static function hit(array $h, array $tokens, ?Store $store, bool $exact): array
    {
        $w = $h['work'];
        $text = self::clean((string) $h['text']);
        $mark = $exact ? self::phraseSpan($text, $tokens, $store) : null;
        $mark ??= self::bestSentence($text, $tokens, $store);
        $ex = self::excerpt($text, $mark);
        $note = (string) ($w['author_note'] ?? '');
        $page = isset($h['page_start']) && $h['page_start'] !== '' ? (string) $h['page_start'] : null;
        $scan = Format::scanLink($w, $page, (string) $h['text']);
        $source = (string) ($w['source_url'] ?? '');
        return [
            'ref' => 'P' . $h['id'],
            'author' => Text::oneLine((string) $w['author'], 200),
            'dates' => $note !== '' ? Text::oneLine(trim(explode(',', $note, 2)[0]), 60) : '',
            'title' => Text::oneLine($w['title'] !== '' ? (string) $w['title'] : 'Untitled', 300),
            'year' => $w['year'] !== null ? (int) $w['year'] : null,
            'page' => Format::pages($page, isset($h['page_end']) ? (string) $h['page_end'] : null),
            'link' => $scan !== null ? $scan['url'] : (preg_match('~^https?://~i', $source) === 1 ? Text::oneLine($source, 500) : null),
            'scan' => Quote::fromScan($w),
            'text' => $ex['text'],
            'cut_start' => $ex['cut_start'],
            'cut_end' => $ex['cut_end'],
        ];
    }

    /** The passage as plain text on one line: no page marks or emphasis, and no "[[" or "]]" of its own. */
    public static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace(['[[', ']]'], ['[', ']'], Text::plain($text))));
    }

    /**
     * The words of $text as tokens in the form the search compares (folded, lower case, modern spelling), each with
     * its byte offset and length in $text.
     * @return list<array{w: string, off: int, len: int}>
     */
    public static function tokens(string $text, ?Store $store): array
    {
        if (preg_match_all("/\\p{L}[\\p{L}\\p{M}]*(?:['’ʼ]\\p{L}[\\p{L}\\p{M}]*)?|\\p{N}+/u", $text, $m, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $raw = [];
        foreach ($m[0] as [$word, $off]) {
            foreach (Text::words(Text::normalize($word)) as $w) {
                $raw[] = ['w' => $w, 'off' => $off, 'len' => strlen($word)];
            }
        }
        if ($store === null) {
            return $raw;
        }
        $spelling = $store->spellings(array_values(array_unique(array_column($raw, 'w'))));
        $out = [];
        foreach ($raw as $t) {
            foreach (Text::modernTokens([$t['w']], $spelling) as $w) {
                $out[] = ['w' => $w, 'off' => $t['off'], 'len' => $t['len']];
            }
        }
        return $out;
    }

    /**
     * Where the query's words stand in $text word for word (old spellings matched), as [start, end] byte offsets, or
     * null. Only for a query of Search::PHRASE_MIN_WORDS words or more: shorter ones are a topic, not a quotation.
     * @param list<string> $tokens
     * @return array{0: int, 1: int}|null
     */
    public static function phraseSpan(string $text, array $tokens, ?Store $store): ?array
    {
        $n = count($tokens);
        if ($n < Search::PHRASE_MIN_WORDS) {
            return null;
        }
        $t = self::tokens($text, $store);
        $words = array_column($t, 'w');
        for ($i = 0, $last = count($words) - $n; $i <= $last; $i++) {
            if (array_slice($words, $i, $n) === $tokens) {
                return [$t[$i]['off'], $t[$i + $n - 1]['off'] + $t[$i + $n - 1]['len']];
            }
        }
        return null;
    }

    /**
     * The sentence that shares most of the query's words (stopwords left out), as [start, end] byte offsets, or null
     * when none shares enough: at least two, or the only one for a query of one or two such words.
     * @param list<string> $tokens
     * @return array{0: int, 1: int}|null
     */
    public static function bestSentence(string $text, array $tokens, ?Store $store): ?array
    {
        $want = [];
        foreach ($tokens as $w) {
            if (!Text::isStopword($w) && mb_strlen($w) > 1) {
                $want[self::stem($w)] = true;
            }
        }
        if ($want === []) {
            return null;
        }
        $need = count($want) <= 2 ? count($want) : 2;
        $best = null;
        foreach (self::sentences($text) as [$s, $e]) {
            if ($e - $s > self::MARK_MAX) {
                continue;
            }
            $words = self::tokens(substr($text, $s, $e - $s), $store);
            if (count($words) < self::MARK_MIN_WORDS) {
                continue;   // a running head ("12 Only a Prayer-meeting!") or a heading is not an answer
            }
            $have = [];
            foreach ($words as $t) {
                $k = self::stem($t['w']);
                if (isset($want[$k])) {
                    $have[$k] = true;
                }
            }
            $score = count($have);
            if ($score >= $need && ($best === null || $score > $best[0])) {
                $best = [$score, $s, $e];
            }
        }
        return $best === null ? null : [$best[1], $best[2]];
    }

    /** A rough stem for comparing a query's words with a sentence's: "saved", "saves" and "save" agree. */
    private static function stem(string $w): string
    {
        foreach (['ings', 'ing', 'eth', 'est', 'ed', 'es', 's', 'e'] as $end) {
            if (mb_strlen($w) - mb_strlen($end) >= 3 && str_ends_with($w, $end)) {
                return mb_substr($w, 0, -mb_strlen($end));
            }
        }
        return $w;
    }

    /**
     * Sentences of $text as [start, end] byte offsets (end after the stop and any closing quote). A stop is . ? ! or ;
     * before a space; abbreviations make extra breaks, which only shortens what is marked.
     * @return list<array{0: int, 1: int}>
     */
    public static function sentences(string $text): array
    {
        $out = [];
        $start = 0;
        if (preg_match_all('/[.?!;](?:["”’\')\]]*)(?=\s)/u', $text, $m, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($m[0] as [$stop, $off]) {
                $end = $off + strlen($stop);
                if ($end - $start > 1) {
                    $out[] = [$start, $end];
                }
                $start = $end;
                while ($start < strlen($text) && $text[$start] === ' ') {
                    $start++;
                }
            }
        }
        if ($start < strlen($text)) {
            $out[] = [$start, strlen($text)];
        }
        return $out;
    }

    /**
     * Up to EXCERPT characters of $text around the marked words, in whole sentences where they fit (else cut at
     * words), with the marked words in [[ ]]. Without a mark, the passage's opening.
     * @param array{0: int, 1: int}|null $mark
     * @return array{text: string, cut_start: bool, cut_end: bool}
     */
    public static function excerpt(string $text, ?array $mark): array
    {
        $len = strlen($text);
        $budget = self::EXCERPT * 2;   // bytes; trimmed to characters below
        [$ms, $me] = $mark ?? [0, 0];
        $sent = self::sentences($text);
        $from = $ms;
        $to = $me;
        foreach ($sent as [$s, $e]) {   // whole sentences round the mark
            if ($s <= $ms && $ms < $e) {
                $from = $s;
            }
            if ($s < $me && $me <= $e) {
                $to = $e;
            }
        }
        if ($mark === null) {
            $to = $sent[0][1] ?? min($len, $budget);
        }
        // Then sentences after and before, in turn, while they fit.
        $chars = static fn (int $a, int $b): int => mb_strlen(substr($text, $a, $b - $a));
        for ($turn = 0, $grew = true; $grew; $turn++) {
            $grew = false;
            foreach ($turn % 2 === 0 ? ['after', 'before'] : ['before', 'after'] as $side) {
                foreach ($sent as [$s, $e]) {
                    if ($side === 'after' && $s >= $to && $chars($from, $e) <= self::EXCERPT) {
                        $to = $e;
                        $grew = true;
                        break;
                    }
                }
                foreach (array_reverse($sent) as [$s, $e]) {
                    if ($side === 'before' && $e <= $from && $chars($s, $to) <= self::EXCERPT) {
                        $from = $s;
                        $grew = true;
                        break;
                    }
                }
            }
        }
        // A sentence too long to show whole: cut at words round the mark.
        if ($chars($from, $to) > self::EXCERPT + max(0, $chars($ms, $me))) {
            $room = max(60, intdiv(self::EXCERPT - $chars($ms, $me), 2));
            $from = max($from, self::wordStart($text, max(0, $ms - $room)));
            $to = min($to, self::wordEnd($text, min($len, $me + $room)));
            if ($mark === null) {
                $from = 0;
                $to = self::wordEnd($text, min($len, self::EXCERPT));
            }
        }
        $from = max(0, $from);
        $to = min($len, max($to, $from));
        $out = $mark === null || $me <= $ms
            ? substr($text, $from, $to - $from)
            : substr($text, $from, $ms - $from) . '[[' . substr($text, $ms, $me - $ms) . ']]' . substr($text, $me, $to - $me);
        return ['text' => trim($out), 'cut_start' => $from > 0, 'cut_end' => $to < $len];
    }

    /** The start of the word at or after byte $at. */
    private static function wordStart(string $text, int $at): int
    {
        if ($at <= 0) {
            return 0;
        }
        $sp = strpos($text, ' ', $at);
        return $sp === false ? $at : $sp + 1;
    }

    /** The end of the word at or before byte $at. */
    private static function wordEnd(string $text, int $at): int
    {
        if ($at >= strlen($text)) {
            return strlen($text);
        }
        $sp = strrpos(substr($text, 0, $at), ' ');
        return $sp === false ? $at : $sp;
    }
}
