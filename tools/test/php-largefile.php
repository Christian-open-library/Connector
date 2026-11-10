<?php
// Large-file check for tools/php: reads and writes past the 2 GiB and 4 GiB marks through plain
// PHP streams and through SQLite. Uses sparse files under tools/run/, so it needs almost no disk
// space, and deletes them afterwards. Run: tools/php tools/test/php-largefile.php

declare(strict_types=1);

$dir = dirname(__DIR__) . '/run/largefile-test';
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $failed;
    if (!$ok) {
        $failed++;
    }
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . ($detail !== '' ? " -- $detail" : '') . "\n";
}

// 1. Plain streams: markers at offsets around 2 GiB, 4 GiB and 5 GiB of a sparse file.
$path = "$dir/sparse.bin";
$offsets = [0, 2 ** 31 + 12345, 2 ** 32 + 777, 5 * 2 ** 30];
$fh = fopen($path, 'w+b');
foreach ($offsets as $i => $off) {
    fseek($fh, $off);
    fwrite($fh, "marker-$i");
}
fclose($fh);
clearstatcache();
check('filesize() of a 5 GiB file', filesize($path) === 5 * 2 ** 30 + 8, (string) filesize($path));
$fh = fopen($path, 'rb');
foreach ($offsets as $i => $off) {
    fseek($fh, $off);
    check("fseek()+fread() at offset $off", fread($fh, 8) === "marker-$i" && ftell($fh) === $off + 8);
}
fclose($fh);
unlink($path);

// 2. SQLite: build a tiny database, then move table t's only b-tree page to a page that starts
// past 4 GiB (the pages in between are a sparse hole that SQLite never reads). Reading t then
// requires SQLite to read at that offset.
$db = "$dir/far.sqlite";
@unlink($db);
$pageSize = 65536;
$pdo = new PDO("sqlite:$db", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("PRAGMA page_size = $pageSize; PRAGMA journal_mode = DELETE");
$pdo->exec("CREATE TABLE t(x TEXT); INSERT INTO t VALUES ('far-away-row')");
$root = (int) $pdo->query("SELECT rootpage FROM sqlite_master WHERE name = 't'")->fetchColumn();
$far = intdiv(4 * 2 ** 30, $pageSize) + 3;
$pdo->exec('PRAGMA writable_schema = ON');
$pdo->exec("UPDATE sqlite_master SET rootpage = $far WHERE name = 't'");
$pdo->exec('PRAGMA writable_schema = OFF');
$pdo = null;

$fh = fopen($db, 'r+b');
fseek($fh, ($root - 1) * $pageSize);
$page = fread($fh, $pageSize);
fseek($fh, ($root - 1) * $pageSize);
fwrite($fh, str_repeat("\0", $pageSize));   // so the row exists only in the far copy
fseek($fh, ($far - 1) * $pageSize);
fwrite($fh, $page);
fseek($fh, 28);                     // header field: database size in pages (big-endian)
fwrite($fh, pack('N', $far));
fclose($fh);
clearstatcache();

$pdo = new PDO("sqlite:$db", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
]);
$x = $pdo->query('SELECT x FROM t')->fetchColumn();
$offset = ($far - 1) * $pageSize;
check("SQLite reads a page at offset $offset (file " . filesize($db) . ' bytes)', $x === 'far-away-row', var_export($x, true));
$pdo = null;
unlink($db);
@rmdir($dir);

echo $failed === 0 ? "ALL PASSED\n" : "$failed CHECK(S) FAILED\n";
exit($failed === 0 ? 0 : 1);
