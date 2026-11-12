<?php
/**
 * Tests for src/Tools.php and src/Format.php through Tools::call on the fixture build: tool
 * definitions, argument validation messages, the result text format, read_passage (refs, seq
 * gaps, first/last passage, printed page labels), find_works, the output size cap, and failures.
 */

declare(strict_types=1);

namespace ColLibraryTest\Tools;

use ColLibrary\Format;
use ColLibrary\SearchResult;
use ColLibrary\Tools;
use ColLibrary\UnknownToolException;
use ColLibraryTest\Fixture;

require_once __DIR__ . '/fixture/testlib.php';

function call(string $tool, array $args, array $config = []): array
{
    Fixture\store_or_skip();
    return Tools::call($tool, $args, Fixture\context($config));
}

/** Text of a successful result. */
function ok(string $tool, array $args, array $config = []): string
{
    $r = call($tool, $args, $config);
    $text = Fixture\text_of($r);
    assert_false($r['isError'], "$tool should succeed: $text");
    return $text;
}

/** Text of an isError result. */
function err(string $tool, array $args, array $config = []): string
{
    $r = call($tool, $args, $config);
    $text = Fixture\text_of($r);
    assert_true($r['isError'], "$tool should fail: $text");
    return $text;
}

function test_definitions(): void
{
    $defs = Tools::definitions();
    assert_same(['search_library', 'read_passage', 'find_works', 'read_scripture', 'find_scripture_citations',
                 'define_term', 'check_quote'], array_column($defs, 'name'));
    foreach ($defs as $d) {
        assert_true((bool) preg_match('/^[a-z0-9_]{1,64}$/', $d['name']), 'name');
        assert_true(is_string($d['title']) && $d['title'] !== '', 'title');
        assert_same(['title' => $d['title'], 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true,
                     'openWorldHint' => false], $d['annotations'], 'annotations');
        assert_true(strlen($d['description']) > 300, 'description carries the guidance');
        assert_not_contains('use search_library', strtolower($d['description']), 'no instructions to call tools');
        assert_same('object', $d['inputSchema']['type']);
        assert_same(false, $d['inputSchema']['additionalProperties']);
        foreach ($d['inputSchema']['properties'] as $name => $p) {
            assert_true(is_string($p['description'] ?? null) && $p['description'] !== '', "$name has a description");
            assert_true(in_array($p['type'], ['string', 'integer', 'boolean'], true), "$name type");
        }
        json_encode($d, JSON_THROW_ON_ERROR);
    }
    $search = $defs[0]['inputSchema'];
    assert_same(['query'], $search['required']);
    assert_same(['query', 'author', 'work_id', 'collection', 'year_from', 'year_to', 'mode', 'limit', 'offset'],
        array_keys($search['properties']));
    assert_same(500, $search['properties']['query']['maxLength']);
    assert_same([1, 20, 8], [$search['properties']['limit']['minimum'], $search['properties']['limit']['maximum'],
                             $search['properties']['limit']['default']]);
    assert_same(['best', 'exact'], $search['properties']['mode']['enum']);
    assert_same(array_keys(Format::COLLECTIONS), $search['properties']['collection']['enum']);
    assert_same(['ref', 'work_id', 'page', 'before', 'after'], array_keys($defs[1]['inputSchema']['properties']));
    assert_same(['query', 'author', 'collection', 'year_from', 'year_to', 'limit'], array_keys($defs[2]['inputSchema']['properties']));
    assert_same(50, $defs[2]['inputSchema']['properties']['limit']['maximum']);
    assert_contains('ſinne', $defs[0]['description']);
}

function test_unknown_tool_is_a_protocol_error(): void
{
    assert_throws(UnknownToolException::class, fn () => Tools::call('search', [], Fixture\context()));
}

function test_argument_errors_are_actionable(): void
{
    $cases = [
        ['search_library', [], 'query is required'],
        ['search_library', ['query' => '   '], 'query is required'],
        ['search_library', ['query' => str_repeat('a', 501)], 'query is too long: at most 500 characters'],
        ['search_library', ['query' => 123], 'query must be a string'],
        ['search_library', ['query' => ['x']], 'query must be a string'],
        ['search_library', ['query' => 'x', 'foo' => 1], 'Unknown argument "foo" for search_library. Accepted arguments: query, author'],
        ['search_library', ['query' => 'x', 'limit' => 0], 'limit must be from 1 to 20 (got 0)'],
        ['search_library', ['query' => 'x', 'limit' => 21], 'limit must be from 1 to 20 (got 21)'],
        ['search_library', ['query' => 'x', 'limit' => 'abc'], 'limit must be a whole number from 1 to 20'],
        ['search_library', ['query' => 'x', 'limit' => 2.5], 'limit must be a whole number'],
        ['search_library', ['query' => 'x', 'limit' => true], 'limit must be a whole number'],
        ['search_library', ['query' => 'x', 'mode' => 'fuzzy'], 'mode must be one of: best, exact'],
        ['search_library', ['query' => 'x', 'collection' => 'eebo'], 'collection must be one of: eebo-tcp, evans-tcp'],
        ['search_library', ['query' => 'x', 'year_from' => 1700, 'year_to' => 1600], 'year_from (1700) is later than year_to (1600)'],
        ['search_library', ['query' => 'x', 'work_id' => 0], 'work_id must be from 1 to'],
        ['search_library', ['query' => 'x', 'work_id' => '1 OR 1=1'], 'work_id must be a whole number'],
        ['search_library', ['query' => 'x', 'author' => '!!!'], 'author must contain letters'],
        ['search_library', ['query' => 'x', 'offset' => 1001], 'offset must be from 0 to 1000'],
        ['search_library', ['query' => '!!! ???'], 'The query has no words to search for'],
        ['search_library', ['query' => 'sin', 'work_id' => 999], 'No work has work_id 999'],
        ['read_passage', [], 'Give ref (e.g. "P1234500017"), or work_id together with page'],
        ['read_passage', ['work_id' => 101], 'Give ref'],
        ['read_passage', ['ref' => 'P10100003', 'work_id' => 101], 'Give either ref, or work_id together with page, not both'],
        ['read_passage', ['ref' => 'X12'], 'ref must look like "P1234500017"'],
        ['read_passage', ['ref' => 'P99999'], 'ref must look like'],
        ['read_passage', ['ref' => 'P123'], 'ref must look like'],
        ['read_passage', ['ref' => 'P10100003', 'before' => 11], 'before must be from 0 to 10 (got 11)'],
        ['read_passage', ['ref' => 'P10100003', 'after' => -1], 'after must be from 0 to 10 (got -1)'],
        ['find_works', ['limit' => 51], 'limit must be from 1 to 50 (got 51)'],
        ['find_works', ['work_id' => 1], 'Unknown argument "work_id" for find_works'],
        ['find_works', ['query' => '!!!'], 'The query has no words to look for'],
    ];
    foreach ($cases as [$tool, $args, $expect]) {
        assert_contains($expect, err($tool, $args), "$tool " . json_encode($args));
    }
    // lenient where it is safe: integral numbers as floats or digit strings
    ok('search_library', ['query' => 'grace', 'limit' => 3.0]);
    ok('search_library', ['query' => 'grace', 'limit' => '3']);
    ok('search_library', ['query' => 'grace', 'author' => '', 'collection' => null]);
}

function test_search_result_format(): void
{
    $text = ok('search_library', ['query' => 'the chief end of man', 'limit' => 3]);
    $lines = explode("\n", $text);
    assert_same('Results 1-3 for "the chief end of man" (meaning + keyword search, re-ranked)', $lines[0]);
    assert_same(Format::CONTEXT_RULE, $lines[1], 'explains the context lines');
    assert_same(Format::MATCH_RULE, $lines[2], 'explains the match strengths');
    assert_same(Format::CITE_RULE, $lines[3], 'says how to quote and cite');
    array_splice($lines, 3, 1);
    assert_same('', $lines[3]);
    assert_same('[1] P10400003 · Théodore de Bèze, *A Brief Sum of the Christian Faith* · pp. 10–11 · A Short Catechism'
        . ' · Match: exact words', $lines[4], 'unknown year left out; pages as a range; the query is in it word for word');
    assert_true(str_starts_with($lines[5], 'Before (P10400002 · pp. 9–10): …'), 'end of the passage before: ' . $lines[5]);
    assert_true(str_ends_with($lines[5], 'them He has also glorified.'), 'it runs up to the result');
    assert_same("What is the chief end of man? Man's chief end is to glorify God, and to enjoy Him for ever. "
        . '[p. 11] What rule has God given to direct us how we may glorify and enjoy Him? The Word of God is the only rule.',
        $lines[6], 'verbatim text with the page marker');
    assert_contains('Copied from: https://www.gutenberg.org/ebooks/99999', implode("\n", array_slice($lines, 7, 2)));
    assert_same(3, count(Fixture\result_ids($text)));
    assert_not_contains('Search notes', $text, 'nothing to note');
    assert_false(str_contains($lines[5], 'Author:'), 'an author the library has no note on gets no author line');

    // Two editions of the same passage: one is a result, the other is named on its "Also in" line.
    $text = ok('search_library', ['query' => 'be killing sin or it will be killing you', 'author' => 'Owen']);
    $ids = Fixture\result_ids($text);
    $old = '· John Owen, *Of the Mortification of Sinne in Beleevers* (1668) · p. 1 · CHAP. I.';
    $new = '· John Owen, *Of the Mortification of Sin in Believers* · Chapter I';
    if (in_array(10100003, $ids, true)) {
        assert_contains("P10100003 $old", $text);
        assert_contains('Also in: John Owen, *Of the Mortification of Sin in Believers*, P10200000', $text);
        assert_false(in_array(10200000, $ids, true));
    } else {
        assert_contains("P10200000 $new", $text);
        assert_contains('Also in: John Owen, *Of the Mortification of Sinne in Beleevers* (1668), P10100003', $text);
    }
    assert_contains("Search notes: Filters: author contains \"Owen\" (2 works).", $text);

    $text = ok('search_library', ['query' => 'the deeds of the body', 'mode' => 'exact']);
    assert_contains("[2] P10100007 · John Owen, *Of the Mortification of Sinne in Beleevers* (1668) · p. 4 · CHAP. II.\n"
        . "Author: John Owen (1616–1683, English Puritan, Congregationalist)\n" . 'Before (P10100006', $text, 'who the author was, then the passage before');
    assert_contains("\nThe Spirite alone is ſufficient for this worke;", $text, 'verbatim old spelling');
    assert_true((bool) preg_match('/deeds of the body\\.\\nNotes: Ezek\\. 36\\. 26\\. \\| Rom\\. 8\\. 13\\.\\n(?:After \\(P\\d+[^\\n]*\\n)?'
        . 'Copied from: https:\\/\\/quod\\.lib\\.umich\\.edu\\/e\\/eebo\\/A53698\\.0001\\.001/u', $text), 'notes, the text after, then source');
}

function test_exact_mode_via_tool(): void
{
    $text = ok('search_library', ['query' => 'deeds of the body', 'mode' => 'exact', 'limit' => 2]);
    assert_contains('Results 1-2 of 3 for the exact phrase "deeds of the body" (in book order)', $text);
    assert_same([10100000, 10100007], Fixture\result_ids($text));
    assert_contains('repeat the search with offset 2', $text);
    $text = ok('search_library', ['query' => 'deeds of the body', 'mode' => 'exact', 'limit' => 2, 'offset' => 2]);
    assert_contains('Results 3-3 of 3', $text);
    assert_same([10200002], Fixture\result_ids($text));
    assert_not_contains('repeat the search', $text);
    assert_contains('Only 3 passages contain the exact phrase "deeds of the body"; use an offset below 3.',
        err('search_library', ['query' => 'deeds of the body', 'mode' => 'exact', 'offset' => 5]));
    $none = err('search_library', ['query' => 'xylophone quartet', 'mode' => 'exact']);
    assert_contains('NOT FOUND: no passage in the library contains the exact phrase "xylophone quartet". Old spellings '
        . 'are matched automatically', $none);
    assert_contains('say the library could not confirm it. ' . Format::FROM_MEMORY_RULE, $none);
    $text = ok('search_library', ['query' => 'grace', 'offset' => 4]);
    assert_contains('offset applies only to mode "exact" and was ignored.', $text);
}

function test_read_passage_by_ref(): void
{
    $text = ok('read_passage', ['ref' => 'P10100003', 'before' => 1, 'after' => 1]);
    $lines = explode("\n", $text);
    assert_same('John Owen, *Of the Mortification of Sinne in Beleevers* (1668) · work_id 101 · EEBO-TCP (original 1500s-1700s editions)', $lines[0]);
    assert_same('Full title: Of the mortification of sinne in beleevers: the necessity, nature, and means of it', $lines[1]);
    assert_same('Author: John Owen (1616–1683, English Puritan, Congregationalist)', $lines[2], 'who the author was');
    assert_same('Copied from: https://quod.lib.umich.edu/e/eebo/A53698.0001.001 · License: cc0', $lines[3]);
    preg_match_all('/^P(\d+) · /m', $text, $m);
    assert_same(['10100002', '10100003', '10100005'], $m[1], 'neighbours skip the missing seq 4');
    assert_contains('P10100003 · p. 1 · CHAP. I. · (requested passage)', $text);
    assert_contains('P10100005 · pp. 1–3 · CHAP. I.', $text);
    assert_contains('Earlier text: ref P10100001 · Later text: ref P10100006', $text);

    $text = ok('read_passage', ['ref' => 'P10100000']);
    preg_match_all('/^P(\d+) · /m', $text, $m);
    assert_same(['10100000', '10100001', '10100002'], $m[1], 'default before 1, after 2');
    assert_contains('This is the beginning of the work. · Later text: ref P10100003', $text);

    $text = ok('read_passage', ['ref' => 'P10100009', 'before' => 0]);
    preg_match_all('/^P(\d+) · /m', $text, $m);
    assert_same(['10100009'], $m[1]);
    assert_contains('Earlier text: ref P10100008 · This is the end of the work.', $text);

    foreach ([10100003, 'p10100003', '10100003', ' P10100003 '] as $ref) {
        assert_contains('P10100003 · p. 1 · CHAP. I. · (requested passage)', ok('read_passage', ['ref' => $ref]));
    }
    $missing = err('read_passage', ['ref' => 'P10100004']);
    assert_contains('There is no passage P10100004 in the library. Work 101 (John Owen, *Of the Mortification of Sinne in Beleevers* (1668)) has passages P10100000 to P10100009', $missing);
    assert_contains('There is no passage P99900001 in the library. Refs look like P1234500017',
        err('read_passage', ['ref' => 'P99900001']));
    assert_contains("P10100007 · p. 4 · CHAP. II.", ok('read_passage', ['ref' => 'P10100007', 'before' => 0, 'after' => 0]));
    assert_contains('Notes: Ezek. 36. 26. | Rom. 8. 13.', ok('read_passage', ['ref' => 'P10100007', 'before' => 0, 'after' => 0]),
        'footnotes joined with " | "');
}

function test_read_passage_by_page(): void
{
    $cases = [
        [101, 'iv', 10100001], [101, 'IV', 10100001], [101, 'p. iv', 10100001], [101, 'iii', 10100000],
        [101, '2', 10100005], [101, 3, 10100005], [101, 'pp. 4-5', 10100007], [104, '10', 10400002],
        [104, 'page 12', 10400004], [103, '{46}', 10300001],
    ];
    foreach ($cases as [$work, $page, $want]) {
        $text = ok('read_passage', ['work_id' => $work, 'page' => $page, 'before' => 0, 'after' => 0]);
        assert_contains("P$want · ", $text, "work $work page " . json_encode($page));
        assert_contains('(requested passage)', $text);
        assert_not_contains('begins inside this passage', $text, 'the start of the page was found');
    }
    assert_contains('Work 101 (John Owen, *Of the Mortification of Sinne in Beleevers* (1668)) has no page "99". Its pages run from iii to 5',
        err('read_passage', ['work_id' => 101, 'page' => '99']));
    assert_contains('has no printed page numbers in this edition. Read it by ref instead: its first passage is P10200000',
        err('read_passage', ['work_id' => 102, 'page' => '1']));
    assert_contains('No work has work_id 999', err('read_passage', ['work_id' => 999, 'page' => '1']));
    assert_contains('page must be a page label', err('read_passage', ['work_id' => 101, 'page' => 'pp. -']));
}

function test_find_works(): void
{
    $text = ok('find_works', ['query' => 'mortification of sin in believers']);
    preg_match_all('/^\[\d+\] work_id (\d+) /m', $text, $m);
    $found = $m[1];
    sort($found);
    assert_same(['101', '102'], $found, 'the old-spelling title (Sinne, Beleevers) matches too');
    assert_contains('Works 1-2 of 2 for "mortification of sin in believers"', $text);
    assert_contains("[1] work_id", $text);
    $owen = ok('find_works', ['query' => 'Mortification Sinne', 'year_from' => 1600]);
    assert_contains('Works 1-1 of 1 for "Mortification Sinne" (from 1600)', $owen);
    assert_contains("[1] work_id 101 · John Owen, *Of the Mortification of Sinne in Beleevers* (1668)\n"
        . "Full title: Of the mortification of sinne in beleevers: the necessity, nature, and means of it\n"
        . "book · EEBO-TCP (original 1500s-1700s editions) · 9 passages · pp. iii–5 · License: cc0\n"
        . "Author: John Owen (1616–1683, English Puritan, Congregationalist)\n"
        . 'Copied from: https://quod.lib.umich.edu/e/eebo/A53698.0001.001', $owen);
    assert_false(str_contains(ok('find_works', ['author' => 'beze']), 'Author:'), 'no note on the author: no line');

    $all = ok('find_works', []);
    preg_match_all('/^\[\d+\] work_id (\d+) /m', $all, $m);
    assert_same(['103', '101', '102', '104'], $m[1], 'by author, then year (unknown last)');
    $two = ok('find_works', ['limit' => 2]);
    assert_contains('Works 1-2 of 4', $two);
    assert_contains('More works match', $two);
    assert_contains('work_id 104 · Théodore de Bèze', ok('find_works', ['author' => 'beze']));
    assert_contains('work_id 103 · Augustine, *The Confessions* (1886)', ok('find_works', ['query' => 'augustine confessions']));
    $none = err('find_works', ['query' => 'xylophone']);
    assert_contains('NOT FOUND: no works found for "xylophone"', $none);
    assert_contains('tell the user the library does not have it. ' . Format::FROM_MEMORY_RULE, $none);
    $some = ok('find_works', ['query' => 'mortification xylophone']);
    assert_true(str_starts_with($some, 'NOT FOUND AS ASKED: no work in the library matches all the words of '
        . '"mortification xylophone". The works below match only some of the words.'), 'said first: ' . substr($some, 0, 120));
    assert_contains(Format::FROM_MEMORY_RULE . "\n\nWorks 1-", $some);
    assert_false(str_contains(ok('find_works', ['query' => 'augustine confessions']), 'NOT FOUND'), 'a full match says nothing');
    assert_contains('No works in the library match these filters: collection docsouth.',
        err('find_works', ['collection' => 'docsouth']));
}

function test_output_is_capped(): void
{
    $work = ['id' => 1, 'author' => 'A', 'title' => 'T', 'year' => null, 'source_url' => '', 'full_title' => 'T',
             'collection' => 'ccel', 'license' => '', 'type' => 'book'];
    $hits = [];
    for ($i = 0; $i < 20; $i++) {
        $len = $i < 3 ? 400 : 30000;      // three short passages, seventeen huge ones
        $hits[] = ['id' => 100000 + $i, 'work_id' => 1, 'seq' => $i, 'page_start' => null, 'page_end' => null,
                   'section' => '', 'text' => str_repeat('word ', intdiv($len, 5)), 'notes' => [], 'work' => $work,
                   'also' => []];
    }
    $text = Format::search(new SearchResult('q', 'best', 'keyword search', $hits, []));
    assert_true(mb_strlen($text) <= Format::MAX_CHARS, 'under the cap: ' . mb_strlen($text));
    assert_true(mb_strlen($text) > Format::MAX_CHARS - 5000, 'uses the room');
    assert_same(20, count(Fixture\result_ids($text)), 'every result is still there');
    assert_contains('Some passages were shortened (ending in …)', $text);
    assert_contains(str_repeat('word ', 79) . 'word', $text, 'short passages stay whole');
    $read = Format::readPassage($work, array_slice($hits, 3, 12), 100004, null, null);
    assert_true(mb_strlen($read) <= Format::MAX_CHARS);
    $small = Format::search(new SearchResult('q', 'best', 'keyword search', array_slice($hits, 0, 3), []));
    assert_not_contains('shortened', $small);
}

function test_pages_format(): void
{
    assert_same('p. 23', Format::pages('23', '23'));
    assert_same('pp. 23–24', Format::pages('23', '24'));
    assert_same('p. 5', Format::pages(null, '5'), 'text before the first marker of the work');
    assert_same('', Format::pages(null, null));
    assert_same('', Format::pages('', ''));
}

function test_backend_failures_are_actionable_and_safe(): void
{
    $dir = col_tmpdir('nodata');
    $text = err('search_library', ['query' => 'grace'], ['data_dir' => $dir . '/missing']);
    assert_contains('search index is not available right now', $text);
    assert_not_contains($dir, $text, 'no paths in results');

    // A file that is not a database: the index counts as unavailable.
    file_put_contents("$dir/library.sqlite", str_repeat('not a database ', 100));
    foreach (['search_library' => ['query' => 'grace'], 'read_passage' => ['ref' => 'P10100003'], 'find_works' => []] as $tool => $args) {
        $text = err($tool, $args, ['data_dir' => $dir]);
        assert_contains('search index is not available right now', $text, $tool);
        assert_not_contains($dir, $text);
        assert_not_contains('SQLSTATE', $text);
    }
    assert_contains('store_unavailable', col_error_log(), 'the details go to the server log');

    // A database without the expected tables: a generic, actionable failure; details in the log.
    $dir2 = col_tmpdir('notables');
    (new \PDO("sqlite:$dir2/library.sqlite"))->exec('CREATE TABLE meta(key TEXT PRIMARY KEY, value TEXT)');
    foreach (['search_library' => ['query' => 'grace'], 'read_passage' => ['ref' => 'P10100003'], 'find_works' => []] as $tool => $args) {
        $text = err($tool, $args, ['data_dir' => $dir2]);
        assert_contains("The $tool tool failed because of a problem on the server", $text, $tool);
        assert_not_contains('SQLSTATE', $text);
        assert_not_contains($dir2, $text);
    }
    assert_contains('"event":"tool_failed","tool":"find_works","error":"PDOException', col_error_log());
}

/** The real Tools behind agent A's MCP dispatcher: tools/list and tools/call in both protocol eras. */
function test_mcp_round_trip(): void
{
    if (!is_file(COL_PLUGIN_DIR . '/src/Mcp.php')) {
        col_skip('src/Mcp.php not written yet');
    }
    col_src('Mcp');
    Fixture\store_or_skip();
    $mcp = new \ColLibrary\Mcp();
    $post = function (array $msg, array $headers) use ($mcp): array {
        $r = $mcp->handle('POST', $headers + ['Content-Type' => 'application/json'],
            json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), Fixture\context());
        assert_same(200, $r->status, (string) $r->body);
        return json_decode((string) $r->body, true, 512, JSON_THROW_ON_ERROR);
    };
    $legacy = ['MCP-Protocol-Version' => '2025-11-25'];
    $list = $post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $legacy);
    assert_same(['search_library', 'read_passage', 'find_works', 'read_scripture', 'find_scripture_citations',
                 'define_term', 'check_quote'], array_column($list['result']['tools'], 'name'));
    $call = $post(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
                   'params' => ['name' => 'search_library', 'arguments' => ['query' => 'the chief end of man', 'limit' => 2]]], $legacy);
    assert_false($call['result']['isError']);
    assert_contains('[1] P10400003 · Théodore de Bèze', $call['result']['content'][0]['text']);
    $bad = $post(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
                  'params' => ['name' => 'read_passage', 'arguments' => ['ref' => 'nope']]], $legacy);
    assert_true($bad['result']['isError']);
    assert_contains('ref must look like', $bad['result']['content'][0]['text']);

    $modern = ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'find_works'];
    $meta = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28',
             'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
             'io.modelcontextprotocol/clientInfo' => ['name' => 'tests', 'version' => '1']];
    $res = $post(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
                  'params' => ['name' => 'find_works', 'arguments' => ['author' => 'augustine'], '_meta' => $meta]], $modern);
    assert_same('complete', $res['result']['resultType'] ?? null);
    assert_contains('[1] work_id 103 · Augustine, *The Confessions* (1886)', $res['result']['content'][0]['text']);
}

function test_opening_kinds(): void
{
    $cases = [
        'Obiect. But faith is a work of ours.' => 'objection',
        'Obj. 2. Then good works are needless.' => 'objection',
        'It will be objected that grace makes men careless.' => 'objection',
        '[p. 12] Answ. Not so; faith receiveth.' => 'answer',
        'Resp. The Apostle meaneth otherwise.' => 'answer',
        'Quest. What is justification?' => 'question',
        'Q. What is the chief end of man?' => 'question',
        'Faith is the hand that receives Christ. Objections answered below.' => null,
        'Questionless the Lord is good.' => null,
        'Answering this, the Apostle saith...' => null,
    ];
    foreach ($cases as $text => $kind) {
        assert_same($kind, Format::opening($text), $text);
    }
}

function test_search_results_carry_their_context(): void
{
    $store = Fixture\store_or_skip();
    $hit = $store->passage(10400003);
    $hit['work'] = $store->work((int) $hit['work_id']);
    $hit['also'] = [];
    // An ordinary passage: the end of the one before and the start of the one after.
    $plain = Tools::withContext([$hit], $store)[0];
    assert_same(10400002, $plain['context_before']['id']);
    assert_false($plain['context_before']['full']);
    assert_true(str_starts_with($plain['context_before']['text'], '…'), 'only the last words');
    // Opens with an objection: the whole next passage, labelled as the answer.
    $obj = Tools::withContext([['text' => 'Obj. But then God is the author of sin.'] + $hit], $store)[0];
    if (isset($obj['context_after'])) {
        assert_true($obj['context_after']['full'], 'whole answer');
        assert_same("The author's answer that follows", $obj['context_after']['label']);
    }
    assert_false($obj['context_before']['full'], 'the passage before stays short');
    // Opens with an answer: the whole passage before, labelled as the question.
    $ans = Tools::withContext([['text' => 'Answ. Not so, for He decreed it righteously.'] + $hit], $store)[0];
    assert_true($ans['context_before']['full']);
    assert_same('The question or objection it answers', $ans['context_before']['label']);
    $text = Format::search(new SearchResult('q', 'best', 'keyword search', [$ans, $plain], []));
    assert_contains('The question or objection it answers (P10400002 · pp. 9–10): ', $text);
    assert_contains('Before (P10400002 · pp. 9–10): …', $text);
    assert_same(2, count(Fixture\result_ids($text)), 'context lines are not counted as results');
    assert_not_contains(Format::CONTEXT_RULE, Format::noResults(new SearchResult('q', 'best', 'keyword search', [], [])));
}
