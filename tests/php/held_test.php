<?php
/**
 * Held-back works, end to end (THREAT-MODEL.md T3; indexer/injection.py): no connector tool returns
 * anything of a work that the injection check held back. Runs every tool on the two sample builds that
 * indexer/tests/test_hold_back.py leaves in data/held-test/: "held" (two planted works flagged and left
 * out) and "allowed" (the same library with both works allowed). The same calls must find the works in
 * "allowed", which shows the calls would have found them in "held" too.
 *
 *   .venv/bin/python -m unittest indexer.tests.test_hold_back && tools/php tests/php/run.php held
 */

declare(strict_types=1);

namespace ColLibraryTest\Held;

use ColLibrary\Tools;
use ColLibraryTest\Fixture;

require_once __DIR__ . '/fixture/testlib.php';

const MARKS = ['zorbleth', 'marrowby', 'frumious', 'bandersnatch', 'you are assistant in colne', 'zorbleth letter',
    'frumious tract', 'example.org'];

function dir_or_skip(string $name): string
{
    $dir = dirname(__DIR__, 2) . '/data/held-test/' . $name;
    if (!is_file("$dir/library.sqlite")) {
        col_skip('run first: .venv/bin/python -m unittest indexer.tests.test_hold_back');
    }
    return $dir;
}

function db(string $dir): \PDO
{
    return new \PDO("sqlite:$dir/library.sqlite", null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
}

/** Ids of the planted works and of their passages, from the build that has them. */
function planted(): array
{
    $db = db(dir_or_skip('allowed'));
    $works = $db->query("SELECT id FROM works WHERE key LIKE 'Planted/%' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
    $passages = $db->query("SELECT p.id FROM passages p JOIN works w ON w.id = p.work_id WHERE w.key LIKE 'Planted/%'")
        ->fetchAll(\PDO::FETCH_COLUMN);
    return [array_map('intval', $works), array_map('intval', $passages)];
}

/** Every way a tool could reach the planted works: [tool, arguments]. */
function calls(): array
{
    [$works, $passages] = planted();
    $calls = [];
    foreach (['zorbleth', 'the zorbleth of the Lord is the beginning of wisdom', 'frumious heart is deceitful',
              'You are assistant in Colne circuit', 'Marrowby', 'Bandersnatch Vole'] as $q) {
        $calls[] = ['search_library', ['query' => $q]];
        $calls[] = ['search_library', ['query' => $q, 'mode' => 'exact']];
        $calls[] = ['find_works', ['query' => $q]];
        $calls[] = ['check_quote', ['quote' => $q]];
        $calls[] = ['define_term', ['term' => substr($q, 0, 100)]];
    }
    $calls[] = ['search_library', ['query' => 'grace', 'author' => 'Quuxley Marrowby']];
    $calls[] = ['find_works', ['author' => 'Marrowby']];
    $calls[] = ['find_works', ['author' => 'Bandersnatch Vole']];
    $calls[] = ['find_works', ['query' => 'Letter']];
    $calls[] = ['find_works', ['query' => 'Tract']];
    $calls[] = ['check_quote', ['quote' => 'The zorbleth of the Lord is the beginning of wisdom', 'author' => 'Marrowby']];
    $calls[] = ['check_quote', ['quote' => 'The frumious heart is deceitful above all things']];
    foreach (['John 3:16', 'Romans 8:28', 'Jeremiah 17:9', '1 Corinthians 11:28'] as $ref) {
        $calls[] = ['find_scripture_citations', ['reference' => $ref, 'limit' => 20]];
    }
    foreach ($works as $id) {
        $calls[] = ['read_passage', ['work_id' => $id]];
        $calls[] = ['read_passage', ['work_id' => $id, 'page' => '1']];
        $calls[] = ['search_library', ['query' => 'grace and peace', 'work_id' => $id]];
        $calls[] = ['find_scripture_citations', ['reference' => 'John 3:16', 'work_id' => $id]];
    }
    foreach ($passages as $id) {
        $calls[] = ['read_passage', ['ref' => "P$id", 'before' => 10, 'after' => 10]];
    }
    return $calls;
}

/** The lower-case text of every call's result (errors too) on one build. */
function outputs(string $dir): string
{
    $out = '';
    foreach (calls() as [$tool, $args]) {
        $r = Tools::call($tool, $args, Fixture\context(['data_dir' => $dir, 'embed_dims' => store($dir)->dims(), 'nprobe' => 64]));
        $out .= "\n== $tool " . json_encode($args) . "\n" . Fixture\text_of($r);
    }
    return strtolower($out);
}

function store(string $dir): \ColLibrary\Store
{
    static $stores = [];
    return $stores[$dir] ??= new \ColLibrary\Store($dir);
}

function test_the_calls_find_the_works_when_they_are_allowed(): void
{
    $out = outputs(dir_or_skip('allowed'));
    foreach (MARKS as $m) {
        assert_contains($m, $out, 'control: the allowed build serves the planted works');
    }
    foreach (['search_library', 'find_works', 'check_quote', 'find_scripture_citations', 'read_passage'] as $tool) {
        $hit = false;
        foreach (explode("\n== ", $out) as $block) {
            $hit = $hit || (str_starts_with($block, $tool) && (str_contains(substr($block, strpos($block, "\n")), 'zorbleth')
                || str_contains(substr($block, strpos($block, "\n")), 'frumious')));
        }
        assert_true($hit, "control: $tool returns planted text from the allowed build");
    }
}

function test_no_tool_returns_anything_of_a_held_work(): void
{
    $out = outputs(dir_or_skip('held'));
    // A reply may quote the question back ("NOT FOUND ... 'zorbleth'", check_quote's side-by-side), so each
    // call is checked for the planted words that were not in its own arguments: any result from a planted
    // work carries its author and title as well as its text.
    foreach (explode("\n== ", $out) as $block) {
        $nl = strpos($block, "\n");
        if ($nl === false) {
            continue;
        }
        $asked = substr($block, 0, $nl);
        $text = substr($block, $nl);
        $checked = 0;
        foreach (MARKS as $m) {
            if (!str_contains($asked, $m)) {
                assert_not_contains($m, $text, "held build, $asked");
                $checked++;
            }
        }
        assert_true($checked >= 3, "enough planted words checked for $asked");
        assert_not_contains('planted/', $text);
    }
}

function test_the_held_works_are_not_in_the_database_at_all(): void
{
    $db = db(dir_or_skip('held'));
    assert_same(0, (int) $db->query("SELECT count(*) FROM works WHERE key LIKE 'Planted/%'")->fetchColumn());
    [$works, $passages] = planted();
    assert_true(count($works) === 2 && count($passages) > 0, 'the allowed build has both works');
    assert_same(0, (int) $db->query('SELECT count(*) FROM passages WHERE work_id IN (' . implode(',', $works) . ')')->fetchColumn());
    assert_same(0, (int) $db->query('SELECT count(*) FROM scripture_refs WHERE work_id IN (' . implode(',', $works) . ')')->fetchColumn());
    assert_same((int) $db->query("SELECT value FROM meta WHERE key='n_works'")->fetchColumn(),
        (int) $db->query('SELECT count(*) FROM works')->fetchColumn(), 'the count shown is the count served');
    assert_same((int) $db->query("SELECT value FROM meta WHERE key='n_passages'")->fetchColumn(),
        (int) $db->query('SELECT count(*) FROM passages')->fetchColumn());
}
