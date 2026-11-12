<?php
/**
 * Tests for src/Quote.php (check_quote) and Format::quoteCheck, through Tools::call on the fixture build
 * (old spelling, two editions of Owen, Augustine 1886), plus the alignment, dating and credit-line helpers.
 */

declare(strict_types=1);

namespace ColLibraryTest\Quote;

use ColLibrary\Quote;
use ColLibrary\Tools;
use ColLibraryTest\Fixture;

require_once __DIR__ . '/fixture/testlib.php';
col_src('Quote');

function call(array $args): array
{
    Fixture\store_or_skip();
    return Tools::call('check_quote', $args, Fixture\context());
}

function ok(array $args): string
{
    $r = call($args);
    $text = Fixture\text_of($r);
    assert_false($r['isError'], 'check_quote should succeed: ' . $text);
    return $text;
}

function err(array $args): string
{
    $r = call($args);
    $text = Fixture\text_of($r);
    assert_true($r['isError'], 'check_quote should fail: ' . $text);
    return $text;
}

function test_word_for_word_in_old_spelling(): void
{
    $text = ok(['quote' => 'Be killing sin, or it will be killing you.', 'author' => 'John Owen']);
    assert_contains('Quote check: "Be killing sin, or it will be killing you." (credited to John Owen)', $text);
    assert_contains('FOUND WORD FOR WORD: these words are in John Owen (1616–1683), *Of the Mortification of Sinne in '
        . 'Beleevers* (1668), p. 1.', $text, 'the earliest edition, with the page');
    assert_contains("The library's wording: \"be killing ſinne or it will be killing you\"", $text, 'original spelling');
    assert_contains('In context: "', $text);
    assert_contains('**be killing ſinne or it will be killing you**', $text, 'the words marked in their context');
    assert_contains('whilſt you liue; ceaſe not a day from this worke;', $text, 'the sentence before');
    assert_contains('Your being dead with Chriſt', $text, 'the sentence after');
    assert_contains('Copied from: https://quod.lib.umich.edu/e/eebo/A53698.0001.001', $text);
    assert_contains("Other works in the library with these words (earliest first):\n- John Owen (1616–1683), *Of the "
        . 'Mortification of Sin in Believers* (year not known) · P10200000 · word for word', $text, 'the other edition');
    assert_not_contains('EARLIER SOURCE', $text, 'same author');
}

function test_no_author_names_the_earliest(): void
{
    $text = ok(['quote' => 'be killing sin or it will be killing you']);
    assert_contains('FOUND WORD FOR WORD: these words are in John Owen (1616–1683), *Of the Mortification of Sinne', $text);
    assert_not_contains('credited to', $text);
}

function test_found_but_by_someone_else(): void
{
    $text = ok(['quote' => 'be killing sin or it will be killing you', 'author' => 'Augustine']);
    assert_contains('FOUND, BUT NOT BY Augustine: the earliest work in the library with these words is John Owen '
        . '(1616–1683), *Of the Mortification of Sinne in Beleevers* (1668), p. 1. None of the library\'s 1 work by '
        . 'Augustine has them', $text);
    assert_contains('the credit to Augustine is not confirmed', $text);
    $absent = ok(['quote' => 'be killing sin or it will be killing you', 'author' => 'C. H. Spurgeon']);
    assert_contains('FOUND, BUT NOT BY C. H. Spurgeon', $absent);
    assert_contains('The library has no works by C. H. Spurgeon, so it cannot say whether he also used these words.',
        $absent);
}

function test_worded_differently_shows_both_with_the_differences(): void
{
    $text = ok(['quote' => 'Thou hast made us for thyself, and our hearts are restless until they rest in thee',
                'author' => 'St. Augustine']);
    assert_contains('FOUND, BUT WORDED DIFFERENTLY: the closest wording is in Augustine (354–430), *The Confessions* '
        . '(1886), p. 45: 12 of the 16 words given are there in the same order.', $text);
    assert_contains("The library's wording: \"Thou hast made us for Thyself, and our **heart** **is** restless, until "
        . '**it** **repose** in Thee"', $text);
    assert_contains('The wording given:     "Thou hast made us for thyself, and our **hearts** **are** restless until '
        . '**they** **rest** in thee"', $text);
    assert_contains('Differences (given vs library): "hearts are" for "heart is"; "they rest" for "it repose"', $text);
    assert_contains('Quote the library\'s wording, not the version given.', $text);
}

function test_not_found_says_it_proves_nothing(): void
{
    $text = ok(['quote' => 'The quick brown fox jumps over the lazy dog', 'author' => 'Spurgeon']);
    assert_contains('NOT FOUND: no passage in the library has these words', $text);
    assert_contains('This does not show that the quotation is false', $text);
    assert_contains('Do not quote or cite it from memory', $text);
    assert_contains('The library has no works by Spurgeon.', $text);
    $owen = ok(['quote' => 'Sin is the greatest evil in all the wide world', 'author' => 'Owen']);
    assert_contains('The library has 2 works by Owen; it holds only some of this author\'s writings.', $owen);
}

function test_gaps_and_quotes_across_passages(): void
{
    $gap = ok(['quote' => 'Great art Thou, O Lord ... for Thou hast made us for Thyself, and our heart is restless',
               'author' => 'Augustine']);
    assert_contains('FOUND WORD FOR WORD', $gap, '"..." skips words');
    assert_contains("The library's wording: \"Great art Thou, O Lord, and greatly to be praised;", $gap);

    $across = ok(['quote' => 'to mortifie the deeds of the body. The Reader may observe that I have not handled every '
        . 'question', 'author' => 'Owen']);
    assert_contains('FOUND WORD FOR WORD', $across, 'a quotation over two passages');
    assert_contains('mortifie the deeds of the body.', $across);
    assert_contains('The Reader may obſerve that I haue not handled euery queſtion', $across);
}

function test_argument_errors(): void
{
    assert_contains('quote is required', err([]));
    assert_contains('too short to check', err(['quote' => 'killing sin']));
    assert_contains('too long to check', err(['quote' => str_repeat('sin and grace ', 60)]));
    assert_contains('at most 1500 characters', err(['quote' => str_repeat('a', 1501)]));
    assert_contains('Unknown argument', err(['quote' => 'be killing sin or it', 'mode' => 'exact']));
}

function test_definition(): void
{
    $defs = array_column(Tools::definitions(), null, 'name');
    $d = $defs['check_quote'];
    assert_same(['quote', 'author'], array_keys($d['inputSchema']['properties']));
    assert_same(['quote'], $d['inputSchema']['required']);
    foreach (['FOUND WORD FOR WORD', 'FOUND, BUT WORDED DIFFERENTLY', 'FOUND, BUT NOT BY', 'NOT FOUND'] as $answer) {
        assert_contains($answer, $d['description']);
    }
}

// --------------------------------------------------------------------------------- helpers

function test_align(): void
{
    $r = Quote::align([1, 2, 3], [], [9, 1, 2, 3, 9]);
    assert_same([0, 3, 1, 3], [$r['edits'], $r['same'], $r['from'], $r['to']]);
    $r = Quote::align([1, 2, 3, 4], [], [1, 2, 8, 3, 4]);
    assert_same([1, 4], [$r['edits'], $r['same']], 'one word only in the passage');
    assert_same(['=', '=', '-', '=', '='], array_column($r['ops'], 0));
    $r = Quote::align([1, 5, 3], [], [1, 2, 3]);
    assert_same(['=', '~', '='], array_column($r['ops'], 0));
    $r = Quote::align([1, 2, 3, 4], [2 => true], [1, 2, 7, 7, 7, 3, 4]);
    assert_same([0, 4, 0, 6], [$r['edits'], $r['same'], $r['from'], $r['to']], 'a "..." gap is free');
    // Of two stretches with as many edits, the one keeping more words: "In the altering of" for "In altering".
    $r = Quote::align([1, 2, 3], [], [1, 7, 2, 8, 3]);
    assert_same([2, 3], [$r['edits'], $r['same']]);
}

function test_when(): void
{
    assert_same(1668, Quote::when(['year' => 1668, 'author_note' => '1616–1683, English Puritan']));
    assert_same(430, Quote::when(['year' => 1886, 'author_note' => '354–430, bishop of Hippo']), 'later translation');
    assert_same(1683, Quote::when(['year' => null, 'author_note' => '1616–1683, English Puritan']));
    assert_same(-348, Quote::when(['year' => null, 'author_note' => 'c. 428–348 BC, Greek philosopher']));
    assert_same(1617, Quote::when(['year' => 1650, 'author_note' => 'd. 1617, English divine']));
    assert_same(1640, Quote::when(['year' => null, 'author_note' => 'fl. 1640s, English preacher']));
    assert_same(9999, Quote::when(['year' => null, 'author_note' => '']));
}

function test_credit_lines(): void
{
    $spurgeon = ['author' => 'Charles Spurgeon', 'author_slug' => 'charles-spurgeon'];
    assert_same('Thomas Adams', Quote::creditAfter(', and make it seem new. They pass, they do not perish ; the metal '
        . "stays. — Thomas Adams.", $spurgeon));
    assert_same('John Colquhoun', Quote::creditAfter(". The most exquisite torment, \" My God, my God! \" —\nJohn "
        . 'Colquhoun, in " A Treatise on Spiritual Comfort," 1814.', $spurgeon));
    assert_same('Bishop Hall', Quote::creditAfter(' is the end. — Bishop Hall.', $spurgeon));
    assert_same(null, Quote::creditAfter(' is the end. — C. H. S.', $spurgeon), 'initials: the compiler');
    assert_same(null, Quote::creditAfter(' is the end. — Spurgeon.', $spurgeon), 'the work\'s own author');
    assert_same(null, Quote::creditAfter(' is the end. — That if death were to come upon him, he should sink for ever '
        . 'and ever under the wrath of the infinite Jehovah, and know no comfort in it at all.', $spurgeon));
    assert_same(null, Quote::creditAfter(" is the end.\n\nVerse 7. — Thomas Goodwin.", $spurgeon), 'another paragraph');
    assert_same(null, Quote::creditAfter(' is the end. — Amen.', $spurgeon));
}

function test_author_words(): void
{
    assert_same(['spurgeon'], Quote::authorWords('C. H. Spurgeon'));
    assert_same(['augustine'], Quote::authorWords('St. Augustine'));
    assert_same(['john', 'owen'], Quote::authorWords('John Owen'));
    assert_true(Quote::byAuthor(['author' => 'Charles Spurgeon', 'author_slug' => 'charles-spurgeon'], ['spurgeon']));
    assert_true(Quote::byAuthor(['author' => 'Théodore de Bèze', 'author_slug' => 'theodore-de-beze'], ['beze']));
    assert_false(Quote::byAuthor(['author' => 'John Owen', 'author_slug' => 'john-owen'], ['john', 'flavel']));
    assert_false(Quote::byAuthor(['author' => 'John Owen', 'author_slug' => 'john-owen'], []));
}

function test_ocr_misreadings(): void
{
    assert_true(Quote::misread('his', 'liis'));
    assert_true(Quote::misread('the', 'tbe'));
    assert_true(Quote::misread('righteousness', 'rigbteousness'));
    assert_false(Quote::misread('sin', 'sun') === false, 'one letter in a short word is allowed');
    assert_false(Quote::misread('of', 'or'), 'two-letter words are never treated as misread');
    assert_false(Quote::misread('rests', 'repose'));
    assert_false(Quote::misread('heart', 'hearts') === false);
}

function test_numbers_in_the_quotation(): void
{
    $text = ok(['quote' => 'Rom. 8. 13. If ye through the Spirit doe mortifie the deeds of the body', 'author' => 'Owen']);
    assert_contains('Quote check:', $text);
    ok(['quote' => 'In the year 1668 he wrote 2 books of 300 pages']);
}
