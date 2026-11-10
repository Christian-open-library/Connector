<?php
/**
 * Plugin Name:       Christian Open Library Connector
 * Description:       Read-only MCP endpoint (a custom connector for Claude, ChatGPT, Gemini and other AI apps) that searches the Christian Open Library and returns verbatim passages with citations.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * Update URI:        false
 *
 * The endpoint is /wp-json/col/v1/mcp/<secret key>; settings live in a config file outside the
 * web root (see deploy/server-config.example.php). Nothing runs on normal page loads: the classes
 * are only declared, and the route is registered when WordPress starts its REST API.
 */

defined('ABSPATH') || exit;

foreach (glob(__DIR__ . '/src/*.php') ?: [] as $col_library_file) {
    require_once $col_library_file;
}
unset($col_library_file);

ColLibrary\Http::register();
