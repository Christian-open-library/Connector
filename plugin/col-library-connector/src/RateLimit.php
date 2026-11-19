<?php
/**
 * Usage limits kept in <state_dir>/state.sqlite (created on first use, WAL mode):
 *  - tool calls per minute and per day (fixed UTC windows), checked before each tools/call;
 *  - Voyage tokens per UTC day: each Voyage request first reserves its estimate (reserveVoyage, one
 *    BEGIN IMMEDIATE transaction, so concurrent requests cannot all pass the check), and the
 *    reservation is then corrected to the tokens actually used (adjustVoyage). A request that
 *    fails or times out keeps its reservation: it may have been billed.
 * All callers share one set of counters. A personal link or signed-in account (Links, OAuth) also has
 * its own per-minute and per-day call counters (link_minute:<id>, link_day:<id>), checked first, and its
 * own daily re-ranking allowance (link_voyage:<id>): max_voyage_tokens_per_person_per_day, never more
 * than a fifth of the shared budget. So one person cannot switch off meaning search for everyone
 * (THREAT-MODEL.md, T1), and the shared budget is spread over many people (2026-11-13). Only re-ranking
 * (the costly step) is refused at the person's limit: query embeddings cost a few tokens, so meaning
 * search keeps working for them, without re-ranking, until 00:00 UTC.
 *
 * A person also has a daily count of passages from documents used by permission (link_licensed:<id>,
 * takeLicensed; src/Licensed.php), and of passages read_passage and exact-mode search gave them in full
 * (link_read:<id>; readRefusal before, countRead after), with a mark on the days a request was refused for that
 * limit (link_readcap:<id>). read_day counts every caller's passages.
 * Counts only: nothing says what was read. readingReport() gives the owner key the last 7 days of these.
 * Like the other day counters they are deleted after a week.
 *
 * Failure policy if the state database cannot be used: tool calls are allowed (the library keeps
 * working), Voyage is refused (no spending that is not counted) and passages of documents used by
 * permission are left out (none handed out uncounted). All cases are logged.
 */

namespace ColLibrary;

defined('ABSPATH') || exit;

final class RateLimit
{
    private const CALLS_MINUTE = 'calls_minute';
    private const CALLS_DAY = 'calls_day';
    private const VOYAGE_DAY = 'voyage_tokens';
    /**
     * Kept for good, by UTC month (period YYYYMM): tokens Voyage billed by kind (spend_embed,
     * spend_rerank), and the number of days the shared daily budget ran out (spend_capped).
     */
    private const SPEND = 'spend_';
    /** Tokens Voyage billed by kind and UTC day (voyage_day:<kind>); pruned after a week like other day counters. */
    private const SPEND_DAY = 'voyage_day:';
    /** Marks a UTC day on which the shared daily Voyage budget ran out (so it is counted once). */
    private const CAPPED_DAY = 'voyage_capped';
    /** A person's allowance is at most this fraction of the daily Voyage budget: 1 / LINK_VOYAGE_DIVISOR. */
    public const LINK_VOYAGE_DIVISOR = 5;
    /** Default max_voyage_tokens_per_person_per_day: about 90 re-ranked searches. */
    public const PERSON_VOYAGE_DEFAULT = 1_000_000;
    /** Default max_read_passages_per_person_per_day (Config explains the number). */
    public const READ_DEFAULT = 2000;
    /** Tool calls in a day that make an account "heavy" in readingReport (80% of a signed-in account's 500). */
    public const HEAVY_CALLS = 400;

    /** Why the last reserveVoyage() refused: 'person', 'pool' or 'shared' (null if it did not refuse). */
    public ?string $lastRefusal = null;

    private ?\PDO $db = null;

    /**
     * @param \Closure|null $clock returns the current Unix time in seconds (tests use a fixed clock)
     */
    public function __construct(
        private readonly string $stateDir,
        private readonly int $callsPerMinute,
        private readonly int $callsPerDay,
        private readonly int $voyageTokensPerDay,
        private readonly ?Log $log = null,
        private readonly ?\Closure $clock = null,
        private readonly ?array $link = null,
        private readonly int $personVoyageTokensPerDay = self::PERSON_VOYAGE_DEFAULT,
        private readonly int $licensedPerDay = Licensed::PER_DAY_DEFAULT,
        private readonly int $readPerDay = self::READ_DEFAULT,
    ) {
    }

    /** @param array{id: string, calls_per_day: int, calls_per_minute: int}|null $link the personal link in use, if any */
    public static function fromConfig(Config $config, ?Log $log = null, ?array $link = null): self
    {
        return new self(
            $config->stateDir(),
            $config->maxCallsPerMinute(),
            $config->maxCallsPerDay(),
            $config->maxVoyageTokensPerDay(),
            $log,
            null,
            $link,
            $config->maxVoyageTokensPerPersonPerDay(),
            $config->maxLicensedPassagesPerPersonPerDay(),
            $config->maxReadPassagesPerPersonPerDay(),
        );
    }

    /**
     * Counts one tool call if the limits allow it.
     * @return string|null null if the call may proceed, else a message for the user explaining the limit
     */
    public function takeCall(): ?string
    {
        $now = $this->now();
        $minute = intdiv($now, 60);
        $day = intdiv($now, 86400);
        try {
            return $this->write(function (\PDO $db) use ($now, $minute, $day): ?string {
                $perMinute = $this->value($db, self::CALLS_MINUTE, $minute);
                $perDay = $this->value($db, self::CALLS_DAY, $day);
                if ($this->link !== null) {
                    $max = $this->link['calls_per_minute'];
                    if ($this->value($db, 'link_minute:' . $this->link['id'], $minute) >= $max) {
                        $wait = 60 - $now % 60;
                        return "Rate limit reached: this link allows at most $max tool calls per minute. "
                            . "Wait about $wait seconds, then try again.";
                    }
                    $max = $this->link['calls_per_day'];
                    if ($this->value($db, 'link_day:' . $this->link['id'], $day) >= $max) {
                        $hours = max(1, (int) ceil((86400 - $now % 86400) / 3600));
                        return "Daily limit reached: this link allows at most $max tool calls per day. The limit "
                            . "resets at 00:00 UTC, in about $hours hour" . ($hours === 1 ? '' : 's') . '.';
                    }
                }
                if (($pool = $this->pool()) !== null) {
                    [$name, $share] = $pool;
                    $max = max(1, (int) floor($this->callsPerMinute * $share));
                    if ($this->value($db, "pool_minute:$name", $minute) >= $max) {
                        $wait = 60 - $now % 60;
                        return 'Rate limit reached: open accounts together may make at most ' . $max . ' tool calls per '
                            . "minute right now. Wait about $wait seconds, then try again.";
                    }
                    $max = max(1, (int) floor($this->callsPerDay * $share));
                    if ($this->value($db, "pool_day:$name", $day) >= $max) {
                        $hours = max(1, (int) ceil((86400 - $now % 86400) / 3600));
                        return 'Daily limit reached: open accounts together may make at most ' . $max . ' tool calls per '
                            . "day. The limit resets at 00:00 UTC, in about $hours hour" . ($hours === 1 ? '' : 's') . '.';
                    }
                }
                if ($perMinute >= $this->callsPerMinute) {
                    $wait = 60 - $now % 60;
                    return "Rate limit reached: this library answers at most {$this->callsPerMinute} tool calls "
                        . "per minute. Wait about $wait seconds, then try again.";
                }
                if ($perDay >= $this->callsPerDay) {
                    $hours = max(1, (int) ceil((86400 - $now % 86400) / 3600));
                    return "Daily limit reached: this library answers at most {$this->callsPerDay} tool calls "
                        . "per day. The limit resets at 00:00 UTC, in about $hours hour" . ($hours === 1 ? '' : 's') . '.';
                }
                $this->add($db, self::CALLS_MINUTE, $minute, 1);
                $this->add($db, self::CALLS_DAY, $day, 1);
                if ($this->link !== null) {
                    $this->add($db, 'link_minute:' . $this->link['id'], $minute, 1);
                    $this->add($db, 'link_day:' . $this->link['id'], $day, 1);
                }
                if (($pool = $this->pool()) !== null) {
                    $this->add($db, 'pool_minute:' . $pool[0], $minute, 1);
                    $this->add($db, 'pool_day:' . $pool[0], $day, 1);
                }
                if ($perMinute === 0) {
                    // First call of a new minute: drop counters nobody will read again (minute
                    // counters before this minute, day counters older than a week, and the public checker's
                    // visitor codes of earlier days, which the privacy page says are deleted the next day).
                    $db->prepare("DELETE FROM counters WHERE
                        ((name = ? OR name LIKE 'link^_minute:%' ESCAPE '^' OR name LIKE 'pool^_minute:%' ESCAPE '^'
                            OR name = 'public_minute' OR name LIKE 'visitor^_minute:%' ESCAPE '^')
                            AND period < ?)
                        OR (name LIKE 'visitor^_day:%' ESCAPE '^' AND period < ?)
                        OR (name <> ? AND name NOT LIKE 'link^_minute:%' ESCAPE '^' AND name NOT LIKE 'pool^_minute:%' ESCAPE '^'
                            AND name <> 'public_minute' AND name NOT LIKE 'visitor^_minute:%' ESCAPE '^'
                            AND name NOT LIKE 'spend^_%' ESCAPE '^' AND period < ?)")
                        ->execute([self::CALLS_MINUTE, $minute, $day, self::CALLS_MINUTE, $day - 7]);
                }
                return null;
            });
        } catch (\Throwable $e) {
            $this->log?->error('ratelimit_unavailable', ['op' => 'takeCall', 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Counts one check by the website's public quotation checker (PublicCheck) if its limits allow it: per visitor
     * (a daily code, PublicCheck::visitorCode) a minute and a day, and for all visitors together a minute and a
     * day. These counters are its own: the checker does not use the signed-in limits, and they do not use its.
     * Yesterday's visitor codes are deleted on the first check of each minute. A kept answer ($kept: PublicCheck's
     * answers kept for repeats of the same check) counts only against the visitor's own limits. The website's library
     * search (PublicSearch) shares these counters; $words names what is counted in the messages (PUBLIC_SEARCH_WORDS).
     * @param array{visitor_minute: int, visitor_day: int, minute: int, day: int} $max
     * @param array{0: string, 1: string, 2: string} $words plural, the service's name, the verb
     * @return array{0: string, 1: string, 2: int}|null null if the check may run, else [which limit, a message for
     *         the visitor, seconds to wait]
     */
    public const PUBLIC_CHECK_WORDS = ['checks', 'The checker', 'check'];
    public const PUBLIC_SEARCH_WORDS = ['searches', 'The search', 'search'];

    public function takePublic(string $visitor, array $max, bool $kept = false, array $words = self::PUBLIC_CHECK_WORDS): ?array
    {
        [$things, $service, $verb] = $words;
        $now = $this->now();
        $minute = intdiv($now, 60);
        $day = intdiv($now, 86400);
        $visitor = preg_match('/^[0-9a-f]{1,64}$/D', $visitor) === 1 ? $visitor : 'unknown';
        $wait = 60 - $now % 60;
        $untilReset = 86400 - $now % 86400;
        $hours = max(1, (int) ceil($untilReset / 3600));
        $resets = "It starts again at 00:00 UTC, in about $hours hour" . ($hours === 1 ? '' : 's') . '.';
        try {
            return $this->write(function (\PDO $db) use ($visitor, $max, $kept, $minute, $day, $wait, $untilReset, $resets,
                                                    $things, $service, $verb): ?array {
                $allMinute = $this->value($db, 'public_minute', $minute);
                if ($this->value($db, "visitor_minute:$visitor", $minute) >= $max['visitor_minute']) {
                    return ['visitor_minute', "That's a lot of $things in one minute. Wait about $wait seconds, then try again.", $wait];
                }
                if ($this->value($db, "visitor_day:$visitor", $day) >= $max['visitor_day']) {
                    return ['visitor_day', "You've reached today's limit of {$max['visitor_day']} $things on this page. $resets "
                        . "To $verb more, add the library to your AI app: it is free.", $untilReset];
                }
                if ($kept) {   // a kept answer costs the server almost nothing: only the visitor's own limits apply
                    $this->add($db, "visitor_minute:$visitor", $minute, 1);
                    $this->add($db, "visitor_day:$visitor", $day, 1);
                    return null;
                }
                if ($allMinute >= $max['minute']) {
                    return ['minute', "$service is busy right now. Wait about $wait seconds, then try again.", $wait];
                }
                if ($this->value($db, 'public_day', $day) >= $max['day']) {
                    return ['day', "$service has answered all it can for today. $resets To $verb now, add the library "
                        . 'to your AI app: it is free.', $untilReset];
                }
                if ($allMinute === 0) {
                    $db->prepare("DELETE FROM counters WHERE
                        ((name = 'public_minute' OR name LIKE 'visitor^_minute:%' ESCAPE '^') AND period < ?)
                        OR (name LIKE 'visitor^_day:%' ESCAPE '^' AND period < ?)")->execute([$minute, $day]);
                }
                $this->add($db, 'public_minute', $minute, 1);
                $this->add($db, 'public_day', $day, 1);
                $this->add($db, "visitor_minute:$visitor", $minute, 1);
                $this->add($db, "visitor_day:$visitor", $day, 1);
                return null;
            });
        } catch (\Throwable $e) {
            // Unlike takeCall, refuse: nobody signed in, so nothing else stands between the page and the server.
            $this->log?->error('ratelimit_unavailable', ['op' => 'takePublic', 'error' => $e->getMessage()]);
            return ['unavailable', "$service is not available right now. Please try again in a few minutes.", 60];
        }
    }

    /** Passages of documents used by permission one person may be given per UTC day. */
    public function licensedPerDay(): int
    {
        return $this->licensedPerDay;
    }

    /**
     * Counts passages of documents used by permission (the licensed shelf) against this person's daily
     * limit, in one write transaction: returns how many of the $want passages may be served (0 to $want)
     * and counts those. Callers without a personal link or account share one counter. If the count
     * cannot be written, none is served (nothing is handed out that is not counted).
     */
    public function takeLicensed(int $want): int
    {
        if ($want <= 0) {
            return 0;
        }
        $day = intdiv($this->now(), 86400);
        $name = $this->link !== null ? 'link_licensed:' . $this->link['id'] : 'licensed_day';
        try {
            return $this->write(function (\PDO $db) use ($want, $day, $name): int {
                $give = max(0, min($want, $this->licensedPerDay - $this->value($db, $name, $day)));
                if ($give > 0) {
                    $this->add($db, $name, $day, $give);
                }
                return $give;
            });
        } catch (\Throwable $e) {
            $this->log?->error('ratelimit_unavailable', ['op' => 'takeLicensed', 'error' => $e->getMessage()]);
            return 0;
        }
    }

    /** Passages read_passage and exact-mode search may give one person in full per UTC day. */
    public function readPerDay(): int
    {
        return $this->readPerDay;
    }

    /** Where a refused reading request came from: read_passage, or search_library in exact mode. */
    public const READ_TOOL = 'read';
    public const READ_EXACT = 'exact';

    /**
     * Before the work of a reading request (read_passage, or search_library in mode "exact", which pages through
     * a work in book order): null if this person may still read today, else why not. A personal link or
     * signed-in account that has already had its daily number is refused, and that day is marked for
     * readingReport (link_readcap:<id>), so only a refused request marks it: 500 ordinary reads (4 passages each,
     * 2,000 in all) never do. The owner key is never refused. If the store can't be used, the request goes ahead
     * (as takeCall does: the library keeps working) and that is logged.
     * @param string $what self::READ_TOOL or self::READ_EXACT (the wording of the refusal)
     */
    public function readRefusal(string $what = self::READ_TOOL): ?string
    {
        if ($this->link === null) {
            return null;
        }
        $now = $this->now();
        $day = intdiv($now, 86400);
        try {
            return $this->write(function (\PDO $db) use ($day, $now, $what): ?string {
                $had = $this->value($db, 'link_read:' . $this->link['id'], $day);
                if ($had < $this->readPerDay) {
                    return null;
                }
                if ($this->value($db, 'link_readcap:' . $this->link['id'], $day) === 0) {
                    $this->add($db, 'link_readcap:' . $this->link['id'], $day, 1);
                }
                $hours = max(1, (int) ceil((86400 - $now % 86400) / 3600));
                return 'Daily reading limit reached: this account has read ' . number_format($had)
                    . ' passages in full today, and one account may read ' . number_format($this->readPerDay)
                    . ' a day' . ($what === self::READ_EXACT ? ' (exact-mode search counts too, as it pages through books)' : '')
                    . '. ' . ($what === self::READ_EXACT ? 'Searching in the normal mode ("best") still works.' : 'Searching still works.')
                    . ' The limit resets at 00:00 UTC, in about ' . $hours
                    . ' hour' . ($hours === 1 ? '' : 's') . '. The library is for study and quotation, not '
                    . 'for copying books: to read a whole work, open its "Copied from" link. To ask for more, '
                    . 'write to hello@christianopenlibrary.org.';
            });
        } catch (\Throwable $e) {
            $this->log?->error('ratelimit_unavailable', ['op' => 'readRefusal', 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * After a reading request: counts the $n passages it actually gave in full (after any trimming) against this
     * person's day (link_read:<id>) and everyone's (read_day). The request was allowed by readRefusal, so a day's
     * count can end over the limit by what requests already under way give (one request: at most 21 passages; a
     * burst: at most a minute's calls_per_minute requests). Counts only: nothing says
     * which passages. A write failure is logged; the passages were already given.
     */
    public function countRead(int $n): void
    {
        if ($n <= 0) {
            return;
        }
        $day = intdiv($this->now(), 86400);
        try {
            $this->write(function (\PDO $db) use ($n, $day): void {
                if ($this->link !== null) {
                    $this->add($db, 'link_read:' . $this->link['id'], $day, $n);
                }
                $this->add($db, 'read_day', $day, $n);
            });
        } catch (\Throwable $e) {
            $this->log?->error('ratelimit_unavailable', ['op' => 'countRead', 'error' => $e->getMessage()]);
        }
    }

    /**
     * For the owner key only (Mcp, method library/reading; homebox's deploy/reading.py): the last 7 UTC days of
     * reading counts. days: the day numbers, oldest first; read: passages read_passage and exact-mode search gave
     * on each (everyone); capped: id => days that id was refused for its reading limit; heavy: id => days it made HEAVY_CALLS or more tool
     * calls. Ids are the made-up ones (u + 12 hex for an account, a personal link's id), never names or emails.
     */
    public function readingReport(): array
    {
        $today = intdiv($this->now(), 86400);
        $days = range($today - 6, $today);
        $db = $this->db();
        $read = array_map(fn (int $d): int => $this->value($db, 'read_day', $d), $days);
        $capped = [];
        $heavy = [];
        $st = $db->prepare("SELECT name, period FROM counters WHERE period BETWEEN ? AND ? AND (
                (name LIKE 'link^_readcap:%' ESCAPE '^' AND value >= 1) OR (name LIKE 'link^_day:%' ESCAPE '^' AND value >= ?))
            ORDER BY period");
        $st->execute([$days[0], $today, self::HEAVY_CALLS]);
        foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$name, $period]) {
            [$kind, $id] = explode(':', (string) $name, 2);
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)) {
                continue;
            }
            if ($kind === 'link_readcap') {
                $capped[$id][] = (int) $period;
            } else {
                $heavy[$id][] = (int) $period;
            }
        }
        return ['days' => $days, 'read' => $read, 'read_limit' => $this->readPerDay, 'heavy_calls' => self::HEAVY_CALLS,
                'capped' => (object) $capped, 'heavy' => (object) $heavy];
    }

    /** True if the daily Voyage token budget has room for about $estimatedTokens more. */
    public function voyageAllowed(int $estimatedTokens): bool
    {
        if ($this->voyageTokensPerDay <= 0) {
            return false;
        }
        try {
            $db = $this->db();
            $day = intdiv($this->now(), 86400);
            $estimate = max(0, $estimatedTokens);
            if ($this->link !== null
                && $this->value($db, $this->linkVoyageName(), $day) + $estimate > $this->linkVoyageMax()) {
                return false;
            }
            return $this->value($db, self::VOYAGE_DAY, $day) + $estimate <= $this->voyageTokensPerDay;
        } catch (\Throwable $e) {
            $this->log?->error('ratelimit_unavailable', ['op' => 'voyageAllowed', 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Reserves about $estimatedTokens of today's Voyage budget before a request is sent: checks
     * and adds in one write transaction. False (refuse Voyage) when the budget has no room or the
     * reservation cannot be written; $lastRefusal then says which limit refused. $personLimit false
     * (query embeddings) still counts the tokens against the person but is not refused by their
     * allowance, only by the pool and shared budgets.
     */
    public function reserveVoyage(int $estimatedTokens, bool $personLimit = true): bool
    {
        $this->lastRefusal = null;
        if ($this->voyageTokensPerDay <= 0) {
            $this->lastRefusal = 'shared';
            return false;
        }
        $estimate = max(1, $estimatedTokens);
        $day = intdiv($this->now(), 86400);
        try {
            return $this->write(function (\PDO $db) use ($estimate, $day, $personLimit): bool {
                if ($this->value($db, self::VOYAGE_DAY, $day) + $estimate > $this->voyageTokensPerDay) {
                    if ($this->value($db, self::CAPPED_DAY, $day) === 0) {
                        $this->add($db, self::CAPPED_DAY, $day, 1);
                        $this->add($db, self::SPEND . 'capped', (int) gmdate('Ym', $this->now()), 1);
                    }
                    $this->lastRefusal = 'shared';
                    return false;
                }
                if ($this->link !== null && $personLimit) {
                    if ($this->value($db, $this->linkVoyageName(), $day) + $estimate > $this->linkVoyageMax()) {
                        $this->lastRefusal = 'person';
                        return false;
                    }
                }
                if (($pool = $this->pool()) !== null
                    && $this->value($db, 'pool_voyage:' . $pool[0], $day) + $estimate
                        > max(1, (int) floor($this->voyageTokensPerDay * $pool[1]))) {
                    $this->lastRefusal = 'pool';
                    return false;
                }
                if ($this->link !== null) {
                    $this->add($db, $this->linkVoyageName(), $day, $estimate);
                }
                if ($pool !== null) {
                    $this->add($db, 'pool_voyage:' . $pool[0], $day, $estimate);
                }
                $this->add($db, self::VOYAGE_DAY, $day, $estimate);
                return true;
            });
        } catch (\Throwable $e) {
            $this->log?->error('ratelimit_unavailable', ['op' => 'reserveVoyage', 'error' => $e->getMessage()]);
            $this->lastRefusal = 'shared';
            return false;
        }
    }

    /**
     * Corrects today's Voyage total once a request's real usage is known: $delta = tokens used
     * minus tokens reserved (negative when the estimate was high). The total never goes below 0.
     */
    public function adjustVoyage(int $delta): void
    {
        if ($delta === 0) {
            return;
        }
        $day = intdiv($this->now(), 86400);
        try {
            $this->write(function (\PDO $db) use ($delta, $day): void {
                $this->add($db, self::VOYAGE_DAY, $day, max($delta, -$this->value($db, self::VOYAGE_DAY, $day)));
                foreach (array_filter([$this->link !== null ? $this->linkVoyageName() : null,
                                       ($p = $this->pool()) !== null ? 'pool_voyage:' . $p[0] : null]) as $name) {
                    $this->add($db, $name, $day, max($delta, -$this->value($db, $name, $day)));
                }
            });
        } catch (\Throwable $e) {
            $this->log?->error('ratelimit_unavailable', ['op' => 'adjustVoyage', 'tokens' => $delta, 'error' => $e->getMessage()]);
        }
    }

    /** Adds the tokens a Voyage call actually used (usage.total_tokens) to today's total. */
    public function addVoyageTokens(int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }
        $day = intdiv($this->now(), 86400);
        try {
            $this->write(function (\PDO $db) use ($tokens, $day): void {
                $this->add($db, self::VOYAGE_DAY, $day, $tokens);
                if ($this->link !== null) {
                    $this->add($db, $this->linkVoyageName(), $day, $tokens);
                }
                if (($pool = $this->pool()) !== null) {
                    $this->add($db, 'pool_voyage:' . $pool[0], $day, $tokens);
                }
            });
        } catch (\Throwable $e) {
            $this->log?->error('ratelimit_unavailable', ['op' => 'addVoyageTokens', 'tokens' => $tokens, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Records the tokens one Voyage request was billed for, by kind ('embed', 'rerank') and UTC month,
     * for the public cost card (Support). Never throws; a failure is logged.
     */
    public function countSpend(string $kind, int $tokens): void
    {
        if ($tokens <= 0 || preg_match('/^[a-z]{1,16}$/', $kind) !== 1) {
            return;
        }
        $month = (int) gmdate('Ym', $this->now());
        $day = intdiv($this->now(), 86400);
        try {
            $this->write(function (\PDO $db) use ($kind, $month, $day, $tokens): void {
                $this->add($db, self::SPEND . $kind, $month, $tokens);
                $this->add($db, self::SPEND_DAY . $kind, $day, $tokens);
            });
        } catch (\Throwable $e) {
            $this->log?->error('ratelimit_unavailable', ['op' => 'countSpend', 'tokens' => $tokens, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, array<int, int>> by kind, then by month (YYYYMM), oldest first: billed tokens
     *   ('embed', 'rerank') and days the daily budget ran out ('capped')
     */
    public function spending(): array
    {
        $out = [];
        $rows = $this->db()->query("SELECT substr(name, 7), period, value FROM counters
            WHERE name LIKE 'spend^_%' ESCAPE '^' ORDER BY name, period")->fetchAll(\PDO::FETCH_NUM);
        foreach ($rows as [$kind, $period, $value]) {
            $out[(string) $kind][(int) $period] = (int) $value;
        }
        return $out;
    }

    /** @return array<string, int> billed tokens by kind over the last 7 whole UTC days (not today) */
    public function recentSpending(): array
    {
        $today = intdiv($this->now(), 86400);
        $st = $this->db()->prepare("SELECT substr(name, 12), SUM(value) FROM counters
            WHERE name LIKE 'voyage^_day:%' ESCAPE '^' AND period >= ? AND period < ? GROUP BY name ORDER BY name");
        $st->execute([$today - 7, $today]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_NUM) as [$kind, $sum]) {
            $out[(string) $kind] = (int) $sum;
        }
        return $out;
    }

    /** @return array{calls_minute: int, calls_day: int, voyage_tokens: int} current counters */
    public function usage(): array
    {
        $now = $this->now();
        $db = $this->db();
        return [
            'calls_minute'  => $this->value($db, self::CALLS_MINUTE, intdiv($now, 60)),
            'calls_day'     => $this->value($db, self::CALLS_DAY, intdiv($now, 86400)),
            'voyage_tokens' => $this->value($db, self::VOYAGE_DAY, intdiv($now, 86400)),
        ];
    }

    /**
     * The shared pool this principal draws from, besides its own limits: [name, share of the global limits],
     * or null. Signed-in accounts that aren't library members draw from "open", so however many free
     * accounts someone makes, they can't use up what the owner, personal links and members rely on.
     */
    private function pool(): ?array
    {
        $name = $this->link['pool'] ?? null;
        if (!is_string($name) || preg_match('/^[a-z]{1,16}$/', $name) !== 1) {
            return null;
        }
        $share = (float) ($this->link['pool_share'] ?? 0.8);
        return [$name, max(0.0, min(1.0, $share))];
    }

    private function linkVoyageName(): string
    {
        return 'link_voyage:' . $this->link['id'];
    }

    /**
     * Tokens per UTC day one person (link or account) may reserve for re-ranking: their allowance, never
     * more than a fifth of the shared budget (at least 1, so the check stays meaningful).
     */
    private function linkVoyageMax(): int
    {
        return max(1, min($this->personVoyageTokensPerDay, intdiv($this->voyageTokensPerDay, self::LINK_VOYAGE_DIVISOR)));
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    private function db(): \PDO
    {
        if ($this->db !== null) {
            return $this->db;
        }
        if (!is_dir($this->stateDir) && !@mkdir($this->stateDir, 0700, true) && !is_dir($this->stateDir)) {
            throw new \RuntimeException('cannot create state directory');
        }
        $file = $this->stateDir . '/state.sqlite';
        $isNew = !is_file($file);
        $db = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA busy_timeout = 2000');
        $db->query('PRAGMA journal_mode = WAL')->fetchAll();   // stays in the old mode if WAL is unavailable
        $db->exec('PRAGMA synchronous = NORMAL');
        $db->exec('CREATE TABLE IF NOT EXISTS counters(name TEXT NOT NULL, period INTEGER NOT NULL,
                   value INTEGER NOT NULL, PRIMARY KEY(name, period)) WITHOUT ROWID');
        if ($isNew) {
            @chmod($file, 0600);
        }
        return $this->db = $db;
    }

    /** Runs $fn inside BEGIN IMMEDIATE ... COMMIT (one writer at a time across PHP workers). */
    private function write(\Closure $fn): mixed
    {
        $db = $this->db();
        $db->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn($db);
            $db->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            try {
                $db->exec('ROLLBACK');
            } catch (\Throwable) {
                // The transaction may already be gone; the original error matters.
            }
            throw $e;
        }
    }

    private function value(\PDO $db, string $name, int $period): int
    {
        $st = $db->prepare('SELECT value FROM counters WHERE name = ? AND period = ?');
        $st->execute([$name, $period]);
        $v = $st->fetchColumn();
        return $v === false ? 0 : (int) $v;
    }

    private function add(\PDO $db, string $name, int $period, int $amount): void
    {
        $db->prepare('INSERT INTO counters(name, period, value) VALUES(?, ?, ?)
                      ON CONFLICT(name, period) DO UPDATE SET value = value + excluded.value')
            ->execute([$name, $period, $amount]);
    }
}
