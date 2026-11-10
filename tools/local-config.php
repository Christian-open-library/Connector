<?php
// Server config for LOCAL testing only (tools/wp-local.sh and tools/php tests).
// Same format as the production config (see PLUGIN-SPEC.md "Server config" and
// deploy/server-config.example.php); the WordPress Playground server receives its path through
// the COL_LIBRARY_CONFIG constant. Nothing here is secret: the URL key is a fixed test value
// and there is no Voyage key, because voyage_fake replaces Voyage with a deterministic fake.
return [
    'url_key'        => 'local-test-key-0123456789abcdefghijklmnopqrstuvw',
    'voyage_api_key' => '',
    'voyage_fake'    => true,
    'data_dir'       => dirname(__DIR__) . '/data/build/LATEST-sample',
    'state_dir'      => dirname(__DIR__) . '/data/local-state',
    // Must match the build's meta table (LATEST-sample: voyage-4-lite, 512 dims, rerank-3).
    'embed_model'    => 'voyage-4-lite',
    'embed_dims'     => 512,
    'rerank_model'   => 'rerank-3',
    'nprobe'         => 32,
    // Generous limits so eval runs against the local server are not throttled.
    'max_calls_per_minute' => 600,
    'max_calls_per_day'    => 20000,
    'log_level'      => 'debug',
    'log_queries'    => true,
];
