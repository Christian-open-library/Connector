<?php
/**
 * Front controller for the Christian Open Library connector without WordPress (the office server).
 * nginx sends every request here through PHP-FPM; the connector's own rules (Http::process) do the rest.
 *
 *   POST /mcp         the MCP endpoint for signed-in library members (GitHub through WorkOS AuthKit)
 *   POST /mcp/<key>   the MCP endpoint with a key in the URL (owner key or a personal link)
 *   GET  /.well-known/oauth-protected-resource[/mcp], /.well-known/oauth-authorization-server
 *   GET  /support.svg?theme=light|dark   the public cost card on the library's front page (Support)
 *
 * Anything else answers 404 {}. The release layout is <release>/www/index.php next to <release>/src/.
 * The config path comes from the LIBRARY_CONFIG environment variable (set in the PHP-FPM pool).
 */

declare(strict_types=1);

const COL_ROUTE = '#^/mcp/([A-Za-z0-9_-]{20,128})/?$#D';

// The connector's files refuse to load unless this is defined (they expect a host to load them).
define('ABSPATH', __DIR__ . '/');
$config = getenv('LIBRARY_CONFIG');
if (is_string($config) && $config !== '') {
    define('COL_LIBRARY_CONFIG', $config);
}
foreach (glob(dirname(__DIR__) . '/src/*.php') ?: [] as $file) {
    require_once $file;
}

function col_send(ColLibrary\Response $r, string $method): void
{
    header_remove('X-Powered-By');
    http_response_code($r->status);
    foreach ($r->headers as $name => $value) {
        header("$name: $value");
    }
    if ($r->body !== null && $method !== 'HEAD') {
        echo $r->body;
    }
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$oauthFile = dirname(__DIR__) . '/oauth.php';
$oauth = is_file($oauthFile) ? (static fn (string $f): mixed => include $f)($oauthFile) : null;

// Sign-in discovery (RFC 9728, and AuthKit's own metadata for clients that look on this host).
$public = ['Cache-Control' => 'public, max-age=3600', 'Access-Control-Allow-Origin' => '*'];
if (is_array($oauth) && in_array($path, ['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp'], true)) {
    col_send(ColLibrary\Response::json(200, (new ColLibrary\OAuth($oauth, '/nonexistent'))->metadata(), $public), $method);
    exit;
}
if (is_array($oauth) && $path === '/.well-known/oauth-authorization-server' && is_file(dirname(__DIR__) . '/authkit-metadata.json')) {
    col_send(ColLibrary\Response::json(200, (string) file_get_contents(dirname(__DIR__) . '/authkit-metadata.json'), $public), $method);
    exit;
}
if ($path === '/support.svg') {
    col_send(ColLibrary\Support::response($method, $_GET, is_array($oauth) ? $oauth : null), $method);
    exit;
}
$signedIn = is_array($oauth) && ($path === '/mcp' || $path === '/mcp/');
if (!$signedIn && !preg_match(COL_ROUTE, $path, $m)) {
    col_send(ColLibrary\Response::json(404, '{}'), $method);
    exit;
}
if (function_exists('set_time_limit')) {
    @set_time_limit(ColLibrary\Http::TIME_LIMIT);
}
$headers = [];
foreach ($_SERVER as $name => $value) {
    if (str_starts_with((string) $name, 'HTTP_')) {
        $headers[str_replace('_', '-', strtolower(substr((string) $name, 5)))] = (string) $value;
    }
}
foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $name => $header) {
    if (isset($_SERVER[$name])) {
        $headers[$header] = (string) $_SERVER[$name];
    }
}
// One byte more than the limit is enough for Http::process to refuse an oversized body.
$body = (string) file_get_contents('php://input', false, null, 0, ColLibrary\Http::MAX_BODY + 1);
ob_start();
try {
    $response = $signedIn
        ? ColLibrary\Http::processBearer($method, $_GET, $headers, $body, $oauth, null, null, __DIR__)
        : ColLibrary\Http::process($method, $m[1], $_GET, $headers, $body, null, null, __DIR__);
} finally {
    $stray = (string) ob_get_clean();
}
if ($stray !== '') {
    error_log('col-library-connector: dropped output printed during the request: '
        . substr((string) preg_replace('/\s+/', ' ', strip_tags($stray)), 0, 300));
}
col_send($response, $method);
