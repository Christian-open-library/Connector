<?php
// Smoke test for the local PHP runtime: tools/php tools/test/php-smoke.php [build_dir]
// Checks the extensions the plugin relies on and reads a search build the way the plugin will.
// Exit code 0 = all checks passed.

declare(strict_types=1);

$dir = $argv[1] ?? dirname(__DIR__, 2) . '/data/build/LATEST-sample';
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $failed;
    if (!$ok) {
        $failed++;
    }
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . ($detail !== '' ? " -- $detail" : '') . "\n";
}

echo 'PHP ' . PHP_VERSION . ' (' . PHP_OS . ', ' . (PHP_INT_SIZE * 8) . "-bit ints)\n";
foreach (['pdo_sqlite', 'sqlite3', 'zlib', 'mbstring', 'json', 'intl', 'curl', 'hash'] as $ext) {
    check("extension $ext", extension_loaded($ext));
}

// Open read-only, like the plugin's Store.
$pdo = new PDO('sqlite:' . $dir . '/library.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
]);
$version = $pdo->query('SELECT sqlite_version()')->fetchColumn();
echo "SQLite $version\n";
check('SQLite compiled with FTS5', (int) $pdo->query("SELECT sqlite_compileoption_used('ENABLE_FTS5')")->fetchColumn() === 1);

try {
    $pdo->exec("INSERT INTO meta(key, value) VALUES ('smoke', 'x')");
    check('read-only open rejects writes', false, 'write succeeded');
} catch (PDOException $e) {
    check('read-only open rejects writes', str_contains($e->getMessage(), 'readonly'), $e->getMessage());
}

$rows = $pdo->query(
    "SELECT rowid, bm25(passages_fts) FROM passages_fts WHERE passages_fts MATCH '\"grace\"' ORDER BY rank LIMIT 3"
)->fetchAll(PDO::FETCH_NUM);
foreach ($rows as [$rowid, $score]) {
    echo "  fts5 hit: rowid=$rowid bm25=$score\n";
}
check('FTS5 MATCH + bm25 returns 3 ranked rows', count($rows) === 3
    && $rows[0][1] < 0 && $rows[0][1] <= $rows[1][1] && $rows[1][1] <= $rows[2][1]);

$stmt = $pdo->prepare('SELECT id, text FROM passages WHERE id = ?');
$stmt->execute([$rows[0][0]]);
[$id, $blob] = $stmt->fetch(PDO::FETCH_NUM);
$text = gzuncompress($blob);
echo "  passage P$id: " . strlen($blob) . ' compressed bytes -> ' . strlen((string) $text) . " bytes\n";
echo '  text: ' . mb_substr((string) $text, 0, 100) . "...\n";
check('gzuncompress() of passages.text', is_string($text) && $text !== '' && mb_check_encoding($text, 'UTF-8'));
check('passage text contains the search term', is_string($text) && stripos($text, 'grace') !== false);

$dims = (int) $pdo->query("SELECT value FROM meta WHERE key = 'embed_dims'")->fetchColumn();
$fh = fopen($dir . '/vectors.bin', 'rb');
$vec = array_values(unpack('c*', fread($fh, $dims)));
fclose($fh);
echo '  vectors.bin row 0: dims=' . count($vec) . ' first8=' . implode(',', array_slice($vec, 0, 8))
    . ' sum=' . array_sum($vec) . "\n";
check("unpack('c*') of $dims bytes", count($vec) === $dims && min($vec) >= -128 && max($vec) <= 127);

$fh = fopen($dir . '/ids.bin', 'rb');
$ids = array_values(unpack('V*', fread($fh, 8)));
fclose($fh);
echo '  ids.bin rows 0-1: ' . implode(',', $ids) . "\n";
$stmt = $pdo->prepare('SELECT COUNT(*) FROM passages WHERE id IN (?, ?)');
$stmt->execute($ids);
check("unpack('V*') ids exist in passages", (int) $stmt->fetchColumn() === 2);

check('intl Normalizer NFKC', class_exists('Normalizer') && Normalizer::normalize("\u{FB01}", Normalizer::FORM_KC) === 'fi');
check('mbstring lowercases Unicode', mb_strtolower('ÆÐÉ') === 'æðé');
check('json depth limit', json_decode(str_repeat('[', 40) . str_repeat(']', 40), true, 32) === null);

echo $failed === 0 ? "ALL PASSED\n" : "$failed CHECK(S) FAILED\n";
exit($failed === 0 ? 0 : 1);
