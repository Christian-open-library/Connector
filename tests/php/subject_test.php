<?php
/**
 * Tests for src/Subject.php (find_scripture_citations: passages that treat the reference as their subject
 * come first) and Citations::order with those levels.
 */

declare(strict_types=1);

use ColLibrary\Citations;
use ColLibrary\Subject;

require_once __DIR__ . '/fixture/testlib.php';

const BOOKS = [
    1 => ['name' => 'Genesis', 'short' => 'Gen', 'aliases' => ['gen', 'genesis', 'gn']],
    4 => ['name' => 'Numbers', 'short' => 'Num', 'aliases' => ['num', 'numb', 'numbers']],
    19 => ['name' => 'Psalms', 'short' => 'Ps', 'aliases' => ['ps', 'psa', 'psal', 'psalm', 'psalme', 'psalmes', 'psalms']],
    43 => ['name' => 'John', 'short' => 'John', 'aliases' => ['ioh', 'iohn', 'joh', 'john']],
    45 => ['name' => 'Romans', 'short' => 'Rom', 'aliases' => ['rom', 'romans']],
    49 => ['name' => 'Ephesians', 'short' => 'Eph', 'aliases' => ['eph', 'ephes', 'ephesians']],
    62 => ['name' => '1 John', 'short' => '', 'aliases' => ['1 john', '1john', 'first john', 'i john']],
    44 => ['name' => 'Acts', 'short' => 'Acts', 'aliases' => ['act', 'acts']],
    66 => ['name' => 'Revelation', 'short' => 'Rev', 'aliases' => ['apoc', 'apocalypse', 'rev', 'revelation']],
];

function subject(int $book, int $chapter): Subject
{
    return new Subject(BOOKS, $book, $chapter, $chapter);
}

function work_level(Subject $s, string $title, string $type = 'book'): int
{
    return $s->works([1 => ['title' => $title, 'type' => $type]])[1];
}

function test_headings_that_name_the_chapter(): void
{
    $rom = subject(45, 8);
    assert_same(2, $rom->passage('Homily XV on Rom. viii. 28.', 0));
    assert_same(2, $rom->passage('Lecture LIX — Romans 8:28', 0));
    assert_same(2, $rom->passage('SERMONS › Romans 8:26-30', 0), 'a later part of the heading');
    assert_same(1, $rom->passage("ROMANS. › MDCCCXLI. THE believer's SECURITY IN CHRIST.", 0), 'the book alone');
    assert_same(2, $rom->passage('CHAPTER VIII.', 1), '"Chapter 8" in a work on the book');
    assert_same(0, $rom->passage('CHAPTER VIII.', 0), '... but not in any work');
    assert_same(0, $rom->passage('CHAPTER III.', 1), 'another chapter of a work on the book: only cited there');
    assert_same(0, $rom->passage('Homily XVI on Rom. ix. 1.', 1), 'a heading of another chapter');
    assert_same(1, $rom->passage('', 1), 'no heading: the work\'s level');
    assert_same(0, subject(49, 2)->passage('EPHESIANS › EPHESIANS - Chapter 1 › EPHESIANS - Chapter 1 - Verse 2', 1));
    assert_same(2, subject(49, 2)->passage('EPHESIANS › EPHESIANS - Chapter 2 › EPHESIANS - Chapter 2 - Verse 8', 0));
    $ps = subject(19, 23);
    assert_same(2, $ps->passage('PSALM XXIII.', 0));
    assert_same(0, $ps->passage('ANNOTATIONS VPON THE BOOKE OF PSALMES. › Annotations, Pſalm. V.', 1));
    assert_same(0, $ps->passage('THE FOVRTH BOOKE OF MOSES, CALLED NVMBERS. › CHAP. XXIII. › Annotations.', 1),
        'chapter 23 of another book');
    assert_same(0, $ps->passage('THE FIRST BOOKE OF MOSES CALLED GENESIS. › CHAP. XXIX.', 1));
    assert_same(0, subject(43, 3)->passage('1 JOHN 3', 0), '"1 John" is not John');
    assert_same(2, subject(62, 3)->passage('I. John iii.', 0));
}

function test_works_on_the_book(): void
{
    $rom = subject(45, 8);
    assert_same(1, work_level($rom, 'Commentary on Romans'));
    assert_same(1, work_level($rom, "Lange's Commentary on the Holy Scriptures - Romans", 'commentary'));
    assert_same(1, work_level($rom, 'Exposition of St. Paul\'s Epistle to the Romans, Volume 2'));
    assert_same(0, work_level($rom, 'Rhemes Against Rome'));
    assert_same(2, work_level($rom, 'Sermons upon Romans viii'), 'title names the chapter');
    $john = subject(43, 3);
    assert_same(1, work_level($john, 'Homilies on the Gospel of John'));
    assert_same(1, work_level($john, 'Commentary on John - Volume 1'));
    assert_same(0, work_level($john, 'The Works of John Owen (ed. Goold), Vol. 20 An Exposition of the Epistle to the Hebrews'),
        'a person named John');
    assert_same(0, work_level($john, 'Critical and Exegetical Handbook to the Revelation of John (Meyer series)', 'commentary'));
    assert_same(0, work_level($john, 'Believers Evidences for Eternall Life Collected Out of the First Epistle of John'));
    assert_same(0, work_level($john, "Sixteen Sermons Concerning the Lord's Prayer in John xvii. 24"), 'on another chapter');
    $ps = subject(19, 23);
    assert_same(0, work_level($ps, 'Practical Exposition upon Psalm CXXX'));
    assert_same(0, work_level($ps, 'The Treasury of David, Volume 2 (Psalms 27-57)'), 'a range without the chapter');
    assert_same(2, work_level(subject(19, 51), 'The Treasury of David, Volume 2 (Psalms 27-57)'), 'a range with it');
    assert_same(1, work_level($ps, 'Annotations upon the Five Bookes of Moses, the Booke of the Psalmes'));
    assert_same(0, work_level(subject(44, 2), 'Acts of Synod Wyoming (2016)'), 'no such book in this list, and no title word');
    assert_same(0, work_level($rom, 'The Philosophy of Romans'), 'no commentary word');
    assert_same(1, work_level($rom, 'Popular Commentary on the New Testament, Vol. 3 (Acts-Romans)'), 'another book, a dash');
    $rev = subject(66, 3);
    assert_same(0, work_level($rev, 'A Sermon on the Death of the Rev. Mr. George Whitfield'), '"Rev." before a name');
    assert_same(0, work_level($rev, 'Gospel-revelation in Three Treatises'));
    assert_same(1, work_level($rev, 'Lange\'s Commentary on the Holy Scriptures - Revelation'));
    assert_same(0, work_level($john, 'Apocalypsis A Briefe and Learned Commentarie vpon the Reuelation of Saint Iohn'));
    assert_same(2, work_level(subject(43, 9), 'Expositions of Holy Scripture St John Ch. I to XIV'), 'a range with "to"');
    assert_same(0, work_level(subject(62, 1), 'Commentary on the Gospel according to S. John, Volume 1, John I-VIII'),
        '"Volume 1, John" is not 1 John');
    assert_same(1, work_level($rom, 'The Philosophy of Romans', 'commentary'), 'filed as a commentary');
}

function test_order_puts_the_passages_on_the_text_first(): void
{
    // works: 1 (1534, a confession), 2 (1746, a commentary), 3 (1836, lectures); passages 10.. by work
    $p = [10 => ['work_id' => 1, 'group' => 1], 11 => ['work_id' => 1, 'group' => 1],
          20 => ['work_id' => 2, 'group' => 1], 21 => ['work_id' => 2, 'group' => 1],
          30 => ['work_id' => 3, 'group' => 1], 40 => ['work_id' => 1, 'group' => 2]];
    $years = [1 => 1534, 2 => 1746, 3 => 1836];
    assert_same([10, 20, 30, 11, 21, 40], Citations::order($p, $years), 'no levels: earliest works first, as before');
    assert_same([30, 20, 21, 10, 11, 40], Citations::order($p, $years, [], [30 => 2, 20 => 1, 21 => 1]),
        'level 2, then 1, then the rest; whole-chapter citations still last');
    assert_same([30, 20, 10, 21, 11, 40], Citations::order($p, $years, [], [30 => 2, 20 => 1]),
        'a work\'s other passages go with the rest, one per work in turn');
    assert_same([11, 10, 30, 20, 21, 40], Citations::order($p, $years, [1 => true], [30 => 2, 20 => 1, 11 => 1]),
        'own present-day documents still first (on the text first among them)');
}
