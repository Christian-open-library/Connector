<?php
/**
 * Tests for the cross-reference tools (PLUGIN-SPEC.md addendum): read_scripture,
 * find_scripture_citations and define_term, with src/Bible.php, src/Citations.php,
 * src/Dictionary.php and their Store and Format parts. Data: the fixture's cross-reference tables
 * (tests/php/make_xref_fixture.py: real rows copied from the sample build, plus synthetic
 * citations of Rom 8, Rom 9, John 3, Ps 23 and Jude), and data/build/LATEST-sample when present.
 */

declare(strict_types=1);

namespace ColLibraryTest\Xref;

use ColLibrary\ArgumentException;
use ColLibrary\Bible;
use ColLibrary\BibleRef;
use ColLibrary\CitationResult;
use ColLibrary\Citations;
use ColLibrary\Dictionary;
use ColLibrary\DictionaryResult;
use ColLibrary\Filters;
use ColLibrary\Format;
use ColLibrary\Mcp;
use ColLibrary\Store;
use ColLibrary\Tools;
use ColLibraryTest\Fixture;

require_once __DIR__ . '/fixture/testlib.php';

const XREF = Fixture\DIR;
const SAMPLE = __DIR__ . '/../../data/build/LATEST-sample';

function store(): Store
{
    $store = Fixture\store_or_skip();
    if (!$store->hasTables('bible_books', 'bible', 'scripture_refs', 'dictionary', 'topic_refs')) {
        col_skip('fixture without cross-reference tables: run .venv/bin/python tests/php/make_fixture.py');
    }
    return $store;
}

function bible(): Bible
{
    return new Bible(store());
}

function call(string $tool, array $args, ?string $dir = null): array
{
    store();
    return Tools::call($tool, $args, Fixture\context(['data_dir' => realpath($dir ?? XREF)]));
}

function ok(string $tool, array $args, ?string $dir = null): string
{
    $r = call($tool, $args, $dir);
    $text = Fixture\text_of($r);
    assert_false($r['isError'], "$tool " . json_encode($args) . " should succeed: $text");
    return $text;
}

function err(string $tool, array $args, ?string $dir = null): string
{
    $r = call($tool, $args, $dir);
    $text = Fixture\text_of($r);
    assert_true($r['isError'], "$tool " . json_encode($args) . " should fail: $text");
    return $text;
}

/** Passage ids of the "[n] P<id> · " lines of a result, in order. @return list<int> */
function ids(string $text): array
{
    return Fixture\result_ids($text);
}

// ------------------------------------------------------------------------------- references

function test_reference_parsing_table(): void
{
    // input => canonical label (BSB unless a translation is given)
    $cases = [
        ['John 3:16', 'John 3:16'], ['john 3:16', 'John 3:16'], ['JOHN 3:16', 'John 3:16'], ['Jn 3:16', 'John 3:16'],
        ['Joh. 3. 16.', 'John 3:16'], ['Ioh. 3. 16', 'John 3:16'], ['John iii. 16', 'John 3:16'], ['John 3.16', 'John 3:16'],
        ['John 3 16', 'John 3:16'], ['John 3, 16', 'John 3:16'], ['John 3:16a', 'John 3:16'], ['John 3:16-18', 'John 3:16-18'],
        ["John 3:16\u{2013}18", 'John 3:16-18'], ["John 3:16\u{2014}18", 'John 3:16-18'], ['John 3:16 - 18', 'John 3:16-18'],
        ['John 3:16 to 18', 'John 3:16-18'], ['John 3', 'John 3'], ['John chapter 3', 'John 3'],
        ['John 3 v 16', 'John 3:16'], ['John 3. ver. 16', 'John 3:16'], ['John 3 verses 16-17', 'John 3:16-17'],
        ['(John 3:16)', 'John 3:16'], ['"John 3:16."', 'John 3:16'], ['  John   3 : 16  ', 'John 3:16'],
        ["\u{FF2A}\u{FF4F}\u{FF48}\u{FF4E} \u{FF13}:\u{FF11}\u{FF16}", 'John 3:16'], ["John\x003:16", 'John 3:16'],
        ['Rom 8:28', 'Romans 8:28'], ['Rom. viii. 28', 'Romans 8:28'], ['Rom. 8. 28', 'Romans 8:28'], ['Rom8:28', 'Romans 8:28'],
        ['Ro 8:28', 'Romans 8:28'], ['Romans 8:28-39', 'Romans 8:28-39'], ['Rom 8:28-8:39', 'Romans 8:28-39'],
        ['Rom 8:38-9:5', 'Romans 8:38-9:5'], ['Rom 8-9', 'Romans 8-9'], ["Rom 8\u{2013}9:5", 'Romans 8:1-9:5'],
        ['Ps 23', 'Psalm 23'], ['Psalm 23', 'Psalm 23'], ['Psalms 23', 'Psalm 23'], ['Psal. xxiii.', 'Psalm 23'],
        ['Ps 23:1-3', 'Psalm 23:1-3'], ['Ps 119:176', 'Psalm 119:176'], ['Psalm cxix. 105', 'Psalm 119:105'],
        ['Pss 22-23', 'Psalms 22-23'], ['Ps 22:31-23:2', 'Psalms 22:31-23:2'],
        ['1 Cor 13', '1 Corinthians 13'], ['1Cor 13:4', '1 Corinthians 13:4'], ['1 Cor. 13. 4', '1 Corinthians 13:4'],
        ['I Cor 13:4-7', '1 Corinthians 13:4-7'], ['First Corinthians 13:13', '1 Corinthians 13:13'],
        ['1st Corinthians 13:1', '1 Corinthians 13:1'], ['2 Cor 5:17', '2 Corinthians 5:17'],
        ['II Cor. v. 17', '2 Corinthians 5:17'], ['Second Corinthians 5:21', '2 Corinthians 5:21'],
        ['1 Samuel 3:10', '1 Samuel 3:10'], ['I Sam 3:10', '1 Samuel 3:10'], ['1 Kings 3:9', '1 Kings 3:9'],
        ['I Kings 3:5', '1 Kings 3:5'], ['2 Kings 2:11', '2 Kings 2:11'], ['2 Sam 7:12-16', '2 Samuel 7:12-16'],
        ['2 Chron 7:14', '2 Chronicles 7:14'], ['1 John 1:9', '1 John 1:9'], ['First John 1:9', '1 John 1:9'],
        ['1Jn 1:9', '1 John 1:9'], ['I John 1:9', '1 John 1:9'], ['2 John 6', '2 John 6'], ['3 John 4', '3 John 4'],
        ['III John 2', '3 John 2'], ['Jude 3', 'Jude 3'], ['Jude 1:3', 'Jude 3'], ['Jude 3-5', 'Jude 3-5'],
        ['Jude 1:24-25', 'Jude 24-25'], ['Jude', 'Jude'], ['Jude 1', 'Jude 1'], ['Obadiah 15', 'Obadiah 15'],
        ['Obad 1:21', 'Obadiah 21'], ['Philemon 6', 'Philemon 6'], ['Phlm 1:6', 'Philemon 6'],
        ['Song of Solomon 2:4', 'Song of Solomon 2:4'], ['Song of Songs 2:4', 'Song of Solomon 2:4'],
        ['Song 2:4', 'Song of Solomon 2:4'], ['Cant. ii. 4', 'Song of Solomon 2:4'], ['Canticles 2:4', 'Song of Solomon 2:4'],
        ['Gen 1:1', 'Genesis 1:1'], ['Gen 1:1-2:3', 'Genesis 1:1-2:3'], ['Gen 1-2', 'Genesis 1-2'], ['Gn 1:1', 'Genesis 1:1'],
        ['Ge 1:1', 'Genesis 1:1'], ['Ex 20:3', 'Exodus 20:3'], ['Exod 20:3', 'Exodus 20:3'], ['Lev 19:18', 'Leviticus 19:18'],
        ['Num 6:24-26', 'Numbers 6:24-26'], ['Dt 6:4', 'Deuteronomy 6:4'], ['Ios. 1. 9', 'Joshua 1:9'],
        ['Jud 1:1', 'Judges 1:1'], ['Ruth 1:16', 'Ruth 1:16'], ['Iob 1:21', 'Job 1:21'], ['Pr 3:5', 'Proverbs 3:5'],
        ['Qoh 1:2', 'Ecclesiastes 1:2'], ['Is 53:5', 'Isaiah 53:5'], ['Esay 53. 5', 'Isaiah 53:5'], ['Jer 31:31', 'Jeremiah 31:31'],
        ['Ezech. xxxvi. 26', 'Ezekiel 36:26'], ['Dan 3:25', 'Daniel 3:25'], ['Jonah 1:17', 'Jonah 1:17'], ['Mal 3:6', 'Malachi 3:6'],
        ['Mt 5:3-12', 'Matthew 5:3-12'], ['Mk 1:15', 'Mark 1:15'], ['Lk 2:14', 'Luke 2:14'], ['Acts 2:38', 'Acts 2:38'],
        ['Gal 2:20', 'Galatians 2:20'], ['Eph 2:8-9', 'Ephesians 2:8-9'], ['Php 4:13', 'Philippians 4:13'],
        ['Col 1:15', 'Colossians 1:15'], ['1 Thess 4:16', '1 Thessalonians 4:16'], ['2 Thess 3:10', '2 Thessalonians 3:10'],
        ['1 Tim 3:16', '1 Timothy 3:16'], ['2Tim3:16', '2 Timothy 3:16'], ['Tit 2:11', 'Titus 2:11'], ['Heb 11:1', 'Hebrews 11:1'],
        ['Jas 1:5', 'James 1:5'], ['1 Pet 1:3', '1 Peter 1:3'], ['2 Pet 1:21', '2 Peter 1:21'], ['Rev 1:8', 'Revelation 1:8'],
        ['Revelations 22:20', 'Revelation 22:20'], ['Apoc. 22. 20', 'Revelation 22:20'], ['Rv 22:20', 'Revelation 22:20'],
        ['Sir 1:1', 'Sirach 1:1', 'kjv'], ['Ecclus 1:1', 'Sirach 1:1', 'kjv'], ['Wisdom of Solomon 7:26', 'Wisdom 7:26', 'kjv'],
        ['1 Macc 1:1', '1 Maccabees 1:1', 'kjv'], ['Tobit 1:1', 'Tobit 1:1', 'kjv'], ['Tob 1:1', 'Tobit 1:1', 'drb'],
        ['Prayer of Azariah 5', 'Prayer of Azariah 5', 'drb'], ['Esther 11:2', 'Esther 11:2', 'kjv'],
    ];
    $bible = bible();
    foreach ($cases as $case) {
        [$input, $want] = $case;
        $tr = $case[2] ?? 'bsb';
        try {
            $ref = $bible->reference($input, [$tr]);
        } catch (ArgumentException $e) {
            throw new \AssertionFailed(json_encode($input) . " ($tr) failed: " . $e->getMessage());
        }
        $label = $bible->label($ref);
        assert_same($want, $label, json_encode($input));
        // labels read back as the same reference
        $again = $bible->reference($label, [$tr]);
        assert_same([$ref->book, $ref->chapter, $ref->verse, $ref->endChapter, $ref->endVerse],
            [$again->book, $again->chapter, $again->verse, $again->endChapter, $again->endVerse], "round trip $label");
    }
    assert_true(count($cases) >= 60, 'at least 60 references');
}

function test_reference_errors_say_what_is_valid(): void
{
    $cases = [
        ['John', 'Give a chapter of John too, for example "John 1" (John has 21 chapters in the BSB).'],
        ['John 0:1', 'Chapter and verse numbers start at 1.'],
        ['John 3:0', 'Chapter and verse numbers start at 1.'],
        ['John 3:37', 'John 3 has 36 verses in the BSB.'],
        ['John 22', 'John has 21 chapters in the BSB.'],
        ['Rom 99999999999:1', 'Romans has 16 chapters in the BSB.'],
        ['Romans 8:28-45', 'Romans 8 has 39 verses in the BSB.'],
        ['Rom 8:39-28', 'The range runs backwards (it ends before it starts). Write it the other way round, e.g. "Romans 8:28-39".'],
        ['Rom 9:5-8:38', 'e.g. "Romans 8:38-9:5"'],
        ['Rom 8:28-9', 'e.g. "Romans 8:9-28", or give the chapter of the last verse for a range across chapters, e.g. '
            . '"Romans 8:28-9:1".'],
        ['Gen 2-1', 'e.g. "Genesis 1-2"'],
        ['Gen 50:26-Exod 1:5', 'A range must stay within one book ("Gen 50:26-Exod 1:5").'],
        ['Rom 8:28; John 3:16', 'Give one reference per call ("Rom 8:28; John 3:16" has several)'],
        ['Rom 8:28 & 30', 'Give one reference per call'],
        ['Rom 8:28, John 3:16', 'Give one reference or one range per call'],
        ['John 3:16, 17', 'Give one reference or one range per call ("John 3:16, 17"), for example "John 3:16-17"'],
        ['John 3:16 KJV', 'Give only the reference, without the Bible version ("John 3:16 KJV")'],
        ['John 3:16 (ESV)', 'without the Bible version'],
        ['Tobit 1:1', 'Tobit is not in the BSB; it is in the KJV and Douay-Rheims (translation "kjv" or "drb").'],
        ['Matt 17:21', 'Matthew 17:21 is not in the BSB; it is in the KJV, ASV, Douay-Rheims, Darby, Webster and YLT'],
        ['Esther 11:2', 'Esther has 10 chapters in the BSB; Esther 11 is in the KJV and Douay-Rheims (translation "kjv" or "drb").'],
        ['Phillipians 4:13', 'There is no book "Phillipians" in the Bible. Did you mean Philippians?'],
        ['Saint John 3:16', 'There is no book "Saint John" in the Bible. Did you mean John?'],
        ['1 Corinthans 13:4', 'There is no book "1 Corinthans" in the Bible. Did you mean 1 Corinthians?'],
        ['Hezekiah 3:5', 'There is no book "Hezekiah" in the Bible. Use a book name or a common abbreviation'],
        ['3:16', 'Start the reference with the name of a book, for example "John 3:16" (got "3:16").'],
        ['Jude 2:3', 'Jude has only one chapter: write "Jude 3" or "Jude 1:3" for a verse, or "Jude" for the whole book.'],
        ['Jude 26', 'Jude has 25 verses in the BSB.'],
        ['Rom xyz', 'Could not read the reference "Rom xyz". Write it like "John 3:16"'],
        ['1 Esdras 1:1', '1 Esdras is not in the library\'s Bibles.'],
        ['...', 'reference must be a Bible reference such as "John 3:16"'],
    ];
    $bible = bible();
    foreach ($cases as [$input, $want]) {
        $e = assert_throws(ArgumentException::class, fn () => $bible->reference($input, ['bsb']), json_encode($input));
        assert_contains($want, $e->getMessage(), json_encode($input));
    }
    // other translations: Psalm 23 has 6 verses in the KJV, 10 in the Douay-Rheims (its Psalm 23 is their 24)
    $e = assert_throws(ArgumentException::class, fn () => $bible->reference('Psalm 23:7', ['kjv']));
    assert_same('Psalm 23 has 6 verses in the KJV; Psalm 23:7 is in the Douay-Rheims (translation "drb").', $e->getMessage());
    // checked against the BSB and KJV together (find_scripture_citations): no translation named
    $e = assert_throws(ArgumentException::class, fn () => $bible->reference('Rom 17', ['bsb', 'kjv']));
    assert_same('Romans has 16 chapters.', $e->getMessage());
    assert_same('Tobit 1:1', $bible->label($bible->reference('Tobit 1:1', ['bsb', 'kjv'])), 'the KJV has Tobit');
    assert_same('Prayer of Azariah 5', $bible->label($bible->reference('Prayer of Azariah 5', ['bsb', 'kjv'])),
        'a book only the Douay-Rheims has');
    $e = assert_throws(ArgumentException::class, fn () => $bible->reference('Esther 11:1', ['bsb', 'asv']));
    assert_same('Esther has 10 chapters; Esther 11 is in the KJV and Douay-Rheims (translation "kjv" or "drb").',
        $e->getMessage());
    assert_same('Matthew 17:21', $bible->label($bible->reference('Matt 17:21', ['bsb', 'kjv'])), 'in the KJV, not the BSB');
    $e = assert_throws(ArgumentException::class, fn () => $bible->reference('Jude 1:26', ['bsb', 'kjv']));
    assert_same('Jude has 25 verses.', $e->getMessage());
    assert_same('Tobit 1:1', $bible->label($bible->reference('Tobit 1:1', ['bsb', 'asv'])),
        'several translations: a book none of them has is checked against those that have it');
}

function test_reference_segments_and_names(): void
{
    $bible = bible();
    $ref = $bible->reference('Rom 8:38-9:5', ['bsb']);
    assert_same([[8, 38, null], [9, null, 5]], $ref->segments());
    assert_false($ref->wholeChapters());
    $ref = $bible->reference('Gen 1-2', ['bsb']);
    assert_same([[1, null, null], [2, null, null]], $ref->segments());
    assert_true($ref->wholeChapters());
    assert_same([[1, 3, 5]], $bible->reference('Jude 3-5', ['bsb'])->segments());
    assert_same('Rom 8:28-30', $bible->label(new BibleRef(45, 8, 28, 8, 30), true));
    assert_same('Ps 23', $bible->label(new BibleRef(19, 23, null, 23, null), true));
    assert_same('2 Sam', $bible->shortName(10), 'the build has no abbreviation for 2 Samuel: the first book\'s');
    assert_same('1 John', $bible->shortName(62), 'no abbreviation at all: the name');
    assert_same(['bsb', 'kjv', 'asv', 'drb', 'darby', 'webster', 'ylt'], $bible->translations());
    assert_same(8, Bible::roman('viii'));
    assert_same(119, Bible::roman('cxix'));
    assert_same(null, Bible::roman('mmx'));
    assert_same('rom 8:28-30', Bible::normalize("Rom.\u{2009}8:28\u{2013}30"));
    assert_same('1 cor 13', Bible::normalize('1Cor13'));
    assert_same('1 john 1:9', Bible::normalize('1st John 1:9'));
}

// --------------------------------------------------------------------------- read_scripture

function test_read_scripture_format(): void
{
    $text = ok('read_scripture', ['reference' => 'John 3:16']);
    $lines = explode("\n", $text);
    assert_same('John 3:16 (BSB)', $lines[0]);
    assert_same('16 For God so loved the world that He gave His one and only Son, that everyone who believes in Him '
        . 'shall not perish but have eternal life.', $lines[1]);
    assert_same('', $lines[2]);
    assert_true(str_starts_with($lines[3], "Topics (Nave's, Torrey's): "), 'topics line');
    $topics = explode('; ', substr($lines[3], strlen("Topics (Nave's, Torrey's): ")));
    assert_same(10, count($topics), 'at most 10 headwords');
    assert_same(count($topics), count(array_unique(array_map('strtolower', $topics))), 'each topic once');
    assert_contains('Love of God, The', $lines[3]);
    assert_not_contains('JESUS', $lines[3], "Nave's capitals in title case");
    assert_same('For passages in the library that cite this, use find_scripture_citations.', $lines[4]);
    assert_same(5, count($lines));

    $kjv = ok('read_scripture', ['reference' => 'Rom 8:28-30', 'translation' => 'kjv']);
    assert_contains("Romans 8:28-30 (KJV)\n28 And we know that all things work together for good to them that love God, "
        . "to them who are the called according to his purpose.\n29 For whom he did foreknow,", $kjv);
    assert_contains('Topics (Nave\'s, Torrey\'s): ', $kjv);
    assert_same($kjv, ok('read_scripture', ['reference' => 'Rom 8:28-30', 'translation' => 'KJV']), 'translation in capitals');

    // whole chapter: "Psalm" for one psalm; six verses, so no topics
    $ps = ok('read_scripture', ['reference' => 'Psalms 23']);
    assert_true(str_starts_with($ps, "Psalm 23 (BSB)\n1 A Psalm of David. The LORD is my shepherd; I shall not want.\n2 "), $ps);
    assert_contains("\n6 Surely goodness and mercy", $ps);
    assert_not_contains('Topics', $ps);
    assert_contains("\n\nFor passages in the library that cite this, use find_scripture_citations.", $ps);
    $short = ok('read_scripture', ['reference' => 'Ps 117']);
    assert_contains("Psalm 117 (BSB)\n1 Praise the LORD, all you nations! Extol Him, all you peoples!\n2 For great", $short);
    assert_contains('Topics (Nave\'s, Torrey\'s): ', $short, 'a whole chapter of two verses has topics');

    // across chapters: chapter:verse on every line
    $cross = ok('read_scripture', ['reference' => 'Rom 8:38-9:2', 'translation' => 'asv']);
    $lines = explode("\n", $cross);
    assert_same('Romans 8:38-9:2 (ASV)', $lines[0]);
    assert_same(['8:38', '8:39', '9:1', '9:2'], array_map(static fn (string $l): string => explode(' ', $l)[0],
        array_slice($lines, 1, 4)));
}

function test_read_scripture_notes_and_cap(): void
{
    // the 200-verse cap: Psalms 118 (29 verses) and 119 (176)
    $long = ok('read_scripture', ['reference' => 'Ps 118-119']);
    $lines = explode("\n", $long);
    assert_same('Psalms 118-119 (BSB)', $lines[0]);
    assert_same('118:1', explode(' ', $lines[1])[0]);
    assert_same('119:171', explode(' ', $lines[200])[0]);
    assert_same('', $lines[201]);
    assert_same('Only the first 200 verses are shown (to Psalm 119:171). The rest: Psalm 119:172-176.', $lines[202]);
    assert_not_contains('Topics', $long);
    assert_same(176, count(explode("\n", ok('read_scripture', ['reference' => 'Ps 119']))) - 3, 'all of Psalm 119');

    // a verse the BSB leaves out, inside a range
    $gap = ok('read_scripture', ['reference' => 'Matt 17:20-22']);
    assert_contains("\n20 ", $gap);
    assert_contains("\n22 ", $gap);
    assert_not_contains("\n21 ", $gap);
    assert_contains('Not in the BSB: Matthew 17:21.', $gap);
    assert_not_contains('Not in the KJV', ok('read_scripture', ['reference' => 'Matt 17:20-22', 'translation' => 'kjv']));

    // Douay-Rheims: Vulgate numbering of the Psalms
    $drb = ok('read_scripture', ['reference' => 'Psalm 22:1', 'translation' => 'drb']);
    assert_contains("Psalm 22:1 (Douay-Rheims)\n1 A psalm for David. The Lord ruleth me: and I shall want nothing.", $drb);
    assert_contains('Note: the Douay-Rheims numbers the Psalms as the Vulgate does', $drb);
    assert_not_contains('Topics', $drb, 'Nave and Torrey use the KJV numbers');
    assert_not_contains('Vulgate', ok('read_scripture', ['reference' => 'Psalm 23:1']));
    assert_not_contains('Vulgate', ok('read_scripture', ['reference' => 'Jude 3', 'translation' => 'drb']));

    // the Apocrypha: in the KJV and Douay-Rheims only
    assert_contains('Tobit 1:1 (KJV)', ok('read_scripture', ['reference' => 'Tobit 1:1', 'translation' => 'kjv']));
    assert_contains('Tobit is not in the BSB; it is in the KJV and Douay-Rheims (translation "kjv" or "drb").',
        err('read_scripture', ['reference' => 'Tobit 1:1']));
    assert_contains('Tobit is not in the ASV; it is in the KJV and Douay-Rheims',
        err('read_scripture', ['reference' => 'Tob. 1. 1', 'translation' => 'asv']));
    assert_contains('Jude 3 (YLT)', ok('read_scripture', ['reference' => 'Jude 1:3', 'translation' => 'ylt']));
    assert_contains("Jude (BSB)\n1 Jude, a servant of Jesus Christ", ok('read_scripture', ['reference' => 'Jude']));
}

function test_read_scripture_arguments(): void
{
    $cases = [
        [[], 'reference is required'],
        [['reference' => '  '], 'reference is required'],
        [['reference' => 316], 'reference must be a string'],
        [['reference' => str_repeat('John 3:16 ', 11)], 'reference is too long: at most 100 characters'],
        [['reference' => 'John 3:16', 'translation' => 'esv'], 'translation must be one of: bsb, kjv, asv, drb, darby, webster, ylt'],
        [['reference' => 'John 3:16', 'version' => 'kjv'], 'Unknown argument "version" for read_scripture. Accepted arguments: reference, translation'],
        [['reference' => 'Saint John 3:16'], 'Did you mean John?'],
    ];
    foreach ($cases as [$args, $want]) {
        assert_contains($want, err('read_scripture', $args));
    }
}

// ------------------------------------------------------------------ find_scripture_citations

const ROM_8_28 = [10100000, 10300001, 10200003, 10400002, 10100002, 10300004, 10100005, 10100006, 10400004, 10400005];

function test_citations_format_and_order(): void
{
    $text = ok('find_scripture_citations', ['reference' => 'Rom 8:28', 'limit' => 20]);
    $lines = explode("\n", $text);
    assert_same('Citations of Romans 8:28 in the library', $lines[0]);
    assert_same('Romans 8:28 (BSB)', $lines[2]);
    assert_same('28 And we know that God works all things together for the good of those who love Him, who are called '
        . 'according to His purpose.', $lines[3]);
    assert_same('Romans 8:28 (KJV)', $lines[5]);
    assert_true(str_starts_with($lines[6], '28 And we know that all things work together for good'), $lines[6]);
    assert_same('Cited in 7 passages; 3 more cite Romans 8 as a whole and come after them (10 passages across 4 works '
        . 'in all, 5 of them in margin notes). Passages 1-10, earliest works first, one passage per work in turn:', $lines[8]);
    // verse matches first; works by year (1668, 1886, then no year), one passage per work per round
    assert_same(ROM_8_28, ids($text));
    assert_contains("[1] P10100000 · John Owen, *Of the Mortification of Sinne in Beleevers* (1668) · p. iii · The Preface · "
        . "(cites Rom 8:28)\nAuthor: John Owen (1616–1683, English Puritan, Congregationalist)\nI Shall not need to acquaint the Reader", $text);
    assert_contains('[2] P10300001 · Augustine, *The Confessions* (1886) · pp. 45–46 · Book I › Chapter 2 · '
        . '(cites Rom 8:28, 8:27-29)' . "\nAuthor: Augustine (354–430, bishop of Hippo, Latin Church Father)\n", $text,
        'text and margin note: not marked');
    assert_contains('[4] P10400002 · Théodore de Bèze, *A Brief Sum of the Christian Faith* · pp. 9–10 · Of Faith · '
        . '(cites Rom 8:28) (in a margin note)', $text);
    assert_contains('· (cites Rom 8:26-30) (in a margin note)', $text, 'a range that contains the verse');
    assert_contains('[8] P10100006 · John Owen, *Of the Mortification of Sinne in Beleevers* (1668) · p. 3 · CHAP. II. · '
        . '(cites Romans 8, whole chapter)' . "\n", $text);
    assert_contains('· (cites Romans 8, whole chapter) (in a margin note)', $text);
    assert_contains("Copied from: https://quod.lib.umich.edu/e/eebo/A53698.0001.001", $text);
    assert_not_contains('More passages cite', $text);
    assert_not_contains('P10300005', $text, 'Rom 8:1-27 does not contain verse 28');
    assert_not_contains('P10100008', $text, 'Rom 8:29-30 does not contain verse 28');

    $verses = ok('find_scripture_citations', ['reference' => 'Rom 8:28', 'include_chapter_refs' => false, 'limit' => 20]);
    assert_same(array_slice(ROM_8_28, 0, 7), ids($verses));
    assert_contains('Cited in 7 passages across 4 works (4 of them in margin notes). Passages 1-7,', $verses);
    assert_same(ids($verses), ids(ok('find_scripture_citations', ['reference' => 'Rom 8:28',
        'include_chapter_refs' => 'false', 'limit' => 20])), '"false" as a string');
}

function test_citations_paging(): void
{
    $p1 = ok('find_scripture_citations', ['reference' => 'Romans 8:28', 'limit' => 3]);
    assert_same(array_slice(ROM_8_28, 0, 3), ids($p1));
    assert_contains('Passages 1-3, earliest works first', $p1);
    assert_contains('Search notes: More passages cite Romans 8:28: repeat with offset 3 for the next ones.', $p1);
    $p2 = ok('find_scripture_citations', ['reference' => 'Romans 8:28', 'limit' => 3, 'offset' => 3]);
    assert_same(array_slice(ROM_8_28, 3, 3), ids($p2));
    assert_contains('[4] P10400002', $p2, 'numbered from the offset');
    assert_contains('repeat with offset 6', $p2);
    $last = ok('find_scripture_citations', ['reference' => 'Romans 8:28', 'limit' => 3, 'offset' => 9]);
    assert_same([10400005], ids($last));
    assert_not_contains('More passages cite', $last);
    assert_same('Only 10 passages cite Romans 8:28; use an offset below 10.',
        err('find_scripture_citations', ['reference' => 'Romans 8:28', 'offset' => 10]));
}

function test_citations_filters(): void
{
    $owen = ok('find_scripture_citations', ['reference' => 'Rom 8:28', 'author' => 'owen']);
    assert_same('Citations of Romans 8:28 in the library (author contains "owen")', explode("\n", $owen)[0]);
    assert_same([10100000, 10200003, 10100002, 10100005, 10100006], ids($owen));
    assert_contains('Search notes: Filters: author contains "owen" (2 works).', $owen);

    $late = ok('find_scripture_citations', ['reference' => 'Rom 8:28', 'year_from' => 1700]);
    assert_same([10300001, 10300004], ids($late));
    assert_contains('Works without a known year are excluded by a year filter (2 of them matched the other filters).', $late);
    assert_same([10300001, 10300004], ids(ok('find_scripture_citations', ['reference' => 'Rom 8:28',
        'collection' => 'ccel-schaff'])));
    assert_same([10400002, 10400004, 10400005], ids(ok('find_scripture_citations', ['reference' => 'Rom 8:28', 'work_id' => 104])));
    assert_contains('No works in the library match these filters: up to 1500.',
        err('find_scripture_citations', ['reference' => 'Rom 8:28', 'year_to' => 1500]));
    assert_contains('No work has work_id 999', err('find_scripture_citations', ['reference' => 'Rom 8:28', 'work_id' => 999]));
    assert_same([10300005], ids(ok('find_scripture_citations', ['reference' => 'Rom 8:13', 'author' => 'augustine'])),
        'Rom 8:1-27 contains verse 13');
    $none = err('find_scripture_citations', ['reference' => 'Rom 8:39', 'author' => 'augustine']);
    assert_contains('No passage in the library cites Romans 8:39 (author contains "augustine")', $none);
    assert_contains('looser filters', $none);
}

function test_citations_of_chapters_and_ranges(): void
{
    // a whole chapter: every citation in it (whole-chapter citations last)
    $ch = ok('find_scripture_citations', ['reference' => 'Rom 8', 'limit' => 20]);
    assert_same([10100000, 10300001, 10200001, 10400002, 10100001, 10300004, 10200003, 10100002, 10300005, 10100003,
                 10100005, 10100007, 10100008, 10100006, 10400004, 10400005], ids($ch));
    assert_contains('Cited in 16 passages across 4 works (8 of them in margin notes); 3 of them cite only Romans 8 as a '
        . 'whole and come after the others.', $ch);
    assert_contains('(cites Rom 8:13) (in a margin note)', $ch, 'a real citation found in the fixture notes');
    assert_contains("Romans 8 (BSB)\n1 Therefore, there is now no condemnation for those who are in Christ Jesus.", $ch);
    assert_contains("\n5 Those who live according to the flesh set their minds on the things of the flesh; but those who "
        . "live according to the Spirit set their minds on the things of the Spirit.\n…\n\nRomans 8 (KJV)\n1 ", $ch,
        'first five verses, then …');

    // across chapters: Rom 8:38-39 and 9:1-5, plus citations of Romans 8 or 9 as a whole
    $cross = ok('find_scripture_citations', ['reference' => 'Rom 8:38-9:5']);
    assert_same([10300002, 10200001, 10200002, 10100006, 10200003, 10400004, 10400005], ids($cross));
    assert_contains("Romans 8:38-9:5 (BSB)\n8:38 For I am convinced", $cross);
    assert_contains('(cites Rom 9:5) (in a margin note)', $cross);
    assert_contains('Cited in 3 passages; 4 more cite Romans 8-9 as a whole and come after them (7 passages across 4 '
        . 'works in all, 2 of them in margin notes).', $cross);

    $john = ok('find_scripture_citations', ['reference' => 'John 3:16']);
    assert_same([10300003, 10400003, 10200005], ids($john));
    assert_contains('(cites John 3:14-17) (in a margin note)', $john);
    assert_contains('(cites John 3, whole chapter)', $john);
    assert_contains('Cited in 2 passages; 1 more cites John 3 as a whole and comes after them (3 passages across 3 works '
        . 'in all, 1 of them in margin notes). Passages 1-3', $john);

    $ps = ok('find_scripture_citations', ['reference' => 'Psalm 23']);
    assert_same([10100009, 10300000, 10300006], ids($ps));
    assert_contains("Psalm 23 (KJV)\n1 The Lord is my shepherd; I shall not want.", $ps);
    assert_contains('(cites Ps 23:4) (in a margin note)', $ps);
    assert_contains('(cites Psalm 23, whole chapter)', $ps);

    $jude = ok('find_scripture_citations', ['reference' => 'Jude 3']);
    assert_same([10200004], ids($jude));
    assert_contains('Cited in 1 passage across 1 work (none of them in margin notes). Passages 1-1', $jude);
    assert_contains('(cites Jude 3)', $jude);

    $tobit = err('find_scripture_citations', ['reference' => 'Tobit 1:3']);
    assert_contains('No passage in the library cites Tobit 1:3.', $tobit);
    assert_contains('Try the whole chapter ("Tobit 1"), a nearby verse, or a search for the words of the verse.', $tobit);
    assert_contains('Try a nearby chapter, or a search for the words of the passage.',
        err('find_scripture_citations', ['reference' => 'Gen 2']));
    assert_contains('include_chapter_refs true', err('find_scripture_citations', ['reference' => 'Gen 1:1',
        'include_chapter_refs' => false]));
    assert_contains('Ask for at most 10 chapters at a time (for example "Psalms 22-31")',
        err('find_scripture_citations', ['reference' => 'Ps 22-119']));
    assert_contains('Matthew 17:21 is not in the BSB; it is in the KJV',
        err('read_scripture', ['reference' => 'Matt 17:21']));
}

function test_citation_order_and_grouping(): void
{
    // works: 7 (1600), 3 (no year), 5 (1550), 9 (1600), 2 (no year); passages id = work * 100000 + seq
    $p = static fn (int $work, int $seq, int $group): array => ['work_id' => $work, 'group' => $group];
    $passages = [];
    foreach ([[7, 1, 1], [7, 2, 1], [7, 3, 1], [3, 1, 1], [5, 4, 1], [5, 2, 1], [9, 1, 1], [2, 8, 1], [2, 1, 2], [5, 9, 2]]
             as [$w, $s, $g]) {
        $passages[$w * 100000 + $s] = $p($w, $s, $g);
    }
    $years = [7 => 1600, 3 => null, 5 => 1550, 9 => 1600, 2 => null];
    assert_same([500002, 700001, 900001, 200008, 300001, 500004, 700002, 700003, 500009, 200001],
        Citations::order($passages, $years));
    assert_same([], Citations::order([], []));

    // a passage citing verses and the whole chapter belongs with the verses; margin notes per group
    $ref = new BibleRef(45, 8, 28, 8, 28);
    $rows = [[8, 1, 10, null, null, 0], [8, 1, 10, 28, null, 1], [8, 2, 20, null, null, 1], [8, 3, 30, 28, null, 0],
             [8, 3, 30, 26, 30, 1]];
    $by = Citations::byPassage($rows);
    assert_same(['work_id' => 10, 'group' => 1, 'in_note' => true], $by[1],
        'its verse citation is only in a note (the chapter one in the text does not count)');
    assert_same(['work_id' => 20, 'group' => 2, 'in_note' => true], $by[2]);
    assert_same(['work_id' => 30, 'group' => 1, 'in_note' => false], $by[3]);
    $cites = Citations::cites($rows, $by, [1, 2, 3], $ref);
    assert_same([[8, 28, null], [8, 26, 30]], $cites[3], 'the exact verse first');
    assert_same([[8, 28, null]], $cites[1], 'only the citations of its group');
    assert_same([[8, null, null]], $cites[2]);
    assert_same([3], array_keys(Citations::cites($rows, $by, [3], $ref)), 'only the passages asked for');
}

function test_citations_row_cap(): void
{
    $store = store();
    $bible = new Bible($store);
    $result = (new Citations($store, $bible, 5))->find($bible->reference('Rom 8', ['bsb', 'kjv']), true, new Filters(), 20, 0);
    assert_true($result->capped);
    assert_true($result->passages <= 5);
    assert_contains('This is cited more than 5 times; only the first 5 citations (chapter by chapter in verse order, '
        . 'those of the whole chapter last) were counted', implode(' ', $result->notes));
    // the cut drops the citations of the whole chapter first, then those of the last verses
    assert_same(0, $result->chapterOnly);
    assert_same([10100001, 10300005, 10100002, 10100003, 10100007], array_column($result->hits, 'id'));
    $full = (new Citations($store, $bible))->find($bible->reference('Rom 8', ['bsb', 'kjv']), true, new Filters(), 20, 0);
    assert_false($full->capped);
    assert_same(16, $full->passages);
}

function test_citation_and_dictionary_results_stay_under_the_cap(): void
{
    $work = ['id' => 1, 'author' => 'A', 'title' => 'T', 'year' => 1650, 'source_url' => '', 'full_title' => 'T',
             'collection' => 'ccel', 'license' => '', 'type' => 'book'];
    $hits = [];
    for ($i = 0; $i < 20; $i++) {
        $hits[] = ['id' => 100000 + $i, 'work_id' => 1, 'seq' => $i, 'page_start' => null, 'page_end' => null, 'section' => '',
                   'text' => str_repeat('word ', 8000), 'notes' => [str_repeat('note ', 2000)], 'work' => $work,
                   'cites' => 'Rom 8:28', 'in_note' => false];
    }
    $text = Format::citations(new CitationResult('Romans 8:28', '', [['label' => 'Romans 8:28 (BSB)', 'lines' => ['28 And'],
        'more' => false]], $hits, 20, 1, 0, 0, 'Romans 8', 0, false, []));
    assert_true(mb_strlen($text) <= Format::MAX_CHARS, 'citations: ' . mb_strlen($text));
    assert_same(20, count(ids($text)), 'every passage is still there');
    assert_contains('Some passages were shortened (ending in …)', $text);
    $entries = [];
    for ($i = 0; $i < 20; $i++) {
        $entries[] = ['id' => $i, 'source' => 'easton', 'work_id' => 669, 'headword' => "H$i", 'norm' => "h$i", 'page' => null,
                      'text' => str_repeat('x', 3999) . '…', 'length' => 9000, 'cut' => true, 'first' => $i === 0,
                      'alone' => $i === 1, 'exact' => true, 'indexed' => false];
    }
    $defs = Format::definitions(new DictionaryResult('h', 'h', null, $entries, true, []));
    assert_true(mb_strlen($defs) <= Format::MAX_CHARS, 'definitions: ' . mb_strlen($defs));
    assert_same(18, substr_count($defs, '(Entry shortened to 4,000 of its 9,000 characters.)'));
    assert_contains("(Entry shortened to 4,000 of its 9,000 characters; to read all of it, repeat with source \"easton\" and "
        . 'limit 1.)', $defs);
    assert_contains('(Entry shortened to 4,000 of its 9,000 characters; to read all of it, look up "H1" with source "easton" '
        . 'and limit 1.)', $defs);
}

function test_citations_arguments(): void
{
    $cases = [
        [[], 'reference is required'],
        [['reference' => 'Rom 8:28', 'include_chapter_refs' => 'maybe'], 'include_chapter_refs must be true or false'],
        [['reference' => 'Rom 8:28', 'limit' => 21], 'limit must be from 1 to 20 (got 21)'],
        [['reference' => 'Rom 8:28', 'offset' => 5001], 'offset must be from 0 to 5000 (got 5001)'],
        [['reference' => 'Rom 8:28', 'year_from' => 1700, 'year_to' => 1600], 'year_from (1700) is later than year_to (1600)'],
        [['reference' => 'Rom 8:28', 'translation' => 'kjv'], 'Unknown argument "translation" for find_scripture_citations'],
        [['reference' => 'Rom 8:28 KJV'], 'without the Bible version'],
        [['reference' => 'Rom 17:1'], 'Romans has 16 chapters.'],
        [['reference' => 'Rom 8:28; 9:1'], 'Give one reference per call'],
    ];
    foreach ($cases as [$args, $want]) {
        assert_contains($want, err('find_scripture_citations', $args));
    }
}

// ------------------------------------------------------------------------------ define_term

function test_norm_matches_the_indexer(): void
{
    $file = XREF . '/xref_expected.json';
    if (!is_file($file)) {
        col_skip('xref_expected.json missing: run .venv/bin/python tests/php/make_fixture.py');
    }
    $cases = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)['norm_headword'];
    assert_true(count($cases) > 400);
    foreach ($cases as [$head, $want]) {
        assert_same($want, Dictionary::norm($head), 'norm_headword(' . json_encode($head, JSON_UNESCAPED_UNICODE) . ')');
    }
}

function test_define_term_format(): void
{
    $text = ok('define_term', ['term' => 'conversation']);
    $lines = explode("\n", $text);
    assert_same('Dictionary entries for "conversation": 5 with this headword; 1 with another headword indexed under it '
        . '(check the meaning)', $lines[0]);
    preg_match_all('/^\[(\d+)\] (.*)$/m', $text, $m);
    assert_same([
        'Conversation · Easton\'s Bible Dictionary (1897) · work_id 669',
        'Conversation · Thomas Wilson, A Complete Christian Dictionary (1661) · p. 122 · work_id 2815',
        'Conversation · Thomas Wilson, A Complete Christian Dictionary (1661) · p. 122 · work_id 2815',
        'Conversation · Elisha Coles, An English Dictionary (1677) · work_id 1200',
        'CONVERSATION · Nave\'s Topical Bible (1896) · work_id 670',
        'Conuersation · Thomas Wilson, A Christian Dictionarie (1612) · p. 66 · work_id 2813 · indexed as "conversation"',
    ], $m[2], 'source order (Easton, Smith, Hitchcock, Wilson, Wilson 1661, Coles, Nave, Torrey), the headword itself first');
    assert_contains("work_id 669\nGenerally the goings out and in of social intercourse (Eph. 2:3;\n4:22; R.V., “manner of "
        . 'life”)', $text, 'entry text verbatim');
    assert_contains("work_id 1200\na being\n", $text);
    assert_not_contains('Search notes', $text);

    $old = ok('define_term', ['term' => 'Conuersation', 'source' => 'wilson']);
    assert_contains('Dictionary entries for "Conuersation" in Thomas Wilson, A Christian Dictionarie (1612) '
        . '(as "conversation"): 1 with this headword', $old);
    assert_contains('[1] Conuersation · Thomas Wilson, A Christian Dictionarie (1612) · p. 66 · work_id 2813', $old);

    // seven entries are exactly "grace"; the eighth begins with it
    $grace = ok('define_term', ['term' => 'Grace']);
    assert_contains('Dictionary entries for "Grace": 7 with this headword, then 1 whose headword begins with it', $grace);
    assert_contains('[8] Grace, means of · Easton\'s Bible Dictionary (1897)', $grace);
    assert_contains('Search notes: More entries match: raise the limit (at most 20) or choose a source.', $grace);
    assert_contains('[1] Grace · Easton', $grace);
    assert_contains('[7] Grace · Torrey\'s New Topical Textbook (1897)', $grace);

    $prefix = ok('define_term', ['term' => 'justif', 'limit' => 4]);
    assert_contains('Dictionary entries for "justif": none with exactly this headword; 4 whose headword begins with it', $prefix);
    preg_match_all('/^\[\d+\] (.*?) · /m', $prefix, $m);
    assert_same(['Justification', 'Iustification', 'Iustifie. sig: pro', 'Justifie'], $m[1]);
    assert_contains('[2] Iustification · Thomas Wilson, A Christian Dictionarie (1612) · p. 266 · work_id 2813 · indexed as '
        . '"justification"', $prefix);
    assert_contains('(Entry shortened to 4,000 of its 7,978 characters; to read all of it, repeat with source "wilson1661" '
        . 'and limit 1.)', $prefix);

    $sin = ok('define_term', ['term' => 'sinne']);
    assert_contains('Dictionary entries for "sinne" (as "sin"): 8 with this headword', $sin);
    assert_contains('More entries match', $sin);
    $a = ok('define_term', ['term' => 'a', 'limit' => 5]);
    preg_match_all('/^\[\d+\] (.*?) · (.*?)(?: · |$)/m', $a, $m);
    assert_same(['A', 'A', 'A', 'Abba', 'Aaron'], $m[1], 'exact, then the shortest headwords that begin with it');
    assert_contains('Dictionary entries for "a": 3 with this headword, then 2 whose headword begins with it', $a);
    assert_contains('[1] Melchizedeck · Elisha Coles', ok('define_term', ['term' => "Melchis\u{E9}dec", 'source' => 'coles']),
        'accents dropped when nothing else matches');
    assert_contains('[1] Self-Denial · Torrey', ok('define_term', ['term' => 'self denial', 'source' => 'torrey']),
        'a space for a hyphen');
    assert_contains('[1] Faith · Torrey', ok('define_term', ['term' => 'FAITH!', 'source' => 'torrey', 'limit' => 1]));
}

function test_define_term_errors_and_odd_input(): void
{
    assert_same('No dictionary entry for "gr_ce". Try another spelling or form of the word (old spellings such as "sinne" '
        . 'are matched automatically, and "justif" finds every headword that begins with it), or a related word.',
        err('define_term', ['term' => 'gr_ce']), '_ is not a wildcard');
    assert_contains('No dictionary entry for "gr%"', err('define_term', ['term' => 'gr%', 'source' => 'hitchcock']));
    assert_contains('Other dictionaries have it: easton (1), torrey (1), wilson (2), wilson1661 (3); use source "all".',
        err('define_term', ['term' => 'grace', 'source' => 'hitchcock']));
    foreach (['%', '_', '\\', '%%', '***', '(grace)'] as $term) {
        assert_contains('term must contain letters or digits', err('define_term', ['term' => $term]), json_encode($term));
    }
    assert_contains('[1] Grace', ok('define_term', ['term' => "gr\x00ace"]), 'NUL bytes are dropped like punctuation');
    assert_contains('[1] Grace', ok('define_term', ['term' => "\u{FF27}\u{FF52}\u{FF41}\u{FF43}\u{FF45}"]), 'full-width letters');
    assert_contains('[1] Grace', ok('define_term', ['term' => '  GRACE (of God)!! ']));
    assert_same(str_replace('"a"', '"a%"', ok('define_term', ['term' => 'a'])), ok('define_term', ['term' => 'a%']),
        '% is dropped, not a wildcard');
    $cases = [
        [[], 'term is required'],
        [['term' => str_repeat('grace ', 17)], 'term is too long: at most 100 characters'],
        [['term' => 'grace', 'source' => 'webster'], 'source must be one of: all, easton, smith, hitchcock, nave, torrey, wilson, wilson1661, coles'],
        [['term' => 'grace', 'limit' => 0], 'limit must be from 1 to 20 (got 0)'],
        [['term' => 'grace', 'word' => 'x'], 'Unknown argument "word" for define_term. Accepted arguments: term, source, limit'],
    ];
    foreach ($cases as [$args, $want]) {
        assert_contains($want, err('define_term', $args));
    }
    assert_contains('[1] Grace · Torrey', ok('define_term', ['term' => 'grace', 'source' => 'TORREY']));
    assert_same('50\\%\\_\\\\x%', Store::likePrefix('50%_\\x'));
    assert_same('grace%', Store::likePrefix('grace'));
}

// ------------------------------------------------------------------- review fixes (2026-11-08)

function test_a_list_after_a_range_is_not_read_as_a_range_across_chapters(): void
{
    // "C:V-V, V" used to be read as C:V-V:V ("Matt 5:3-10, 12" gave Matthew 5:3-10:12); Nave writes such lists
    foreach (['Matt 5:3-10, 12', 'Ex 4:14-16,27', 'Gen 1:1-2, 3', 'Rom 8:28-30, 35', 'Ge 4:1-15,25', 'Nu 14:2-5,10',
              '1Ch 6:3-15,50'] as $input) {
        $want = 'Give one reference or one range per call ("' . $input . '")';
        assert_contains($want, err('read_scripture', ['reference' => $input]), $input);
        assert_contains($want, err('find_scripture_citations', ['reference' => $input]), $input);
    }
    $bible = bible();
    foreach (['Rom 8:38-9:5', 'Rom 8:38-9.5', 'Rom 8.38-9.5', 'Rom 8, 38-9:5'] as $input) {
        assert_same('Romans 8:38-9:5', $bible->label($bible->reference($input, ['bsb'])), $input);
    }
    assert_same('John 3:16', $bible->label($bible->reference('John 3, 16', ['bsb'])), 'a comma after the chapter still works');
}

function test_topical_bible_abbreviations_are_book_names(): void
{
    $bible = bible();
    foreach (['Mr 14:36' => 'Mark 14:36', 'Zec 13:1' => 'Zechariah 13:1', 'Es 1:10' => 'Esther 1:10',
              'So 5:3' => 'Song of Solomon 5:3', 'Joe 1:19' => 'Joel 1:19', 'Jdj 4:3' => 'Judges 4:3'] as $input => $want) {
        $p = $bible->parse($input);
        assert_same($want, $bible->name($p['book']) . " {$p['c1']}:{$p['v1']}", $input);
    }
    // every "Book c:v" abbreviation in the text of Nave's and Torrey's entries reads as a book
    $dirs = array_filter([XREF, is_file(SAMPLE . '/library.sqlite') ? realpath(SAMPLE) : null]);
    foreach ($dirs as $dir) {
        $db = new \PDO('sqlite:' . $dir . '/library.sqlite', null, null, [\ColLibrary\Store::readOnlyFlag() => \ColLibrary\Store::readOnlyValue()]);
        $seen = [];
        foreach ($db->query("SELECT text FROM dictionary WHERE source IN ('nave', 'torrey')")->fetchAll(\PDO::FETCH_NUM) as [$blob]) {
            preg_match_all('/(?<![\p{L}\d.])((?:[123] ?)?\p{Lu}\p{Ll}{0,3})\.? \d{1,3}:\d/u', (string) gzuncompress($blob), $m);
            foreach ($m[1] as $abbr) {
                $seen[$abbr] = true;
            }
        }
        assert_true(count($seen) > 60 && isset($seen['Mr'], $seen['Zec'], $seen['Jdj']), 'abbreviations found: ' . count($seen));
        $bad = [];
        foreach (array_keys($seen) as $abbr) {
            try {
                $bible->parse("$abbr 1:1");
            } catch (ArgumentException) {
                $bad[] = $abbr;
            }
        }
        assert_same([], $bad, "abbreviations not read as books ($dir)");
    }
}

function test_citation_errors_name_no_translation_argument(): void
{
    // find_scripture_citations has no translation argument: it says that citations use the KJV's numbers
    assert_same('Psalm 23 has 6 verses; Psalm 23:7 is only in the Douay-Rheims. Citations are indexed by the chapter and '
        . 'verse numbers of the KJV.', err('find_scripture_citations', ['reference' => 'Psalm 23:7']));
    assert_same('Daniel has 12 chapters; Daniel 14 is only in the Douay-Rheims. Citations are indexed by the chapter and '
        . 'verse numbers of the KJV.', err('find_scripture_citations', ['reference' => 'Dan 14:42']));
    foreach (['Psalm 23:7', 'Dan 14:42', 'Matt 17:22-21'] as $input) {
        assert_not_contains('translation', strtolower(str_replace('translations', '', err('find_scripture_citations',
            ['reference' => $input]))), $input);
    }
    // read_scripture does have it
    assert_contains('Daniel 14 is in the Douay-Rheims (translation "drb")', err('read_scripture', ['reference' => 'Dan 14:42']));
}

function test_douay_rheims_psalms_point_to_the_kjv_numbers(): void
{
    $drb = ok('read_scripture', ['reference' => 'Ps 22:1-2', 'translation' => 'drb']);
    assert_contains('For passages in the library that cite this, use find_scripture_citations with the KJV\'s numbering: '
        . '"Psalm 23".', $drb);
    assert_contains("For passages in the library that cite this, use find_scripture_citations.",
        ok('read_scripture', ['reference' => 'Ps 22:1-2']), 'other translations: the reference itself');
    $cases = [1 => [1, 1], 8 => [8, 8], 9 => [9, 10], 10 => [11, 11], 22 => [23, 23], 112 => [113, 113], 113 => [114, 115],
              114 => [116, 116], 115 => [116, 116], 116 => [117, 117], 145 => [146, 146], 146 => [147, 147],
              147 => [147, 147], 148 => [148, 148], 150 => [150, 150]];
    foreach ($cases as $drbPsalm => $kjv) {
        assert_same($kjv, Bible::kjvPsalms($drbPsalm), "Douay-Rheims Psalm $drbPsalm");
    }
}

function test_book_names_with_more_words_or_without_their_number(): void
{
    $bible = bible();
    foreach (['Song Sol 2:4' => 'Song of Solomon 2:4', 'Revelation of John 1:1' => 'Revelation 1:1',
              'Revelation of St. John 1:1' => 'Revelation 1:1', 'Pr Azar 5' => 'Prayer of Azariah 5'] as $input => $want) {
        assert_same($want, $bible->label($bible->reference($input, ['bsb', 'drb'])), $input);
    }
    foreach (['Pr Man 1:1' => 76, 'Prayer of Manasses 1' => 76, 'Song of the Three 1' => 73, 'Sg 1:1' => 22, 'Tb 1:1' => 67,
              'Jth 1:1' => 68, 'Ben Sira 1:1' => 71] as $input => $book) {
        assert_same($book, $bible->parse($input)['book'], $input);
    }
    $cases = [
        // more words than the alias that matched: an unknown book, not "one reference per call"
        ['Revelation of Jesus 1:1', 'There is no book "Revelation of Jesus" in the Bible. Did you mean Revelation?'],
        ['Song Solomons 2:4', 'There is no book "Song Solomons" in the Bible. Did you mean Song of Solomon?'],
        // a book name without its number: the numbered books that begin so
        ['Cor. 13. 4', 'There is no book "Cor." in the Bible. Did you mean 1 Corinthians or 2 Corinthians?'],
        ['Tim. 3. 16', 'Did you mean 1 Timothy or 2 Timothy?'],
        ['Sam. 15. 22', 'Did you mean 1 Samuel or 2 Samuel?'],
        ['Machab. 1. 1', 'Did you mean 1 Maccabees or 2 Maccabees?'],
        ['Jude ver. 3', 'Could not read the reference "Jude ver. 3".'],
        // the Additions to Esther are Esther 10:4-16:24 in the KJV and Douay-Rheims
        ['Additions to Esther 11:2', 'The Additions to Esther are not a separate book in the library\'s Bibles: the KJV and '
            . 'Douay-Rheims have them as chapters 10:4-16:24 of Esther. Ask for "Esther 11:2" with translation "kjv" or "drb".'],
        ['Rest of Esther 12', 'Ask for "Esther 12" with translation "kjv" or "drb".'],
        ['Additions to Esther 10:2', 'Ask for "Esther 10:4" with translation "kjv" or "drb".'],
        ['Additions to Esther 1:1', 'Ask for "Esther 11" with translation "kjv" or "drb".'],
    ];
    foreach ($cases as [$input, $want]) {
        assert_contains($want, err('read_scripture', ['reference' => $input]), $input);
    }
    assert_contains('Ask for "Esther 11:2".', err('read_scripture', ['reference' => 'Add Esth 11:2', 'translation' => 'kjv']));
    assert_contains('of Esther. Ask for "Esther 11:2".', err('find_scripture_citations', ['reference' => 'Additions to Esther 11:2']));
}

function test_the_count_for_a_verse_leaves_out_citations_of_its_chapter(): void
{
    $none = ok('find_scripture_citations', ['reference' => 'Rom 8:33']);
    assert_contains("\n\nNo passage cites Romans 8:33 itself; 4 passages across 3 works cite Romans 8 as a whole (1 of them in "
        . 'margin notes). Passages 1-4,', $none);
    // a whole chapter: every citation in it counts
    assert_contains('Cited in 16 passages across 4 works (8 of them in margin notes); 3 of them cite only Romans 8 as a '
        . 'whole', ok('find_scripture_citations', ['reference' => 'Rom 8']));
}

function test_a_single_dictionary_entry_is_shown_whole(): void
{
    // shortened among others, with the way to read it all; alone, whole
    $prefix = ok('define_term', ['term' => 'justif', 'limit' => 4]);
    assert_contains('repeat with source "wilson1661" and limit 1', $prefix);
    $alone = ok('define_term', ['term' => 'justif', 'source' => 'wilson1661', 'limit' => 1]);
    assert_contains('[1] Justifie · Thomas Wilson, A Complete Christian Dictionary (1661)', $alone);
    assert_not_contains('Entry shortened', $alone);
    $text = substr($alone, strpos($alone, "work_id 2815\n") + strlen("work_id 2815\n"));
    assert_same(7978, mb_strlen(explode("\n\nSearch notes:", $text)[0]), 'the whole entry');
    // not the first of its source here: looked up by its own headword
    $a = ok('define_term', ['term' => 'a', 'limit' => 5]);
    assert_contains("(Entry shortened to 4,000 of its 5,591 characters; to read all of it, look up \"Aaron\" with source "
        . "\"easton\" and limit 1.)", $a);
    $aaron = ok('define_term', ['term' => 'Aaron', 'source' => 'easton', 'limit' => 1]);
    assert_contains('[1] Aaron · Easton', $aaron);
    assert_not_contains('Entry shortened', $aaron);
    assert_true(mb_strlen($aaron) > 5591, 'whole');
}

function test_citation_paging_stops_at_the_offset_limit(): void
{
    $work = ['id' => 1, 'author' => 'A', 'title' => 'T', 'year' => 1650, 'source_url' => '', 'full_title' => 'T',
             'collection' => 'ccel', 'license' => '', 'type' => 'book'];
    $page = static function (int $offset) use ($work): string {
        $hits = [];
        for ($i = 0; $i < 20; $i++) {
            $hits[] = ['id' => 100000 + $i, 'work_id' => 1, 'seq' => $i, 'page_start' => null, 'page_end' => null,
                       'section' => '', 'text' => 'x', 'notes' => [], 'work' => $work, 'cites' => 'Rom 8:28', 'in_note' => false];
        }
        return Format::citations(new CitationResult('Romans 8', '', [], $hits, 9000, 900, 0, 0, 'Romans 8', $offset, false, []));
    };
    assert_contains('More passages cite Romans 8: repeat with offset 5000 for the next ones.', $page(4980));
    $last = $page(5000);
    assert_not_contains('repeat with offset 5020', $last);
    assert_contains('More passages cite Romans 8, beyond the largest offset (5,000): narrow the reference to fewer verses, '
        . 'or filter by author, work_id, collection or years.', $last);
    assert_same(Citations::MAX_OFFSET, array_column(Tools::definitions(), null, 'name')['find_scripture_citations']
        ['inputSchema']['properties']['offset']['maximum']);
}

function test_citation_grouping_keeps_little_per_passage(): void
{
    // 20,000 citations of Romans 8 in 20,000 passages: grouping, ordering and the labels of one page
    $rows = [];
    $years = [];
    for ($i = 0; $i < Citations::CAP; $i++) {
        $work = 1000 + $i % 1800;
        $rows[] = [8, $work * Store::SEQ_MULT + intdiv($i, 1800), $work, $i % 12 === 0 ? null : 1 + $i % 39, null, $i % 4 === 0 ? 1 : 0];
        $years[$work] = 1500 + $work % 400;
    }
    gc_collect_cycles();
    $before = memory_get_usage();
    $by = Citations::byPassage($rows);
    $order = Citations::order($by, $years);
    $cites = Citations::cites($rows, $by, array_slice($order, 0, 20), new BibleRef(45, 8, null, 8, null));
    $used = memory_get_usage() - $before;
    assert_same(Citations::CAP, count($by));
    assert_same(20, count($cites));
    assert_true($used < 12 * 1024 * 1024, sprintf('%.1f MB', $used / 1048576));
}

function test_control_and_format_characters_in_input(): void
{
    $nul = ok('define_term', ['term' => "gr\x00ace"]);
    assert_true(str_starts_with($nul, 'Dictionary entries for "grace":'), strtok($nul, "\n"));
    $esc = err('define_term', ['term' => "\x1b[2J\u{202E}zzqx"]);
    assert_same('No dictionary entry for "[2Jzzqx".', substr($esc, 0, strlen('No dictionary entry for "[2Jzzqx".')));
    assert_false((bool) preg_match('/[\x00-\x1F\x7F]|\x{202E}/u', str_replace("\n", '', $esc)), 'no control characters echoed');
    assert_contains('John 3:16 (BSB)', ok('read_scripture', ['reference' => "\u{2068}John 3:16\u{2069}"]));
    assert_contains('John 3:16 (BSB)', ok('read_scripture', ['reference' => "\u{202B}John 3:16\u{202C}"]));
    assert_contains('John 3:16 (BSB)', ok('read_scripture', ['reference' => 'John 3:16;']));
    assert_contains('John 3:16 (BSB)', ok('read_scripture', ['reference' => 'John 3:16, ']));
    assert_contains('Give one reference per call', err('read_scripture', ['reference' => 'John 3:16; 3:17']));
    assert_same('grace', \ColLibrary\Text::oneLine("\u{FEFF}gr\u{00AD}a\x00ce\u{200F}"));
}

function test_nave_headwords_in_title_case(): void
{
    foreach (['JESUS, THE CHRIST' => 'Jesus, the Christ', 'MINISTER, Christian' => 'Minister, Christian',
              'ANGEL (a spirit)' => 'Angel (a spirit)', 'Love of God, The' => 'Love of God, The', 'GOD' => 'God',
              'Faith' => 'Faith'] as $head => $want) {
        assert_same($want, Bible::topicName($head), $head);
    }
}

function test_citation_wording_promises_no_more_than_the_index(): void
{
    $cite = array_column(Tools::definitions(), null, 'name')['find_scripture_citations']['description'];
    assert_not_contains('any style', $cite);
    assert_contains('a verse given only by its number ("ver. 30") is not matched, so the counts are a lower bound', $cite);
    assert_contains('but not a verse given only by its number ("ver. 30")', err('find_scripture_citations', ['reference' => 'Gen 2']));
    // the dictionary forms looked up: no double spaces
    $forms = (new Dictionary(store()))->candidates('a - b');
    assert_same([], array_values(array_filter($forms, static fn (string $f): bool => str_contains($f, '  '))), json_encode($forms));
}

// ------------------------------------------------------------------------- tools and builds

function test_definitions_of_the_new_tools(): void
{
    $defs = array_column(Tools::definitions(), null, 'name');
    $read = $defs['read_scripture'];
    assert_same('Read Bible verses', $read['title']);
    assert_contains('Berean Standard Bible (default; a modern translation close to the ESV)', $read['description']);
    assert_contains('Douay-Rheims numbers the Psalms as the Vulgate does', $read['description']);
    assert_same(['reference', 'translation'], array_keys($read['inputSchema']['properties']));
    assert_same(['bsb', 'kjv', 'asv', 'drb', 'darby', 'webster', 'ylt'], $read['inputSchema']['properties']['translation']['enum']);
    assert_same('bsb', $read['inputSchema']['properties']['translation']['default']);
    assert_same(100, $read['inputSchema']['properties']['reference']['maxLength']);
    $cite = $defs['find_scripture_citations'];
    assert_same(['reference', 'author', 'work_id', 'collection', 'year_from', 'year_to', 'include_chapter_refs', 'limit',
                 'offset'], array_keys($cite['inputSchema']['properties']));
    assert_same(['type' => 'boolean', 'default' => true],
        array_intersect_key($cite['inputSchema']['properties']['include_chapter_refs'], ['type' => 1, 'default' => 1]));
    assert_same(5000, $cite['inputSchema']['properties']['offset']['maximum']);
    assert_contains('Rom. viii. 28, Rom. 8. 28, Romans 8:28', $cite['description']);
    $define = $defs['define_term'];
    assert_same(['all', 'easton', 'smith', 'hitchcock', 'nave', 'torrey', 'wilson', 'wilson1661', 'coles'],
        $define['inputSchema']['properties']['source']['enum']);
    assert_contains('"conversation" meaning manner of life', $define['description']);
    foreach ([$read, $cite, $define] as $d) {
        assert_same(['title' => $d['title'], 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true,
                     'openWorldHint' => false], $d['annotations']);
        assert_same(false, $d['inputSchema']['additionalProperties']);
        assert_same([array_key_first($d['inputSchema']['properties'])], $d['inputSchema']['required']);
        assert_true(strlen($d['description']) < 1200, 'concise: ' . strlen($d['description']));
        assert_true((bool) preg_match('/^[a-z_]{1,64}$/', $d['name']));
    }
}

function test_builds_without_the_tables(): void
{
    // the first builds had no cross-reference tables: a server-side problem, said plainly
    $dir = col_tmpdir('noxref');
    (new \PDO("sqlite:$dir/library.sqlite"))->exec('CREATE TABLE meta(key TEXT PRIMARY KEY, value TEXT)');
    foreach (['read_scripture' => ['reference' => 'John 3:16'], 'find_scripture_citations' => ['reference' => 'John 3:16'],
              'define_term' => ['term' => 'grace']] as $tool => $args) {
        $text = err($tool, $args, $dir);
        assert_contains('not available right now', $text, $tool);
    }
    assert_contains('the build has no cross-reference tables', col_error_log());
}

function test_mcp_round_trip_of_the_new_tools(): void
{
    col_src('Mcp');
    store();
    $mcp = new Mcp();
    $ctx = Fixture\context(['data_dir' => realpath(XREF)]);
    $post = function (array $msg, array $headers) use ($mcp, $ctx): array {
        $r = $mcp->handle('POST', $headers + ['Content-Type' => 'application/json'],
            json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $ctx);
        assert_same(200, $r->status, (string) $r->body);
        return json_decode((string) $r->body, true, 512, JSON_THROW_ON_ERROR);
    };
    $legacy = ['MCP-Protocol-Version' => '2025-11-25'];
    $list = $post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $legacy);
    assert_same(['search_library', 'read_passage', 'find_works', 'read_scripture', 'find_scripture_citations', 'define_term'],
        array_column($list['result']['tools'], 'name'));
    $r = $post(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
                'params' => ['name' => 'read_scripture', 'arguments' => ['reference' => 'John 3:16']]], $legacy);
    assert_false($r['result']['isError']);
    assert_contains("John 3:16 (BSB)\n16 For God so loved", $r['result']['content'][0]['text']);
    $r = $post(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
                'params' => ['name' => 'find_scripture_citations', 'arguments' => ['reference' => 'Rom 8:28', 'limit' => 2]]], $legacy);
    assert_contains('[1] P10100000', $r['result']['content'][0]['text']);

    $meta = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28',
             'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
             'io.modelcontextprotocol/clientInfo' => ['name' => 'tests', 'version' => '1']];
    foreach ([['define_term', ['term' => 'conversation', 'limit' => 1], '[1] Conversation · Easton'],
              ['read_scripture', ['reference' => 'Ps 23:1', 'translation' => 'kjv'], "Psalm 23:1 (KJV)\n1 The Lord is my shepherd"],
              ['find_scripture_citations', ['reference' => 'Jude 3'], '(cites Jude 3)']] as $i => [$tool, $args, $want]) {
        $modern = ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => $tool];
        $res = $post(['jsonrpc' => '2.0', 'id' => 10 + $i, 'method' => 'tools/call',
                      'params' => ['name' => $tool, 'arguments' => $args, '_meta' => $meta]], $modern);
        assert_same('complete', $res['result']['resultType'] ?? null);
        assert_false($res['result']['isError']);
        assert_contains($want, $res['result']['content'][0]['text']);
    }
}

/** The same tools against data/build/LATEST-sample (40 works, all seven Bibles, the full dictionaries). */
function test_sample_build(): void
{
    if (!is_file(SAMPLE . '/library.sqlite')) {
        col_skip('data/build/LATEST-sample not present');
    }
    $dir = realpath(SAMPLE);
    $john = ok('read_scripture', ['reference' => 'John 3:16'], $dir);
    assert_contains("John 3:16 (BSB)\n16 For God so loved the world that He gave His one and only Son", $john);
    assert_contains("Topics (Nave's, Torrey's): ", $john);
    $ps = ok('read_scripture', ['reference' => 'Ps 119', 'translation' => 'kjv'], $dir);
    assert_same(176, count(array_filter(explode("\n", $ps), static fn (string $l): bool => (bool) preg_match('/^\d+ /', $l))));
    assert_contains('Esther 11:2 (KJV)', ok('read_scripture', ['reference' => 'Esther 11:2', 'translation' => 'kjv'], $dir));

    $t0 = microtime(true);
    $rom = ok('find_scripture_citations', ['reference' => 'Rom 8:28', 'limit' => 20], $dir);
    assert_true(microtime(true) - $t0 < 5.0, 'quick enough');
    assert_true((bool) preg_match('/^Cited in (\d+) passages?; \d+ more cite Romans 8 as a whole and come after them \((\d+) '
        . 'passages across (\d+) works in all/m', $rom, $m), 'counts line');
    assert_true((int) $m[1] >= 10 && (int) $m[2] > (int) $m[1] && (int) $m[3] >= 4, "counts {$m[1]} / {$m[2]} / {$m[3]}");
    // round 1: one passage per work, years ascending, unknown years last
    preg_match_all('/^\[\d+\] P(\d+) · .*?\*(?: \((\d{3,4})\))? · /m', $rom, $mm, PREG_SET_ORDER);
    $round = [];
    foreach ($mm as $hit) {
        $work = intdiv((int) $hit[1], Store::SEQ_MULT);
        if (isset($round[$work])) {
            break;
        }
        $round[$work] = isset($hit[2]) && $hit[2] !== '' ? (int) $hit[2] : PHP_INT_MAX;
    }
    $years = array_values($round);
    $sorted = $years;
    sort($sorted);
    assert_same($sorted, $years, 'first round in year order');
    $chapter = ok('find_scripture_citations', ['reference' => 'Ps 119', 'include_chapter_refs' => true], $dir);
    assert_contains('Citations of Psalm 119 in the library', $chapter);

    $grace = ok('define_term', ['term' => 'grace', 'limit' => 20], $dir);
    assert_contains("[1] Grace · Easton's Bible Dictionary (1897) · work_id ", $grace);
    assert_contains('Thomas Wilson, A Christian Dictionarie (1612) · p. ', $grace);
    assert_contains('[1] Conuersation · Thomas Wilson', ok('define_term', ['term' => 'conuersation', 'source' => 'wilson'], $dir));
    // Coles's "Belive" (anon) and "Blive" (readily) were indexed as "believe": marked, not counted as the headword
    $believe = ok('define_term', ['term' => 'beleeue'], $dir);
    assert_true(str_starts_with($believe, 'Dictionary entries for "beleeue" (as "believe"): none with exactly this headword; 2 '
        . 'with another headword indexed under it (check the meaning)'), strtok($believe, "\n"));
    assert_contains('[1] Belive · Elisha Coles, An English Dictionary (1677) · work_id 1200 · indexed as "believe"', $believe);
    // the longest entries come whole when asked for alone
    $paul = ok('define_term', ['term' => 'Paul', 'source' => 'smith', 'limit' => 1], $dir);
    assert_not_contains('Entry shortened', $paul);
    assert_true(mb_strlen($paul) > 29000, (string) mb_strlen($paul));
    $jesus = ok('define_term', ['term' => 'Jesus', 'source' => 'nave', 'limit' => 1], $dir);
    assert_true(mb_strlen($jesus) > 50000 && mb_strlen($jesus) <= Format::MAX_CHARS, (string) mb_strlen($jesus));

    // define_term normalizes like the indexer: compare with the stored norm of the sample's headwords
    $db = new \PDO('sqlite:' . $dir . '/library.sqlite', null, null, [\ColLibrary\Store::readOnlyFlag() => \ColLibrary\Store::readOnlyValue()]);
    $rows = $db->query('SELECT headword, norm FROM dictionary WHERE id % 20 = 0')->fetchAll(\PDO::FETCH_NUM);
    $dict = new Dictionary(new Store($dir));
    $same = 0;
    foreach ($rows as [$head, $norm]) {
        $same += in_array($norm, $dict->candidates((string) $head), true) ? 1 : 0;
    }
    $share = $same / max(1, count($rows));
    // The indexer modernized headwords with its full rule set; the plugin has only the spelling table, so
    // some old forms (mostly in Coles and Wilson: "Fidelitie", "Vncertainly") are not found as typed.
    assert_true($share >= 0.95, sprintf('%.1f%% of %d headwords found by their own norm', 100 * $share, count($rows)));
}
