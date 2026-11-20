<?php
/**
 * Whether a passage that cites a Bible reference treats it as its subject (find_scripture_citations lists
 * these first: DESIGN.md, find_scripture_citations order). Read from the passage's section heading and its
 * work's title, never from the passage text:
 *   2  the heading or the title names the book and the chapter ("Homily XV on Rom. viii. 28.",
 *      "Lecture LIX — Romans 8:28", "PSALM XXIII.", "A Treatise of Delighting in God from Psal. 37. 4"),
 *      or the work is on the book and a heading is that chapter ("CHAPTER VIII.");
 *   1  the work is on the book: its title names it ("Commentary on Romans", "Lange's Commentary - Romans",
 *      "Homilies on the Gospel of John") with a word that marks commentary or sermons, or the work is filed as
 *      a commentary; or a heading is the book's name alone ("ROMANS. › ...", Matthew Henry's "Romans › ...");
 *   0  anything else (a treatise or a confession quoting the verse, a margin note).
 * Book names are the build's aliases of the book (not the short extra forms such as "is" or "so"); a name
 * after an ordinal ("1 John", "I. John") is not the unnumbered book ("John").
 */

declare(strict_types=1);

namespace ColLibrary;

defined('ABSPATH') || exit;

final class Subject
{
    private const ORDINALS = ['1' => '1', 'i' => '1', 'first' => '1', '2' => '2', 'ii' => '2', 'second' => '2',
                              '3' => '3', 'iii' => '3', 'third' => '3'];
    /** Title words that mark a commentary, exposition or sermons on a book. */
    private const COMMENTARY_WORDS = '/\b(commentar\w*|expositions?|expository|explications?|explained|opened|'
        . 'homil\w*|lectures?|sermons?|discourses?|notes|annotations?|paraphrase|treasury|epistles?|gospel|psalter|'
        . 'meditations?|preached|exegetical|critical|studies)\b/i';
    /** Words before a book's name in a title ("Commentary on Romans", "Gospel of St. John", "- Romans"). */
    private const BEFORE = ['on', 'upon', 'of', 'to', 'unto', 'st', 'saint', 's', 'the', 'according', 'in'];
    /** Capitalised words that may follow a book's name in a title ("Romans Volume 2"); any other one
     *  makes it a person's name ("The Works of John Owen"). */
    private const AFTER_OK = ['chapter', 'chapters', 'chap', 'vol', 'volume', 'volumes', 'part', 'and', 'to', 'with',
                              'in', 'the', 'a', 'an', 'for', 'by', 'from', 'being', 'or', 'expositor', 'translated'];

    /** @var array<string,true> alias keys of the book ("rom", "romans"; "1 cor") */
    private array $keys = [];
    /** @var array<string,true> alias keys of the other books that start with a number ("1 john") */
    private array $numberedKeys = [];
    /** @var array<string,true> words (3 letters or more) of every book's names ("corinthians", "luke") */
    private array $bookWords = [];
    /** @var array<string,true> alias keys (4 letters or more) of the other books ("numbers", "genesis") */
    private array $otherKeys = [];
    private bool $isJohn;
    private int $longest = 1;
    /** @var array<string,int> memo: heading => level */
    private array $headingMemo = [];

    /** @param array<int,array{name:string, short:string, aliases:list<string>}> $books Store::bibleBooks() */
    public function __construct(array $books, int $book, private readonly int $chapter, private readonly int $endChapter)
    {
        foreach ($books as $id => $b) {
            foreach (array_merge([$b['name'], $b['short'] ?? ''], $b['aliases']) as $alias) {
                $words = self::words((string) $alias);
                if ($words === []) {
                    continue;
                }
                $k = self::key($words);
                foreach ($words as $w) {
                    if (strlen($w) >= 3 && !ctype_digit($w)) {
                        $this->bookWords[$w] = true;
                    }
                }
                if ($id === $book) {
                    $this->keys[$k] = true;
                    $this->longest = max($this->longest, count($words));
                } else {
                    if (count($words) === 1 && strlen($k) >= 4) {
                        $this->otherKeys[$k] = true;
                    }
                    if (preg_match('/^[123] /', $k) === 1) {
                        $this->numberedKeys[$k] = true;
                    }
                }
            }
        }
        $name = self::key(self::words((string) ($books[$book]['name'] ?? '')));
        $this->isJohn = $name === 'john';
    }

    /**
     * Level of each work (by its title and kind): 2, 1 or 0, as above.
     * @param array<int,array{title:string, type:string}> $works by id
     * @param array<int,true> $skip work ids never counted on the book (church records: "Acts of Synod")
     * @return array<int,int>
     */
    public function works(array $works, array $skip = []): array
    {
        $out = [];
        foreach ($works as $id => $w) {
            $title = (string) $w['title'];
            $out[$id] = 0;
            if (isset($skip[$id])) {
                continue;
            }
            $named = $this->names($title, true);   // -1: the title is on another chapter ("Psalm CXXX")
            if ($named === 2) {
                $out[$id] = 2;
            } elseif ($named === 1 && (($w['type'] ?? '') === 'commentary' || preg_match(self::COMMENTARY_WORDS, $title) === 1)) {
                $out[$id] = 1;
            }
        }
        return $out;
    }

    /** True when the headings of a work's passages are worth reading: a commentary, or sermons, lectures and
     *  the like by its title (reading every citing passage's heading is too slow for a verse cited 2,000 times). */
    public static function readHeadings(array $work): bool
    {
        return ($work['type'] ?? '') === 'commentary' || preg_match(self::COMMENTARY_WORDS, (string) $work['title']) === 1;
    }

    /** Level of a passage from its section heading, given its work's level. */
    public function passage(string $section, int $workLevel): int
    {
        $memo = ($this->headingMemo[$section] ??= $this->heading($section));
        // $memo: 2 the heading names the chapter, 1 a heading is the book alone, 3 a heading is "Chapter N" only,
        // -1 a heading names another chapter
        if ($memo === 2 || $workLevel === 2) {
            return 2;
        }
        if ($memo === -1) {
            return 0;
        }
        if ($memo === 3) {
            return $workLevel >= 1 ? 2 : 0;
        }
        return max($memo, $workLevel);
    }

    private function heading(string $section): int
    {
        if (trim($section) === '') {
            return 0;
        }
        $level = 0;
        $chapterOnly = false;
        $otherBook = false;
        $otherChapter = false;
        $chapterOther = false;
        foreach (preg_split('/\s+›\s+/u', $section) ?: [] as $part) {
            $named = $this->names($part, false);
            if ($named === 2) {
                return 2;
            }
            $otherChapter = $otherChapter || $named === -1;
            $words = self::words($part);
            if ($words !== [] && $this->isBookAlone($words)) {
                $level = 1;
            }
            $chapterWords = isset($this->keys['psalm']) ? ['chapter', 'chap', 'cap', 'psalm', 'psal', 'ps'] : ['chapter', 'chap', 'cap'];
            if (count($words) >= 2 && in_array($words[0], $chapterWords, true)) {
                $c = ctype_digit($words[1]) ? (int) $words[1] : Bible::roman($words[1]);
                if ($c !== null && $c >= $this->chapter && $c <= $this->endChapter) {
                    $chapterOnly = true;
                } elseif ($c !== null) {
                    $chapterOther = true;
                }
            }
            foreach ($words as $w) {
                if (isset($this->otherKeys[$w]) || isset($this->otherKeys[str_replace('v', 'u', $w)])) {
                    $otherBook = true;   // "THE FOURTH BOOK OF MOSES, CALLED NUMBERS › CHAP. 23"
                }
            }
        }
        if ($chapterOnly && !$otherBook) {
            return 3;
        }
        if ($otherChapter || $chapterOther || $chapterOnly) {
            // a heading of another chapter of the book, or a chapter of another book ("GENESIS › CHAP. XXIX"):
            // the verse is only cited there
            return -1;
        }
        return $level;
    }

    /**
     * 2 when $text names the book followed by one of the chapters, 1 when it names the book (in a title: after
     * one of BEFORE, or after "-" or "(", and not followed by a capitalised name), 0 when not.
     */
    private function names(string $text, bool $title): int
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        $words = array_map(static fn (array $t): string => mb_strtolower(Text::fold($t[0]), 'UTF-8'), $tokens);
        $best = 0;
        $other = false;
        $n = count($words);
        for ($i = 0; $i < $n; $i++) {
            for ($len = min($this->longest, $n - $i); $len >= 1; $len--) {
                if (!isset($this->keys[self::key(array_slice($words, $i, $len))])
                    || ($len > 1 && self::split(self::gap($text, $tokens, $i + 1)))) {
                    continue;   // ("Volume 1, John" is not "1 John")
                }
                if ($i > 0 && !self::split(self::gap($text, $tokens, $i))
                    && isset($this->numberedKeys[self::key(array_slice($words, $i - 1, $len + 1))])) {
                    break;   // "1 John" is not "John" (but "Volume 2 (Psalms" is Psalms)
                }
                if ($this->isJohn && array_intersect(array_map(static fn (string $w): string => str_replace('v', 'u', $w),
                        array_slice($words, max(0, $i - 4), min(4, $i))),
                        ['reuelation', 'apocalypse', 'epistle', 'epistles', 'reuelations']) !== []) {
                    break;   // "Revelation of John", "First Epistle of John", "Reuelation of Saint Iohn"
                }
                $at = $i + $len;
                if (in_array($words[$at] ?? '', ['chapter', 'chap', 'ch', 'cap'], true)) {
                    $at++;   // "EPHESIANS - Chapter 1"
                }
                $next = $words[$at] ?? null;
                $c = $next === null ? null : (ctype_digit($next) ? (int) $next : Bible::roman($next));
                if ($c !== null && $c > 0) {
                    // a chapter, or a range of chapters ("Psalms 27-57")
                    $to = $c;
                    $after = $at + 1;
                    if (($words[$after] ?? '') === 'to') {
                        $after++;   // "Ch. I to XIV"
                    } elseif (preg_match('/^\s*[-–]\s*$/u', self::gap($text, $tokens, $after)) !== 1) {
                        $after = -1;
                    }
                    $end = $after >= 0 && isset($words[$after])
                        ? (ctype_digit($words[$after]) ? (int) $words[$after] : Bible::roman($words[$after])) : null;
                    if ($end !== null && $end > $c) {
                        $to = $end;   // a range of chapters ("Psalms 27-57", "I-XXXIX")
                    }
                    if ($c <= $this->endChapter && $to >= $this->chapter) {
                        return 2;
                    }
                    if ($title) {
                        return -1;
                    }
                    $other = true;
                    break;
                }
                if ($title && $this->titleNames($text, $tokens, $words, $i, $len)) {
                    $best = 1;
                }
                break;
            }
        }
        return $best === 0 && $other ? -1 : $best;
    }

    /** @param list<array{0:string,1:int}> $tokens @param list<string> $words */
    private function titleNames(string $text, array $tokens, array $words, int $i, int $len): bool
    {
        $start = $tokens[$i][1];
        $before = $i > 0 ? $words[$i - 1] : null;
        $gap = $i > 0 ? self::gap($text, $tokens, $i) : '';
        // after a word such as "on" or "of", after " - " or "(" ("Lange's Commentary - Romans"), or after another book
        // and a dash ("(Acts-Romans)"; not "Gospel-revelation")
        if (!(($before !== null && in_array($before, self::BEFORE, true)) || preg_match('/\s[-–—]|\(/u', $gap) === 1
            || ($before !== null && isset($this->bookWords[$before]) && preg_match('/^[-–—]$/u', $gap) === 1))) {
            return false;
        }
        $after = $tokens[$i + $len] ?? null;
        if ($after !== null && preg_match('/^\p{Lu}\p{Ll}/u', $after[0]) === 1
            && !in_array(mb_strtolower($after[0], 'UTF-8'), self::AFTER_OK, true)) {
            $between = substr($text, $tokens[$i + $len - 1][1] + strlen($tokens[$i + $len - 1][0]),
                $after[1] - $tokens[$i + $len - 1][1] - strlen($tokens[$i + $len - 1][0]));
            if (preg_match('/^\.?\s*$/', $between) === 1) {
                return false;   // "John Owen", "the Rev. Robert Murray M'Cheyne"
            }
        }
        return true;
    }

    /** The text between token $k - 1 and token $k. @param list<array{0:string,1:int}> $tokens */
    private static function gap(string $text, array $tokens, int $k): string
    {
        if ($k <= 0 || !isset($tokens[$k])) {
            return '';
        }
        $from = $tokens[$k - 1][1] + strlen($tokens[$k - 1][0]);
        return substr($text, $from, $tokens[$k][1] - $from);
    }

    /** True when a gap separates names ("Volume 1, John": the "1" is not part of the book's name). */
    private static function split(string $gap): bool
    {
        return preg_match('/[,;:()\[\]]/', $gap) === 1;
    }

    /** A heading that is the book's name alone: "ROMANS.", "Romans", "The Epistle to the Romans", "F I R S T S A M U E L". */
    private function isBookAlone(array $words): bool
    {
        $s = implode(' ', $words);
        $s = (string) preg_replace('/^(the )?((first|second|third|general|1|2|3|i|ii|iii) )?(book|epistle|gospel|'
            . 'prophecy|lamentations of)?( general)?( of| to| according to)?( the| st| saint| paul| s)*( apostle)?( to| of)?( the)? /', '', $s . ' ');
        $s = trim($s);
        if ($s === '') {
            return false;
        }
        if (isset($this->keys[self::key(explode(' ', $s))])) {
            return true;
        }
        $joined = str_replace(' ', '', $s);   // "f i r s t s a m u e l"
        foreach (array_keys($this->keys) as $k) {
            if (strlen($k) >= 4 && str_replace(' ', '', self::spelled($k)) === $joined) {
                return true;
            }
        }
        return false;
    }

    private function isChapter(string $word): bool
    {
        $c = ctype_digit($word) ? (int) $word : Bible::roman($word);
        return $c !== null && $c >= $this->chapter && $c <= $this->endChapter;
    }

    /** @return list<string> lower-case words of a name, accents folded */
    private static function words(string $s): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(Text::fold($s), 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Alias key: an ordinal first word as a digit ("i cor", "first cor" -> "1 cor"). @param list<string> $words */
    private static function key(array $words): string
    {
        if (count($words) > 1 && isset(self::ORDINALS[$words[0]])) {
            $words[0] = self::ORDINALS[$words[0]];
        }
        return implode(' ', $words);
    }

    /** "1 samuel" -> "first samuel" (for headings that spell the number out). */
    private static function spelled(string $key): string
    {
        return (string) preg_replace_callback('/^([123]) /', static fn (array $m): string
            => ['1' => 'first ', '2' => 'second ', '3' => 'third '][$m[1]], $key);
    }
}
