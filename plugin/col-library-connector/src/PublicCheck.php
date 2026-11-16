<?php
/**
 * The public quotation checker: POST /check on the office server, called by the library's website
 * (christianopenlibrary.org/quote.html) without signing in. It runs the same check as the check_quote tool
 * (src/Quote.php) and answers with JSON for the page to show.
 *
 * Nobody signs in, so the limits are its own (RateLimit::takePublic): a few checks a minute and a few dozen a
 * day per visitor, and a small total a minute and a day for everyone together, apart from what signed-in
 * people use. A visitor is counted by a code made from the address Cloudflare reports (CF-Connecting-IP) and
 * the day, keyed with a server secret, and the day's codes are deleted the next day. The words checked are
 * never written down: the log line has the verdict and the time only.
 *
 * A finished answer is kept for public_check_cache_hours (24) so that a share link opened by many people is checked
 * once: the file's name and its AES-256-GCM key are both HMACs, with a server secret, of the build, the name and the
 * words, so a kept answer can be found and read only by someone who sends the same words again. A kept answer costs
 * no slot and counts only against the visitor's own limits. A new build starts afresh.
 *
 * Only the library's website may call it from a browser (Origin), and the answer carries the CORS header for
 * that origin only. That is a courtesy, not a wall: a script can send any Origin, so the limits are the guard.
 * The request is a "simple" CORS request (POST, Content-Type text/plain, a JSON body), so browsers send no
 * preflight; OPTIONS is answered anyway.
 *
 * Documents used by permission (the licensed shelf) are not shown here: a match there gives the verdict, the
 * citation and the owner's official link, never the text.
 */

namespace ColLibrary;

defined('ABSPATH') || exit;

final class PublicCheck
{
    public const MAX_BODY = 4096;
    /** Time allowed for one check (the signed-in tools get Http::TOOL_SECONDS). */
    public const SECONDS = 12.0;
    /** Checks that may run at once. PHP-FPM has 8 workers for everything; the rest stay free for AI apps. */
    public const SLOTS = 2;
    /** Gaps ("...") allowed in one quotation: each piece is its own phrase search. */
    public const MAX_GAPS = 4;
    public const DEFAULT_ORIGINS = ['https://christianopenlibrary.org', 'https://www.christianopenlibrary.org'];
    private const OTHERS = 8;

    /**
     * @param array<string,string> $headers request headers (names in any case)
     * @param string $visitor the caller's address as Cloudflare reports it ('' if unknown)
     */
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
        if (!$config->publicCheck()) {
            return Response::json(404, '{}');
        }
        $log = Log::fromConfig($config)->with(['link' => 'public']);
        $origin = Config::normalizeOrigin((string) ($headers['origin'] ?? ''));
        if ($origin === null || !in_array($origin, $config->publicCheckOrigins(), true)) {
            $log->info('public_check', ['status' => 403, 'origin' => mb_substr((string) ($headers['origin'] ?? ''), 0, 100)]);
            return self::answer(403, ['error' => 'This checker answers only the library\'s own website.'], null);
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
            return self::answer(405, ['error' => 'Use POST.'], $origin, ['Allow' => 'POST, OPTIONS']);
        }
        if (strlen($body) > self::MAX_BODY) {
            return self::answer(413, ['error' => 'The quotation is too long to check.'], $origin);
        }
        $type = strtolower(trim(explode(';', (string) ($headers['content-type'] ?? ''), 2)[0]));
        if (!in_array($type, ['text/plain', 'application/json'], true)) {
            return self::answer(415, ['error' => 'Send the quotation as JSON.'], $origin);
        }
        try {
            $in = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $in = null;
        }
        $quote = is_array($in) && is_string($in['quote'] ?? null) ? trim($in['quote']) : null;
        $author = is_array($in) && is_string($in['author'] ?? null) ? trim(Text::oneLine($in['author'], 200)) : '';
        if ($quote === null || $quote === '' || (is_array($in) && array_diff(array_keys($in), ['quote', 'author']) !== [])) {
            return self::answer(400, ['error' => 'Type or paste the words to check.'], $origin);
        }
        if (mb_strlen($quote) > Quote::MAX_CHARS) {
            return self::answer(400, ['error' => 'That is too long to check at once (at most ' . number_format(Quote::MAX_CHARS)
                . ' characters). Paste its most distinctive sentence or two.'], $origin);
        }
        if (mb_strlen($author) > 100) {
            return self::answer(400, ['error' => 'The name is too long (at most 100 characters).'], $origin);
        }
        $quote = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $quote);
        if (preg_match_all('/\.{3,}|…/u', $quote) > self::MAX_GAPS) {
            return self::answer(400, ['error' => 'That has too many gaps ("...") to check at once. Use at most '
                . self::MAX_GAPS . ', or paste the words in full.'], $origin);
        }

        $kept = self::kept($config, $quote, $author);
        if ($kept !== null && ($answer = $kept->read()) !== null) {
            $now = $clock !== null ? (int) $clock() : time();
            $refusal = (new RateLimit($config->stateDir(), $config->maxCallsPerMinute(), $config->maxCallsPerDay(),
                $config->maxVoyageTokensPerDay(), $log, $clock))->takePublic(self::visitorCode($visitor, $config, $now),
                $config->publicCheckLimits(), true);
            if ($refusal !== null) {
                $log->info('public_check', ['status' => 429, 'limit' => $refusal[0]]);
                return self::answer(429, ['error' => $refusal[1], 'limit' => $refusal[0]], $origin,
                    ['Retry-After' => (string) $refusal[2]]);
            }
            $log->info('public_check', ['status' => 200, 'verdict' => $answer['verdict'] ?? '', 'kept' => true]);
            return self::answer(200, $answer, $origin);
        }

        // At most SLOTS checks at once, whoever asks: a crafted quotation can take the whole time allowed, and the
        // workers it would hold are the ones AI apps use. Held until this request ends.
        $slot = self::slot($config->stateDir());
        if ($slot === null) {
            $log->info('public_check', ['status' => 429, 'limit' => 'busy']);
            return self::answer(429, ['error' => 'The checker is busy right now. Wait a few seconds, then try again.',
                'limit' => 'busy'], $origin, ['Retry-After' => '5']);
        }
        // The checks together count as one person for the shared Voyage budget (their embeddings are small; this
        // keeps them under that person's allowance, so they can never use up what signed-in people rely on).
        $limits = new RateLimit($config->stateDir(), $config->maxCallsPerMinute(), $config->maxCallsPerDay(),
            $config->maxVoyageTokensPerDay(), $log, $clock,
            ['id' => 'public-check', 'calls_per_minute' => 1, 'calls_per_day' => 1],
            $config->maxVoyageTokensPerPersonPerDay(), $config->maxLicensedPassagesPerPersonPerDay());
        $now = $clock !== null ? (int) $clock() : time();
        $refusal = $limits->takePublic(self::visitorCode($visitor, $config, $now), $config->publicCheckLimits());
        if ($refusal !== null) {
            $log->info('public_check', ['status' => 429, 'limit' => $refusal[0]]);
            return self::answer(429, ['error' => $refusal[1], 'limit' => $refusal[0]], $origin,
                ['Retry-After' => (string) $refusal[2]]);
        }

        $started = microtime(true);
        $ctx = new Context($config, $limits, $log, $started + self::SECONDS);
        try {
            $store = new Store($config->dataDir());
            $check = new Quote($store, Voyage::forBuild($ctx, $store), $ctx->deadline, $config->nprobe(), $log,
                $config->rerankCandidates(), $config->rerankDocChars());
            $result = $check->check($quote, $author !== '' ? $author : null);
        } catch (SearchInputException $e) {
            return self::answer(400, ['error' => self::plainError($e->getMessage())], $origin);
        } catch (StoreException $e) {
            $log->error('store_unavailable', ['tool' => 'public_check', 'error' => Text::logMessage($e)]);
            return self::answer(503, ['error' => 'The library is not available right now. Please try again later.'], $origin);
        } catch (\Throwable $e) {
            $log->error('tool_failed', ['tool' => 'public_check', 'error' => Text::logMessage($e),
                'at' => basename($e->getFile()) . ':' . $e->getLine()]);
            return self::answer(500, ['error' => 'The check failed because of a problem on the server. Please try again '
                . 'in a moment.'], $origin);
        }
        $bible = null;
        try {
            $bible = self::bible($store, $quote);
        } catch (\Throwable $e) {   // the answer from the books stands without it
            $log->error('public_check_bible_failed', ['error' => Text::logMessage($e)]);
        }
        // A verse of the Bible is an answer even when the search of the books ran out of time.
        if ($result->incomplete && $result->verdict === QuoteResult::NOT_FOUND && $bible === null) {
            // Not a verdict: a "not found" the check didn't finish would be shared as if it were one.
            $log->info('public_check', ['status' => 503, 'limit' => 'time',
                'ms' => (int) round((microtime(true) - $started) * 1000)]);
            fclose($slot);
            return self::answer(503, ['error' => 'The check took too long to finish, so it can\'t give an answer. Please '
                . 'try again in a moment.', 'limit' => 'time'], $origin, ['Retry-After' => '10']);
        }
        $out = self::json($result);
        $out['bible'] = $bible;
        $log->info('public_check', ['status' => 200, 'verdict' => $out['verdict'], 'bible' => ($out['bible'] ?? null) !== null,
            'ms' => (int) round((microtime(true) - $started) * 1000)]);
        if (!$result->incomplete) {   // an answer cut short by time might be better next time
            $kept?->write($out);
        }
        fclose($slot);
        return self::answer(200, $out, $origin);
    }

    /** Where the answer to these words would be kept, or null if answers are not kept (or the build is missing). */
    private static function kept(Config $config, string $quote, string $author): ?KeptAnswer
    {
        $hours = $config->publicCheckCacheHours();
        $db = $config->dataDir() . '/library.sqlite';
        $stat = $hours > 0 ? @stat($db) : false;
        if ($stat === false) {
            return null;
        }
        $build = ($stat['ino'] ?? 0) . ':' . ($stat['size'] ?? 0) . ':' . ($stat['mtime'] ?? 0);
        $secret = hash('sha256', 'public-check-kept|' . $config->urlKey(), true);
        return new KeptAnswer($config->stateDir() . '/public-answers', $secret, "$build\0$author\0$quote", $hours * 3600);
    }

    /**
     * One of SLOTS lock files in the state directory, locked for this request (the lock goes when the handle is
     * closed or PHP ends the request), or null if every slot is taken.
     * @return resource|null
     */
    private static function slot(string $stateDir)
    {
        if (!is_dir($stateDir) && !@mkdir($stateDir, 0700, true) && !is_dir($stateDir)) {
            return null;
        }
        for ($i = 0; $i < self::SLOTS; $i++) {
            $file = "$stateDir/public-check-$i.lock";
            $h = @fopen($file, 'c');
            if ($h === false) {
                continue;
            }
            @chmod($file, 0600);
            if (flock($h, LOCK_EX | LOCK_NB)) {
                return $h;
            }
            fclose($h);
        }
        return null;
    }

    /**
     * The visitor's code for the limits: 16 hex characters from the address and the UTC day, keyed with a secret
     * derived from the server's url_key, so the codes say nothing about the address and change every day. An IPv6
     * address counts by its /64 network (one connection usually has a whole /64 to choose from). No address (a
     * request that did not come through Cloudflare) counts as one shared visitor.
     */
    public static function visitorCode(string $visitor, Config $config, int $now): string
    {
        $visitor = trim($visitor);
        $bin = strlen($visitor) <= 64 ? @inet_pton($visitor) : false;
        if ($bin === false) {
            $visitor = 'unknown';
        } elseif (strlen($bin) === 16) {
            $visitor = bin2hex(substr($bin, 0, 8)) . '::/64';
        } else {
            $visitor = inet_ntop($bin);
        }
        $key = hash('sha256', 'public-check-visitor|' . $config->urlKey(), true);
        return substr(hash_hmac('sha256', intdiv($now, 86400) . '|' . strtolower($visitor), $key), 0, 16);
    }

    /**
     * The result as the website shows it. Every string is plain text (the page puts it in with textContent);
     * "marked" and "given_marked" are lists of [text, differs] pieces.
     * @return array<string,mixed>
     */
    public static function json(QuoteResult $r): array
    {
        $p = $r->primary;
        $verdict = $r->verdict;
        $credit = self::credit($p);
        if ($verdict === QuoteResult::OTHER_AUTHOR && $credit !== null && $r->author !== null
            && Quote::byAuthor($p['work'], $r->authorKeys)) {
            $verdict = 'quoting';   // in the credited author's book, but he is quoting someone (a credit line follows)
        }
        $out = [
            'verdict' => $verdict,
            'quote' => Text::oneLine($r->quote, Quote::MAX_CHARS),
            'author' => $r->author !== null ? Text::oneLine($r->author, 100) : null,
            'author_works' => $r->authorWorks,
            'common' => $r->phraseWorks > Quote::COMMON_WORKS,
            'primary' => null,
            'earlier' => null,
            'others' => [],
            'others_total' => 0,
            'notes' => array_values(array_map(static fn (string $n): string => Text::oneLine($n, 300), $r->notes)),
            'bible' => null,
        ];
        if ($p === null) {
            return $out;
        }
        $out['primary'] = self::match($p, true) + ['credit' => $credit, 'quoting' => (bool) $p['quoting'],
            'named' => !empty($p['named'])];   // the writer names the credited author next to the words (Quote::namesAuthor)
        $e = $r->earliest;
        if ($verdict !== QuoteResult::OTHER_AUTHOR && $verdict !== 'quoting' && $e !== null
            && $e['work']['id'] !== $p['work']['id'] && $e['when'] < $p['when']
            && Text::looseKey($e['work']['author']) !== Text::looseKey($p['work']['author'])) {
            $out['earlier'] = self::match($e, false);
        }
        $others = array_values(array_filter($r->matches, static fn (array $m): bool => $m['work']['id'] !== $p['work']['id']));
        $out['others_total'] = count($others);
        foreach (array_slice($others, 0, self::OTHERS) as $m) {
            $out['others'][] = self::match($m, false) + ['credit' => self::credit($m)];
        }
        return $out;
    }

    /** Translations looked in, in this order: the first that has the words gives the reference and the text. */
    private const BIBLE_ORDER = ['kjv' => 'King James Version', 'asv' => 'American Standard Version',
        'drb' => 'Douay-Rheims Bible', 'webster' => 'Webster\'s Bible', 'darby' => 'Darby Translation',
        'ylt' => 'Young\'s Literal Translation', 'bsb' => 'Berean Standard Bible'];
    private const BIBLE_MORE = 4;      // further verses named after the first
    private const BIBLE_SPAN = 4;      // verses a quotation may run across

    /**
     * The words as the Bible has them, word for word (spelling as in the translation; capitals and punctuation are
     * not compared): within one verse, or running across up to BIBLE_SPAN verses. A quotation with a gap ("...")
     * is not looked for. Without this a verse was credited to the earliest book that quotes it.
     * When no translation has them word for word, the closest verse (bibleNear), marked near with the count of
     * words that match.
     * @return array{ref:string, translation:string, text:string, more:list<string>, total:int, near:bool, same?:int, words?:int}|null
     */
    public static function bible(Store $store, string $quote): ?array
    {
        if (preg_match('/\.{3,}|…/u', $quote)) {
            return null;
        }
        $words = self::bibleWords($quote);
        if (count($words) < 4 || count($words) > 150) {
            return null;
        }
        $bible = new Bible($store);
        $name = static fn (int $book): string => $bible->name($book, true);   // "Psalm 23:1", not "Psalms"
        $want = ' ' . implode(' ', $words) . ' ';
        foreach (self::BIBLE_ORDER as $code => $title) {
            // 1. Inside one verse.
            $hits = [];
            foreach ($store->bibleLike($code, '%' . implode('%', $words) . '%', 400) as $v) {
                if (str_contains(' ' . implode(' ', self::bibleWords($v['text'])) . ' ', $want)) {
                    $hits[] = $v;
                }
            }
            if ($hits !== []) {
                $first = $hits[0];
                $more = [];
                foreach (array_slice($hits, 1, self::BIBLE_MORE) as $v) {
                    $more[] = $name($v['book']) . ' ' . $v['chapter'] . ':' . $v['verse'];
                }
                return ['ref' => $name($first['book']) . ' ' . $first['chapter'] . ':' . $first['verse'],
                    'translation' => $title, 'text' => Text::oneLine($first['text'], 2000), 'more' => $more,
                    'total' => count($hits), 'near' => false];
            }
            // 2. Across verses: a verse that ends with the quotation's first 4 or more words, then the verses after it.
            if (count($words) < 6) {
                continue;
            }
            foreach ($store->bibleLike($code, '%' . implode('%', array_slice($words, 0, 4)) . '%', 2000) as $v) {
                $have = self::bibleWords($v['text']);
                for ($from = count($have) - 4; $from >= 0; $from--) {
                    $tail = array_slice($have, $from);
                    if ($tail !== array_slice($words, 0, count($tail))) {
                        continue;
                    }
                    $rest = array_slice($words, count($tail));
                    $texts = [$v['text']];
                    $last = $v['verse'];
                    foreach ($store->bibleVerses($code, $v['book'], $v['chapter'], $v['verse'] + 1, $v['chapter'],
                        $v['verse'] + self::BIBLE_SPAN - 1, self::BIBLE_SPAN) as $next) {
                        if ($rest === []) {
                            break;
                        }
                        $w = self::bibleWords($next['text']);
                        $n = min(count($w), count($rest));
                        if ($n === 0 || array_slice($w, 0, $n) !== array_slice($rest, 0, $n)
                            || ($n < count($rest) && $n < count($w))) {
                            continue 2;
                        }
                        $rest = array_slice($rest, $n);
                        $texts[] = $next['text'];
                        $last = $next['verse'];
                    }
                    if ($rest === [] && count($texts) > 1) {
                        return ['ref' => $name($v['book']) . ' ' . $v['chapter'] . ':' . $v['verse'] . '–' . $last,
                            'translation' => $title, 'text' => Text::oneLine(implode(' ', $texts), 4000), 'more' => [],
                            'total' => 1, 'near' => false];
                    }
                }
            }
        }
        return self::bibleNear($store, $words, $name);
    }

    private const BIBLE_NEAR_MIN = 6;      // words a quotation needs before a near match is looked for
    private const BIBLE_NEAR_MAX = 60;     // and at most (one verse's worth)
    private const BIBLE_NEAR_SHARE = 0.8;  // of its words that must be in the verse, in the same order

    /**
     * The verse closest to the quotation when no translation has it word for word: a modern translation the
     * library doesn't hold, or a verse quoted from memory. Candidates are the verses with two of the quotation's
     * three longest uncommon words; the closest is the one with most of the quotation's words in the same order
     * (at least BIBLE_NEAR_SHARE of them; all but one for a quotation under 8 words), and the same share of its
     * uncommon words, or all but as many as may differ (at least 2): shared small words alone are not a match.
     * @param list<string> $words  @param \Closure(int): string $name
     */
    private static function bibleNear(Store $store, array $words, \Closure $name): ?array
    {
        $n = count($words);
        if ($n < self::BIBLE_NEAR_MIN || $n > self::BIBLE_NEAR_MAX) {
            return null;
        }
        $rare = array_values(array_unique(array_filter($words,
            static fn (string $w): bool => mb_strlen($w) >= 4 && !Text::isStopword($w))));
        usort($rare, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        if (count($rare) < 3) {
            return null;
        }
        $need = $n < 8 ? $n - 1 : (int) ceil($n * self::BIBLE_NEAR_SHARE);
        // A word the verse doesn't have may be one of the uncommon ones ("I am the bread of life, Caesar"): as many may
        // be missing as the words allowed to differ, but at least 2 uncommon words must be in the verse.
        $needRare = min((int) ceil(count($rare) * self::BIBLE_NEAR_SHARE), max(2, count($rare) - ($n - $need)));
        $order = array_flip(array_keys(self::BIBLE_ORDER));
        $best = null;
        foreach ($store->bibleWithWords(array_slice($rare, 0, 3), 1500) as $v) {
            if (!isset($order[$v['translation']])) {
                continue;
            }
            $have = self::bibleWords($v['text']);
            $rareIn = count(array_intersect($rare, $have));
            if ($rareIn < $needRare) {
                continue;
            }
            // In a short quotation an uncommon word may be missing only if the rest is one phrase of the verse
            // ("I am the bread of life, Caesar"), not words scattered through it ("I believe in order to understand").
            if ($n < 8 && $rareIn < count($rare) && !self::phraseLessOne($words, $have)) {
                continue;
            }
            $same = self::inOrder($words, $have);
            $rank = [-$same, $order[$v['translation']], $v['book'], $v['chapter'], $v['verse']];
            if ($same >= $need && ($best === null || $rank < $best[0])) {
                $best = [$rank, $v, $same];
            }
        }
        if ($best === null) {
            return null;
        }
        [, $v, $same] = $best;
        return ['ref' => $name($v['book']) . ' ' . $v['chapter'] . ':' . $v['verse'],
            'translation' => self::BIBLE_ORDER[$v['translation']], 'text' => Text::oneLine($v['text'], 2000),
            'more' => [], 'total' => 1, 'near' => true, 'same' => $same, 'words' => $n];
    }

    /** Whether $words, less one of them, are one phrase of $have. */
    private static function phraseLessOne(array $words, array $have): bool
    {
        $in = ' ' . implode(' ', $have) . ' ';
        foreach (array_keys($words) as $k) {
            $less = $words;
            unset($less[$k]);
            if (str_contains($in, ' ' . implode(' ', $less) . ' ')) {
                return true;
            }
        }
        return false;
    }

    /** How many of $a's words are in $b in the same order (longest common subsequence). */
    private static function inOrder(array $a, array $b): int
    {
        $prev = array_fill(0, count($b) + 1, 0);
        foreach ($a as $x) {
            $row = [0];
            foreach ($b as $j => $y) {
                $row[] = $x === $y ? $prev[$j] + 1 : max($prev[$j + 1], $row[$j]);
            }
            $prev = $row;
        }
        return $prev[count($b)];
    }

    /** @return list<string> the words of a quotation or a verse, lower case, for comparing */
    private static function bibleWords(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', Text::normalize(str_replace(['’', '\''], '', $text)), $m);
        return $m[0];
    }

    /** One match: where it is, how close, and (for the main one) the wording, the context and the links. */
    private static function match(array $m, bool $full): array
    {
        $w = $m['work'];
        $licensed = Format::isLicensed($w);
        $note = (string) ($w['author_note'] ?? '');
        $page = $m['page'] !== null && $m['page'] !== '' ? (string) $m['page'] : null;
        $pageEnd = $m['page_end'] !== null && $m['page_end'] !== '' ? (string) $m['page_end'] : $page;
        $out = [
            'author' => Text::oneLine((string) $w['author'], 200),
            'dates' => $note !== '' ? Text::oneLine(trim(explode(',', $note, 2)[0]), 60) : '',
            'title' => Text::oneLine($w['title'] !== '' ? (string) $w['title'] : 'Untitled', 300),
            'year' => $w['year'] !== null ? (int) $w['year'] : null,
            'pages' => Format::pages($page, $pageEnd),
            'exact' => $m['edits'] === 0 && $m['misread'] === 0,
            'misread' => (int) $m['misread'],
            'same' => (int) $m['same'],
            'words' => (int) $m['words'],
            'left_out' => (bool) ($m['thin_pieces'] ?? false),
            'licensed' => $licensed,
        ];
        if (!$full) {
            return $out;
        }
        $source = (string) ($w['source_url'] ?? '');
        $out += [
            'about' => $note !== '' && $w['author'] !== '' ? Text::oneLine($note, 300) : '',
            'church' => Text::oneLine(Churches::label($w), 600),
            'ocr' => Quote::fromScan($w),
            'source' => preg_match('~^https?://~i', $source) === 1 ? Text::oneLine($source, 500) : null,
            'source_label' => $licensed ? 'Official copy' : 'Copied from',
            'scan' => null,
            'text' => null,
            'marked' => null,
            'given_marked' => null,
            'changes' => [],
            'before' => null,
            'after' => null,
        ];
        if ($licensed) {
            // Used by permission for the AI connector: the page shows where, not the words.
            return $out;
        }
        $out['scan'] = Format::scanLink($w, $page, (string) $m['text']);
        $out['text'] = Text::oneLine(Text::plain((string) $m['text']), 3000);
        if (!$out['exact']) {
            $out['marked'] = self::segments(Text::plain((string) $m['lib_spans']));
            $out['given_marked'] = self::segments((string) $m['quote_spans']);
            $out['changes'] = array_values(array_map(static fn (string $c): string => Text::oneLine($c, 300),
                array_slice($m['changes'], 0, 20)));
        }
        $out['before'] = Text::oneLine(Text::plain((string) $m['before']), 600);
        $out['after'] = Text::oneLine(Text::plain((string) $m['after']), 600);
        return $out;
    }

    /**
     * The name in a credit line after the words ("— Thomas Adams"), or null. Never for a document used by permission:
     * the line is the document's own text.
     */
    private static function credit(?array $m): ?string
    {
        return $m === null || $m['credit'] === null || Format::isLicensed($m['work']) ? null : Text::oneLine($m['credit'], 80);
    }

    /**
     * Text with Quote::MARK_OPEN/MARK_CLOSE around the words that differ, as [[text, differs], ...]: the marks are
     * characters no text has, so neither the library's Markdown nor the caller's asterisks can pass for one.
     * @return list<array{0: string, 1: bool}>
     */
    public static function segments(string $marked): array
    {
        $out = [];
        $budget = 3000;
        foreach (preg_split('/(' . Quote::MARK_OPEN . '.*?' . Quote::MARK_CLOSE . ')/u', $marked, -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            $differs = str_starts_with($piece, Quote::MARK_OPEN);
            $text = (string) preg_replace('/[\p{Cc}\p{Cf}\x{E000}\x{E001}]+/u', '', (string) preg_replace('/\s+/u', ' ', $piece));
            if ($text === '' || $budget <= 0) {
                continue;
            }
            $text = mb_substr($text, 0, $budget);
            $budget -= mb_strlen($text);
            $out[] = [$text, $differs];
        }
        return $out;
    }

    /** The checker's input errors, worded for a person at the website rather than an AI app. */
    private static function plainError(string $message): string
    {
        if (str_contains($message, 'too short')) {
            return 'That is too short to check: give at least ' . Quote::MIN_WORDS . ' words.';
        }
        if (str_contains($message, 'too long')) {
            return 'That is too long to check at once (at most ' . Quote::MAX_WORDS . ' words). Paste its most '
                . 'distinctive sentence or two.';
        }
        return 'The checker could not read that. Try again with the words alone.';
    }

    /** A JSON answer, with the CORS header when the origin is the website's. */
    private static function answer(int $status, array $data, ?string $origin, array $extra = []): Response
    {
        $headers = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'Vary' => 'Origin'] + $extra;
        if ($origin !== null) {
            $headers['Access-Control-Allow-Origin'] = $origin;
        }
        return Response::json($status, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE), $headers);
    }
}
