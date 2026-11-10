<?php
/**
 * Text handling for queries and passages: normalization (the same character folds as the indexer,
 * indexer/modernize.py), word tokens, stopwords, FTS5 query strings, plain text and shingles.
 *
 * FTS5 safety: every user word reaches FTS5 inside double quotes with internal quotes doubled
 * (quote()), so nothing a user types can act as FTS5 syntax (AND/OR/NOT/NEAR, *, ^, column
 * filters, parentheses). Column names in queries are constants from this code, never user input.
 */

declare(strict_types=1);

namespace ColLibrary;

defined('ABSPATH') || exit;

final class Text
{
    /** Character folds applied before matching (indexer/modernize.py CHAR_MAP). */
    private const CHAR_MAP = [
        'ſ' => 's', 'ẜ' => 's', 'ẝ' => 's', 'ꝛ' => 'r', 'Ʋ' => 'V', 'ʋ' => 'v',
        'ꝑ' => 'p', 'ꝓ' => 'p', 'ꝙ' => 'q', '’' => "'", 'ʼ' => "'",
    ];

    /**
     * Word tokens: a letter run with at most one inner apostrophe part ("he'll", "lov'd"), as
     * modernize.WORD_RE, or a run of digits. Apostrophes at the ends are not part of a word.
     */
    private const WORD_RE = "/\\p{L}[\\p{L}\\p{M}]*(?:'\\p{L}[\\p{L}\\p{M}]*)?|\\p{N}+/u";

    /** "it selfe" -> "itselfe" before spelling lookup (modernize.SELF_RE). */
    private const SELF_RE = '/\b(it|him|her|my|thy|our|your|them|one|thee)\s+(selfe|self|selues|selves|selfes)\b/iu';

    /**
     * Words left out of keyword (AND) queries: common English function words plus archaic forms
     * frequent in the corpus. Spelling is modernized first, so "hath" arrives here as "has".
     * "will", "might" and "art" stay searchable (free will, with all their might, the art of...).
     */
    private const STOPWORDS = 'a about above after again against all am an and any are as at be because been '
        . 'before being below between both but by can could did do does doing down during each few for from '
        . 'further had has have having he her here hers herself him himself his how i if in into is it its '
        . 'itself me more most my myself no nor not of off on once only or other our ours ourselves out over '
        . 'own same she should so some such than that the their theirs them themselves then there these they '
        . 'this those through to too under until up very was we were what when where which while who whom '
        . 'why with would you your yours yourself yourselves '
        . 'thee thou thy thine ye unto upon shall shalt wilt hast dost didst canst wast hath doth thereof '
        . 'therein whereof wherein also may must us';

    /** @var array<string,true>|null */
    private static ?array $stop = null;

    /**
     * Longest run of combining marks kept before NFKC (canonical reordering is quadratic in the
     * length of a run; real text has a few marks per letter at most).
     */
    public const MAX_MARK_RUN = 16;

    /**
     * Unicode NFKC (when intl is available), then the indexer's character folds: long s and other
     * old letter forms, curly apostrophes, VV -> W, and "it selfe" -> "itselfe". Case is kept.
     */
    public static function fold(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');   // invalid bytes become "?"
        }
        if (preg_match('/\p{M}{' . (self::MAX_MARK_RUN + 1) . '}/u', $text)) {
            $text = (string) preg_replace('/(\p{M}{' . self::MAX_MARK_RUN . '})\p{M}++/u', '$1', $text);
        }
        if (class_exists(\Normalizer::class)) {
            $nfkc = \Normalizer::normalize($text, \Normalizer::FORM_KC);
            if (is_string($nfkc)) {
                $text = $nfkc;
            }
        }
        $text = strtr($text, self::CHAR_MAP);
        if (strpbrk($text, 'vV') !== false) {
            // Same order as modernize.fold_chars: VVHICH -> WHICH, VVhich -> Which, vv -> w.
            $text = (string) preg_replace('/VV(?=[A-Z])/', 'W', $text);
            $text = (string) preg_replace('/(?<![A-Za-z])VV/', 'W', $text);
            $text = (string) preg_replace('/(?<![A-Za-z])Vv/', 'W', $text);
            $text = str_replace('vv', 'w', $text);
        }
        return (string) preg_replace(self::SELF_RE, '$1$2', $text);
    }

    /** fold() and lower case: the form words are looked up in (spelling table, stopwords). */
    public static function normalize(string $text): string
    {
        return mb_strtolower(self::fold($text), 'UTF-8');
    }

    /** @return list<string> word tokens of normalized text, in order (duplicates kept) */
    public static function words(string $normalized): array
    {
        if (preg_match_all(self::WORD_RE, $normalized, $m) === false) {
            return [];
        }
        return $m[0];
    }

    /**
     * Replaces each token by its modern spelling; a standard form with several words
     * ("as soon") becomes several tokens.
     * @param list<string> $tokens  @param array<string,string> $spelling variant => standard
     * @return list<string>
     */
    public static function modernTokens(array $tokens, array $spelling): array
    {
        $out = [];
        foreach ($tokens as $t) {
            $std = $spelling[$t] ?? $t;
            foreach (explode(' ', $std) as $part) {
                if ($part !== '') {
                    $out[] = $part;
                }
            }
        }
        return $out;
    }

    /**
     * Running text with old spellings replaced, keeping the case pattern of each word
     * (modernize_text): "Sinne" -> "Sin", "SINNE" -> "SIN". Input should be fold()ed.
     * @param array<string,string> $spelling variant => standard
     */
    public static function modernizeText(string $folded, array $spelling): string
    {
        if ($spelling === []) {
            return $folded;
        }
        $out = preg_replace_callback("/\\p{L}+(?:'\\p{L}+)?/u", static function (array $m) use ($spelling): string {
            $word = $m[0];
            $low = mb_strtolower($word, 'UTF-8');
            $std = $spelling[$low] ?? null;
            if ($std === null || $std === $low) {
                return $word;
            }
            if (mb_strlen($word) > 1 && mb_strtoupper($word, 'UTF-8') === $word) {
                return mb_strtoupper($std, 'UTF-8');
            }
            $first = mb_substr($word, 0, 1);
            if ($first !== mb_strtolower($first, 'UTF-8')) {
                return mb_strtoupper(mb_substr($std, 0, 1), 'UTF-8') . mb_substr($std, 1);
            }
            return $std;
        }, $folded);
        return is_string($out) ? $out : $folded;
    }

    public static function isStopword(string $token): bool
    {
        if (self::$stop === null) {
            self::$stop = array_fill_keys(explode(' ', self::STOPWORDS), true);
        }
        return isset(self::$stop[$token]);
    }

    /**
     * Distinct tokens for a keyword query: stopwords dropped, unless that would leave nothing
     * (a query such as "to be or not to be" keeps all its words).
     * @param list<string> $tokens  @return list<string>
     */
    public static function keywordTokens(array $tokens, int $max = 24): array
    {
        $keep = [];
        foreach ($tokens as $t) {
            if (!self::isStopword($t) && !in_array($t, $keep, true)) {
                $keep[] = $t;
            }
        }
        if ($keep === []) {
            foreach ($tokens as $t) {
                if (!in_array($t, $keep, true)) {
                    $keep[] = $t;
                }
            }
        }
        return array_slice($keep, 0, $max);
    }

    /**
     * An FTS5 string literal: the text in double quotes, inner quotes doubled. Control characters
     * become spaces (FTS5 reads a NUL byte as the end of the query: "unterminated string").
     */
    public static function quote(string $text): string
    {
        return '"' . str_replace('"', '""', (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $text)) . '"';
    }

    /** FTS5 query matching rows that contain every token (implicit AND). @param list<string> $tokens */
    public static function ftsAll(array $tokens): string
    {
        return implode(' ', array_map([self::class, 'quote'], $tokens));
    }

    /**
     * FTS5 query matching rows that contain, for every group, at least one of its alternatives:
     * ("sin" OR "sinne") AND ("love" OR "loue"). The AND must be explicit: FTS5 accepts an
     * implicit AND between plain phrases only, not next to a parenthesized group.
     * @param list<list<string>> $groups
     */
    public static function ftsAllOfAny(array $groups, string $join = ' AND '): string
    {
        $parts = [];
        foreach ($groups as $alts) {
            $alts = array_values(array_unique($alts));
            $q = implode(' OR ', array_map([self::class, 'quote'], $alts));
            $parts[] = count($alts) > 1 ? "($q)" : $q;
        }
        return implode($join, $parts);
    }

    /**
     * FTS5 phrase query restricted to one column: body : "the just shall live by faith".
     * $column must be a column name from this code, never user input.
     * @param list<string> $tokens
     */
    public static function ftsPhrase(array $tokens, string $column = 'body'): string
    {
        return $column . ' : ' . self::quote(implode(' ', $tokens));
    }

    /**
     * Plain text of a displayed passage (corpus.strip_markdown): no [p. N] markers, no emphasis
     * markers or backslashes, single spaces.
     */
    public static function plain(string $text): string
    {
        $text = (string) preg_replace('/\[p\. [^\]]*\]/u', ' ', $text);
        $text = (string) preg_replace('/(\*\*|__|\*|_)(?=\S)|(?<=\S)(\*\*|__|\*|_)/u', '', $text);
        $text = str_replace('\\', '', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Lower-case, accent-free form for loose comparisons (author names): "Théodore de Bèze" and
     * "theodore-de-beze" both become "theodore de beze".
     */
    public static function looseKey(string $text): string
    {
        static $memo = [];   // an author filter folds every work's author name, and names repeat
        if (isset($memo[$text])) {
            return $memo[$text];
        }
        if (count($memo) > 20000) {
            $memo = [];
        }
        $original = $text;
        $text = self::fold($text);
        if (preg_match('/[^\x00-\x7F]/', $text)) {
            static $tr = null;
            if ($tr === null && class_exists(\Transliterator::class)) {
                $tr = \Transliterator::create('NFKD; Latin-ASCII; [:Nonspacing Mark:] Remove; NFC') ?: false;
            }
            if ($tr instanceof \Transliterator) {
                $text = (string) ($tr->transliterate($text) ?: $text);
            } elseif (class_exists(\Normalizer::class)) {
                $text = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($text, \Normalizer::FORM_KD));
            }
        }
        $text = mb_strtolower($text, 'UTF-8');
        return $memo[$original] = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }

    /**
     * Word 5-shingles of a text (lower-cased words joined by spaces), for near-duplicate checks.
     * A text shorter than $n words gives one shingle.
     * @return array<string,true>
     */
    public static function shingles(string $text, int $n = 5): array
    {
        if (preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), $m) === false || !$m[0]) {
            return [];
        }
        $w = $m[0];
        $count = count($w);
        if ($count <= $n) {
            return [implode(' ', $w) => true];
        }
        $out = [];
        for ($i = 0; $i <= $count - $n; $i++) {
            $out[implode(' ', array_slice($w, $i, $n))] = true;
        }
        return $out;
    }

    /** Jaccard similarity of two shingle sets. @param array<string,true> $a  @param array<string,true> $b */
    public static function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect_key($a, $b));
        return $inter / (count($a) + count($b) - $inter);
    }

    /** Text cut to at most $max characters at a word boundary, with "…" when cut. */
    public static function clip(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, max(1, $max - 1));
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut) . '…';
    }

    /**
     * An exception message for the server log with quoted fragments removed: SQLite's FTS5 errors
     * quote parts of the query ('syntax error near "..."'), and query text is only logged when
     * log_queries is on.
     */
    public static function logMessage(\Throwable $e): string
    {
        return get_class($e) . ': ' . mb_substr((string) preg_replace('/"[^"]*"/u', '"…"', $e->getMessage()), 0, 300);
    }

    /**
     * Library text made inert for Markdown and HTML renderers, for every verbatim field of a tool
     * result (passage text, notes, labels, metadata, verses, dictionary entries): "![" and "]("
     * are escaped, so no image or link can form, and "<" before a letter, "/", "!" or "?" is
     * escaped, so no HTML tag, comment or autolink can form. Anything else is unchanged.
     */
    public static function inert(string $text): string
    {
        if (strpbrk($text, '[(<:') === false) {
            return $text;
        }
        // "![" and "](" start images and inline links; "]:" ends the label of a link reference definition
        // ("[label]: url", which turns "[text][label]" into a link), wherever it is (a quote or list too).
        // Page markers ("[p. 23]") and other brackets are untouched.
        $text = str_replace(['![', '](', ']:'], ['!\\[', ']\\(', ']\\:'], $text);
        // An even run of backslashes before "<" (none included) leaves it live: add one. Covers tags,
        // comments, declarations and autolinks, including email autolinks ("<1x@host>").
        return (string) preg_replace_callback('/(?<!\\\\)(\\\\*+)<(?=[^\\s<\\\\])/',
            static fn (array $m): string => $m[1] . (strlen($m[1]) % 2 === 0 ? '\\' : '') . '<', $text);
    }

    /**
     * One line of user text for echoing in a result header (no newlines, at most $max chars), without
     * control or invisible format characters (NUL, ESC, bidirectional overrides).
     */
    public static function oneLine(string $text, int $max = 200): string
    {
        $line = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', (string) preg_replace('/\s+/u', ' ', $text));
        return self::clip(trim($line), $max);
    }

    /**
     * Text a caller supplied (a query, term, author, page or reference), echoed in a result: one line
     * and inert, like library text, because the AI app may render the result as Markdown.
     */
    public static function echo(string $text, int $max = 200): string
    {
        return self::inert(self::oneLine($text, $max));
    }
}
