<?php
/**
 * Server config for the Christian Open Library Connector plugin (example; PLUGIN-SPEC.md,
 * "Server config").
 *
 * Install as ~/col-library/config.php on the web server: outside the web root, mode 600
 * (chmod 600 ~/col-library/config.php), in a directory of mode 700. The plugin reads it on every
 * request to the endpoint. It looks for the file at:
 *   1. the COL_LIBRARY_CONFIG constant, if wp-config.php defines one:
 *        define('COL_LIBRARY_CONFIG', '/home/USER/col-library/config.php');
 *   2. otherwise <home of the PHP user>/col-library/config.php (home from posix, else $HOME, else
 *      the parent directory of the WordPress root).
 * deploy/deploy.sh --first-time writes this file with a new url_key and the homebox's Voyage key.
 * If the file is missing or invalid, the endpoint answers 404 {} to everything and the reason is
 * written to PHP's error log.
 *
 * The connector URL to paste into an AI app's connector settings is
 *   https://<site>/wp-json/col/v1/mcp/<url_key>
 * Anyone with that URL can use the connector, so treat it like a password. To rotate it, change
 * url_key here, run deploy/deploy.sh --smoke, check that the old URL now answers 404 {} (README.md,
 * "New URL key"), and update the connector in each AI app that uses it (remove and re-add, or edit the URL).
 */
return [
    // REQUIRED. The secret part of the endpoint URL: 43 to 128 characters from A-Z a-z 0-9 _ -.
    // Generate one with:  python3 -c 'import secrets; print(secrets.token_urlsafe(32))'
    // (The placeholder below is deliberately invalid, so an unedited copy keeps the endpoint off.)
    'url_key' => 'CHANGE ME',

    // Voyage AI API key for meaning search and re-ranking. Empty: keyword search only (every
    // result then says that meaning search is unavailable).
    'voyage_api_key' => '',

    // Voyage API base URL, matching where the key was made: https://api.voyageai.com/v1 (keys from
    // dash.voyageai.com; the default) or https://ai.mongodb.com/v1 (MongoDB Atlas model API keys).
    // 'voyage_base_url' => 'https://api.voyageai.com/v1',

    // The build being served: library.sqlite, vectors.bin, ids.bin and manifest.json.
    // Default: <directory of this file>/current (a symlink that deploy.sh switches atomically).
    // 'data_dir' => '/home/USER/col-library/current',

    // Where state.sqlite (rate-limit counters) and the request log go. The plugin creates the
    // files on first use; the directory must be writable by PHP and outside the web root.
    // Default: the directory of this file.
    // 'state_dir' => '/home/USER/col-library',

    // The WordPress root that may serve the endpoint (the directory holding wp-config.php). Any
    // other WordPress install of this account with the plugin (a staging copy) answers 404 {}.
    // deploy.sh --first-time writes it; add it by hand to a config written before 2026-11-08.
    // Unset: every WordPress root of this account may serve it.
    // 'wp_root' => '/home/USER/example.org',

    // Voyage models. They must match the build (its meta table records them); the plugin prefers
    // the build's values when present. Defaults shown.
    'embed_model'  => 'voyage-4-lite',
    'embed_dims'   => 512,
    'rerank_model' => 'rerank-3',

    // IVF clusters scanned per meaning search (more = better recall, slower). Default 32.
    'nprobe' => 32,

    // Optional approximate speed-up of the vector scan: fully score only this share (0 < x <= 1)
    // of each scanned cluster's rows, picked by a cheap sign-bit pre-filter. Default 1.0 (all
    // rows). Untested on real vectors; leave unset unless meaning search is too slow.
    // 'vector_prefilter_keep' => 1.0,

    // Limits, shared by all callers. Over a limit, tool calls return a tool error explaining it.
    'max_calls_per_minute'      => 120,         // tool calls per minute (UTC minute)
    'max_calls_per_day'         => 10000,       // tool calls per UTC day
    'max_voyage_tokens_per_day' => 15_000_000,  // Voyage tokens per UTC day; beyond it, keyword search only. 0 disables Voyage.
    // One person's (personal link or signed-in account) daily re-ranking allowance, never more than a fifth
    // of the line above. Past it, their searches still use meaning + keyword, without re-ranking, until
    // 00:00 UTC. Default 1,000,000 (about 90 re-ranked searches).
    // 'max_voyage_tokens_per_person_per_day' => 1_000_000,
    // What one search costs: passages sent to the reranker and characters of each (defaults below).
    // 'rerank_candidates' => 50,
    // 'rerank_doc_chars'  => 800,

    // Origin header values accepted. Requests from AI apps' servers normally carry no Origin;
    // any other Origin is rejected with 403 and recorded in the log (or PHP's error log).
    'allowed_origins' => ['https://claude.ai', 'https://claude.com', 'https://www.claude.ai', 'https://chatgpt.com', 'https://chat.openai.com', 'https://gemini.google.com', 'https://copilot.microsoft.com', 'https://chat.mistral.ai'],

    // Request log: <state_dir>/log-YYYY-MM.jsonl. 'off' (default), 'info' (one line per request)
    // or 'debug' (also tool arguments, if log_queries is true). Keys are never logged. Errors are
    // always recorded: in this log when it is on, else in PHP's error log.
    'log_level'   => 'off',
    // Log the text of searches and other tool arguments (privacy: off by default).
    'log_queries' => false,

    // Testing only: replace Voyage with a deterministic fake. Never set this on the server.
    // 'voyage_fake' => false,
];
