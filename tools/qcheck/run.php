<?php
// php run.php SRC BUILD QUOTES.json : one PublicCheck answer per line. Real Voyage if COL_VOYAGE_KEY. COL_SHARD=i/n.
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
[$_, $src, $build, $file] = $argv;
foreach (glob("$src/*.php") as $f) { require_once $f; }
$state = getenv('COL_STATE'); @mkdir($state, 0700, true);
$key = (string) getenv('COL_VOYAGE_KEY');
$cfg = ColLibrary\Config::fromArray(['url_key' => str_repeat('k', 43), 'voyage_fake' => $key === '', 'voyage_api_key' => $key,
    'max_voyage_tokens_per_day' => 10 ** 8, 'data_dir' => $build, 'state_dir' => $state,
    'public_check_visitor_per_minute' => 10000, 'public_check_visitor_per_day' => 10000,
    'public_check_per_minute' => 10000, 'public_check_per_day' => 10000], __DIR__ . '/x.php');
[$shard, $shards] = array_map('intval', explode('/', getenv('COL_SHARD') ?: '0/1'));
foreach (json_decode(file_get_contents($file), true) as $k => $x) {
    if ($k % $shards !== $shard) continue;
    $body = json_encode(($x['author'] ?? '') !== '' ? ['quote' => $x['quote'], 'author' => $x['author']] : ['quote' => $x['quote']]);
    $r = ColLibrary\PublicCheck::response('POST', ['Origin' => 'https://christianopenlibrary.org', 'Content-Type' => 'text/plain'],
        $body, '203.0.113.' . ($k % 250), $cfg);
    echo json_encode(['k' => $k, 'status' => $r->status, 'body' => json_decode($r->body, true)]), "\n";
}
