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
    assert_same([104], allowed('methodist episcopal'), 'words of a body\'s name');
    assert_contains('No church body is named exactly "methodist episcopal"', notes('methodist episcopal'));
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
