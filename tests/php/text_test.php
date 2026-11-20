<?php
/**
 * Tests for src/Text.php: normalization (same folds as indexer/modernize.py), tokens, spelling,
 * stopwords, FTS5 query building (hostile input cannot become FTS5 syntax), shingles.
 */

declare(strict_types=1);

namespace ColLibraryTest\Text;

use ColLibrary\Store;
use ColLibrary\Text;
use ColLibraryTest\Fixture;

require_once __DIR__ . '/fixture/testlib.php';

function test_normalize_folds_old_letters_and_unicode(): void
{
    assert_same('christ dyed for sinnes', Text::normalize('Chriſt dyed for ſinnes'));
    assert_same('which which wee', Text::normalize('VVhich vvhich WEE'), 'VV/vv -> w; then lower case');
    assert_same('whither', Text::normalize('VVHITHER'), 'VV before a capital');
    assert_same("lov'd", Text::normalize('lov’d'), 'curly apostrophe');
    assert_same('fire full 2', Text::normalize('ﬁre Ｆｕｌｌ ²'), 'NFKC: ligature, full width, superscript');
    assert_same('café', Text::normalize("cafe\u{301}"), 'NFKC composes accents');
    assert_same('himselfe and itself', Text::normalize('him selfe and it self'), 'it selfe -> itselfe');
    assert_same('ok ?', Text::normalize("ok \xC3"), 'invalid UTF-8 does not break anything');
}

function test_words(): void
{
    assert_same(["he'll", "do't", "god's", '123', 'rock', 'n', 'roll', 'tis'],
        Text::words(Text::normalize("He'll do't, God's 123 rock-n-roll! 'tis")));
    assert_same([], Text::words(Text::normalize('*** ??? ---')));
    assert_same(['ἀγάπη', 'λόγος'], Text::words(Text::normalize('ἀγάπη, λόγος')), 'Greek words are words');
}

function test_spelling_and_modernize_text(): void
{
    $map = ['sinne' => 'sin', 'assoon' => 'as soon', 'hath' => 'has'];
    assert_same(['sin', 'as', 'soon', 'grace'], Text::modernTokens(['sinne', 'assoon', 'grace'], $map));
    assert_same('Sin and SIN and sin; it has grace', Text::modernizeText('Sinne and SINNE and sinne; it hath grace', $map));
    assert_same('unchanged text', Text::modernizeText('unchanged text', []));
}

function test_spelling_lookup_in_fixture(): void
{
    $store = Fixture\store_or_skip();
    $map = $store->spellings(['sinne', 'beleevers', 'grace', 'assoon', "lov'd", '123']);
    assert_same(['sinne' => 'sin', 'beleevers' => 'believers', 'assoon' => 'as soon', "lov'd" => 'loved'], $map);
}

function test_keyword_tokens_drop_stopwords_but_never_everything(): void
{
    assert_same(['killing', 'sin'], Text::keywordTokens(['be', 'killing', 'sin', 'or', 'it', 'be', 'killing', 'you']),
        'stopwords and duplicates dropped');
    assert_same(['to', 'be', 'or', 'not'], Text::keywordTokens(['to', 'be', 'or', 'not', 'to', 'be']),
        'all stopwords: keep them (deduplicated)');
    assert_same(['free', 'will'], Text::keywordTokens(['free', 'will']), '"will" is meaningful here');
    assert_same(2, count(Text::keywordTokens(['a', 'b', 'c'], 2)), 'capped');
}

function test_fts_quoting(): void
{
    assert_same('"sin"', Text::quote('sin'));
    assert_same('"a""b"', Text::quote('a"b'), 'inner quotes doubled');
    assert_same('" x y"', Text::quote("\0x\ny"), 'control characters (a NUL ends an FTS5 query) become spaces');
    assert_same('"sin" "grace"', Text::ftsAll(['sin', 'grace']));
    assert_same('("sin" OR "sinne") AND "grace"', Text::ftsAllOfAny([['sin', 'sinne', 'sin'], ['grace']]));
    assert_same('("a" OR "b") OR "c"', Text::ftsAllOfAny([['a', 'b'], ['c']], ' OR '));
    assert_same('body : "the just shall live"', Text::ftsPhrase(['the', 'just', 'shall', 'live']));
    assert_same('body : "say ""hi"""', Text::ftsPhrase(['say', '"hi"']));
}

/** Every quoted token is literal text to FTS5: no syntax errors and no operators, whatever the input. */
function test_hostile_fts_input_is_inert(): void
{
    $store = Fixture\store_or_skip();
    $hostile = ['"', '""', '" OR 1=1 --', 'NEAR(sin grace, 2)', 'body:grace', 'header : owen', '{body notes}: sin',
        '*', 'sin*', '^sin', '-sin', 'sin AND NOT grace', 'OR', 'AND', 'NOT', '(((', ')', ':', '+', 'NEAR',
        "sin\0grace", '🙏 ✝', 'שָׁלוֹם', str_repeat('"', 500), str_repeat('x', 300), "\u{202E}evil"];
    foreach ($hostile as $input) {
        $tokens = Text::words(Text::normalize($input));
        foreach ([Text::ftsAll($tokens !== [] ? $tokens : [$input]), Text::ftsPhrase($tokens !== [] ? $tokens : [$input]),
                  Text::ftsAll([$input]), Text::ftsPhrase([$input])] as $match) {
            $store->keywordSearch($match, null, 5);       // must not throw
            $store->matchInOrder($match, null, 5, 0);
        }
    }
    // Operators inside quotes are plain words: "NEAR" must occur as a word (it does not in the
    // fixture), and "AND" matches the word "and" (so it narrows, never widens, the results).
    assert_same([], array_keys($store->keywordSearch(Text::ftsAll(['sin', 'NEAR', 'grace']), null, 50)));
    $sinGrace = array_keys($store->keywordSearch(Text::ftsAll(['sin', 'grace']), null, 50));
    $withAnd = array_keys($store->keywordSearch(Text::ftsAll(['sin', 'AND', 'grace']), null, 50));
    assert_same([], array_diff($withAnd, $sinGrace), '"AND" only narrows');
    assert_true(count($store->keywordSearch(Text::ftsAll(['sin']), null, 50)) > 3, 'plain word still matches');
    // A column filter typed by a user stays a word, not a filter.
    assert_same([], array_keys($store->keywordSearch(Text::ftsAll(['header:owen']), null, 50)));
}

function test_plain_text(): void
{
    assert_same('In such case no inferior can agree', Text::plain("In [p. 3] such case\n\nno *inferior* can \\*agree"),
        'as corpus.strip_markdown: markers, emphasis and backslashes go');
    assert_same('bold and under', Text::plain('**bold** and __under__'));
}

function test_loose_key(): void
{
    assert_same('theodore de beze', Text::looseKey('Théodore de Bèze'));
    assert_same('theodore de beze', Text::looseKey('theodore-de-beze'));
    assert_same('aelfric strasse', Text::looseKey('Ælfric Straße'));
    assert_same('john owen', Text::looseKey('  JOHN   OWEN '));
    assert_same('or 1 1', Text::looseKey("' OR 1=1 --"), 'punctuation becomes spaces');
}

function test_shingles_and_jaccard(): void
{
    $a = Text::shingles('be killing sin or it will be killing you');
    assert_same(5, count($a), '9 words -> 5 shingles');
    assert_true(isset($a['be killing sin or it']));
    $b = Text::shingles('Be killing SIN, or it will be killing you!');
    assert_same(1.0, Text::jaccard($a, $b), 'case and punctuation do not matter');
    $c = Text::shingles('be killing sin or it will not be killing you');
    assert_true(Text::jaccard($a, $c) > 0.2 && Text::jaccard($a, $c) < 0.5, 'one inserted word changes several shingles');
    assert_same(['short text' => true], Text::shingles('short text'));
    assert_same(0.0, Text::jaccard([], $a));
}

function test_clip_and_one_line(): void
{
    assert_same('short', Text::clip('short', 10));
    $clipped = Text::clip('one two three four five six', 12);
    assert_true(mb_strlen($clipped) <= 12 && str_ends_with($clipped, '…'), $clipped);
    assert_same('a b c', Text::oneLine("a\n b\t\tc"));
}

function test_log_message_drops_quoted_query_text(): void
{
    $e = new \PDOException('SQLSTATE[HY000]: General error: 1 fts5: syntax error near "secret words"');
    assert_same('PDOException: SQLSTATE[HY000]: General error: 1 fts5: syntax error near "…"', Text::logMessage($e));
}

function test_foreign_gap_mark_reads_as_plain_words(): void
{
    $out = Text::inert('the word [gap: foreign] signifies life');
    assert_same('the word ' . Text::FOREIGN_GAP . ' signifies life', $out);
    assert_same('[illegible] and [gap: 1 page]', Text::inert('[illegible] and [gap: 1 page]'), 'other marks unchanged');
    assert_same('no marks here', Text::inert('no marks here'));
}

function test_possessives_match_with_and_without_the_apostrophe(): void
{
    assert_same('god', Text::unpossessive("god's"));
    assert_same('moses', Text::unpossessive("moses's"));
    assert_same('gods', Text::unpossessive('gods'));
    assert_same("o'er", Text::unpossessive("o'er"));
    assert_same('body : "the lord supper"', Text::ftsPhraseAny(['the', 'lord', 'supper']), 'no possessive: one phrase');
    assert_same('body : ("the lord\'s supper" OR "the lords supper")', Text::ftsPhraseAny(['the', "lord's", 'supper']));
    assert_same('body : ("moses\'s law" OR "moses law")', Text::ftsPhraseAny(["moses's", 'law']), 'a name ending in s');
    $three = Text::ftsPhraseAny(["a's", "b's", "c's"]);
    assert_same(4, substr_count($three, ' OR ') + 1, 'at most ' . Text::POSSESSIVE_FORMS . ' possessives get two forms');
}

function test_contractions_leave_no_common_word_in_a_keyword_search(): void
{
    $kw = Text::keywordTokens(array_map([Text::class, 'unpossessive'], ["what's", 'the', 'chief', "it's", "god's"]));
    assert_same(['chief', 'god'], $kw, '"what\'s" and "it\'s" become stopwords and are dropped');
}
