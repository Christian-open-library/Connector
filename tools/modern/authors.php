<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
[$_, $src, $build, $file] = $argv;
foreach (glob("$src/*.php") as $f) { require_once $f; }
$store = new ColLibrary\Store($build);
$works = iterator_to_array($store->allWorks(), false);
foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
    $r = explode("\t", $line);
    $names = array_merge([$r[0]], array_filter(explode(';', $r[2] ?? '')), array_filter([$r[3] ?? '']));
    foreach ($names as $n) {
        $keys = ColLibrary\Quote::authorKeysFor($n, $works);
        $hit = [];
        foreach ($works as $w) { if (ColLibrary\Quote::byAuthor($w, $keys)) { $hit[$w['author']] = ($hit[$w['author']] ?? 0) + 1; } }
        if ($hit) { echo "$r[0]\t$n\t[" . implode(' ', $keys) . "]\t" . json_encode($hit, JSON_UNESCAPED_UNICODE) . "\n"; }
    }
}
