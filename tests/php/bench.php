<?php
/**
 * Timings for the search layer (not a test; prints numbers).
 *
 *   tools/php -d memory_limit=1G tests/php/bench.php            all sections
 *   tools/php -d memory_limit=1G tests/php/bench.php scan       only the synthetic vector scan
 *   options: --lists=32 --rows=800 --dims=512 --k=2048 --build=DIR (default data/build/LATEST-sample)
 *
 * Sections:
 *   fixture   Tools::call on tests/php/fixture (fake Voyage): search, exact, read_passage, find_works
 *   scan      synthetic production-size IVF scan: lists x rows x dims int8 rows, the same code
 *             path as a search (Search::scoreRows on one string per list), plus centroid scoring
 *   prefilter the opt-in sign prefilter (vector_prefilter_keep) on the same data: time and recall
 *   sample    best/exact searches on a real build with fake vectors (LATEST-sample)
 * Runs under WebAssembly here (tools/php), which is several times slower than native PHP.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
$root = dirname(__DIR__, 2);
foreach (['Context', 'Config', 'Log', 'RateLimit', 'Text', 'Store', 'Voyage', 'Search', 'Format', 'Tools'] as $f) {
    require_once "$root/plugin/col-library-connector/src/$f.php";
}

use ColLibrary\Config;
use ColLibrary\Context;
use ColLibrary\Filters;
use ColLibrary\Log;
use ColLibrary\RateLimit;
use ColLibrary\Search;
use ColLibrary\Store;
use ColLibrary\Tools;
use ColLibrary\Voyage;

$opt = ['lists' => 32, 'rows' => 800, 'dims' => 512, 'k' => 2048, 'build' => "$root/data/build/LATEST-sample"];
$sections = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(\w+)=(.*)$/', $a, $m) && isset($opt[$m[1]])) {
        $opt[$m[1]] = is_int($opt[$m[1]]) ? (int) $m[2] : $m[2];
    } else {
        $sections[] = $a;
    }
}
$sections = $sections ?: ['fixture', 'scan', 'prefilter', 'sample'];

/** Runs $fn $reps times; returns [best ms, result of the last run]. */
function timed(callable $fn, int $reps = 1): array
{
    $best = INF;
    $out = null;
    for ($i = 0; $i < $reps; $i++) {
        $t = hrtime(true);
        $out = $fn();
        $best = min($best, (hrtime(true) - $t) / 1e6);
    }
    return [$best, $out];
}

function context_for(string $configFile, string $stateDir, array $over = []): Context
{
    $values = require $configFile;
    $cfg = Config::fromArray($over + ['state_dir' => $stateDir] + $values, $configFile);
    $log = Log::off();
    return new Context($cfg, new RateLimit($stateDir, 100000, 1000000, 3_000_000, $log), $log, microtime(true) + 25);
}

/** $n deterministic pseudo-random bytes (mt_rand per byte is slow, so blocks are reused). */
function seeded_bytes(int $n, int $seed): string
{
    mt_srand($seed);
    $block = '';
    for ($i = 0; $i < 65536; $i++) {
        $block .= chr(mt_rand(0, 255));
    }
    $out = '';
    while (strlen($out) < $n) {
        $out .= substr($block, mt_rand(0, 65535));
    }
    return substr($out, 0, $n);
}

printf("PHP %s on %s, %d-bit; JIT %s\n\n", PHP_VERSION, php_uname('m'), PHP_INT_SIZE * 8,
    function_exists('opcache_get_status') && (@opcache_get_status(false)['jit']['on'] ?? false) ? 'on' : 'off');
$stateDir = sys_get_temp_dir() . '/col-bench-' . getmypid();
@mkdir($stateDir, 0700, true);

// ------------------------------------------------------------------------------------ fixture
if (in_array('fixture', $sections, true)) {
    echo "== fixture (tests/php/fixture, 28 passages, fake Voyage) via Tools::call, best of 3\n";
    $ctx = context_for(__DIR__ . '/fixture/config.php', $stateDir);
    foreach ([['search_library', ['query' => 'be killing sin or it will be killing you']],
              ['search_library', ['query' => 'faith', 'author' => 'beze']],
              ['search_library', ['query' => 'grace', 'year_from' => 1600, 'year_to' => 1700]],
              ['search_library', ['query' => 'deeds of the body', 'mode' => 'exact']],
              ['read_passage', ['ref' => 'P10100003', 'before' => 2, 'after' => 2]],
              ['read_passage', ['work_id' => 101, 'page' => '2']],
              ['find_works', ['query' => 'mortification of sin']]] as [$tool, $args]) {
        [$ms, $r] = timed(fn () => Tools::call($tool, $args, $ctx), 3);
        printf("  %-15s %-62s %7.1f ms  %s\n", $tool, json_encode($args, JSON_UNESCAPED_UNICODE), $ms,
            $r['isError'] ? 'ERROR: ' . $r['content'][0]['text'] : strlen($r['content'][0]['text']) . ' chars');
    }
    echo "\n";
}

// --------------------------------------------------------------------------------------- scan
$lists = $opt['lists'];
$rows = $opt['rows'];
$dims = $opt['dims'];
$data = [];
$q1 = [];
if (array_intersect(['scan', 'prefilter'], $sections)) {
    // Synthetic lists with some structure: each list is a cluster centre plus noise, and the query
    // is near one row (list 3, row 123), so the best rows stand out as they do with real vectors.
    mt_srand(11);
    $gauss = static fn (): float => sqrt(-2 * log(max(1e-12, mt_rand() / mt_getrandmax())))
        * cos(2 * M_PI * mt_rand() / mt_getrandmax());
    $target = '';
    for ($l = 0; $l < $lists; $l++) {
        $centre = [];
        for ($d = 0; $d < $dims; $d++) {
            $centre[$d] = $gauss();
        }
        // byte -> value lookup per dimension would be slow to build; quantize row by row instead
        $noise = seeded_bytes($rows * $dims, 100 + $l);
        $buf = '';
        for ($r = 0; $r < $rows; $r++) {
            $row = '';
            $off = $r * $dims;
            for ($d = 0; $d < $dims; $d++) {
                $v = (int) round(($centre[$d] * 0.6 + (ord($noise[$off + $d]) - 127.5) / 74.0) * 40);
                $row .= chr(max(-127, min(127, $v)) & 0xFF);
            }
            $buf .= $row;
            if ($l === 3 && $r === 123) {
                $target = $row;
            }
        }
        $data[] = $buf;
    }
    foreach (array_values(unpack("c$dims", $target)) as $d => $x) {
        $q1[$d + 1] = $x / 40.0 + 0.3 * $gauss();
    }
}

if (in_array('scan', $sections, true)) {
    $madds = $lists * $rows * $dims;
    printf("== synthetic IVF scan: %d lists x %d rows x %d dims = %.1fM multiply-adds\n", $lists, $rows, $dims, $madds / 1e6);
    [$ms, $top] = timed(function () use ($data, $q1, $dims) {
        $all = [];
        foreach ($data as $l => $buf) {
            foreach (Search::scoreRows($q1, $buf, $dims) as $r => $s) {
                $all[$l * 1000000 + $r] = $s;
            }
        }
        arsort($all);
        return array_slice(array_keys($all), 0, 100);
    }, 2);
    printf("  Search::scoreRows (4 rows per pass) + top 100:  %8.1f ms = %5.1f us/row = %.1fM multiply-adds/s (best row %s)\n",
        $ms, $ms * 1000 / ($lists * $rows), $madds / $ms / 1000, $top[0] === 3 * 1000000 + 123 ? 'found' : 'MISSED');
    [$ms1] = timed(function () use ($data, $q1, $dims) {
        $fmt = "c$dims";
        foreach ($data as $buf) {
            $n = intdiv(strlen($buf), $dims);
            for ($r = 0; $r < $n; $r++) {
                $s = 0.0;
                foreach (unpack($fmt, $buf, $r * $dims) as $k => $x) {
                    $s += $q1[$k] * $x;
                }
            }
        }
    });
    printf("  for comparison, one row per pass:               %8.1f ms = %5.1f us/row\n", $ms1, $ms1 * 1000 / ($lists * $rows));
    [$msu] = timed(function () use ($data, $dims) {
        foreach ($data as $buf) {
            $n = intdiv(strlen($buf), $dims);
            for ($r = 0; $r < $n; $r++) {
                unpack("c$dims", $buf, $r * $dims);
            }
        }
    });
    printf("  of which unpack() of the rows alone:            %8.1f ms = %5.1f us/row\n", $msu, $msu * 1000 / ($lists * $rows));

    $k = $opt['k'];
    $cbuf = '';
    mt_srand(5);
    for ($i = 0; $i < $k; $i++) {
        $c = [];
        for ($d = 0; $d < $dims; $d++) {
            $c[] = mt_rand() / mt_getrandmax() - 0.5;
        }
        $cbuf .= pack('g*', ...$c);
    }
    [$msc] = timed(function () use ($cbuf, $q1, $dims) {
        $s = Search::scoreRows($q1, $cbuf, $dims, null, 'g');
        arsort($s);
        return array_slice(array_keys($s), 0, 32);
    });
    printf("  IVF centroid scoring, %d float32 centroids:   %8.1f ms\n\n", $k, $msc);
}

if (in_array('prefilter', $sections, true)) {
    echo "== opt-in sign prefilter (config vector_prefilter_keep < 1): Hamming distance of sign bits with C string\n"
        . "   functions, then exact scores for the rows kept. Recall = share of the exact top k still found.\n";
    $exact = [];
    foreach ($data as $l => $buf) {
        foreach (Search::scoreRows($q1, $buf, $dims) as $r => $s) {
            $exact[$l * 1000000 + $r] = $s;
        }
    }
    arsort($exact);
    $top100 = array_slice(array_keys($exact), 0, 100);
    $top10 = array_slice($top100, 0, 10);
    $signs = Search::signs(array_values($q1));
    foreach ([0.5, 0.25, 0.1] as $keep) {
        [$ms, $found] = timed(function () use ($data, $q1, $dims, $signs, $keep) {
            $all = [];
            foreach ($data as $l => $buf) {
                foreach (Search::scoreRows($q1, $buf, $dims, Search::signPrefilter($buf, $dims, $signs, $keep)) as $r => $s) {
                    $all[$l * 1000000 + $r] = $s;
                }
            }
            arsort($all);
            return array_slice(array_keys($all), 0, 100);
        });
        printf("  keep %4.2f: %8.1f ms = %5.1f us/row; recall@10 %3d%%, recall@100 %3d%%\n", $keep, $ms,
            $ms * 1000 / ($lists * $rows), 10 * count(array_intersect($top10, $found)), count(array_intersect($top100, $found)));
    }
    echo "  (synthetic data: measure recall on real Voyage vectors before turning this on)\n\n";
}

// ------------------------------------------------------------------------------------- sample
if (in_array('sample', $sections, true) && is_file($opt['build'] . '/library.sqlite')) {
    $build = (string) realpath($opt['build']);
    $store = new Store($build);
    printf("== sample build %s: %s passages, %s vectors in %s lists, %d dims, fake vectors: %s; nprobe 32\n",
        basename($build), $store->meta('n_passages'), $store->meta('n_vectors'), $store->meta('ivf_k'), $store->dims(),
        $store->meta('embed_fake') === '1' ? 'yes' : 'no');
    $ctx = context_for(__DIR__ . '/fixture/config.php', $stateDir, ['data_dir' => $build, 'embed_dims' => $store->dims(), 'nprobe' => 32]);
    $runs = [['assurance of salvation', 'best', null], ['what is the chief end of man', 'best', null],
             ['iustification by faith onely', 'best', null], ['covenant of grace', 'best', null],
             ['the covenant of grace', 'exact', null], ['grace', 'best', new Filters(author: 'owen')],
             ['god', 'best', null]];
    foreach ($runs as [$q, $mode, $filters]) {
        $store = new Store($build);   // a fresh store per search, as in a request
        $s = new Search($store, Voyage::forBuild($ctx, $store), microtime(true) + 25, 32);
        [$ms, $r] = timed(fn () => $mode === 'exact' ? $s->exact($q, new Filters(), 8, 0) : $s->best($q, $filters ?? new Filters(), 8));
        $t = $s->trace;
        printf("  %-5s %-31s %7.1f ms, %d hits [keyword %s ms/%s hits; scan %s: %s ms, %s rows; rerank %s ms]\n",
            $mode, json_encode($q) . ($filters ? ' +author' : ''), $ms, count($r->hits), $t['keyword_ms'] ?? $t['exact_ms'] ?? '-',
            $t['keyword_hits'] ?? '-', $t['scan'] ?? '-', $t['scan_ms'] ?? '-', $t['rows_scored'] ?? '-', $t['rerank_ms'] ?? '-');
    }
}

printf("\npeak PHP memory: %.1f MB\n", memory_get_peak_usage(true) / 1048576);
