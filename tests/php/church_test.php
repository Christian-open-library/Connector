<?php
/**
 * Tests for src/Churches.php: the denomination and tradition filters (bodies, present-day names and their
 * ancestors with year limits, traditions, words of names, unknown names), the "Church record" label, and
 * builds without the church tables. Runs on a copy of the fixture build with church tables added:
 *   work 101 (Owen, 1668)       -> Presbyterian Church in the United States of America, minutes
 *   work 102 (Owen, no year)    -> Presbyterian Church in the Confederate States of America (a PCUS tag), constitution
 *   work 103 (Augustine, 1886)  -> Presbyterian Church in the United States of America, digest
 *   work 104 (Beze, no year)    -> Methodist Episcopal Church, history
 */

declare(strict_types=1);

namespace ColLibraryTest\Church;

use ColLibrary\Churches;
use ColLibrary\Filters;
use ColLibrary\Search;
use ColLibrary\SearchInputException;
use ColLibrary\Store;
use ColLibrary\Tools;
use ColLibraryTest\Fixture;

require_once __DIR__ . '/fixture/testlib.php';

const BODIES = [
    ['Presbyterian Church in the United States of America', 'Presbyterian', 1789, 1958,
     ['Presbyterian Church in the United States of America'], ['PCUSA', 'Northern Presbyterian Church'], 0],
    ['Presbyterian Church in the United States', 'Presbyterian', 1861, 1983,
     ['Presbyterian Church in the United States', 'Presbyterian Church in the Confederate States of America'],
     ['PCUS', 'Southern Presbyterian Church'], 1],
    ['Methodist Episcopal Church', 'Methodist', 1784, 1939, ['Methodist Episcopal Church'], ['MEC'], 0],
];
const NAMES = [
    ['Presbyterian Church in America', ['PCA'], 'Presbyterian',
     [['Presbyterian Church in the United States', null, null],
      ['Presbyterian Church in the United States of America', null, 1861]], 0],
    ['Orthodox Presbyterian Church', ['OPC'], 'Presbyterian',
     [['Presbyterian Church in the United States of America', null, 1936]], 1],
    ['United Methodist Church', ['UMC'], 'Methodist', [['Methodist Episcopal Church', null, null]], 0],
];
const RECORDS = [
    [101, 'Presbyterian Church in the United States of America', 'Presbyterian', 'minutes', 'historical'],
    [102, 'Presbyterian Church in the Confederate States of America', 'Presbyterian', 'constitution', 'historical'],
    [103, 'Presbyterian Church in the United States of America', 'Presbyterian', 'digest', 'historical'],
    [104, 'Methodist Episcopal Church', 'Methodist', 'history', 'historical'],
];

/** A copy of the fixture build with the church tables (built once per run). */
function church_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    Fixture\store_or_skip();
    $dir = col_tmpdir('church');
    foreach (['library.sqlite', 'vectors.bin', 'ids.bin', 'manifest.json', 'config.php'] as $f) {
        if (is_file(Fixture\DIR . "/$f")) {
            copy(Fixture\DIR . "/$f", "$dir/$f");
        }
    }
    $db = new \PDO("sqlite:$dir/library.sqlite");
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $db->exec('DROP TABLE IF EXISTS church_records; DROP TABLE IF EXISTS church_bodies; DROP TABLE IF EXISTS church_names;
        CREATE TABLE church_records(work_id INTEGER PRIMARY KEY, denomination TEXT NOT NULL, tradition TEXT NOT NULL,
                                    doc_kind TEXT NOT NULL, status TEXT NOT NULL);
        CREATE TABLE church_bodies(name TEXT PRIMARY KEY, tradition TEXT NOT NULL, founded INTEGER, ended INTEGER,
                                   tags TEXT NOT NULL, aliases TEXT NOT NULL, verified INTEGER NOT NULL) WITHOUT ROWID;
        CREATE TABLE church_names(name TEXT PRIMARY KEY, aliases TEXT NOT NULL, tradition TEXT NOT NULL,
                                  ancestors TEXT NOT NULL, verified INTEGER NOT NULL) WITHOUT ROWID;');
    $st = $db->prepare('INSERT INTO church_bodies VALUES (?,?,?,?,?,?,?)');
    foreach (BODIES as [$n, $t, $f, $e, $tags, $aliases, $v]) {
        $st->execute([$n, $t, $f, $e, json_encode($tags), json_encode($aliases), $v]);
    }
    $st = $db->prepare('INSERT INTO church_names VALUES (?,?,?,?,?)');
    foreach (NAMES as [$n, $aliases, $t, $anc, $v]) {
        $st->execute([$n, json_encode($aliases), $t, json_encode($anc), $v]);
    }
    $st = $db->prepare('INSERT INTO church_records VALUES (?,?,?,?,?)');
    foreach (RECORDS as $r) {
        $st->execute($r);
    }
    $db = null;
    return $dir;
}

function store(): Store
{
    return new Store(church_dir());
}

/** @return list<int> work ids the filters allow */
function allowed(?string $denomination, ?string $tradition = null, ?int $from = null, ?int $to = null): array
{
    $ids = (new Filters(yearFrom: $from, yearTo: $to, denomination: $denomination, tradition: $tradition))
        ->resolve(store())['ids'];
    sort($ids);
    return $ids;
}

function notes(string $denomination): string
{
    return implode("\n", (new Filters(denomination: $denomination))->resolve(store())['notes']);
}

function call(string $tool, array $args): array
{
    return Tools::call($tool, $args, Fixture\context(['data_dir' => church_dir()]));
}

function test_a_body_by_name_tag_or_other_name(): void
{
    assert_same([101, 103], allowed('Presbyterian Church in the United States of America'));
    assert_same([101, 103], allowed('pcusa '), 'abbreviation, any case');
    assert_same([102], allowed('Presbyterian  Church, in the United-States'), 'punctuation and spaces ignored');
    assert_same([102], allowed('Presbyterian Church in the Confederate States of America'), 'a tag of the PCUS');
    assert_same([102], allowed('the Southern Presbyterian Church'), 'a leading "the" is dropped');
    assert_contains('the records of the Presbyterian Church in the United States (1861–1983)', notes('PCUS'));
}

function test_a_present_day_name_searches_its_ancestors_within_their_years(): void
{
    // PCA: all PCUS records, PCUSA records only to 1861 (101 is 1668; 103 is 1886)
    assert_same([101, 102], allowed('PCA'));
    assert_same([101, 102], allowed('PCA church'), 'a trailing "church" is dropped');
    $n = notes('PCA');
    assert_contains('"PCA (the Presbyterian Church in America)": the library has no records of that body itself', $n);
    assert_contains('Presbyterian Church in the United States of America (1789–1958), records to 1861', $n);
    assert_contains('have not yet been checked by the library\'s curator', $n);
    assert_contains('1 record of those bodies from outside the years in that line of descent was left out', $n);
    assert_contains(Churches::HISTORICAL_RULE, $n);
    assert_same([101, 103], allowed('OPC'));
    assert_not_contains('not yet been checked', notes('OPC'), 'a checked line says nothing about checking');
    assert_same([104], allowed('UMC'));
}

function test_the_record_year_comes_from_the_title_first(): void
{
    assert_same(1858, Churches::recordYear(['title' => 'Minutes of the General Assembly, 1838-1858 (Old School)', 'year' => 1894]));
    assert_same(1925, Churches::recordYear(['title' => 'The Book of Church Order, Revised Edition (1925)', 'year' => 1926]));
    assert_same(1855, Churches::recordYear(['title' => 'What Is Presbyterianism', 'year' => 1855]));
    assert_same(null, Churches::recordYear(['title' => 'Notes', 'year' => null]));
}

function test_traditions_and_words_of_names(): void
{
    assert_same([101, 102, 103], allowed(null, 'Presbyterian'));
    assert_same([104], allowed(null, 'Methodist'));
    assert_same([101, 102, 103], allowed('Presbyterians'), 'a tradition named as a denomination');
    assert_same([104], allowed('methodist episcopal'), 'a body\'s name without "Church"');
    assert_contains('Denomination "methodist episcopal": the records of the Methodist Episcopal Church', notes('methodist episcopal'));
    assert_same([104], allowed('episcopal church'), 'words of a body\'s name');
    assert_contains('No church body is named exactly "episcopal church"', notes('episcopal church'));
    assert_same([101], allowed('PCA', null, 1600, 1700), 'other filters still apply');
}

function test_unknown_denomination_lists_what_the_library_has(): void
{
    $e = assert_throws(SearchInputException::class, fn () => allowed('Church of the Flying Spaghetti'));
    $m = $e->getMessage();
    assert_contains('NOT FOUND: the library has no church records filed under "Church of the Flying Spaghetti"', $m);
    assert_contains('Presbyterian: Presbyterian Church in the United States; Presbyterian Church in the United States of America', $m);
    assert_contains('Methodist: Methodist Episcopal Church', $m);
    assert_contains('OPC, PCA, UMC', $m);
}

function test_filters_combine_and_say_so(): void
{
    $e = assert_throws(SearchInputException::class, fn () => allowed('UMC', 'Presbyterian'));
    assert_contains('No works in the library match these filters: denomination "UMC", church records of the '
        . 'Presbyterian tradition', $e->getMessage());
    $e = assert_throws(SearchInputException::class, fn () => allowed('PCUSA', null, 1900, 1950));
    assert_contains('The church records alone match 2 works: loosen the other filters.', $e->getMessage());
}

function test_label(): void
{
    $works = store()->works([101, 102, 103, 104]);
    assert_same('Church record: Presbyterian Church in the United States of America (1789–1958) · minutes · historical '
        . 'edition of 1668. Its current rules may differ, and the body may since have merged or divided.',
        Churches::label($works[101]));
    assert_same('Church record: Presbyterian Church in the Confederate States of America (Presbyterian Church in the '
        . 'United States, 1861–1983) · constitution · historical edition. Its current rules may differ, and the body '
        . 'may since have merged or divided.', Churches::label($works[102]));
    assert_same('Filed with the church records of: Methodist Episcopal Church (1784–1939) · history · historical; not a '
        . 'statement of that body\'s current position.', Churches::label($works[104]));
    $w = $works[101];
    $w['church']['status'] = 'current';
    assert_contains('edition of 1668, marked current in the library', Churches::label($w));
    assert_same('', Churches::label(Fixture\store_or_skip()->work(101)), 'the plain fixture has no church tables');
}

function test_tools(): void
{
    $r = call('search_library', ['query' => 'mortification of sin', 'denomination' => 'PCA']);
    $text = Fixture\text_of($r);
    assert_false($r['isError'], $text);
    assert_contains('Church record: Presbyterian Church in the', $text);
    assert_contains('Filters: denomination "PCA" (2 works).', $text);
    assert_contains('Never present them as a present-day church\'s current position.', $text);
    foreach (Fixture\result_ids($text) as $pid) {
        assert_true(in_array(intdiv($pid, 100000), [101, 102], true), "P$pid is not a PCA-line record");
    }
    $r = call('search_library', ['query' => 'sin', 'tradition' => 'presbyterian']);
    assert_false($r['isError'], Fixture\text_of($r));
    $r = call('search_library', ['query' => 'sin', 'tradition' => 'Druid']);
    assert_true($r['isError']);
    assert_contains('tradition must be one of: Presbyterian', Fixture\text_of($r));
    $r = call('search_library', ['query' => 'sin', 'denomination' => '()']);
    assert_contains('denomination must contain letters', Fixture\text_of($r));
    $r = call('find_works', ['denomination' => 'OPC']);
    $text = Fixture\text_of($r);
    assert_false($r['isError'], $text);
    assert_contains('Works 1-2 of 2', $text);
    assert_contains('Church record: Presbyterian Church in the United States of America (1789–1958) · digest', $text);
    $r = call('find_works', ['denomination' => 'Druids of Gaul']);
    assert_true($r['isError']);
    assert_contains('NOT FOUND: the library has no church records filed under "Druids of Gaul"', Fixture\text_of($r));
    $r = call('read_passage', ['ref' => 'P10200001']);
    assert_contains('Church record: Presbyterian Church in the Confederate States of America', Fixture\text_of($r));
}

function test_builds_without_the_church_tables(): void
{
    Fixture\store_or_skip();
    $r = Tools::call('search_library', ['query' => 'sin', 'denomination' => 'PCA'], Fixture\context());
    assert_true($r['isError']);
    assert_contains('Searching by denomination or tradition is not available in this copy of the library yet',
        Fixture\text_of($r));
    $r = Tools::call('search_library', ['query' => 'sin'], Fixture\context());
    assert_false($r['isError'], 'searches without the filters are unchanged');
    assert_not_contains('Church record', Fixture\text_of($r));
}

/**
 * A copy of the church build in which work 103 (Augustine) is a present-day document of the licensed shelf: the
 * URCNA's current church order, used by permission (collection "licensed", status "current"), and work 104 an
 * official record (status "record").
 */
function licensed_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $src = church_dir();
    $dir = col_tmpdir('licensed');
    foreach (['library.sqlite', 'vectors.bin', 'ids.bin', 'manifest.json', 'config.php'] as $f) {
        if (is_file("$src/$f")) {
            copy("$src/$f", "$dir/$f");
        }
    }
    $db = new \PDO("sqlite:$dir/library.sqlite");
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $db->exec("UPDATE works SET collection = 'licensed', year = 2026, source_url = 'https://urcna.org/church-order',
                   license = 'Used by permission' WHERE id IN (103, 104);
               DELETE FROM church_records WHERE work_id IN (103, 104);
               INSERT INTO church_records VALUES (103, 'United Reformed Churches in North America', 'Reformed',
                   'church_order', 'current');
               INSERT INTO church_records VALUES (104, 'United Reformed Churches in North America', 'Reformed', 'acts',
                   'record');
               INSERT INTO church_bodies VALUES ('United Reformed Churches in North America', 'Reformed', 1996, NULL,
                   '[\"United Reformed Churches in North America\"]', '[\"URCNA\"]', 1);");
    $db = null;
    return $dir;
}

function licensed_call(string $tool, array $args): array
{
    return Tools::call($tool, $args, Fixture\context(['data_dir' => licensed_dir()]));
}

function test_licensed_labels_and_rules(): void
{
    $works = (new Store(licensed_dir()))->works([103, 104]);
    assert_same('Church record: United Reformed Churches in North America (from 1996) · church order · CURRENT EDITION '
        . '(2026), the body\'s own present text (later amendments may not be in the library yet; the official copy is '
        . 'the authority).', Churches::label($works[103]));
    assert_contains('· acts of synod or assembly · official record (2026) of the body. Later synods',
        Churches::label($works[104]));

    $r = licensed_call('search_library', ['query' => 'grace', 'denomination' => 'URCNA']);
    $text = Fixture\text_of($r);
    assert_false($r['isError'], $text);
    assert_contains(Churches::PRESENT_RULE, $text);
    assert_not_contains(Churches::HISTORICAL_RULE, $text);
    assert_contains('Official copy: https://urcna.org/church-order', $text);
    assert_contains('Used by permission of its owner (not public domain)', $text);
    assert_not_contains('Copied from: https://urcna.org', $text);

    $r = licensed_call('search_library', ['query' => 'grace', 'tradition' => 'Reformed']);
    assert_false($r['isError'], Fixture\text_of($r));
    $r = licensed_call('find_works', ['denomination' => 'Druids of Gaul']);
    assert_contains('United Reformed Churches in North America (present-day documents, used by permission)',
        Fixture\text_of($r));
    // Mixed: a tradition filter over historical and present-day records.
    $notes = (new Filters(tradition: 'Presbyterian'))->resolve(new Store(licensed_dir()))['notes'];
    assert_true(in_array(Churches::HISTORICAL_RULE, $notes, true));
}

function test_licensed_read_passage_is_limited(): void
{
    $store = new Store(licensed_dir());
    $range = $store->passageRange(103);
    if ($range === null || $range[1] - $range[0] < 6) {
        col_skip('the fixture work is too short');
    }
    $mid = $range[0] + 3;
    $r = licensed_call('read_passage', ['ref' => "P$mid", 'before' => 10, 'after' => 10]);
    $text = Fixture\text_of($r);
    assert_false($r['isError'], $text);
    assert_contains('used by permission, so read_passage returns at most 2 passages before and 3 after', $text);
    assert_same(6, preg_match_all('/^P\d+\b/m', $text), 'the requested passage, 2 before and 3 after');
    $r = call('read_passage', ['ref' => "P$mid", 'before' => 10, 'after' => 10]);
    assert_not_contains('used by permission', Fixture\text_of($r), 'other works keep the full range');
    assert_same(7, preg_match_all('/^P\d+\b/m', Fixture\text_of($r)));
}

// ---------------------------------------------------------------------------------------------
// The licensed shelf's cap holds in every tool (src/Licensed.php; security sweep 2026-11-14, finding 17).

/** A context on the licensed build whose daily count of licensed passages is $perDay; reuse it to share the count. */
function licensed_ctx(int $perDay = 100): \ColLibrary\Context
{
    $cfg = Fixture\config(['data_dir' => licensed_dir()]);
    $log = \ColLibrary\Log::fromConfig($cfg);
    $limits = new \ColLibrary\RateLimit($cfg->stateDir(), 100000, 1000000, $cfg->maxVoyageTokensPerDay(), $log,
        fn () => Fixture\FIXED_TIME, null, \ColLibrary\RateLimit::PERSON_VOYAGE_DEFAULT, $perDay);
    return new \ColLibrary\Context($cfg, $limits, $log, microtime(true) + 25.0);
}

/** Refs of the passages a result prints in full ("[1] P10300000 ·" in a search, "P10300000 ·" in read_passage). */
function shown_refs(string $text): array
{
    preg_match_all('/^(?:\[\d+\] )?P(\d+)\b/m', $text, $m);
    return array_map('intval', $m[1]);
}

function of_work(array $refs, int $work): array
{
    return array_values(array_filter($refs, static fn (int $id): bool => intdiv($id, 100000) === $work));
}

function test_licensed_search_gives_a_few_passages_of_a_work_even_when_it_is_named(): void
{
    foreach ([['mode' => 'best']] as $mode) {     // (exact mode: the next test; no distinctive word is in four passages)
        $args = ['query' => 'God', 'work_id' => 103, 'limit' => 20] + $mode;
        $public = of_work(shown_refs(Fixture\text_of(call('search_library', $args))), 103);
        if (count($public) <= \ColLibrary\Licensed::PER_WORK) {
            col_skip('the fixture work has too few passages with the word');
        }
        $r = Tools::call('search_library', $args, licensed_ctx());
        $text = Fixture\text_of($r);
        assert_false($r['isError'], $text);
        $kept = of_work(shown_refs($text), 103);
        assert_same(array_slice($public, 0, 3), $kept, $mode['mode'] . ': the first three, in the same order');
        assert_contains('Documents used by permission: at most 3 passages of one such document are returned per call, so '
            . (count($public) - 3 === 1 ? '1 passage was' : (count($public) - 3) . ' passages were') . ' left out.', $text);
        assert_contains('"Official copy" (*The Confessions*: https://urcna.org/church-order)', $text);
        // Public-domain works are served exactly as before, by the same code and with the same daily count running.
        $again = Tools::call('search_library', ['query' => 'God', 'work_id' => 101, 'limit' => 20] + $mode, licensed_ctx(0));
        assert_same(Fixture\text_of(call('search_library', ['query' => 'God', 'work_id' => 101, 'limit' => 20] + $mode)),
            Fixture\text_of($again), 'a public-domain work is untouched, even with the licensed count at its limit');
    }
    // Without work_id: a mixed result keeps its public-domain passages and at most three of each licensed work.
    $r = Tools::call('search_library', ['query' => 'God', 'limit' => 20, 'mode' => 'exact'], licensed_ctx());
    $refs = shown_refs(Fixture\text_of($r));
    assert_true(count(of_work($refs, 103)) <= 3 && count(of_work($refs, 104)) <= 3);
    $plain = shown_refs(Fixture\text_of(call('search_library', ['query' => 'God', 'limit' => 20, 'mode' => 'exact'])));
    assert_same(array_merge(of_work($plain, 101), of_work($plain, 102)), array_merge(of_work($refs, 101), of_work($refs, 102)));
}

function test_licensed_works_cannot_be_paged_through_in_exact_mode(): void
{
    // A phrase of only common words: the whole document in book order, before the fix.
    $r = Tools::call('search_library', ['query' => 'and', 'work_id' => 103, 'mode' => 'exact', 'limit' => 20], licensed_ctx());
    $text = Fixture\text_of($r);
    assert_true($r['isError'], $text);
    assert_same([], shown_refs($text));
    assert_contains('Documents used by permission are not paged through in exact mode: a phrase of only common words', $text);
    assert_contains('https://urcna.org/church-order', $text);
    $public = call('search_library', ['query' => 'and', 'work_id' => 103, 'mode' => 'exact', 'limit' => 20]);
    assert_false($public['isError'], 'the same search on the public-domain copy still works');
    assert_true(count(shown_refs(Fixture\text_of($public))) > 3);
    // Deep offsets: Licensed::limit leaves out licensed matches after the first EXACT_DEPTH.
    $hit = static fn (int $id, string $collection): array => ['id' => $id, 'work' => ['id' => intdiv($id, 100000),
        'collection' => $collection, 'title' => 'T', 'source_url' => 'https://example.org/official']];
    $limits = licensed_ctx()->limits;
    $five = array_map(static fn (int $i) => $hit(10300000 + $i, 'licensed'), range(0, 4));
    $kept = \ColLibrary\Licensed::limit($five, $limits, 0, true);
    assert_same([10300000, 10300001, 10300002], array_column($kept['hits'], 'id'), 'three of one work per call in exact mode');
    assert_contains('at most 3 passages of one such document are returned per call, so 2 passages were left out', $kept['notes'][0]);
    // A distinctive word still finds its passages in a licensed work.
    $r = Tools::call('search_library', ['query' => 'heart', 'work_id' => 103, 'mode' => 'exact', 'limit' => 20], licensed_ctx());
    assert_same(shown_refs(Fixture\text_of(call('search_library', ['query' => 'heart', 'work_id' => 103, 'mode' => 'exact', 'limit' => 20]))),
        shown_refs(Fixture\text_of($r)));
    $kept = \ColLibrary\Licensed::limit([$hit(10300018, 'licensed'), $hit(10100019, 'ccel'), $hit(10300020, 'licensed'),
        $hit(10300021, 'licensed')], $limits, 18, true);
    assert_same([10300018, 10100019], array_column($kept['hits'], 'id'), 'matches 21 and later of a licensed work are left out');
    assert_same(2, $kept['dropped']);
    $kept = \ColLibrary\Licensed::limit([$hit(10300018, 'licensed'), $hit(10300020, 'licensed')], $limits, 18, false);
    assert_same(0, $kept['dropped'], 'the depth rule is for exact mode');
    $kept = \ColLibrary\Licensed::limit([$hit(10100001, 'ccel'), $hit(10200001, 'eebo-tcp')], licensed_ctx(0)->limits, 500, true, true);
    assert_same(['hits' => [$hit(10100001, 'ccel'), $hit(10200001, 'eebo-tcp')], 'notes' => [], 'dropped' => 0], $kept);
}

function test_licensed_passages_are_counted_per_person_per_day_in_every_tool(): void
{
    $store = new Store(licensed_dir());
    $range = $store->passageRange(103);
    if ($range === null || $range[1] - $range[0] < 6) {
        col_skip('the fixture work is too short');
    }
    $mid = $range[0] + 3;
    $ctx = licensed_ctx(8);
    $r = Tools::call('read_passage', ['ref' => "P$mid", 'before' => 2, 'after' => 3], $ctx);
    assert_same(6, count(shown_refs(Fixture\text_of($r))), 'six of the day\'s eight');
    assert_contains('at most 8 passages of such documents a day', Fixture\text_of(
        Tools::call('read_passage', ['ref' => "P$mid", 'before' => 9, 'after' => 0], licensed_ctx(8))), 'the number is said');
    // Two left: the requested passage and the one after it; the pointers still lead to the rest.
    $r = Tools::call('read_passage', ['ref' => "P$mid", 'before' => 2, 'after' => 3], $ctx);
    $text = Fixture\text_of($r);
    assert_false($r['isError'], $text);
    assert_same([$mid, $mid + 1], shown_refs($text));
    assert_contains('this account has reached today\'s limit of 8 passages from them', $text);
    assert_contains('The limit resets at 00:00 UTC.', $text);
    assert_contains('Official copy: https://urcna.org/church-order', $text);
    assert_contains('Earlier text: ref P' . ($mid - 1), $text);
    assert_contains('Later text: ref P' . ($mid + 2), $text);
    // Nothing left: every tool leaves licensed text out and points to the official copy.
    $r = Tools::call('read_passage', ['ref' => "P$mid"], $ctx);
    assert_true($r['isError']);
    assert_same([], shown_refs(Fixture\text_of($r)));
    assert_contains('today\'s limit of 8 passages', Fixture\text_of($r));
    assert_contains('Official copy: https://urcna.org/church-order', Fixture\text_of($r));
    foreach ([['mode' => 'best'], ['mode' => 'exact']] as $mode) {
        $r = Tools::call('search_library', ['query' => 'God', 'work_id' => 103] + $mode, $ctx);
        assert_true($r['isError'], $mode['mode']);
        assert_same([], shown_refs(Fixture\text_of($r)));
        assert_contains('today\'s limit of 8 passages', Fixture\text_of($r));
        $r = Tools::call('search_library', ['query' => 'God', 'limit' => 20] + $mode, $ctx);
        $refs = shown_refs(Fixture\text_of($r));
        assert_same([], array_merge(of_work($refs, 103), of_work($refs, 104)), 'no licensed passage in a mixed result');
        assert_true(count($refs) > 0, 'the public-domain passages are still served');
    }
    $quote = ['quote' => 'for Thou hast made us for Thyself, and our heart is restless'];
    $with = Fixture\text_of(Tools::call('check_quote', $quote, licensed_ctx(8)));
    assert_contains('Thou movest us to delight in praising Thee', $with, 'the text around the words, within the limit');
    $without = Fixture\text_of(Tools::call('check_quote', $quote, $ctx));
    assert_contains('FOUND WORD FOR WORD', $without, 'the verdict stays');
    assert_not_contains('Thou movest us to delight in praising Thee', $without);
    assert_contains('The text around the words is left out. Official copy: https://urcna.org/church-order', $without);
    // The count is the person's: another person (another state) starts at nought; public-domain reads never count.
    $r = Tools::call('read_passage', ['ref' => 'P10100003', 'before' => 3, 'after' => 3], $ctx);
    assert_same(7, count(shown_refs(Fixture\text_of($r))));
}

function test_licensed_citations_are_capped_and_counted(): void
{
    $store = new Store(licensed_dir());
    $db = new \PDO('sqlite:' . licensed_dir() . '/library.sqlite');
    $row = $db->query('SELECT b.name, s.chapter, count(DISTINCT s.passage_id) n FROM scripture_refs s
        JOIN passages p ON p.id = s.passage_id JOIN bible_books b ON b.id = s.book
        WHERE p.work_id IN (103, 104) GROUP BY s.book, s.chapter ORDER BY n DESC LIMIT 1')->fetch(\PDO::FETCH_NUM);
    $db = null;
    if ($row === false) {
        col_skip('the fixture\'s licensed works cite no scripture');
    }
    $args = ['reference' => $row[0] . ' ' . $row[1], 'limit' => 20];
    $public = shown_refs(Fixture\text_of(call('find_scripture_citations', $args)));
    $r = Tools::call('find_scripture_citations', $args, licensed_ctx());
    $refs = shown_refs(Fixture\text_of($r));
    assert_true(count(of_work($refs, 103)) <= 3 && count(of_work($refs, 104)) <= 3);
    assert_same(array_merge(of_work($public, 101), of_work($public, 102)), array_merge(of_work($refs, 101), of_work($refs, 102)));
    $r = Tools::call('find_scripture_citations', $args + ['work_id' => 103], licensed_ctx());
    assert_true(count(shown_refs(Fixture\text_of($r))) <= 3, 'also when the work is named');
    $r = Tools::call('find_scripture_citations', $args, licensed_ctx(0));
    $refs = shown_refs(Fixture\text_of($r));
    assert_same([], array_merge(of_work($refs, 103), of_work($refs, 104)), 'the daily count holds here too');
    assert_contains('today\'s limit of 0 passages', Fixture\text_of($r));
}

function test_the_licensed_count_fails_closed_and_is_per_person(): void
{
    $dir = col_tmpdir('lic');
    $mk = static fn (?array $link): \ColLibrary\RateLimit => new \ColLibrary\RateLimit($dir, 100, 1000, 1000, null,
        fn () => Fixture\FIXED_TIME, $link, \ColLibrary\RateLimit::PERSON_VOYAGE_DEFAULT, 5);
    $a = $mk(['id' => 'aaaa', 'calls_per_day' => 10, 'calls_per_minute' => 10]);
    assert_same(3, $a->takeLicensed(3));
    assert_same(2, $a->takeLicensed(3), 'only what is left');
    assert_same(0, $a->takeLicensed(1));
    assert_same(5, $mk(['id' => 'bbbb', 'calls_per_day' => 10, 'calls_per_minute' => 10])->takeLicensed(9), 'another person');
    assert_same(0, $a->takeLicensed(0));
    $broken = new \ColLibrary\RateLimit('/nonexistent/' . bin2hex(random_bytes(4)) . "/\0", 100, 1000, 1000);
    assert_same(0, @$broken->takeLicensed(2), 'no count, no licensed text');
    assert_same(100, \ColLibrary\Config::fromArray(['url_key' => 'Zx9_-abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG',
        'data_dir' => $dir, 'state_dir' => $dir])->maxLicensedPassagesPerPersonPerDay(), 'the default');
}

function test_church_body_names_are_inert_in_notes(): void
{
    $dir = col_tmpdir('inert');
    foreach (['library.sqlite', 'vectors.bin', 'ids.bin', 'manifest.json', 'config.php'] as $f) {
        if (is_file(church_dir() . "/$f")) {
            copy(church_dir() . "/$f", "$dir/$f");
        }
    }
    $db = new \PDO("sqlite:$dir/library.sqlite");
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $evil = 'Church of ![x](https://evil.example/p?d=1) <img src=x>';
    $db->prepare("INSERT INTO church_bodies VALUES (?, 'Reformed', 1900, NULL, ?, ?, 1)")
        ->execute([$evil, json_encode([$evil]), json_encode(['EVILCHURCH'])]);
    $db->prepare("INSERT INTO church_records VALUES (104, ?, 'Reformed', 'confession', 'historical')
                  ON CONFLICT(work_id) DO UPDATE SET denomination = excluded.denomination")->execute([$evil]);
    $db = null;
    foreach ([['search_library', ['query' => 'grace', 'denomination' => 'EVILCHURCH']],
              ['find_works', ['denomination' => 'Druids of Gaul']]] as [$tool, $args]) {
        $text = Fixture\text_of(Tools::call($tool, $args, Fixture\context(['data_dir' => $dir])));
        assert_contains('Church of', $text, $tool);
        assert_not_contains('![x](', $text, "$tool: no image can form");
        assert_not_contains(' <img', $text, "$tool: no tag can form");
    }
}

function test_a_body_that_allows_only_links_gets_its_link_and_wording(): void
{
    $dir = col_tmpdir('crc');
    foreach (['library.sqlite', 'vectors.bin', 'ids.bin', 'manifest.json', 'config.php'] as $f) {
        if (is_file(church_dir() . "/$f")) {
            copy(church_dir() . "/$f", "$dir/$f");
        }
    }
    $db = new \PDO("sqlite:$dir/library.sqlite");
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $crc = 'Christian Reformed Church in North America';
    $db->prepare("INSERT INTO church_bodies VALUES (?, 'Reformed', 1857, NULL, ?, ?, 1)")
        ->execute([$crc, json_encode([$crc]), json_encode(['CRC', 'CRCNA'])]);
    $db->prepare("INSERT INTO church_names VALUES (?, ?, 'Reformed', ?, 1)")
        ->execute([$crc, json_encode(['CRC', 'CRCNA']), json_encode([[$crc, null, null]])]);
    $db->prepare("INSERT INTO church_names VALUES ('United Reformed Churches in North America', ?, 'Reformed', ?, 1)")
        ->execute([json_encode(['URCNA']), json_encode([[$crc, null, 1995]])]);
    $db->prepare("INSERT INTO church_records VALUES (104, ?, 'Reformed', 'church_order', 'historical')
                  ON CONFLICT(work_id) DO UPDATE SET denomination = excluded.denomination, doc_kind = excluded.doc_kind")->execute([$crc]);
    $db = null;
    $store = new Store($dir);
    $n = static fn (string $d): string => implode("\n", (new Filters(denomination: $d))->resolve($store)['notes']);
    foreach (['CRC', 'CRCNA', 'Christian Reformed Church'] as $d) {
        assert_contains('https://www.crcna.org/synod/church-order-and-forms', $n($d), $d);
        assert_contains('"This link is included with the permission of the Christian Reformed Church in North America. '
            . 'The material on crcna.org is copyrighted and maintained by the Christian Reformed Church in North America."', $n($d), $d);
    }
    try {
        $urc = $n('URCNA');
    } catch (SearchInputException $e) {
        $urc = $e->getMessage();     // the fixture's CRC record has no year, so the URCNA's years leave nothing
    }
    assert_contains('United Reformed Churches', $urc);
    assert_not_contains('crcna.org', $urc, 'a body descended from the CRC does not get its link');
    assert_not_contains('crcna.org', notes('PCA'));
}


// ---------------------------------------------------------------------------------------------
// Names people use (2026-11-19 denomination eval) and a body's own present-day documents first (DESIGN.md 11).

function test_names_without_church_or_plural_s(): void
{
    assert_same([104], allowed('United Methodist'), 'a present-day name without "Church"');
    assert_same([104], allowed('The United Methodist Churches'));
    assert_same([101, 103], allowed('Orthodox Presbyterian'));
    assert_contains('"Orthodox Presbyterian (the Orthodox Presbyterian Church)"', notes('Orthodox Presbyterian'));
    assert_same([102], allowed('Southern Presbyterians'), 'another name with a plural s');
    assert_same([104], allowed('Methodist'), 'a tradition stays a tradition');
    assert_contains('the records of all 1 Methodist bodies', notes('Methodist'));
    assert_same([101, 102, 103], allowed('Presbyterian church'), 'a tradition with "church"');
    assert_throws(SearchInputException::class, fn () => allowed('Methodist Presbyterian Union'));
    assert_same('unitedmethodist', Churches::looseKey('The United Methodist Church'));
    assert_same('reformedbaptist', Churches::looseKey('Reformed Baptists'));
}

/**
 * The licensed build with the URCNA as a present-day name that descends from the PCUSA (work 101, historical):
 * its own documents 103 (current church order) and 104 (an official record) among the history.
 */
function own_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $src = licensed_dir();
    $dir = col_tmpdir('own');
    foreach (['library.sqlite', 'vectors.bin', 'ids.bin', 'manifest.json', 'config.php'] as $f) {
        if (is_file("$src/$f")) {
            copy("$src/$f", "$dir/$f");
        }
    }
    $db = new \PDO("sqlite:$dir/library.sqlite");
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $db->prepare("INSERT INTO church_names VALUES ('United Reformed Churches in North America', ?, 'Reformed', ?, 1)")
        ->execute([json_encode(['URCNA', 'URC']),
                   json_encode([['Presbyterian Church in the United States of America', null, null]])]);
    $db = null;
    return $dir;
}

function own_call(string $tool, array $args): array
{
    return Tools::call($tool, $args, Fixture\context(['data_dir' => own_dir()]));
}

function test_resolve_gives_the_named_body_s_own_present_day_documents(): void
{
    $store = new Store(own_dir());
    $r = (new Filters(denomination: 'URCNA'))->resolve($store);
    $ids = $r['ids'];
    sort($ids);
    assert_same([101, 103, 104], $ids);
    assert_same([103, 104], $r['own']);
    assert_same('United Reformed Churches in North America', $r['own_body']);
    assert_same([], (new Filters(denomination: 'PCUSA'))->resolve($store)['own'], 'no present-day documents');
    assert_same([], (new Filters(tradition: 'Reformed'))->resolve($store)['own'], 'a tradition names no body');
    assert_same([], (new Filters())->resolve($store)['own']);
    assert_same([], (new Filters(denomination: 'URCNA', yearTo: 1700))->resolve($store)['own'], 'other filters apply');
}

function cand(int $work, ?float $score): array
{
    static $n = 0;
    $n++;
    return ['id' => $work * 100000 + $n, 'work_id' => $work] + ($score !== null ? ['score' => $score] : []);
}

function works_of(array $cands): array
{
    return array_map(static fn (array $c): int => $c['work_id'], $cands);
}

function test_own_passages_come_first_by_the_floors(): void
{
    $own = [7 => true];
    // matching own passages (partial or better) first, in re-ranked order; the rest keep theirs
    $c = [cand(1, 0.9), cand(7, 0.6), cand(2, 0.8), cand(7, 0.5), cand(7, 0.2)];
    assert_same([7, 7, 1, 2, 7], works_of(Search::ownFirst($c, $own)));
    // below the floor, or not scored: no change (a weak own passage is not pushed up: 2026-11-19 eval)
    $c = [cand(1, 0.9), cand(2, 0.8), cand(3, 0.7), cand(7, 0.44), cand(7, null)];
    assert_same([1, 2, 3, 7, 7], works_of(Search::ownFirst($c, $own)));
    assert_same([1, 2], works_of(Search::ownFirst([cand(1, 0.9), cand(2, 0.8)], [])), 'no own works: unchanged');
    assert_same(Search::MATCH_LOOSE, Search::OWN_FLOOR, 'the floor is the "partial" label');
    // without re-ranking: every other place from the second
    $c = [cand(1, null), cand(2, null), cand(7, null), cand(3, null), cand(7, null), cand(7, null), cand(7, null)];
    assert_same([1, 7, 2, 7, 3, 7, 7], works_of(Search::ownInterleaved($c, $own)));
}

function test_search_lists_matching_own_documents_first(): void
{
    foreach (['God', 'mortification of sin', 'grace of God'] as $q) {
        $r = own_call('search_library', ['query' => $q, 'denomination' => 'URCNA', 'limit' => 8]);
        $text = Fixture\text_of($r);
        assert_false($r['isError'], $text);
        preg_match_all('/^\[\d+\] P(\d+) .*?(?:Match: (\w+[\w ]*))?$/m', $text, $m, PREG_SET_ORDER);
        $seenOther = false;
        foreach ($m as $hit) {
            $own = in_array(intdiv((int) $hit[1], 100000), [103, 104], true);
            $strong = in_array($hit[2] ?? '', ['close', 'partial', 'exact words'], true);
            if ($own && $strong) {
                assert_false($seenOther, "$q: a matching own passage after another body's: $text");
            }
            $seenOther = $seenOther || !$own;
        }
        assert_true(str_contains($text, 'itself (used by permission) are listed first')
            || str_contains($text, 'itself matches this question well'), $text);
    }
    $r = call('search_library', ['query' => 'God', 'denomination' => 'PCA']);
    assert_not_contains(Churches::OWN_NOTE, Fixture\text_of($r), 'no present-day documents: no note');
}

function test_other_tools_list_own_documents_first(): void
{
    $r = own_call('find_works', ['denomination' => 'URCNA']);
    $text = Fixture\text_of($r);
    assert_false($r['isError'], $text);
    preg_match_all('/^\[\d+\] work_id (\d+)/m', $text, $m);
    assert_same(['103', '104', '101'], $m[1], 'current edition, official record, then the history');
    assert_contains('itself (used by permission) are listed first', $text);
    $r = licensed_call('find_works', ['denomination' => 'URCNA']);
    preg_match_all('/^\[\d+\] work_id (\d+)/m', Fixture\text_of($r), $m);
    assert_same(['103', '104'], $m[1], 'only own documents: current first, no note');
    assert_not_contains('listed first', Fixture\text_of($r));

    $r = own_call('search_library', ['query' => 'God', 'mode' => 'exact', 'denomination' => 'URCNA', 'limit' => 20]);
    $text = Fixture\text_of($r);
    assert_false($r['isError'], $text);
    assert_contains('(its own present-day documents first, then in book order)', $text);
    $works = array_map(static fn (int $id): int => intdiv($id, 100000), shown_refs($text));
    $firstOther = array_search(101, $works, true);
    assert_true($firstOther !== false && $firstOther > 0, implode(',', $works));
    assert_same([], array_filter(array_slice($works, (int) $firstOther), static fn (int $w): bool => $w !== 101),
        'own matches before the others: ' . implode(',', $works));
    $page2 = own_call('search_library', ['query' => 'God', 'mode' => 'exact', 'denomination' => 'URCNA', 'limit' => 2,
        'offset' => 2]);
    $all = own_call('search_library', ['query' => 'God', 'mode' => 'exact', 'denomination' => 'URCNA', 'limit' => 4]);
    assert_same(array_slice(shown_refs(Fixture\text_of($all)), 2, 2), shown_refs(Fixture\text_of($page2)),
        'paging walks own matches, then the rest');
    $r = own_call('search_library', ['query' => 'God', 'mode' => 'exact', 'denomination' => 'PCUSA']);
    assert_contains('(in book order)', Fixture\text_of($r));
}

function test_citations_list_own_documents_first_within_each_group(): void
{
    $p = [
        1 => ['work_id' => 10, 'group' => 1], 2 => ['work_id' => 11, 'group' => 1], 3 => ['work_id' => 12, 'group' => 1],
        4 => ['work_id' => 11, 'group' => 2], 5 => ['work_id' => 10, 'group' => 2], 6 => ['work_id' => 12, 'group' => 1],
    ];
    $years = [10 => 1700, 11 => 1800, 12 => 2026];
    assert_same([1, 2, 3, 6, 5, 4], \ColLibrary\Citations::order($p, $years), 'earliest first');
    assert_same([3, 6, 1, 2, 5, 4], \ColLibrary\Citations::order($p, $years, [12 => true]), 'own work 12 first in group 1');
    assert_same([2, 1, 3, 6, 4, 5], \ColLibrary\Citations::order($p, $years, [11 => true]), 'in both groups');
}

function test_the_own_note_gives_way_to_the_daily_cap(): void
{
    $cfg = Fixture\config(['data_dir' => own_dir()]);
    $log = \ColLibrary\Log::fromConfig($cfg);
    $limits = new \ColLibrary\RateLimit($cfg->stateDir(), 100000, 1000000, $cfg->maxVoyageTokensPerDay(), $log,
        fn () => Fixture\FIXED_TIME, null, \ColLibrary\RateLimit::PERSON_VOYAGE_DEFAULT, 0);
    $ctx = new \ColLibrary\Context($cfg, $limits, $log, microtime(true) + 25.0);
    assert_same(0, $limits->licensedLeft());
    $r = Tools::call('search_library', ['query' => 'God', 'denomination' => 'URCNA', 'limit' => 8], $ctx);
    $text = Fixture\text_of($r);
    assert_false($r['isError'], $text);
    assert_contains("this account has reached today's limit of passages from them, so the present-day documents of the "
        . 'United Reformed Churches in North America itself are not listed first', $text);
    assert_not_contains(Churches::OWN_NOTE, $text, 'no promise of documents the cap left out');
    assert_true(in_array(101, array_map(static fn (int $id): int => intdiv($id, 100000), shown_refs($text)), true),
        'the history is shown: ' . $text);
    assert_same([], array_filter(shown_refs($text), static fn (int $id): bool => intdiv($id, 100000) !== 101));
    $r = Tools::call('find_scripture_citations', ['reference' => 'Romans 8', 'denomination' => 'URCNA'], $ctx);
    assert_not_contains(Churches::OWN_NOTE, Fixture\text_of($r));
}

function test_own_candidates_always_reach_the_reranker(): void
{
    $store = new Store(own_dir());
    foreach ([2, 50] as $rc) {
        $search = new Search($store, Fixture\fake_voyage($store), microtime(true) + 25.0, 8, null, 1.0,
            Search::EXACT_SCAN_MAX, $rc);
        $search->best('the grace of God and the knowledge of God', new Filters(denomination: 'URCNA'), 5);
        assert_true(isset($search->trace['own_best']), "rerank_candidates $rc: own passages were scored");
        assert_same(Search::OWN_TOP <= 7 ? Search::OWN_TOP : 7, min($search->trace['own_candidates'], 7));
    }
}

function test_exact_mode_lists_a_few_of_each_own_document_then_the_history(): void
{
    $r = own_call('search_library', ['query' => 'God', 'mode' => 'exact', 'denomination' => 'URCNA', 'limit' => 20]);
    $text = Fixture\text_of($r);
    $works = array_map(static fn (int $id): int => intdiv($id, 100000), shown_refs($text));
    $of103 = count(array_filter($works, static fn (int $w): bool => $w === 103));
    assert_true($of103 >= 1 && $of103 <= \ColLibrary\Licensed::PER_WORK, $text);
    assert_same(101, $works[count($works) - 1], 'the history after: ' . implode(',', $works));
    assert_same(103, $works[0], 'the current edition first');
    assert_contains('Own documents: the first matches in the present-day documents of the United Reformed Churches in '
        . 'North America itself (used by permission) are listed first', $text);
    assert_not_contains('left out', $text, 'nothing listed is then dropped by the licensed shelf\'s rules');
    $r = own_call('search_library', ['query' => 'the', 'mode' => 'exact', 'denomination' => 'URCNA']);
    assert_not_contains('Own documents', Fixture\text_of($r), 'a phrase of only common words: no promotion');
}

function test_a_present_day_name_that_is_a_body_includes_its_own_records(): void
{
    // The URCNA is a body (licensed_dir) and, in own_dir, a present-day name; "URC Synods" is an other name only
    // on the present-day row: its own records must be searched too, not only its ancestors' (2026-11-19).
    $dir = col_tmpdir('ownalias');
    foreach (['library.sqlite', 'vectors.bin', 'ids.bin', 'manifest.json', 'config.php'] as $f) {
        if (is_file(own_dir() . "/$f")) {
            copy(own_dir() . "/$f", "$dir/$f");
        }
    }
    $db = new \PDO("sqlite:$dir/library.sqlite");
    $db->exec("UPDATE church_names SET aliases = '[\"URCNA\", \"URC\", \"URC Synods\"]'
               WHERE name = 'United Reformed Churches in North America'");
    $db = null;
    $r = (new Filters(denomination: 'URC Synods'))->resolve(new Store($dir));
    $ids = $r['ids'];
    sort($ids);
    assert_same([101, 103, 104], $ids);
    assert_same([103, 104], $r['own']);
}

function test_find_works_lists_own_records_newest_first(): void
{
    $dir = col_tmpdir('ownyears');
    foreach (['library.sqlite', 'vectors.bin', 'ids.bin', 'manifest.json', 'config.php'] as $f) {
        if (is_file(own_dir() . "/$f")) {
            copy(own_dir() . "/$f", "$dir/$f");
        }
    }
    $db = new \PDO("sqlite:$dir/library.sqlite");
    $db->exec("UPDATE church_records SET status = 'record' WHERE work_id = 103; UPDATE works SET year = 1999 WHERE id = 104");
    $db = null;
    $r = Tools::call('find_works', ['denomination' => 'URCNA'], Fixture\context(['data_dir' => $dir]));
    preg_match_all('/^\[\d+\] work_id (\d+)/m', Fixture\text_of($r), $m);
    assert_same(['103', '104', '101'], $m[1], 'records newest first (2026, 1999), then the history');
    $db = new \PDO("sqlite:$dir/library.sqlite");
    $db->exec("UPDATE works SET year = 2030 WHERE id = 104");
    $db = null;
    $r = Tools::call('find_works', ['denomination' => 'URCNA'], Fixture\context(['data_dir' => $dir]));
    preg_match_all('/^\[\d+\] work_id (\d+)/m', Fixture\text_of($r), $m);
    assert_same(['104', '103', '101'], $m[1]);
}

/** A copy of the church fixture where only 101 and 102 are church records: 103 (Augustine) and 104 are writers' works. */
function writers_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $src = church_dir();
    $dir = col_tmpdir('church-writers');
    foreach (['library.sqlite', 'vectors.bin', 'ids.bin', 'manifest.json', 'config.php'] as $f) {
        if (is_file("$src/$f")) {
            copy("$src/$f", "$dir/$f");
        }
    }
    $db = new \PDO("sqlite:$dir/library.sqlite");
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $db->exec("DELETE FROM church_records WHERE work_id IN (103, 104);
        INSERT OR REPLACE INTO authors VALUES ('Théodore de Bèze', '1519–1605', 'Reformed', 'Genevan Reformer')");
    $db = null;
    return $dir;
}

function test_the_tradition_filter_also_takes_writers_of_the_tradition(): void
{
    $store = new Store(writers_dir());
    $ids = static function (?string $tradition, ?string $denomination = null) use ($store): array {
        $r = (new Filters(denomination: $denomination, tradition: $tradition))->resolve($store);
        sort($r['ids']);
        return $r['ids'];
    };
    assert_same([103], $ids('Catholic'), 'a Church Father counts as Catholic');
    assert_same([103], $ids('Orthodox'), 'and as Orthodox');
    assert_same([104], $ids('Reformed'), 'a writer by the tradition of the authors table');
    assert_same([101, 102], $ids('Presbyterian'), 'church records stay; Owen\'s works here are records');
    $r = (new Filters(denomination: 'PCUSA', tradition: 'Presbyterian'))->resolve($store);
    assert_same([101], $r['ids'], 'with a denomination only that body\'s records, no writers');
    $notes = implode("\n", (new Filters(tradition: 'Catholic'))->resolve($store)['notes']);
    assert_contains('the works of 1 writer of the tradition (1 work', $notes);
    assert_contains('the Catholic tradition (church records and writers)', $notes);
    assert_not_contains(Churches::HISTORICAL_RULE, $notes, 'no church-record rule without church records');
    try {
        (new Filters(tradition: 'Methodist'))->resolve($store);
        assert_true(false, 'no records and no writers is an error');
    } catch (SearchInputException $e) {
        assert_contains('No works in the library match these filters', $e->getMessage());
        assert_contains('the library lists no writers of this tradition, so only church records', $e->getMessage());
    }
}

function test_writer_traditions(): void
{
    $w = static fn (string $t, string $note = '', string $name = 'X'): array => Churches::writerTraditions($name, $t, $note);
    assert_same(['Catholic', 'Orthodox'], $w('Church Father'));
    assert_same(['Catholic'], $w('Medieval'));
    assert_same(['Catholic'], $w('Roman Catholic'));
    assert_same(['Congregational'], $w('Congregationalist'));
    assert_same(['Moravian'], $w('Medieval', '', 'Jan Hus'));
    assert_same([], $w('Medieval', '', 'John Wycliffe'));
    foreach (['Other', 'Other Christian', 'Jewish', 'Philosopher', 'Classical', 'Brethren', '', 'Unknown value'] as $t) {
        assert_same([], $w($t), "$t counts for no tradition");
    }
    assert_same(['Presbyterian'], $w('Puritan', 'English Presbyterian minister, Westminster Assembly member'));
    assert_same(['Congregational'], $w('Puritan', 'English Puritan Independent minister'));
    assert_same(['Congregational'], $w('Puritan', 'English-born Puritan minister, founder of Hartford, Connecticut'));
    assert_same(['Baptist'], $w('Puritan', 'English Baptist preacher and writer, Bedford'));
    assert_same(['Anglican'], $w('Puritan', 'English Cambridge theologian and preacher, Church of England'));
    assert_same(['Presbyterian', 'Congregational'], $w('Puritan', 'English Nonconformist minister, Church of England, ejected 1662'),
        'an ejected minister is not counted Anglican');
    assert_same(['Presbyterian', 'Congregational'], $w('Puritan', 'English Puritan preacher, London'), 'no church named');
    assert_same(['Presbyterian', 'Congregational'], $w('Puritan', 'Baptism-debate writer'), '"Baptism" is not "Baptist"');
    foreach (Churches::WRITER_TRADITIONS as $t => $list) {
        foreach ($list as $c) {
            assert_true(in_array($c, Churches::TRADITIONS, true), "$t maps to a tradition of the filter ($c)");
        }
    }
}
