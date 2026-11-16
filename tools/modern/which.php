<?php
// php which.php SRC QUOTES.json : the quotations credited to a writer on the modern list.
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
[$_, $src, $file] = $argv;
foreach (glob("$src/*.php") as $f) { require_once $f; }
foreach (json_decode(file_get_contents($file), true) as $k => $x) {
    $a = $x['author'] ?? $x['author_given'] ?? '';
    if ($a !== '' && ($m = ColLibrary\Modern::find($a)) !== null) { echo "$k\t$a\t{$m['name']}\n"; }
}
