<?php
/**
 * The Bible tables of a build (DESIGN.md section 10; PLUGIN-SPEC.md, addendum): reads a reference
 * as people write it ("John 3:16", "Rom. viii. 28", "1 Cor 13", "Rom 8:38-9:5", "Jude 3"), checks it
 * against the verses a translation really has, and reads the verses and the topics of Nave's and
 * Torrey's topical Bibles that list them.
 *
 * Book names: the aliases of the build's bible_books table (lower case: "rom", "1 cor", "i cor",
 * "first cor", "song of solomon"), compared after lower-casing, turning dots into spaces and
 * collapsing spaces; a leading I/II/III, First/Second/Third or 1st/2nd/3rd counts as 1/2/3, and
 * "1cor" as "1 cor". A few common modern abbreviations ("Jn", "Mt", "Is") and those of Nave's and
 * Torrey's topical Bibles ("Mr", "Zec", "Jdj") are added (EXTRA_ALIASES).
 * Numbers: a chapter (arabic, or roman as in "Rom. viii. 28"), then ":", ".", "," or a space and a
 * verse, then "-" and a verse, or a chapter, ":" or "." and a verse; en and em dashes count as "-".
 * A chapter alone is the whole chapter, "Gen 1-3" a range of chapters. In one-chapter books (Obadiah,
 * Philemon, 2 and 3 John, Jude) one number is a verse: "Jude 3" = "Jude 1:3". A list ("Rom 8:28-30,
 * 35") is refused: one reference or range per call.
 */

declare(strict_types=1);

namespace ColLibrary;

defined('ABSPATH') || exit;

/**
 * One Bible reference: $chapter:$verse to $endChapter:$endVerse of one book. A null $verse means
 * from the start of $chapter, a null $endVerse to the end of $endChapter; both null: whole chapters.
 */
final class BibleRef
{
    public function __construct(
        public readonly int $book,
        public readonly int $chapter,
        public readonly ?int $verse,
        public readonly int $endChapter,
        public readonly ?int $endVerse,
    ) {
    }

    /** No verse numbers: one or more whole chapters. */
    public function wholeChapters(): bool
    {
        return $this->verse === null && $this->endVerse === null;
    }

    /**
     * The verses covered in each chapter: [chapter, from, to]; from / to null for the start / end
     * of the chapter (both null: all of it).
     * @return list<array{0:int, 1:?int, 2:?int}>
     */
    public function segments(int $max = PHP_INT_MAX): array
    {
        $out = [];
        for ($c = $this->chapter; $c <= $this->endChapter && count($out) < $max; $c++) {
            $out[] = [$c, $c === $this->chapter ? $this->verse : null, $c === $this->endChapter ? $this->endVerse : null];
        }
        return $out;
    }
}

/** Verses of a translation, ready for Format::scripture. */
final class BibleText
{
    /**
     * @param list<array{chapter:int, verse:int, text:string}> $verses
     * @param list<string> $notes  @param list<string> $topics
     */
    public function __construct(
        public readonly string $label,          // "John 3:16-18"
        public readonly string $translation,    // "BSB"
        public readonly array $verses,
        public readonly bool $withChapter,      // the passage crosses chapters: lines start "9:1"
        public readonly array $notes,
        public readonly array $topics,
        public readonly ?string $citeAs = null,  // the reference find_scripture_citations knows it by, if not $label
    ) {
    }
}

final class Bible
{
    /** Translations the tools offer and their labels in results (a build lists its own in meta). */
    public const TRANSLATIONS = ['bsb' => 'BSB', 'kjv' => 'KJV', 'asv' => 'ASV', 'drb' => 'Douay-Rheims',
                                 'darby' => 'Darby', 'webster' => 'Webster', 'ylt' => 'YLT'];
    public const DEFAULT_TRANSLATION = 'bsb';
    /** Translations whose text find_scripture_citations shows (the older authors mostly quote the KJV). */
    public const CITATION_TRANSLATIONS = ['bsb', 'kjv'];
    public const MAX_VERSES = 200;
    public const TOPIC_VERSES = 5;         // topics are listed for passages of at most this many verses
    public const MAX_TOPICS = 10;
    public const MAX_TOPIC_CHAPTERS = 10;  // chapters whose topic links one read_scripture call reads
    private const MAX_GAPS = 1000;         // missing verses listed (whatever the stored numbering)
    public const PSALMS = 19;
    public const DRB_PSALMS_NOTE = 'Note: the Douay-Rheims numbers the Psalms as the Vulgate does; from Psalm 10 to '
        . '147 its numbers are usually one lower than in the BSB and KJV (its Psalm 22 is their Psalm 23).';
    public const ESTHER = 17;
    public const ADDITIONS_TO_ESTHER = 69;
    /** find_scripture_citations reads references as the KJV numbers them (see check()). */
    public const KJV_NUMBERING = 'Citations are indexed by the chapter and verse numbers of the KJV';

    private const ORDINALS = ['1' => '1', 'i' => '1', 'first' => '1', '2' => '2', 'ii' => '2', 'second' => '2',
                              '3' => '3', 'iii' => '3', 'third' => '3'];
    /**
     * Common modern abbreviations, those of Nave's and Torrey's topical Bibles ("Mr 14:36", "Zec 13:1",
     * "Es 1:10", "So 5:3", "Joe 1:19", "Jdj 4:3") and a few SBL forms missing from the build's
     * aliases; a build alias always wins.
     */
    private const EXTRA_ALIASES = [
        1 => ['ge'], 2 => ['ex'], 3 => ['le', 'lv'], 4 => ['nu', 'nm', 'nb'], 5 => ['dt', 'de'],
        6 => ['jos', 'jsh'], 7 => ['jdg', 'jg', 'jdgs', 'jdj'], 8 => ['ru', 'rth'], 9 => ['1 sa', '1 sm'],
        10 => ['2 sa', '2 sm'], 11 => ['1 ki', '1 kg'], 12 => ['2 ki', '2 kg'], 13 => ['1 ch'], 14 => ['2 ch'],
        16 => ['ne'], 17 => ['est', 'es'], 18 => ['jb'], 19 => ['pss', 'psm', 'pslm'], 20 => ['pr', 'prv'],
        21 => ['ec', 'qoh'], 22 => ['sos', 'sng', 'sg', 'so', 'song sol', 'canticle of canticles'], 23 => ['is'],
        24 => ['je', 'jr'], 25 => ['la'], 26 => ['ezk'], 27 => ['da', 'dn'], 28 => ['ho'], 29 => ['jl', 'jol', 'joe'],
        30 => ['am', 'amo'], 31 => ['ob', 'oba', 'obd'], 32 => ['jnh'], 33 => ['mi'], 34 => ['na', 'nam'],
        35 => ['hb'], 36 => ['zp'], 37 => ['hg'], 38 => ['zc', 'zec'], 39 => ['ml'], 40 => ['mt'],
        41 => ['mk', 'mrk', 'mr'], 42 => ['lk', 'lu'], 43 => ['jn', 'jhn'], 44 => ['ac', 'acts of the apostles'],
        45 => ['ro', 'rm'], 46 => ['1 co'], 47 => ['2 co'], 48 => ['ga'], 49 => ['ep'], 50 => ['php'], 52 => ['1 th'],
        53 => ['2 th'], 54 => ['1 ti', '1 tm'], 55 => ['2 ti', '2 tm'], 56 => ['ti'], 57 => ['phm'], 58 => ['he'],
        59 => ['jm'], 60 => ['1 pe', '1 pt'], 61 => ['2 pe', '2 pt'], 62 => ['1 jn', '1 jo', '1 jhn'],
        63 => ['2 jn', '2 jo', '2 jhn'], 64 => ['3 jn', '3 jo', '3 jhn'], 65 => ['jd'],
        66 => ['re', 'rv', 'the revelation', 'revelation of john', 'revelation of st john', 'revelation of saint john'],
        67 => ['tb'], 68 => ['jth'], 69 => ['add esth', 'rest of esther'], 70 => ['ws', 'wisdom of solomon'],
        71 => ['ben sira'], 73 => ['pr azar', 'song of the three', 'song of three'], 76 => ['pr man', 'prayer of manasses'],
        77 => ['1 ma'], 78 => ['2 ma'],
    ];
    // The end of a range across chapters is "9:5" or "9.5", not "9, 5": "Rom 8:28-30, 35" is a list.
    private const NUMBERS_RE = '/^(?:(?:chapters?|chap|ch) ?)?(?<c1>\d+|[ivxlc]+)'
        . '(?:(?: ?[:.,] ?| )(?:(?:verses?|vers|ver|vv|vs|v) ?)?(?<v1>\d+)[a-d]?)?'
        . '(?: ?- ?(?:(?<c2>\d+) ?[:.] ?(?<v2>\d+)[a-d]?|(?<x2>\d+)[a-d]?))?$/D';
    /** Words after a book name that are part of the numbers: "John chapter 3", "Rom. viii. 28", "Jude ver. 3". */
    private const NUMBER_WORDS = '/^(?:chapters?|chap|ch|verses?|vers|ver|vv|vs|v|[ivxlc]+)$/D';
    private const VERSION_WORDS = 'bsb|kjv|akjv|nkjv|asv|drb|dr|darby|webster|ylt|esv|niv|nasb|nas|rsv|nrsv|net|csb|'
        . 'hcsb|nlt|msg|av|rv|geneva|vulgate|douay|lxx|septuagint|version|translation|bible';

    /** @var array<string,int>|null alias => book id */
    private ?array $aliases = null;
    /** @var array<string,int> "translation book chapter" => last verse */
    private array $lastVerse = [];
    /** @var array<string,int> "translation book" => last chapter */
    private array $lastChapter = [];

    public function __construct(private readonly Store $store)
    {
    }

    // ------------------------------------------------------------------------------ references

    /**
     * Reads a reference and checks it against the verses of $translations (each chapter and verse
     * must be in at least one of them). With $citations (find_scripture_citations, which has no
     * translation argument), errors say where else a passage is without naming that argument.
     * @param list<string> $translations
     * @throws ArgumentException saying what is wrong and what is valid
     */
    public function reference(string $input, array $translations, bool $citations = false): BibleRef
    {
        return $this->check($this->parse($input), $translations, $citations);
    }

    /**
     * The book and the numbers of a reference, not yet checked against a translation.
     * @return array{book:int, c1:?int, v1:?int, c2:?int, v2:?int, x2:?int}
     * @throws ArgumentException
     */
    public function parse(string $input): array
    {
        $s = trim(self::normalize($input), ' ;,');      // "John 3:16;" is one reference
        $shown = Text::echo($input, 100);
        if ($s === '') {
            throw new ArgumentException('reference must be a Bible reference such as "John 3:16" or "Ps 23".');
        }
        if (str_contains($s, ';') || preg_match('/\d ?& ?\d/', $s)) {
            throw new ArgumentException("Give one reference per call (\"$shown\" has several), for example "
                . '"Rom 8:28" and then "John 3:16" in a second call.');
        }
        $tokens = explode(' ', $s);
        $n = count($tokens);
        $i = $n > 1 && isset(self::ORDINALS[$tokens[0]]) && preg_match('/^\p{L}/u', $tokens[1]) ? 1 : 0;
        while ($i < $n && !preg_match('/\d/', $tokens[$i])) {
            $i++;
        }
        $words = array_slice($tokens, 0, $i);   // the book name, perhaps followed by a roman chapter
        $map = $this->aliasMap();
        $book = null;
        $used = 0;
        for ($k = min(count($words), 6); $k >= 1; $k--) {
            $key = self::bookKey(array_slice($words, 0, $k));
            if (isset($map[$key])) {
                $book = $map[$key];
                $used = $k;
                break;
            }
        }
        if ($book === null) {
            throw new ArgumentException($words === []
                ? "Start the reference with the name of a book, for example \"John 3:16\" (got \"$shown\")."
                : $this->unknownBook(implode(' ', $words), $input));
        }
        $rest = implode(' ', array_slice($tokens, $used));
        $out = ['book' => $book, 'c1' => null, 'v1' => null, 'c2' => null, 'v2' => null, 'x2' => null];
        if ($rest === '') {
            return $out;
        }
        if (!preg_match(self::NUMBERS_RE, $rest, $m)) {
            $extra = array_slice($words, $used);
            if ($extra !== [] && $i < $n && !preg_match(self::NUMBER_WORDS, $extra[0])
                && !preg_match('/^(?:' . self::VERSION_WORDS . ')$/D', $extra[0])) {
                // "Song Sol 2:4", "Pr Man 1:1": more of a book name than the alias that matched
                throw new ArgumentException($this->unknownBook(implode(' ', $words), $input));
            }
            throw new ArgumentException(self::unreadable($rest, $shown));
        }
        if (ctype_digit($m['c1'])) {
            $out['c1'] = self::number($m['c1']);
        } else {
            $c = self::roman($m['c1']);
            if ($c === null) {
                throw new ArgumentException("\"{$m['c1']}\" in \"$shown\" is not a chapter number. Write the chapter "
                    . 'in figures ("Rom 8:28") or as a roman numeral ("Rom. viii. 28").');
            }
            $out['c1'] = $c;
        }
        foreach (['v1', 'c2', 'v2', 'x2'] as $k) {
            if (($m[$k] ?? '') !== '') {
                $out[$k] = self::number($m[$k]);
            }
        }
        return $out;
    }

    /**
     * Applies the rules for one-chapter books and checks every number against the translations
     * ($citations: as in reference()).
     * @param array{book:int, c1:?int, v1:?int, c2:?int, v2:?int, x2:?int} $p
     * @param list<string> $translations
     */
    public function check(array $p, array $translations, bool $citations = false): BibleRef
    {
        $book = $p['book'];
        $name = $this->name($book);
        $have = array_values(array_filter($translations, fn (string $t): bool => $this->lastChapter($t, $book) > 0));
        // " in the BSB" after counts when one translation is asked for; "is not in the BSB or KJV" for several
        $in = count($translations) === 1 ? ' in the ' . self::TRANSLATIONS[$translations[0]] : '';
        $notIn = ' not in the ' . implode(' or ', array_map(static fn (string $t): string => self::TRANSLATIONS[$t],
            $translations));
        // where else it is: 'the KJV (translation "kjv")'; find_scripture_citations has no translation argument
        $where = static fn (array $codes): string => $citations ? 'only in ' . self::translationList($codes, false)
            . '. ' . self::KJV_NUMBERING : 'in ' . self::translationList($codes);
        if ($have === []) {
            $elsewhere = $this->translationsWith($translations, $book);
            if ($elsewhere === [] && $book === self::ADDITIONS_TO_ESTHER) {
                throw new ArgumentException($this->additionsToEsther($p, $translations, $citations));
            }
            if ($elsewhere === []) {
                throw new ArgumentException("$name is not in the library's Bibles.");
            }
            if ($in !== '') {
                throw new ArgumentException("$name is$notIn; it is " . $where($elsewhere) . '.');
            }
            $have = $elsewhere;   // e.g. a book only the Douay-Rheims has
        }
        $last = max(array_map(fn (string $t): int => $this->lastChapter($t, $book), $have));
        [$c1, $v1, $c2, $v2, $x2] = [$p['c1'], $p['v1'], $p['c2'], $p['v2'], $p['x2']];
        if ($this->oneChapter($book)) {
            if ($c1 === null) {
                return new BibleRef($book, 1, null, 1, null);
            }
            if ($v1 === null && $c2 === null) {          // "Jude 3", "Jude 3-5": verses
                [$c1, $v1, $v2, $x2] = [1, $c1, $x2 ?? $c1, null];
                $c2 = 1;
            } elseif ($c1 !== 1 || ($c2 !== null && $c2 !== 1)) {
                throw new ArgumentException("$name has only one chapter: write \"$name " . ($v1 ?? $c1) . '" or "'
                    . "$name 1:" . ($v1 ?? $c1) . '" for a verse, or "' . $name . '" for the whole book.');
            }
        } elseif ($c1 === null) {
            throw new ArgumentException("Give a chapter of $name too, for example \"$name 1\" ($name has $last "
                . 'chapters' . "$in).");
        }
        if ($v1 === null) {
            if ($c2 !== null) {                           // "Rom 8-9:5" = "Rom 8:1-9:5"
                [$start, $end] = [[$c1, 1], [$c2, $v2]];
            } else {                                      // "Ps 23", "Gen 1-3"
                [$start, $end] = [[$c1, null], [$x2 ?? $c1, null]];
            }
        } elseif ($c2 !== null) {                         // "Rom 8:38-9:5", "Rom 8:28-8:39"
            [$start, $end] = [[$c1, $v1], [$c2, $v2]];
        } else {                                          // "John 3:16", "Rom 8:28-39"
            [$start, $end] = [[$c1, $v1], [$c1, $x2 ?? $v1]];
        }
        foreach ([$start, $end] as [$c, $v]) {
            if ($c === 0 || $v === 0) {
                throw new ArgumentException('Chapter and verse numbers start at 1.');
            }
        }
        $ref = new BibleRef($book, $start[0], $start[1], $end[0], $end[1]);
        $sameChapter = $end[0] === $start[0] && $start[1] !== null && $end[1] !== null;
        if ($end[0] < $start[0] || ($sameChapter && $end[1] < $start[1])) {
            $fixed = new BibleRef($book, $end[0], $end[1] ?? ($start[1] === null ? null : 1), $start[0], $start[1]);
            $msg = 'The range runs backwards (it ends before it starts). Write it the other way round, e.g. "'
                . $this->label($fixed) . '"';
            if ($sameChapter && $x2 !== null && $x2 > $start[0] && $x2 <= $last) {   // "Rom 8:28-9": into chapter 9?
                $msg .= ', or give the chapter of the last verse for a range across chapters, e.g. "'
                    . $this->label(new BibleRef($book, $start[0], $start[1], $x2, 1)) . '"';
            }
            throw new ArgumentException($msg . '.');
        }
        foreach ([$start[0], $end[0]] as $c) {
            if ($c > $last) {
                $elsewhere = $c < 1000 ? $this->translationsWith($have, $book, $c) : [];
                throw new ArgumentException("$name has $last chapter" . ($last === 1 ? '' : 's') . "$in"
                    . ($elsewhere !== [] ? '; ' . $this->label(new BibleRef($book, $c, null, $c, null)) . ' is '
                        . $where($elsewhere) : '') . '.');
            }
        }
        foreach (array_unique([$start[0], $end[0]]) as $c) {
            if ($this->lastVerseOf($have, $book, $c) === 0) {
                $elsewhere = $this->translationsWith($have, $book, $c);
                throw new ArgumentException($this->label(new BibleRef($book, $c, null, $c, null)) . " is$notIn"
                    . ($elsewhere !== [] ? '; it is ' . $where($elsewhere) : '') . '.');
            }
        }
        foreach ([$start, $end] as [$c, $v]) {
            $max = $this->lastVerseOf($have, $book, $c);
            if ($v !== null && $v > $max) {
                $elsewhere = $this->translationsWith($have, $book, $c, $v);
                $chapter = $this->label(new BibleRef($book, $c, null, $c, null));
                throw new ArgumentException("$chapter has $max verse" . ($max === 1 ? '' : 's') . "$in"
                    . ($elsewhere !== [] ? '; ' . $this->label(new BibleRef($book, $c, $v, $c, $v)) . ' is '
                        . $where($elsewhere) : '') . '.');
            }
        }
        if ($start[1] !== null && $start === $end && !$this->verseIn($have, $book, $start[0], $start[1])) {
            $elsewhere = $this->translationsWith($have, $book, $start[0], $start[1]);
            throw new ArgumentException($this->label($ref) . " is$notIn" . ($elsewhere !== []
                ? '; it is ' . $where($elsewhere) : '') . '.');
        }
        return $ref;
    }

    /**
     * The Additions to Esther are not a book of their own in the library's Bibles: the KJV and the
     * Douay-Rheims print them as Esther 10:4-16:24.
     * @param array{book:int, c1:?int, v1:?int} $p  @param list<string> $translations
     */
    private function additionsToEsther(array $p, array $translations, bool $citations): string
    {
        $have = $this->translationsWith([], self::ESTHER, 11);
        if ($have === []) {
            return "Additions to Esther is not in the library's Bibles.";
        }
        $c = $p['c1'] !== null && $p['c1'] >= 10 && $p['c1'] <= 16 ? $p['c1'] : 11;
        $v = $c === 10 ? max(4, $p['v1'] ?? 4) : ($c === $p['c1'] ? $p['v1'] : null);   // the additions start at 10:4
        $example = "Esther $c" . ($v !== null ? ":$v" : '');
        $with = $citations || array_intersect($have, $translations) !== [] ? ''
            : ' with translation ' . self::orList(array_map(static fn (string $t): string => "\"$t\"", $have));
        return 'The Additions to Esther are not a separate book in the library\'s Bibles: '
            . self::translationList($have, false) . ' have them as chapters 10:4-16:24 of Esther. Ask for '
            . "\"$example\"$with.";
    }

    // ---------------------------------------------------------------------------------- text

    /**
     * The verses of a reference in one translation (at most MAX_VERSES), with notes on verses the
     * translation leaves out, the cut, the Douay-Rheims numbering of the Psalms, and the topics.
     */
    public function read(BibleRef $ref, string $translation): BibleText
    {
        $rows = $this->verses($ref, $translation, self::MAX_VERSES + 1);
        $notes = [];
        $more = null;
        if (count($rows) > self::MAX_VERSES) {
            $more = $rows[self::MAX_VERSES];
            $rows = array_slice($rows, 0, self::MAX_VERSES);
        }
        $missing = self::gaps($rows, $ref, $more === null);
        if ($missing !== []) {
            $labels = array_map(fn (array $cv): string => $this->label(new BibleRef($ref->book, $cv[0], $cv[1], $cv[0], $cv[1])),
                array_slice($missing, 0, 10));
            $notes[] = 'Not in the ' . self::TRANSLATIONS[$translation] . ': ' . implode(', ', $labels)
                . (count($missing) > 10 ? ' and ' . (count($missing) - 10) . ' more' : '') . '.';
        }
        if ($more !== null) {
            $lastRow = $rows[count($rows) - 1];
            $shown = $this->label(new BibleRef($ref->book, $lastRow['chapter'], $lastRow['verse'], $lastRow['chapter'],
                $lastRow['verse']));
            $endVerse = $ref->endVerse ?? $this->lastVerse($translation, $ref->book, $ref->endChapter);
            $rest = $this->label(new BibleRef($ref->book, $more['chapter'], $more['verse'], $ref->endChapter, $endVerse));
            $notes[] = 'Only the first ' . self::MAX_VERSES . " verses are shown (to $shown). The rest: $rest.";
        }
        if ($translation === 'drb' && $ref->book === self::PSALMS) {
            $notes[] = self::DRB_PSALMS_NOTE;
        }
        $drbPsalms = $translation === 'drb' && $ref->book === self::PSALMS;
        $topics = [];
        if (count($rows) <= self::TOPIC_VERSES && !$drbPsalms) {
            // only the chapters of the verses read: a span such as "Gen 50:26-999999:1" over a
            // translation with a stray high chapter must not mean a query per chapter in between
            $topics = $this->topics($ref, self::MAX_TOPICS, array_values(array_unique(array_column($rows, 'chapter'))));
        }
        $citeAs = null;
        if ($drbPsalms) {   // citations are indexed by the KJV's numbers: "Psalm 22" here is their Psalm 23
            $citeAs = $this->label(new BibleRef(self::PSALMS, self::kjvPsalms($ref->chapter)[0], null,
                self::kjvPsalms($ref->endChapter)[1], null));
        }
        return new BibleText($this->label($ref), self::TRANSLATIONS[$translation], $rows,
            $ref->chapter !== $ref->endChapter, $notes, $topics, $citeAs);
    }

    /**
     * The KJV psalms a Douay-Rheims (Vulgate) psalm covers, [first, last]: its 9 is their 9-10, its
     * 10-112 their 11-113, its 113 their 114-115, its 114 and 115 their 116, its 116-145 their
     * 117-146, its 146 and 147 their 147.
     * @return array{0:int, 1:int}
     */
    public static function kjvPsalms(int $drb): array
    {
        return match (true) {
            $drb <= 8 || $drb >= 148 => [$drb, $drb],
            $drb === 9 => [9, 10],
            $drb <= 112 => [$drb + 1, $drb + 1],
            $drb === 113 => [114, 115],
            $drb <= 115 => [116, 116],
            $drb <= 145 => [$drb + 1, $drb + 1],
            default => [147, 147],
        };
    }

    /** @return list<array{chapter:int, verse:int, text:string}> */
    public function verses(BibleRef $ref, string $translation, int $limit): array
    {
        return $this->store->bibleVerses($translation, $ref->book, $ref->chapter, $ref->verse ?? 1, $ref->endChapter,
            $ref->endVerse ?? 999, $limit);
    }

    /**
     * Headwords of the Nave's and Torrey's topics that list these verses, most specific first: a link
     * to just these verses before a link to a longer range or to the whole chapter, then smaller
     * topics (fewer links in all) before broad ones such as "God", then alphabetical. Each topic is
     * named once (the two books share many); Nave's capitals are shown in title case.
     * @param list<int>|null $chapters only these chapters of the reference (null: at most
     *                              MAX_TOPIC_CHAPTERS from its start)
     * @return list<string>
     */
    public function topics(BibleRef $ref, int $max = self::MAX_TOPICS, ?array $chapters = null): array
    {
        $best = [];
        foreach ($ref->segments(self::MAX_TOPIC_CHAPTERS) as [$c, $from, $to]) {
            if ($chapters !== null && !in_array($c, $chapters, true)) {
                continue;
            }
            foreach ($this->store->topicLinks($ref->book, $c, $from ?? 1, $to ?? 999) as $t) {
                $span = $t['verse_start'] === null ? 1000 : ($t['verse_end'] ?? $t['verse_start']) - $t['verse_start'];
                $key = $t['norm'] !== '' ? $t['norm'] : mb_strtolower($t['headword'], 'UTF-8');
                $old = $best[$key] ?? null;
                $caps = self::shouting($t['headword']);
                if ($old === null || $span < $old['span'] || ($span === $old['span'] && $old['caps'] && !$caps)) {
                    $best[$key] = ['span' => $span, 'id' => $t['id'], 'name' => self::topicName($t['headword']),
                                   'caps' => $caps];
                }
            }
        }
        $sizes = $best === [] ? [] : $this->store->topicSizes(array_column($best, 'id'));
        uasort($best, static fn (array $a, array $b): int
            => [$a['span'], $sizes[$a['id']] ?? 0, mb_strtolower($a['name'], 'UTF-8')]
            <=> [$b['span'], $sizes[$b['id']] ?? 0, mb_strtolower($b['name'], 'UTF-8')]);
        return array_slice(array_column(array_values($best), 'name'), 0, $max);
    }

    // -------------------------------------------------------------------------------- names

    /** Codes of the offered translations this build has (meta bible_translations), in TRANSLATIONS order. */
    public function translations(): array
    {
        $listed = array_map('trim', explode(',', (string) $this->store->meta('bible_translations')));
        return array_values(array_filter(array_keys(self::TRANSLATIONS),
            static fn (string $t): bool => in_array($t, $listed, true)));
    }

    /** The translations find_scripture_citations checks references against: BSB and KJV, as far as the build has them. */
    public function citationTranslations(): array
    {
        $have = array_values(array_intersect(self::CITATION_TRANSLATIONS, $this->translations()));
        return $have !== [] ? $have : $this->translations();
    }

    /** "Romans"; "Psalm" instead of "Psalms" for one psalm. */
    public function name(int $book, bool $onePsalm = false): string
    {
        if ($onePsalm && $book === self::PSALMS) {
            return 'Psalm';
        }
        return $this->store->bibleBooks()[$book]['name'] ?? "Book $book";
    }

    /**
     * Abbreviated name: "Rom", "1 Cor", "Ps". Second and third books without an abbreviation of
     * their own take the first book's ("1 Sam" -> "2 Sam"); otherwise the full name ("2 John").
     */
    public function shortName(int $book): string
    {
        $books = $this->store->bibleBooks();
        $b = $books[$book] ?? null;
        if ($b === null) {
            return "Book $book";
        }
        if ($b['short'] !== '') {
            return $b['short'];
        }
        if (preg_match('/^([23]) (.+)$/', $b['name'], $m)) {
            foreach ($books as $first) {
                if ($first['name'] === "1 {$m[2]}" && preg_match('/^1 (.+)$/', $first['short'], $s)) {
                    return "{$m[1]} {$s[1]}";
                }
            }
        }
        return $b['name'];
    }

    /** One-chapter books (Obadiah, Philemon, 2 and 3 John, Jude, ...): verses are given without a chapter. */
    public function oneChapter(int $book): bool
    {
        return ($this->store->bibleBooks()[$book]['chapters'] ?? 2) <= 1;
    }

    /**
     * "Romans 8:28-39", "Psalm 23", "Romans 8:38-9:5", "Genesis 1-3", "Jude 3", "Jude";
     * $short: "Rom 8:28-39", "Ps 23".
     */
    public function label(BibleRef $r, bool $short = false): string
    {
        $name = $short ? $this->shortName($r->book) : $this->name($r->book, $r->chapter === $r->endChapter);
        if ($r->wholeChapters()) {
            if ($this->oneChapter($r->book)) {
                return $name;
            }
            return "$name {$r->chapter}" . ($r->endChapter !== $r->chapter ? "-{$r->endChapter}" : '');
        }
        $v1 = $r->verse ?? 1;
        $v2 = $r->endVerse;
        if ($this->oneChapter($r->book)) {
            return "$name $v1" . ($v2 !== null && $v2 !== $v1 ? "-$v2" : '');
        }
        if ($r->chapter === $r->endChapter) {
            return "$name {$r->chapter}:$v1" . ($v2 !== null && $v2 !== $v1 ? "-$v2" : '');
        }
        return "$name {$r->chapter}:$v1-{$r->endChapter}" . ($v2 !== null ? ":$v2" : '');
    }

    // ------------------------------------------------------------------------------ helpers

    /**
     * Lower case, compatibility forms folded ("Ⅷ" -> "viii", full-width digits), dashes as "-",
     * control characters as spaces, "1st"/"2nd"/"3rd" as digits, "28 to 30" as "28-30", a space
     * between letters and digits ("1cor13" -> "1 cor 13"), dots as spaces except between digits
     * ("8.28"), no brackets or quotes, single spaces.
     */
    public static function normalize(string $input): string
    {
        $s = mb_strtolower(Text::fold($input), 'UTF-8');
        $s = (string) preg_replace('/[\x{2010}-\x{2015}\x{2212}\x{FE58}\x{FE63}\x{FF0D}]/u', '-', $s);
        $s = (string) preg_replace('/[\x00-\x1F\x7F\x{80}-\x{9F}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2069}'
            . '\x{FEFF}]/u', ' ', $s);
        $s = (string) preg_replace('/\b([123])(?:st|nd|rd)\b/u', '$1', $s);
        $s = (string) preg_replace('/(\d)\s*\b(?:to|through|thru|till|until)\b\s*(?=\d)/u', '$1-', $s);
        $s = (string) preg_replace('/(?<=\p{L})(?=\d)|(?<=\d)(?=\p{L}{2})/u', ' ', $s);
        $s = (string) preg_replace('/(?<!\d)\.|\.(?!\d)/u', ' ', $s);
        $s = (string) preg_replace('/["\'()\[\]{}«»‹›„“”‘’]/u', ' ', $s);
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** The alias key of book-name words: ordinal words and roman ordinals as digits. @param list<string> $words */
    private static function bookKey(array $words): string
    {
        if (count($words) > 1 && isset(self::ORDINALS[$words[0]])) {
            $words[0] = self::ORDINALS[$words[0]];
        }
        return implode(' ', $words);
    }

    /** @return array<string,int> alias => book id: the build's aliases and names, then EXTRA_ALIASES */
    private function aliasMap(): array
    {
        if ($this->aliases !== null) {
            return $this->aliases;
        }
        $map = [];
        $books = $this->store->bibleBooks();
        foreach ($books as $id => $b) {
            foreach (array_merge([$b['name'], $b['short']], $b['aliases']) as $alias) {
                $words = explode(' ', self::normalize($alias));
                $key = self::bookKey($words);
                if ($key !== '' && !isset($map[$key])) {
                    $map[$key] = $id;
                }
            }
        }
        foreach (self::EXTRA_ALIASES as $id => $list) {
            if (isset($books[$id])) {
                foreach ($list as $alias) {
                    $map[$alias] ??= $id;
                }
            }
        }
        return $this->aliases = $map;
    }

    /** The message for a book name that matches no alias, with up to 5 books whose names are close. */
    private function unknownBook(string $words, string $input): string
    {
        $q = self::bookKey(explode(' ', $words));
        // "Cor. 13. 4" without its number: 1 or 2 Corinthians (only names that begin so)
        $numbered = preg_match('/^[123] /', $q) || strlen($q) < 3 ? [] : ["1 $q", "2 $q", "3 $q"];
        $scores = [];
        foreach ($this->aliasMap() as $alias => $id) {
            $alias = (string) $alias;
            $d = null;
            if (strlen($q) >= 2 && (str_starts_with($alias, $q) || (strlen($alias) >= 3 && str_starts_with($q, $alias)))) {
                $d = 0;
            } elseif (array_filter($numbered, static fn (string $nq): bool => str_starts_with($alias, $nq)) !== []) {
                $d = 0;
            } elseif (strlen($q) >= 3 && strlen($q) < 255) {
                $lev = levenshtein($q, $alias);
                if ($lev <= (strlen($q) >= 6 ? 2 : 1)) {
                    $d = $lev;
                }
            }
            foreach (explode(' ', $q) as $word) {      // "Saint John": a word that is a book name
                if (strlen($word) >= 3 && $word === $alias) {
                    $d = min($d ?? 1, 1);
                }
            }
            if ($d !== null && $d < ($scores[$id] ?? PHP_INT_MAX)) {
                $scores[$id] = $d;
            }
        }
        // the name as typed: the input without its numbers ("Phillipians 4:13" -> "Phillipians")
        $typed = trim((string) preg_replace('/(?:^|\s)\d[\d\s:.,\-–—abcdv]*$/u', '', Text::oneLine($input, 100)));
        $shown = Text::echo($typed !== '' ? $typed : $words, 60);
        if ($scores === []) {
            return "There is no book \"$shown\" in the Bible. Use a book name or a common abbreviation, for example "
                . '"Genesis", "1 Sam", "Ps", "Song of Solomon", "Rom" or "Rev".';
        }
        if (in_array(0, $scores, true)) {
            $scores = array_filter($scores, static fn (int $d): bool => $d === 0);   // names that begin so: only those
        }
        uksort($scores, static fn (int $a, int $b): int => [$scores[$a], $a] <=> [$scores[$b], $b]);
        $names = array_map(fn (int $id): string => $this->name($id), array_slice(array_keys($scores), 0, 5));
        return "There is no book \"$shown\" in the Bible. Did you mean " . self::orList($names) . '?';
    }

    /** Why the numbers after a book name could not be read. */
    private static function unreadable(string $rest, string $shown): string
    {
        if (preg_match('/(?:^|[ \d])(' . self::VERSION_WORDS . ')$/', $rest)) {
            return "Give only the reference, without the Bible version (\"$shown\"); the version is chosen with the "
                . 'translation argument where a tool has one.';
        }
        if (preg_match('/- ?(?:[123] )?\p{L}{2,}/u', $rest)) {
            return "A range must stay within one book (\"$shown\"). Ask for each book separately, for example "
                . '"Gen 50:26" and then "Exod 1:1-5".';
        }
        if (preg_match('/,|\band\b|(?<!\p{L})(?!(?:chapters?|chap|verses?|vers|ver) ?\d)\p{L}{2,} ?\d/u', $rest)) {
            return "Give one reference or one range per call (\"$shown\"), for example \"John 3:16-17\" or \"Rom 8:28\".";
        }
        return "Could not read the reference \"$shown\". Write it like \"John 3:16\", \"Rom 8:28-39\", "
            . '"Rom 8:38-9:5", "Ps 23" or "1 Cor 13".';
    }

    /** A chapter or verse number; absurdly long ones become 999999 (rejected later as too high). */
    private static function number(string $digits): int
    {
        $digits = ltrim($digits, '0');
        return $digits === '' ? 0 : (strlen($digits) > 6 ? 999999 : (int) $digits);
    }

    /** "viii" -> 8 (i, v, x, l, c), as in indexer/scripture.py roman_to_int; null if not a numeral up to 150. */
    public static function roman(string $s): ?int
    {
        if (!preg_match('/^[ivxlc]{1,9}$/D', $s)) {
            return null;
        }
        $vals = ['i' => 1, 'v' => 5, 'x' => 10, 'l' => 50, 'c' => 100];
        $total = 0;
        $prev = 0;
        foreach (array_reverse(str_split($s)) as $ch) {
            $v = $vals[$ch];
            $total += $v < $prev ? -$v : $v;
            $prev = max($prev, $v);
        }
        return $total > 0 && $total <= 150 ? $total : null;
    }

    private function lastChapter(string $translation, int $book): int
    {
        return $this->lastChapter["$translation $book"] ??= $this->store->bibleLastChapter($translation, $book);
    }

    private function lastVerse(string $translation, int $book, int $chapter): int
    {
        return $this->lastVerse["$translation $book $chapter"] ??= $this->store->bibleLastVerse($translation, $book, $chapter);
    }

    /** @param list<string> $translations */
    private function lastVerseOf(array $translations, int $book, int $chapter): int
    {
        $max = 0;
        foreach ($translations as $t) {
            $max = max($max, $this->lastVerse($t, $book, $chapter));
        }
        return $max;
    }

    /** @param list<string> $translations */
    private function verseIn(array $translations, int $book, int $chapter, int $verse): bool
    {
        foreach ($translations as $t) {
            if ($this->store->bibleVerses($t, $book, $chapter, $verse, $chapter, $verse, 1) !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * Other translations of the build that have a book (and a chapter, and a verse).
     * @param list<string> $except  @return list<string>
     */
    private function translationsWith(array $except, int $book, ?int $chapter = null, ?int $verse = null): array
    {
        $out = [];
        foreach ($this->translations() as $t) {
            if (in_array($t, $except, true)) {
                continue;
            }
            $ok = $chapter === null ? $this->lastChapter($t, $book) > 0
                : ($verse === null ? $this->lastVerse($t, $book, $chapter) > 0 : $this->verseIn([$t], $book, $chapter, $verse));
            if ($ok) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * 'the KJV and Douay-Rheims (translation "kjv" or "drb")'; without $argument (for a tool that has
     * no translation argument): 'the KJV and Douay-Rheims'. @param list<string> $codes
     */
    private static function translationList(array $codes, bool $argument = true): string
    {
        $labels = array_map(static fn (string $t): string => self::TRANSLATIONS[$t], $codes);
        $quoted = array_map(static fn (string $t): string => "\"$t\"", $codes);
        $and = count($labels) > 1 ? implode(', ', array_slice($labels, 0, -1)) . ' and ' . end($labels) : $labels[0];
        return "the $and" . ($argument ? ' (translation ' . self::orList($quoted) . ')' : '');
    }

    /** @param list<string> $items */
    private static function orList(array $items): string
    {
        return count($items) > 1 ? implode(', ', array_slice($items, 0, -1)) . ' or ' . end($items) : (string) ($items[0] ?? '');
    }

    /**
     * Verses missing between the first and the last verse read (and at the ends of the range,
     * when the read was not cut), e.g. Matthew 17:21 in the BSB.
     * @param list<array{chapter:int, verse:int, text:string}> $rows
     * @return list<array{0:int,1:int}> [chapter, verse]
     */
    private static function gaps(array $rows, BibleRef $ref, bool $complete): array
    {
        $out = [];
        $expect = [$ref->chapter, $ref->verse ?? 1];
        foreach ($rows as $r) {
            if ($r['chapter'] !== $expect[0]) {
                $expect = [$r['chapter'], 1];
            }
            for ($v = $expect[1]; $v < $r['verse'] && count($out) < self::MAX_GAPS; $v++) {
                $out[] = [$r['chapter'], $v];
            }
            $expect = [$r['chapter'], $r['verse'] + 1];
        }
        if ($complete && $rows !== [] && $ref->endVerse !== null && $expect[0] === $ref->endChapter) {
            for ($v = $expect[1]; $v <= $ref->endVerse && count($out) < self::MAX_GAPS; $v++) {
                $out[] = [$ref->endChapter, $v];
            }
        }
        return $out;
    }

    /**
     * Nave's headwords are in capitals, wholly or in their first words: "JESUS, THE CHRIST" -> "Jesus,
     * the Christ", "MINISTER, Christian" -> "Minister, Christian".
     */
    public static function topicName(string $head): string
    {
        $head = Text::oneLine($head, 80);
        if (!self::shouting($head) && !preg_match('/^\\p{Lu}{2,}(?!\\p{L})/u', $head)) {
            return $head;
        }
        $small = ['a', 'an', 'and', 'as', 'at', 'by', 'for', 'from', 'in', 'of', 'on', 'or', 'the', 'to', 'unto', 'with'];
        $first = true;
        return (string) preg_replace_callback("/\\p{L}[\\p{L}'’]*/u", static function (array $m) use (&$first, $small): string {
            $isFirst = $first;
            $first = false;
            if (!self::shouting($m[0])) {
                return $m[0];       // a word Nave did not print in capitals
            }
            $w = mb_strtolower($m[0], 'UTF-8');
            return !$isFirst && in_array($w, $small, true) ? $w : mb_strtoupper(mb_substr($w, 0, 1), 'UTF-8') . mb_substr($w, 1);
        }, $head);
    }

    private static function shouting(string $text): bool
    {
        return preg_match('/\p{Ll}/u', $text) === 0 && preg_match('/\p{Lu}/u', $text) === 1;
    }
}
