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

function test_retellings(): void
{
    $writers = Quote::writerKeys([['author' => 'Augustine'], ['author' => 'Ambrose'], ['author' => 'Thomas Brooks'],
        ['author' => 'Thomas a Kempis'], ['author' => 'Bernard of Clairvaux'], ['author' => 'Church of Scotland'],
        ['author' => 'Christopher Love']]);
    assert_true(isset($writers['augustine'], $writers['bernard'], $writers['kempis']));
    assert_false(isset($writers['church']) || isset($writers['love']) || isset($writers['scotland']));
    $same = static fn (array $w): array => $w;
    $stems = static fn (string $q): array => Quote::queryStems(\ColLibrary\Text::words(\ColLibrary\Text::normalize($q)));
    $spurgeon = ['author' => 'Charles Spurgeon', 'author_slug' => 'charles-spurgeon'];
    $tolle = $stems('Who heard a child singing a refrain that told him to pick up the book, opened Paul\'s letter, and was converted?');

    $r = Quote::retold('Remember the story of Augustine. To the grief of his dear mother he had been leading a wicked '
        . 'life; but as he walked in the garden he heard a little child say, "Take! Read!"', $spurgeon, $writers, $tolle, $same);
    assert_same('Augustine', $r['name'] ?? null, 'the story of Augustine');
    $r = Quote::retold('Saint Auſtine in his troubles of ſpirit, thought he heard a voice ſaying, Take up and read; '
        . 'he took up his Bible and was converted. So ſaith Saint Auſtine.', ['author' => 'Obadiah Sedgwick',
        'author_slug' => ''], $writers, $tolle, $same);
    assert_same('Augustine', $r['name'] ?? null, 'old spelling Austine');
    // The credit is about something else: no flag.
    assert_same(null, Quote::retold('Whereupon good Mr. Brooks observes, "It is sweeter than that which drops." '
        . str_repeat('Some words are plain and need no study at all. ', 12) . 'Some words of the Lord are as nuts that '
        . 'need cracking.', $spurgeon, $writers, $stems('the hardest texts are like nuts: the ones hardest to crack '
        . 'have the sweetest kernels'), $same));
    // A quotation after the credit is what is credited: Brooks on honey, not on nuts (q62, Only a Prayer-Meeting).
    assert_same(null, Quote::retold('Whereupon good Mr. Brooks observes, "It is sweeter than that which drops '
        . 'immediately and naturally, the purest and the sweetest honey." There are texts of Scripture which are '
        . 'exceedingly sweet. Some words are as nuts that need cracking.', $spurgeon, $writers + ['latimer' => 'Hugh Latimer'],
        $stems('the hardest texts are like nuts: the ones hardest to crack have the sweetest kernels'), $same));
    // A history recording what a martyr said is the source, not a retelling.
    assert_same(null, Quote::retold('Then Mr. Latimer said, Be of good comfort, Master Ridley, and play the man: we shall '
        . 'this day light such a candle in England as shall never be put out.', ['author' => 'John Foxe', 'author_slug' => ''],
        $writers + ['latimer' => 'Hugh Latimer'], $stems('A martyr at the stake told his fellow prisoner they would light a '
        . 'candle in England never to be put out'), $same));
    // The work's own author, and a writer the library does not hold.
    assert_same(null, Quote::retold('Saint Augustine writes of himself that he heard a child and was converted.',
        ['author' => 'Augustine', 'author_slug' => 'augustine'], $writers, $tolle, $same));
    assert_same(null, Quote::retold('As Plato says, he heard a child and was converted.', $spurgeon, $writers, $tolle, $same));
    // A '— Name' credit line in a compilation.
    $r = Quote::retold("He heard the child, took the book, and was converted. — Bernard.", $spurgeon, $writers, $tolle, $same);
    assert_same('Bernard', $r['name'] ?? null, 'credit line');
}


// ---------------------------------------------------------------------------------------------
// Security sweep 2026-11-14, findings 18 and 19: "word for word" is said only when it is.

/** The fixture with Augustine's Confessions marked as uncorrected OCR of a scan, and four dictionary headwords. */
function scan_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    Fixture\store_or_skip();
    $dir = col_tmpdir('scan');
    foreach (['library.sqlite', 'vectors.bin', 'ids.bin', 'manifest.json', 'config.php'] as $f) {
        if (is_file(Fixture\DIR . "/$f")) {
            copy(Fixture\DIR . "/$f", "$dir/$f");
        }
    }
    $db = new \PDO("sqlite:$dir/library.sqlite");
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $db->exec('UPDATE works SET ocr = 1 WHERE id = 103');
    $st = $db->prepare("INSERT INTO dictionary(source, headword, norm, text) VALUES ('wilson1661', ?, ?, ?)");
    foreach (['power', 'tower', 'end', 'and'] as $w) {
        $st->execute([ucfirst($w), $w, (string) gzcompress('an ordinary word')]);
    }
    $db = null;
    return $dir;
}

function scan(array $args): string
{
    $r = Tools::call('check_quote', $args, Fixture\context(['data_dir' => scan_dir()]));
    assert_false($r['isError'], Fixture\text_of($r));
    return Fixture\text_of($r);
}

function test_a_word_passed_as_a_misreading_is_never_word_for_word(): void
{
    // "wisdorn" for the book's "wisdom": a letter apart and not a word, so it is taken as a misreading of the scan.
    $text = scan(['quote' => 'great is Thy power, and of Thy wisdorn there is no end', 'author' => 'Augustine']);
    assert_not_contains('FOUND WORD FOR WORD', $text);
    assert_contains('FOUND, BUT A WORD DIFFERS BY A LETTER: the words are in Augustine', $text);
    assert_contains('CHECK THE SCAN', $text);
    assert_contains('do not tell the user the quotation is word for word', $text);
    assert_contains('Match: 1 word differs by a letter (check the scan), the rest word for word', $text);
    assert_contains('**wisdom**', $text, 'the word is marked');
    // The same quotation of a book that is not a scan was never called word for word, and still is not.
    $plain = ok(['quote' => 'great is Thy power, and of Thy wisdorn there is no end', 'author' => 'Augustine']);
    assert_contains('FOUND, BUT WORDED DIFFERENTLY', $plain);
    // With every word right, a scanned book still gives the plain verdict.
    assert_contains('FOUND WORD FOR WORD', scan(['quote' => 'great is Thy power, and of Thy wisdom there is no end']));
}

function test_two_ordinary_words_are_a_difference_not_a_misreading(): void
{
    // "tower" for "power": one letter apart, but both are ordinary words (dictionary headwords).
    $text = scan(['quote' => 'great is Thy tower, and of Thy wisdom there is no end', 'author' => 'Augustine']);
    assert_contains('FOUND, BUT WORDED DIFFERENTLY', $text);
    assert_not_contains('FOUND WORD FOR WORD', $text);
    assert_not_contains('DIFFERS BY A LETTER', $text);
    assert_contains('"tower" for "power"', $text);
    assert_contains('SCAN: this book\'s text is uncorrected OCR', $text, 'the reader is still told it is a scan');
    // Very common words count as ordinary without a dictionary: "not", "the".
    $q = new Quote(Fixture\store_or_skip(scan_dir()), Fixture\fake_voyage(Fixture\store_or_skip(scan_dir())), microtime(true) + 20);
    $real = (new \ReflectionMethod($q, 'realWords'))->invoke($q, ['not', 'the', 'power', 'tower', 'liis', 'tbe', 'wisdorn']);
    assert_same(['not', 'the', 'power', 'tower'], array_keys($real));
    assert_same(['power' => true], (new \ColLibrary\Store(scan_dir()))->headwords(['power', 'liis']));
}

function test_a_slip_in_the_quotation_is_not_a_misreading(): void
{
    // "kiling" against the book's "killing": the book's word is common, the quotation's twenty times rarer.
    $q = new Quote(Fixture\store_or_skip(scan_dir()), Fixture\fake_voyage(Fixture\store_or_skip(scan_dir())), microtime(true) + 20);
    $cache = new \ReflectionProperty($q, 'countCache');
    $cache->setValue($q, ['killing' => 2000, 'kiling' => 3, 'sinner' => 2000, 'sinners' => 1800, 'wisdom' => 40, 'wisdorn' => 0]);
    $slip = new \ReflectionMethod($q, 'slip');
    assert_true($slip->invoke($q, 'kiling', 'killing'));
    assert_false($slip->invoke($q, 'sinners', 'sinner'), 'two common forms are a difference of wording');
    assert_false($slip->invoke($q, 'wisdorn', 'wisdom'), 'a word under 100 passages decides nothing');
}

function test_single_words_joined_by_gaps_are_not_word_for_word(): void
{
    $text = ok(['quote' => 'Great ... Lord ... greatly ... praised', 'author' => 'Augustine']);
    assert_not_contains('FOUND WORD FOR WORD', $text);
    assert_contains('FOUND WITH WORDS LEFT OUT: every word given is in Augustine', $text);
    assert_contains('do not call the version given word for word', $text);
    assert_contains('Match: every word, with words left out between them', $text);
    // One real phrase and a stray word: still not word for word.
    $text = ok(['quote' => 'Great art Thou, O Lord ... restless', 'author' => 'Augustine']);
    assert_contains('FOUND WITH WORDS LEFT OUT', $text);
    // Real phrases (three or more words) on each side keep the plain verdict.
    $text = ok(['quote' => 'Great art Thou, O Lord ... our heart is restless', 'author' => 'Augustine']);
    assert_contains('FOUND WORD FOR WORD', $text);
    assert_same(\ColLibrary\QuoteResult::LEFT_OUT, Quote::verdict(['edits' => 0, 'misread' => 0, 'thin_pieces' => true]));
    assert_same(\ColLibrary\QuoteResult::SCAN_LETTERS, Quote::verdict(['edits' => 0, 'misread' => 1, 'thin_pieces' => true]));
    assert_same(\ColLibrary\QuoteResult::REWORDED, Quote::verdict(['edits' => 1, 'misread' => 1]));
    assert_same(\ColLibrary\QuoteResult::EXACT, Quote::verdict(['edits' => 0, 'misread' => 0, 'thin_pieces' => false]));
}

function test_longer_and_place_names_match_the_library_author(): void
{
    $works = [['author' => 'Charles Spurgeon', 'author_slug' => 'charles-spurgeon'], ['author' => 'Augustine', 'author_slug' => 'augustine'],
              ['author' => 'Francis of Assisi', 'author_slug' => 'francis-of-assisi']];
    assert_same(['charles', 'spurgeon'], Quote::authorKeysFor('Charles Haddon Spurgeon', $works), 'middle name dropped');
    assert_same(['spurgeon'], Quote::authorKeysFor('C. H. Spurgeon', $works));
    assert_same(['augustine'], Quote::authorKeysFor('Augustine of Hippo', $works), 'the place dropped');
    assert_same(['augustine'], Quote::authorKeysFor('St. Augustine', $works));
    assert_same(['francis', 'assisi'], Quote::authorKeysFor('Francis of Assisi', $works), 'kept when the library has it so');
    assert_same(['john', 'haddon', 'owen'], Quote::authorKeysFor('John Haddon Owen', $works), 'no shorter form matches either');
    assert_same(['thomas', 'brooks'], Quote::authorKeysFor('Thomas Brooks', $works));

    $text = ok(['quote' => 'be killing sin or it will be killing you', 'author' => 'Dr. John Haddon Owen']);
    assert_contains('FOUND WORD FOR WORD', $text, 'John Owen in the library');
    $text = ok(['quote' => 'Thou hast made us for Thyself, and our heart is restless', 'author' => 'Augustine of Hippo']);
    assert_not_contains('NOT BY', $text);
}

function test_a_misspelt_name_finds_the_writer(): void
{
    $works = [['author' => 'Charles Spurgeon', 'author_slug' => 'charles-spurgeon'], ['author' => 'Augustine', 'author_slug' => 'augustine'],
              ['author' => 'Augustine', 'author_slug' => 'augustine'], ['author' => 'John Owen', 'author_slug' => 'john-owen'],
              ['author' => 'Jonathan Edwards', 'author_slug' => 'jonathan-edwards']];
    assert_same(['augustine'], Quote::authorKeysFor('Augustin', $works), 'one letter short');
    assert_same(['charles', 'spurgeon'], Quote::authorKeysFor('Charles Spurgen', $works));
    assert_same(['jonathan', 'edwards'], Quote::authorKeysFor('Johnathan Edwards', $works), 'two letters in a long word');
    assert_same(['spurgeon'], Quote::authorKeysFor('Spurgon', $works));
    assert_same(['john', 'owen'], Quote::authorKeysFor('John Owens', $works));
    assert_same(['jon', 'owen'], Quote::authorKeysFor('Jon Owen', $works), 'short words must be exact');
    assert_same(['benjamin', 'franklin'], Quote::authorKeysFor('Benjamin Franklin', $works), 'no writer near it');
    assert_same(['lewis'], Quote::authorKeysFor('C. S. Lewis', $works));
}

function test_out_of_time_is_marked_incomplete(): void
{
    $store = \ColLibraryTest\Fixture\store_or_skip();
    $check = new Quote($store, \ColLibraryTest\Fixture\fake_voyage($store), microtime(true) + 0.5);
    $r = $check->check('be killing sin or it will be killing you', 'Owen');
    assert_true($r->incomplete, 'every step skipped');
    assert_same(\ColLibrary\QuoteResult::NOT_FOUND, $r->verdict);
    assert_contains('The check ran short of time', implode(' ', $r->notes));
    $full = (new Quote($store, \ColLibraryTest\Fixture\fake_voyage($store), microtime(true) + 25))->check('be killing sin or it will be killing you', 'Owen');
    assert_false($full->incomplete);
    assert_same([], array_values(array_filter($full->notes, static fn (string $n): bool => str_contains($n, 'time'))));
}

function test_pieces_leave_out_the_commonest_words(): void
{
    // Wesley's "Do all the good you can ...": "the" and "all" are in most passages, so they are left out;
    // "do" alone is only a stopword, so it is no piece.
    $wesley = ['do', 'all', 'the', 'good', 'you', 'can', 'by', 'all', 'the', 'means', 'you', 'can', 'in', 'all', 'the',
               'ways', 'you', 'can'];
    assert_same([[['good', 'you', 'can'], 3], [['means', 'you', 'can'], 9], [['ways', 'you', 'can'], 15]],
        Quote::pieces([$wesley]));
    // A run longer than three words gives every three in a row, so "many good books" is kept whole.
    assert_same([[['visit', 'many', 'good'], 0], [['many', 'good', 'books'], 1], [['live'], 5], [['bible'], 8]],
        Quote::pieces([['visit', 'many', 'good', 'books', 'but', 'live', 'in', 'the', 'bible']]));
    // "...": pieces either side never overlap
    $gap = Quote::pieces([['killing', 'sin'], ['slaying', 'you']]);
    assert_same([], Quote::pieces([['one', 'god', 'will', 'the']]), 'the commonest ordinary words too');
    assert_same(['killing sin', 'slaying you'], array_map(static fn (array $p): string => implode(' ', $p[0]), $gap));
    assert_true($gap[1][1] >= $gap[0][1] + 2);
    assert_same([], Quote::pieces([['to', 'be', 'or', 'not', 'to', 'be']]), 'only stopwords: no pieces');
}

function test_pieces_at_most_six_longer_first(): void
{
    $long = explode(' ', 'grace reigns through righteousness unto eternal life and mercy endures for ever and truth '
        . 'abides in the soul of the saint and love never fails');
    $p = Quote::pieces([$long]);
    assert_same(6, count($p));
    assert_same([], array_filter($p, static fn (array $x): bool => count($x[0]) === 1), 'two- and three-word pieces first');
    $at = array_column($p, 1);
    $sorted = $at;
    sort($sorted);
    assert_same($sorted, $at, 'in the quotation\'s order');
    assert_same(0, $at[0], 'spread from the start...');
    assert_true(end($at) >= 18, '...to the end');
}

function test_near_phrases_query_runs_on_the_index(): void
{
    $store = \ColLibraryTest\Fixture\store_or_skip();
    $q = Quote::nearPhrases([['killing', 'sin'], ['will']], 19);
    assert_same('body : NEAR("killing sin" "will", 19)', $q);
    assert_same([10100003, 10200000], $store->matchInOrder($q, null, 41, 0), 'both editions of Owen');
    assert_same([], $store->matchInOrder(Quote::nearPhrases([['killing', 'sin'], ['restless']], 19), null, 41, 0));
}

function test_a_reworded_quotation_of_common_words_is_found_through_its_pieces(): void
{
    // "them" for "you": not there word for word; the pair of pieces ("killing sin" near "killing") finds it.
    $store = \ColLibraryTest\Fixture\store_or_skip();
    $check = new Quote($store, \ColLibraryTest\Fixture\fake_voyage($store), microtime(true) + 25);
    $r = $check->check('be killing sin, or it will be killing them', 'John Owen');
    assert_same(0, $check->trace['phrase_hits']);
    assert_true(($check->trace['pair_hits'] ?? 0) >= 2, 'the pairs found both editions');
    assert_same(\ColLibrary\QuoteResult::REWORDED, $r->verdict);
    assert_same(101, $r->primary['work']['id']);
    // Found word for word: the pairs are not searched.
    $exact = new Quote($store, \ColLibraryTest\Fixture\fake_voyage($store), microtime(true) + 25);
    $exact->check('be killing sin or it will be killing you', 'John Owen');
    assert_false(isset($exact->trace['pair_hits']));
}

function test_windows_list_their_passages_without_ranking(): void
{
    // Runs of content words ("killing sin will", "sin will killing") are looked up in id order, not ranked by
    // bm25 (which makes SQLite count each word over the whole library): both editions of Owen come back.
    $store = \ColLibraryTest\Fixture\store_or_skip();
    $check = new Quote($store, \ColLibraryTest\Fixture\fake_voyage($store), microtime(true) + 25);
    $r = $check->check('be killing sin, or it will be killing them', null);
    assert_true(($check->trace['window_hits'] ?? 0) >= 2, 'the windows found both editions');
    assert_false(isset($check->trace['windows_common']), 'no window is common wording in the fixture');
    assert_same(\ColLibrary\QuoteResult::REWORDED, $r->verdict);
}

function test_initials_tell_two_writers_of_one_surname_apart(): void
{
    $moody = ['author' => 'D. L. Moody', 'author_slug' => 'd-l-moody'];
    $son = ['author' => 'Paul D. Moody and Arthur P. Fitt', 'author_slug' => ''];
    $keys = Quote::authorKeysFor('D.L. Moody', [$son, $moody]);
    assert_same(['d', 'l', 'moody'], $keys);
    assert_true(Quote::byAuthor($moody, $keys));
    assert_false(Quote::byAuthor($son, $keys), "his son's book is not his");
    assert_same(['spurgeon'], Quote::authorKeysFor('C.H. Spurgeon', [['author' => 'Charles Spurgeon', 'author_slug' => '']]),
        'initials dropped when no author in the library has them');
}

function test_a_writer_who_names_the_credited_author_is_quoting_him(): void
{
    $keys = ['richard', 'baxter'];
    assert_true(Quote::namesAuthor(['before' => 'his aim must be to serve the church. Well says Baxter, "', 'after' => ''], $keys));
    assert_true(Quote::namesAuthor(['before' => 'and *Luther* had rather', 'after' => ''], ['martin', 'luther']));
    assert_true(Quote::namesAuthor(['before' => '', 'after' => ' (Luther\'s Table Talk.)'], ['martin', 'luther']));
    assert_false(Quote::namesAuthor(['before' => 'the Baxterians say', 'after' => ''], $keys), 'a whole word only');
    assert_false(Quote::namesAuthor(['before' => 'Well says Owen, "', 'after' => ''], $keys));

    $r = call(['quote' => 'be killing sin or it will be killing you', 'author' => 'Augustine']);
    assert_contains('FOUND, BUT NOT BY Augustine', \ColLibraryTest\Fixture\text_of($r));
    $store = \ColLibraryTest\Fixture\store_or_skip();
    $res = (new Quote($store, \ColLibraryTest\Fixture\fake_voyage($store), microtime(true) + 20))
        ->check('be killing sin or it will be killing you', 'Augustine');
    $named = new \ColLibrary\QuoteResult($res->quote, $res->author, $res->verdict, array_merge($res->primary, ['named' => true]),
        $res->matches, $res->earliest, $res->authorWorks, $res->phraseWorks, $res->notes);
    $text = \ColLibrary\Format::quoteCheck($named);
    assert_contains('FOUND ONLY AS QUOTED: the earliest work in the library with these words is John Owen', $text);
    assert_contains('there the writer names Augustine just before or after them', $text);
    assert_contains('do not say Augustine did not write them', $text);
    assert_not_contains('NOT BY Augustine', $text);
}

function test_a_rewording_keeps_the_quotation_whole(): void
{
    $words = static fn (string $s): array => explode(' ', $s);
    $check = static function (string $quote, string $lib) use ($words): bool {
        $q = $words($quote);
        $p = $words($lib);
        $ids = array_flip(array_values(array_unique(array_merge($q, $p))));
        $r = Quote::align(array_map(fn ($w) => $ids[$w], $q), [], array_map(fn ($w) => $ids[$w], $p));
        return Quote::keepsWhole($r, $q, fn (int $j): string => $p[$j]);
    };
    assert_true($check('thou hast made us for thyself and our hearts are restless until they rest in thee',
        'thou hast made us for thyself and our heart is restless until it repose in thee'));
    assert_true($check('we must remember that satan has his miracles too',
        'and it becomes us to remember that satan has his miracles which although'), 'two words at an end');
    assert_false($check('lord i know that i am not worthy but i will keep trying',
        'o lord i know that i am not worthy that thou shouldest enter'), 'a stock phrase: the last five words missing');
    assert_false($check('missions is not the ultimate goal of the church',
        'is as has been well said the ultimate consequential goal of the church and'), 'the first three missing');
    assert_false($check('you ve got to get people lost before you can get them saved',
        'now you have got to get water before you can get it out'), 'only small words kept');
    assert_true($check('the bible is the cradle wherein christ is laid', 'the bible is the cradle wherein christ is laid'));
}

function test_catechism_marks_are_not_words(): void
{
    $label = static function (string $text, string $word): bool {
        $off = strpos($text, $word);
        assert_true($off !== false, "$word in $text");
        return Quote::isLabel($text, $word, (int) $off);
    };
    $wsc = 'What is the chief end of man? A. Man\'s chief end is to glorify God, and to enjoy him for ever.';
    assert_true(Quote::isLabel($wsc, 'A', (int) strpos($wsc, 'A.')), 'the answer mark after the question');
    assert_true($label('Q. 1. What is the chief end of man?', 'Q'), 'the question mark at the start, before a number');
    assert_true($label('for ever. Q. 2. What rule hath God given', 'Q'), 'after the last answer');
    assert_true($label('A. Man\'s chief end is to glorify God', 'A'), 'a passage that opens with the answer');
    assert_true($label('Who made you? Answ. God made me.', 'Answ'), 'older abbreviations');
    assert_true($label('What is God? “A. God is a Spirit', 'A'), 'a quotation mark between');
    assert_true($label('Quest. What is the misery of that estate?', 'Quest'));
    // Not marks.
    assert_false($label('A man of sorrows, and acquainted with grief', 'A'), 'the word "a"');
    assert_false($label('as A. A. Hodge says. A. A. Hodge', 'A'), 'an initial');
    assert_false(Quote::isLabel('Hodge. A. A. Hodge wrote', 'A', 7), 'an initial, even after a sentence');
    assert_false($label('written by A. Barnes', 'A'), 'an initial after a word');
    assert_false($label('Is it so? A great many think it.', 'A'), 'no full stop after it');
    assert_false($label('chapter Q. The end', 'Q'), 'a "Q." after a word');
    assert_false($label('What then? Answer. He came', 'Answer'), 'the whole word stays a word');
}

function test_catechism_marks_do_not_make_a_quotation_reworded(): void
{
    $store = \ColLibraryTest\Fixture\store_or_skip();
    $check = new Quote($store, \ColLibraryTest\Fixture\fake_voyage($store), microtime(true) + 25);
    $tokens = new \ReflectionMethod($check, 'tokens');
    $ids = static fn (string $t): array => array_column($tokens->invoke($check, $t, null), 'id');
    $words = static fn (string $t): array => array_column($tokens->invoke($check, $t, null), 'w');
    $book = '1. What is the chief end of man? A. Man\'s chief end is to glorify God, and to enjoy him for ever. Q. 2. What rule';
    assert_false(in_array('a', $words($book), true), 'the mark is left out of the book\'s words');
    $given = 'What is the chief end of man? Man\'s chief end is to glorify God, and to enjoy him for ever.';
    $r = Quote::align($ids($given), [], $ids($book));
    assert_same(0, $r['edits'], 'quoted without the mark: word for word');
    $r = Quote::align($ids('What is the chief end of man? A. Man\'s chief end is to glorify God'), [], $ids($book));
    assert_same(0, $r['edits'], 'quoted with the mark: word for word too');
    assert_true(in_array('a', $words('A man of sorrows'), true), 'the word "a" is still a word');
}
