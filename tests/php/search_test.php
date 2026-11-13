<?php
/**
 * Tests for src/Search.php and src/Store.php on the fixture build: keyword ranking and vector
 * scores against values computed in Python, RRF, near-duplicate collapse, filters, keyword
 * relaxation, exact-phrase paging, and the fallbacks when Voyage fails or time runs short.
 */

declare(strict_types=1);

namespace ColLibraryTest\Search;

use ColLibrary\Filters;
use ColLibrary\RateLimit;
use ColLibrary\Search;
use ColLibrary\SearchInputException;
use ColLibrary\Store;
use ColLibrary\Text;
use ColLibrary\Voyage;
use ColLibraryTest\Fixture;

require_once __DIR__ . '/fixture/testlib.php';

function search(?Store $store = null, ?Voyage $voyage = null, float $seconds = 25.0, int $nprobe = 2): Search
{
    $store ??= Fixture\store_or_skip();
    return new Search($store, $voyage ?? Fixture\fake_voyage($store), microtime(true) + $seconds, $nprobe);
}

/** @return list<int> */
function ids(array $hits): array
{
    return array_map(fn (array $h): int => $h['id'], $hits);
}

function test_keyword_ranking_matches_python(): void
{
    $store = Fixture\store_or_skip();
    foreach (Fixture\expected()['fts'] as $case) {
        $got = $store->keywordSearch($case['match'], null, 100);
        assert_same($case['ids'], array_keys($got), "bm25 order for {$case['match']}");
        foreach ($case['scores'] as $i => $score) {
            assert_true(abs($score - $got[$case['ids'][$i]]) < 1e-9, 'bm25 score');
        }
    }
}

function test_work_filters_in_sql(): void
{
    $store = Fixture\store_or_skip();
    $all = array_keys($store->keywordSearch('"sin"', null, 100));
    foreach ([[101], [102, 104], [101, 103, 104], [101, 102, 103, 104], []] as $works) {
        $got = array_keys($store->keywordSearch('"sin"', $works, 100));
        $want = array_values(array_filter($all, fn (int $id): bool => in_array(intdiv($id, 100000), $works, true)));
        assert_same($want, $got, 'works ' . json_encode($works));
        $inOrder = $store->matchInOrder('"sin"', $works, 100, 0);
        $sorted = $want;
        sort($sorted);
        assert_same($sorted, $inOrder, 'book order for works ' . json_encode($works));
    }
}

function test_score_rows_matches_naive_dot_products(): void
{
    $dims = 16;
    mt_srand(7);
    $buf = '';
    for ($i = 0; $i < 11 * $dims; $i++) {
        $buf .= chr(mt_rand(0, 255));
    }
    $q = [];
    for ($d = 1; $d <= $dims; $d++) {
        $q[$d] = mt_rand(-1000, 1000) / 1000;
    }
    $naive = [];
    foreach (str_split($buf, $dims) as $r => $row) {
        $s = 0.0;
        foreach (array_values(unpack('c*', $row)) as $d => $x) {
            $s += $q[$d + 1] * $x;
        }
        $naive[$r] = $s;
    }
    $all = Search::scoreRows($q, $buf, $dims);
    assert_same(11, count($all), '4 + 4 + 3 rows: blocks of four and a tail');
    foreach ($naive as $r => $s) {
        assert_true(abs($s - $all[$r]) < 1e-9, "row $r");
    }
    $some = Search::scoreRows($q, $buf, $dims, [10, 3, 0]);
    assert_same([10, 3, 0], array_keys($some));
    assert_same($all[3], $some[3]);
    assert_same([], Search::scoreRows($q, '', $dims));
    assert_throws(\OutOfRangeException::class, fn () => Search::scoreRows($q, $buf, $dims, [11]));
    $floats = pack('g*', ...array_values($q)) . pack('g*', ...array_fill(0, $dims, 1.0));
    $f = Search::scoreRows($q, $floats, $dims, null, 'g');
    $sq = 0.0;
    foreach ($q as $w) {
        $sq += $w * (float) unpack('g', pack('g', $w))[1];
    }
    assert_true(abs($f[0] - $sq) < 1e-6 && abs($f[1] - array_sum($q)) < 1e-5, 'float32 rows (centroids)');
}

function test_vector_exact_scan_matches_numpy(): void
{
    $store = Fixture\store_or_skip();
    foreach (Fixture\expected()['vector_exact'] as $case) {
        $notes = [];
        $q = Voyage::fakeEmbed($case['query'], 8);
        // all four works = 28 passages <= 20,000: the exact scan through passages.vec_pos
        $s = search($store);
        $got = $s->vectorSearch($q, [101, 102, 103, 104], 100, $notes);
        assert_same('exact', $s->trace['scan']);
        check_ranking($case, $got);
    }
}

function test_ivf_probe_matches_numpy(): void
{
    $store = Fixture\store_or_skip();
    foreach (Fixture\expected()['ivf'] as $case) {
        $notes = [];
        $s = search($store, null, 25.0, $case['nprobe']);
        $got = $s->vectorSearch(Voyage::fakeEmbed($case['query'], 8), null, 100, $notes);
        assert_same('ivf', $s->trace['scan']);
        check_ranking($case, $got);
        assert_same(count($case['ids']), $s->trace['rows_scored'], 'rows of the two probed lists');
    }
}

/** Same ids as Python, and in non-increasing order of Python's scores (ties may differ). */
function check_ranking(array $case, array $got): void
{
    $want = $case['ids'];
    $sortedGot = $got;
    sort($sortedGot);
    sort($want);
    assert_same($want, $sortedGot, 'same passages for "' . $case['query'] . '"');
    $score = array_combine($case['ids'], $case['scores']);
    for ($i = 1; $i < count($got); $i++) {
        assert_true($score[$got[$i - 1]] >= $score[$got[$i]] - 1e-9, 'ranked by score for "' . $case['query'] . '"');
    }
    assert_same($case['ids'][0], $got[0], 'best hit');
}

/** A filter allowing more passages than the exact-scan limit: IVF lists, other works' rows skipped. */
function test_filtered_ivf_scan(): void
{
    $store = Fixture\store_or_skip();
    foreach (Fixture\expected()['vector_exact'] as $case) {
        $notes = [];
        $s = new Search($store, Fixture\fake_voyage($store), microtime(true) + 25, 2, null, 1.0, 5);
        $got = $s->vectorSearch(Voyage::fakeEmbed($case['query'], 8), [101, 102], 100, $notes);
        assert_same('ivf', $s->trace['scan']);
        assert_same(4, $s->trace['lists'], 'nprobe 2 x min(3, 27/15 -> 2) = 4 lists');
        assert_same(15, $s->trace['rows_scored'], 'only the rows of works 101 and 102 are scored');
        // Python's exact ranking, restricted to the two works
        $filtered = ['query' => $case['query'], 'ids' => [], 'scores' => []];
        foreach ($case['ids'] as $i => $id) {
            if (in_array(intdiv($id, 100000), [101, 102], true)) {
                $filtered['ids'][] = $id;
                $filtered['scores'][] = $case['scores'][$i];
            }
        }
        check_ranking($filtered, $got);
    }
}

function test_sign_prefilter(): void
{
    $dims = 8;
    $rows = pack('c*', 5, 5, 5, 5, 5, 5, 5, 5) . pack('c*', -5, -5, -5, -5, -5, -5, -5, -5)
        . pack('c*', 5, 5, 5, 5, -5, -5, -5, -5);
    $signs = Search::signs([0.1, 0.2, 0.3, 0.1, 0.2, 0.3, 0.1, 0.2]);
    assert_same("\0\0\0\0\0\0\0\0", $signs);
    assert_same([0, 2, 1], Search::signPrefilter($rows, $dims, $signs, 1.0), 'nearest sign pattern first');
    assert_same([0, 2], Search::signPrefilter($rows, $dims, $signs, 0.5));
    assert_same([0], Search::signPrefilter($rows, $dims, $signs, 0.01), 'at least one row');
}

function test_rrf(): void
{
    assert_same([3, 1, 2, 4], Search::rrf([[1, 2, 3], [3, 4]]), '3 is in both lists; 2 and 4 tie, keyword list first');
    assert_same([1, 2], Search::rrf([[1, 2], []]));
    assert_same([], Search::rrf([[], []]));
    assert_same([5, 6], Search::rrf([[5, 6, 7, 8]], 60, 2), 'top cut');
    assert_same([8, 9], Search::rrf([[8, 9], [9, 8]]), 'a symmetric tie keeps first-seen order');
    assert_same([9, 8], Search::rrf([[9], [9, 8]]), 'two lists agreeing beat one');
    assert_same([7, 1], Search::rrf([[1, 7], [7]]), 'rank 2 in both beats rank 1 in one');
}

function test_collapse_near_duplicates(): void
{
    $text = 'be killing sin or it will be killing you your being dead with christ virtually';
    $c = fn (int $id, string $author, string $t) => ['id' => $id, 'author_key' => Text::looseKey($author),
                                                     'shingles' => Text::shingles($t)];
    $cands = [
        $c(1, 'John Owen', $text),
        $c(2, 'John Owen', $text . ' amen'),                 // near-duplicate, same author
        $c(3, 'Thomas Goodwin', $text),                      // same text, other author: kept
        $c(4, 'John Owen', 'an entirely different passage about the spirit and the flesh'),
        $c(5, 'JOHN OWEN', $text),                           // beyond the limit, still a duplicate of 1
    ];
    $out = Search::collapse($cands, 3);
    assert_same([1, 3, 4], ids($out));
    assert_same([2, 5], ids($out[0]['also']));
    assert_same([], $out[1]['also']);
}

function test_collapse_spreads_results_across_works(): void
{
    $c = fn (int $id, int $work, string $t) => ['id' => $id, 'work_id' => $work, 'author_key' => 'a' . $work,
                                                 'shingles' => Text::shingles($t)];
    $cands = [
        $c(1, 10, 'first passage of the big book about grace'),
        $c(2, 10, 'second passage of the big book about mercy'),
        $c(3, 10, 'third passage of the big book about faith'),
        $c(4, 10, 'fourth passage of the big book about hope'),
        $c(5, 20, 'a passage from another book about love'),
        $c(6, 30, 'a passage from a third book about peace'),
    ];
    assert_same([1, 2, 5, 6], ids(Search::collapse($cands, 4, 2)), 'two from the big book, then other books');
    assert_same([1, 2, 5, 6, 3, 4], ids(Search::collapse($cands, 6, 2)), 'spare slots go back to the big book, in rank order');
    assert_same([1, 2, 3, 4], ids(Search::collapse($cands, 4)), 'no cap by default');
}

function test_phrase_first_keeps_order_within_groups(): void
{
    $c = fn (int $id) => ['id' => $id];
    assert_same([3, 5, 1, 2, 4], ids(Search::phraseFirst([$c(1), $c(3), $c(2), $c(5), $c(4)], [5, 3])));
    assert_same([1, 2], ids(Search::phraseFirst([$c(1), $c(2)], [9])), 'no candidate holds the phrase');
}

function test_best_search_puts_a_quotation_first(): void
{
    $s = search();
    $r = $s->best('be killing sin or it will be killing you', new Filters(), 8);
    $first = $r->hits[0];
    assert_contains('killing you', strtolower(str_replace("\n", ' ', $first['text'])), 'the passage holding the words comes first');
    assert_true(isset($s->trace['phrase_matches']) && $s->trace['phrase_matches'] >= 1, 'phrase search ran');
    $short = search();
    $short->best('killing sin', new Filters(), 8);
    assert_false(isset($short->trace['phrase_matches']), 'short queries are not treated as quotations');
}

function test_best_search_collapses_editions(): void
{
    $s = search();
    $r = $s->best('be killing sin or it will be killing you', new Filters(), 8);
    $ids = ids($r->hits);
    assert_true(count($ids) >= 3, 'several results');
    $owen = array_values(array_intersect($ids, [10100003, 10200000]));
    assert_same(1, count($owen), 'the 1668 and the CCEL edition of the same passage appear once: ' . json_encode($ids));
    $hit = $r->hits[array_search($owen[0], $ids, true)];
    assert_same([$owen[0] === 10100003 ? 10200000 : 10100003], ids($hit['also']), 'the other edition is "also in"');
    assert_same('meaning + keyword search, re-ranked', $r->method);
    assert_same([], $r->notes);
    foreach ($r->hits as $h) {
        assert_true(isset($h['work']['title'], $h['text']), 'hits carry work and text');
    }
}

function test_old_spelling_query_finds_modern_and_old_texts(): void
{
    $s = search();
    $r = $s->best('iustification by faith onely', new Filters(), 8);
    assert_true(in_array(10100006, ids($r->hits), true), 'finds "Iuſtification is by faith onely" (old spelling)');
    $r = $s->best('justification by faith only', new Filters(), 8);
    assert_true(in_array(10100006, ids($r->hits), true), 'a modern query finds the old spelling too');
    assert_same(['justification', 'by', 'faith', 'only'], $s->queryTokens('Iuſtification by faith onely'));
    assert_same('Justification by faith only', $s->modernQuery('Iuſtification by faith onely'));
}

function test_filters(): void
{
    $s = search();
    foreach (['beze', 'BÈZE', 'Théodore de Bèze', 'theodore-de', 'de beze'] as $author) {
        $r = $s->best('faith', new Filters(author: $author), 8);
        assert_true($r->hits !== [], "author $author finds something");
        assert_same([104], array_values(array_unique(array_map(fn ($h) => $h['work_id'], $r->hits))), "author $author");
    }
    $r = $s->best('faith', new Filters(yearFrom: 1600), 20);
    assert_same([], array_diff(array_unique(array_column($r->hits, 'work_id')), [101, 103]), 'only works with a year >= 1600');
    assert_contains('Works without a known year are excluded', implode(' ', $r->notes));
    assert_contains('Filters: from 1600 (2 works).', implode(' ', $r->notes));
    $r = $s->best('mortification', new Filters(collection: 'ccel'), 20);
    assert_same([102], array_values(array_unique(array_column($r->hits, 'work_id'))));
    $r = $s->best('grace', new Filters(workId: 103), 20);
    assert_same([103], array_values(array_unique(array_column($r->hits, 'work_id'))));
    $r = $s->best('sin', new Filters(author: 'owen', yearFrom: 1600, yearTo: 1700), 20);
    assert_same([101], array_values(array_unique(array_column($r->hits, 'work_id'))), 'combined filters');

    $e = assert_throws(SearchInputException::class, fn () => $s->best('sin', new Filters(author: 'owne'), 8));
    assert_contains('Authors with similar names: John Owen', $e->getMessage());
    $e = assert_throws(SearchInputException::class, fn () => $s->best('sin', new Filters(author: "' OR 1=1 --"), 8));
    assert_contains('No works in the library match these filters', $e->getMessage());
    $e = assert_throws(SearchInputException::class, fn () => $s->best('sin', new Filters(yearFrom: 1700, yearTo: 1800), 8));
    assert_contains('2 matching works have no known year', $e->getMessage());
    $e = assert_throws(SearchInputException::class, fn () => $s->best('sin', new Filters(workId: 999), 8));
    assert_contains('No work has work_id 999', $e->getMessage());
}

function test_keyword_relaxation(): void
{
    $s = search();
    $strict = $s->keywordList(['killing', 'sin', 'conqueror'], null);
    assert_true($s->trace['relaxed'] ?? false, 'fewer than 30 hits with 3 words: relaxed');
    assert_same(10200005, $strict[0], 'the passage with all three words comes first');
    assert_true(count($strict) > 1, 'passages with two of the three words follow');
    $s2 = search();
    $s2->keywordList(['sin', 'grace'], null);
    assert_false($s2->trace['relaxed'] ?? false, 'two words: never relaxed');
}

function test_exact_mode_paging(): void
{
    $s = search();
    $r = $s->exact('deeds of the body', new Filters(), 2, 0);
    assert_same(3, $r->total);
    assert_same([10100000, 10100007], ids($r->hits), 'book (id) order');
    assert_same('exact', $r->mode);
    $r = $s->exact('deeds of the body', new Filters(), 2, 2);
    assert_same([10200002], ids($r->hits));
    assert_same(2, $r->offset);
    $r = $s->exact('deeds of the body', new Filters(), 2, 3);
    assert_same([], $r->hits);
    assert_same(3, $r->total);
    $r = $s->exact('deeds of the body', new Filters(workId: 102), 8, 0);
    assert_same([10200002], ids($r->hits));
    $r = $s->exact('mortifie the deeds of the body', new Filters(), 8, 0);
    assert_same([10100000, 10100007], ids($r->hits), 'old spelling in the phrase is modernized like the index');
    $r = $s->exact('deeds body of the', new Filters(), 8, 0);
    assert_same([], $r->hits, 'word order matters');
    assert_throws(SearchInputException::class, fn () => $s->exact(str_repeat('word ', 70), new Filters(), 8, 0));
    assert_throws(SearchInputException::class, fn () => $s->exact('!!!', new Filters(), 8, 0));
}

function test_hostile_queries(): void
{
    $s = search();
    foreach (['" OR 1=1 --', 'NEAR(sin grace)', 'body:grace', 'sin*', '{body}: x', "sin\0grace", ')))', '🙏',
              str_repeat('"', 500), "grace' ; DROP TABLE works; --"] as $q) {
        foreach (['best', 'exact'] as $mode) {
            try {
                $mode === 'best' ? $s->best($q, new Filters(), 5) : $s->exact($q, new Filters(), 5, 0);
            } catch (SearchInputException $e) {
                assert_contains('no words', $e->getMessage(), json_encode($q));
            }
        }
    }
    $store = Fixture\store_or_skip();
    assert_same(4, count($store->allWorks()), 'tables untouched');
}

// ------------------------------------------------------------------------------- fallbacks

/** Search over the "real vectors" copy of the fixture with a scripted Voyage transport. */
function real_search(array &$calls, array $script = [], ?int $budget = null, float $seconds = 25.0): Search
{
    $store = Fixture\store_or_skip(Fixture\REALMODE_DIR);
    $voyage = Fixture\real_voyage($store, Fixture\voyage_like($calls, $script),
        $budget === null ? null : Fixture\limits($budget));
    return new Search($store, $voyage, microtime(true) + $seconds, 2);
}

function test_real_mode_pipeline(): void
{
    $calls = [];
    $r = real_search($calls)->best('the chief end of man', new Filters(), 5);
    assert_same(['/embeddings', '/rerank'], array_column($calls, 'path'));
    assert_same('meaning + keyword search, re-ranked', $r->method);
    assert_same(10400003, $r->hits[0]['id'], 'the catechism answer first');
    assert_same(min(10, count($calls[1]['body']['documents'])), $calls[1]['body']['top_k'], 'top_k = limit * 2');
    assert_true(count($calls[1]['body']['documents']) <= 50, 'at most 50 candidates reranked');
    $docs = implode("\n", $calls[1]['body']['documents']);
    assert_contains('John Owen, Of the Mortification of Sin in Believers (1668). CHAP. I.', $docs,
        'header lines in modern spelling, like the embedding input');
    assert_not_contains('Beleevers', $docs);
    foreach ($calls[1]['body']['documents'] as $doc) {
        assert_true(mb_strlen($doc) <= Search::RERANK_DOC_CHARS, 'documents capped');
        assert_not_contains('[p. ', $doc, 'page markers stripped');
        assert_not_contains('ſ', $doc, 'long s folded');
    }
}

function test_fallback_when_embedding_fails(): void
{
    $calls = [];
    $fail = ['status' => 500, 'body' => '{"detail":"oops"}'];
    $r = real_search($calls, ['/embeddings' => [$fail, $fail]])->best('the chief end of man', new Filters(), 5);
    assert_same(['/embeddings', '/embeddings'], array_column($calls, 'path'), 'one retry, then no rerank either');
    assert_same('keyword search', $r->method);
    assert_contains(Search::MEANING_DOWN, implode(' ', $r->notes));
    assert_same(10400003, $r->hits[0]['id'], 'keyword results still come back');
}

function test_fallback_when_rerank_fails(): void
{
    $calls = [];
    $r = real_search($calls, ['/rerank' => [['status' => 400, 'body' => '{"detail":"bad"}']]])
        ->best('the chief end of man', new Filters(), 5);
    assert_same(['/embeddings', '/rerank'], array_column($calls, 'path'));
    assert_same('meaning + keyword search', $r->method);
    assert_contains('Re-ranking unavailable right now', implode(' ', $r->notes));
    assert_true($r->hits !== []);
}

function test_fallback_when_budget_is_used_up(): void
{
    $calls = [];
    $r = real_search($calls, [], 0)->best('the chief end of man', new Filters(), 5);
    assert_same([], $calls, 'no request is made');
    assert_same('keyword search', $r->method);
    assert_contains(Search::MEANING_DOWN, implode(' ', $r->notes));
}

function test_fallback_when_time_is_short(): void
{
    $calls = [];
    $r = real_search($calls, [], null, 2.0)->best('the chief end of man', new Filters(), 5);
    assert_same([], $calls, 'no Voyage call with 2 s left');
    assert_contains('skipped to stay within the time limit', implode(' ', $r->notes));
    assert_true($r->hits !== [], 'keyword results');
}

function test_fake_and_real_vectors_never_mix(): void
{
    $calls = [];
    $store = Fixture\store_or_skip();   // fake vectors (meta embed_fake = 1)
    $s = new Search($store, Fixture\real_voyage($store, Fixture\voyage_like($calls)), microtime(true) + 25, 2);
    $r = $s->best('the chief end of man', new Filters(), 5);
    assert_same(['/rerank'], array_column($calls, 'path'), 'no query embedding against test vectors; rerank still runs');
    assert_contains(Search::MEANING_DOWN, implode(' ', $r->notes));
    assert_same('keyword search, re-ranked', $r->method);
}

function test_build_without_vectors(): void
{
    $dir = col_tmpdir('novec');
    copy(Fixture\DIR . '/library.sqlite', "$dir/library.sqlite");   // no vectors.bin / ids.bin
    $store = new Store($dir);
    assert_false($store->vectorsReady());
    $r = (new Search($store, Fixture\fake_voyage($store), microtime(true) + 25, 2))->best('faith', new Filters(), 5);
    assert_same('keyword search, re-ranked', $r->method);
    assert_contains(Search::MEANING_DOWN, implode(' ', $r->notes));
}

function test_rerank_candidates_and_doc_chars_are_settings(): void
{
    $calls = [];
    $store = Fixture\store_or_skip(Fixture\REALMODE_DIR);
    $s = new Search($store, Fixture\real_voyage($store, Fixture\voyage_like($calls)), microtime(true) + 25, 2,
        null, 1.0, Search::EXACT_SCAN_MAX, 4, 300);
    $r = $s->best('faith', new Filters(), 5);
    $rerank = array_values(array_filter($calls, fn (array $c): bool => $c['path'] === '/rerank'));
    assert_same(1, count($rerank));
    assert_true(count($rerank[0]['body']['documents']) <= 4, 'at most 4 passages re-ranked');
    foreach ($rerank[0]['body']['documents'] as $d) {
        assert_true(mb_strlen($d) <= 300, 'each passage cut to 300 characters');
    }
    assert_true(count($r->hits) >= 4, 'passages past the re-ranked ones still appear, in fused order');
}

function test_past_the_personal_allowance_search_keeps_meaning_but_not_reranking(): void
{
    $calls = [];
    $store = Fixture\store_or_skip(Fixture\REALMODE_DIR);
    $limits = new RateLimit(col_tmpdir('rl'), 100000, 1000000, 3_000_000, null, fn () => Fixture\FIXED_TIME,
        ['id' => 'la00001', 'calls_per_day' => 500, 'calls_per_minute' => 30], 100);
    $s = new Search($store, Fixture\real_voyage($store, Fixture\voyage_like($calls), $limits), microtime(true) + 25, 2);
    $r = $s->best('the chief end of man', new Filters(), 5);
    assert_same(['/embeddings'], array_column($calls, 'path'), 'the query is embedded; no rerank is sent');
    assert_same('meaning + keyword search', $r->method);
    assert_contains('used today\'s re-ranked searches', implode(' ', $r->notes));
    assert_true($r->hits !== []);
}
