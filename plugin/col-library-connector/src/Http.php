<?php
/**
 * The REST endpoint POST|GET|DELETE /wp-json/col/v1/mcp/{key} (PLUGIN-SPEC.md, "HTTP").
 *
 * process() holds all HTTP rules and does not need WordPress (tests call it directly):
 *   config missing or invalid, data/state dir inside
 *   the web root, a WordPress root other than the
 *   config's wp_root, or wrong key        -> 404 {}  (never reveal that the endpoint works;
 *                                                     the reason goes to PHP's error log)
 *   Origin present and not allowed        -> 403
 *   not POST                              -> 405, Allow: POST
 *   query parameters (e.g. ?_jsonp)       -> 400     (WordPress must never rewrap our output)
 *   body over 64 KB                       -> 413
 *   Content-Type not application/json     -> 415
 *   then Mcp::handle() with a 25 s deadline for tools.
 *
 * The rest is WordPress glue. Our handler runs from rest_pre_dispatch, before WordPress parses
 * the JSON body itself (it would answer invalid JSON with its own error), and the exact response
 * bytes are printed from rest_pre_serve_request; the WP_REST_Response returned to WordPress only
 * carries the status. CORS headers are not sent: the clients are servers, not browsers.
 */

namespace ColLibrary;

defined('ABSPATH') || exit;

final class Http
{
    public const NAMESPACE = 'col/v1';
    public const ROUTE = '/mcp/(?P<key>[A-Za-z0-9_-]{20,128})';
    public const MAX_BODY = 65536;
    public const TOOL_SECONDS = 25.0;
    public const TIME_LIMIT = 40;

    /** The only query parameter allowed: WordPress uses it to route REST requests without pretty permalinks. */
    private const ALLOWED_QUERY = ['rest_route'];
    /** Headers WordPress (or PHP) sets on every REST response that do not belong on ours. */
    private const DROP_HEADERS = ['Access-Control-Allow-Origin', 'Access-Control-Allow-Methods',
        'Access-Control-Allow-Credentials', 'Access-Control-Allow-Headers', 'Access-Control-Expose-Headers', 'Link',
        'X-Powered-By'];

    /** Response computed for the current request, printed by serve(). */
    private static ?Response $pending = null;

    /** Called by the main plugin file. */
    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoute']);
    }

    public static function registerRoute(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => ['POST', 'GET', 'DELETE'],
            'callback' => [self::class, 'handleRest'],
            'permission_callback' => '__return_true',   // the secret key in the URL is the auth, checked first
            'show_in_index' => false,
        ]);
        add_filter('rest_pre_dispatch', [self::class, 'preDispatch'], 0, 3);
        add_filter('rest_pre_serve_request', [self::class, 'serve'], 0, 4);
    }

    /** rest_pre_dispatch: take over requests for our route before WordPress validates them. */
    public static function preDispatch(mixed $result, mixed $server, mixed $request): mixed
    {
        if ($result !== null || !$request instanceof \WP_REST_Request || self::routeKey($request->get_route()) === null) {
            return $result;
        }
        return self::handleRest($request);
    }

    /**
     * REST callback (normally reached through preDispatch). WordPress passes only the request;
     * tests also pass a config.
     */
    public static function handleRest(\WP_REST_Request $request, Config|string|null $config = null): \WP_REST_Response
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::TIME_LIMIT);
        }
        // WordPress's own REST response handling does not apply to this route: no CORS headers, and
        // no "_fields" filtering, which reads the JSON body too (a top-level "_fields" key in a
        // request would otherwise crash WordPress on our carrier response).
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        remove_filter('rest_post_dispatch', 'rest_filter_response_fields', 10);
        $headers = [];
        foreach ($request->get_headers() as $name => $values) {
            // WordPress stores header names lower-case with "_" for "-".
            $headers[str_replace('_', '-', strtolower((string) $name))] = implode(', ', (array) $values);
        }
        // Anything printed while handling (a PHP warning with display_errors on, say) would corrupt
        // the JSON, so it is caught, dropped and reported to PHP's error log.
        ob_start();
        try {
            $response = self::process(
                $request->get_method(),
                self::routeKey($request->get_route()) ?? '',
                (array) $request->get_query_params(),
                $headers,
                (string) $request->get_body(),
                $config,
                webRoot: ABSPATH,
            );
        } finally {
            $stray = (string) ob_get_clean();
        }
        if ($stray !== '') {
            error_log('col-library-connector: dropped output printed during the request: '
                . substr((string) preg_replace('/\s+/', ' ', strip_tags($stray)), 0, 300));
        }
        self::$pending = $response;
        // Empty array, not null: other rest_post_dispatch filters may expect array data.
        $carrier = new \WP_REST_Response([], $response->status);
        $carrier->set_headers($response->headers);
        return $carrier;
    }

    /** rest_pre_serve_request: print our response exactly, so WordPress cannot re-encode or wrap it. */
    public static function serve(mixed $served, mixed $result, mixed $request, mixed $server): mixed
    {
        if ($served || self::$pending === null || !$request instanceof \WP_REST_Request
            || self::routeKey($request->get_route()) === null) {
            return $served;
        }
        $response = self::$pending;
        self::$pending = null;
        if (!headers_sent()) {
            foreach (self::DROP_HEADERS as $name) {
                header_remove($name);
            }
            http_response_code($response->status);
            foreach ($response->headers as $name => $value) {
                header("$name: $value");
            }
        }
        if ($response->body !== null && $request->get_method() !== 'HEAD') {
            echo $response->body;
        }
        return true;
    }

    /** The key from a REST route of ours ("/col/v1/mcp/<key>"), else null. */
    public static function routeKey(string $route): ?string
    {
        // Case-insensitive like WordPress's own route matching; the key part is matched exactly.
        return preg_match('#^/col/v1/mcp/([A-Za-z0-9_-]{20,128})$#iD', $route, $m) ? $m[1] : null;
    }

    /**
     * All HTTP rules of the endpoint, without WordPress.
     * @param array<string,mixed> $query   query parameters
     * @param array<string,string> $headers request headers (names in any case)
     * @param Config|string|null $config   a Config, the path of the config file, or null for the default path
     * @param string|null $webRoot         if set, data_dir and state_dir inside it make the config invalid
     */
    public static function process(
        string $method,
        string $key,
        array $query,
        array $headers,
        string $body,
        Config|string|null $config = null,
        ?Mcp $mcp = null,
        ?string $webRoot = null,
    ): Response {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $config = self::loadConfig($config, $webRoot);
        if ($config === null || $key === '') {
            return self::notFound();
        }
        $link = null;
        if (!hash_equals($config->urlKey(), $key)) {
            $link = Links::find($config->linksPath(), $key);
            if ($link === null) {
                return self::notFound();
            }
        }
        return self::handle($method, $query, $headers, $body, $config, $mcp, $link);
    }

    /**
     * The signed-in endpoint (office server, /mcp): an AuthKit bearer token instead of a key in the URL.
     * No token: 401 with the metadata pointer; a bad one: 401 invalid_token; a valid token of a blocked
     * account (or, in members-only mode, of someone who isn't a member): 403 saying why.
     */
    public static function processBearer(
        string $method,
        array $query,
        array $headers,
        string $body,
        OAuth|array $oauth,
        Config|string|null $config = null,
        ?Mcp $mcp = null,
        ?string $webRoot = null,
    ): Response {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $config = self::loadConfig($config, $webRoot);
        if ($config === null) {
            return self::notFound();
        }
        if (is_array($oauth)) {
            $oauth = new OAuth($oauth, $config->stateDir());
        }
        $log = Log::fromConfig($config);
        $auth = (string) ($headers['authorization'] ?? '');
        if (!preg_match('/^Bearer ([A-Za-z0-9._~+\/-]+=*)$/D', $auth, $m)) {
            $log->info('auth', ['status' => 401, 'reason' => $auth === '' ? 'no token' : 'not a bearer token']);
            return Response::json(401, Mcp::errorJson(null, Mcp::INVALID_REQUEST, 'Sign-in required'),
                ['WWW-Authenticate' => $oauth->challenge()]);
        }
        try {
            $claims = $oauth->verify($m[1]);
        } catch (OAuthError $e) {
            $log->info('auth', ['status' => 401, 'reason' => $e->getMessage()]);
            return Response::json(401, Mcp::errorJson(null, Mcp::INVALID_REQUEST, 'Sign-in expired or invalid; sign in again'),
                ['WWW-Authenticate' => $oauth->challenge(true)]);
        }
        $link = $oauth->principal($claims['sub']);
        if (!$oauth->allowed($claims['sub'])) {
            $blocked = !$oauth->membersOnly() || $oauth->isMember($claims['sub']);
            $log->with(['link' => $link['id']])->info('auth', ['status' => 403, 'reason' => $blocked ? 'blocked' : 'not a member']);
            return Response::json(403, Mcp::errorJson(null, Mcp::INVALID_REQUEST,
                $blocked ? $oauth->blockedMessage() : $oauth->notMemberMessage()));
        }
        return self::handle($method, $query, $headers, $body, $config, $mcp, $link);
    }

    /** The config, checked against the web root and WordPress root; null (reason in PHP's error log) if unusable. */
    private static function loadConfig(Config|string|null $config, ?string $webRoot): ?Config
    {
        try {
            if (!$config instanceof Config) {
                $config = Config::load($config);
            }
            foreach (['data_dir' => $config->dataDir(), 'state_dir' => $config->stateDir()] as $name => $dir) {
                if ($webRoot !== null && Config::isInside($dir, $webRoot)) {
                    // The library, the log and the counters would be downloadable.
                    throw new ConfigException("invalid config: $name is inside the web root ($dir)");
                }
            }
            $wpRoot = $config->wpRoot();
            if ($wpRoot !== null && $webRoot !== null && !Config::samePath($wpRoot, $webRoot)) {
                // Another WordPress install of this account (a staging copy, say) with a stale copy
                // of the plugin: only the site deploy.sh manages may serve the config's key.
                throw new ConfigException("this WordPress root ($webRoot) is not the config's wp_root");
            }
            return $config;
        } catch (\Throwable $e) {
            // No usable log without a config: PHP's error log gets the reason.
            error_log('col-library-connector: ' . ($e instanceof ConfigException ? '' : get_class($e) . ': ')
                . $e->getMessage());
            return null;
        }
    }

    /** Everything after authentication: the HTTP rules, then MCP. $link is the personal link or signed-in user. */
    private static function handle(string $method, array $query, array $headers, string $body, Config $config,
                                   ?Mcp $mcp, ?array $link): Response
    {
        $log = Log::fromConfig($config)->with(['link' => $link['id'] ?? 'owner']);
        $started = microtime(true);
        try {
            $origin = $headers['origin'] ?? null;
            if ($origin !== null && !in_array(Config::normalizeOrigin((string) $origin), $config->allowedOrigins(), true)) {
                // Only callers with the key get here, so this is rare and worth recording even with
                // logging off: if an AI app ever sends an unexpected Origin, the log says which.
                $log->error('origin_rejected', ['origin' => mb_substr((string) $origin, 0, 200)]);
                return self::reject($log, 403, 'Forbidden: Origin not allowed');
            }
            if (strtoupper($method) !== 'POST') {
                $log->info('http', ['status' => 405, 'method' => mb_substr($method, 0, 10)]);
                return Mcp::methodNotAllowed();
            }
            $extra = array_values(array_diff(array_map('strval', array_keys($query)), self::ALLOWED_QUERY));
            if ($extra !== []) {
                return self::reject($log, 400, 'Bad Request: query parameters are not accepted (' . mb_substr(implode(', ', $extra), 0, 100) . ')');
            }
            if (strlen($body) > self::MAX_BODY) {
                return self::reject($log, 413, 'Payload Too Large: the request body may be at most 64 KB', ['bytes' => strlen($body)]);
            }
            $type = strtolower(trim(explode(';', (string) ($headers['content-type'] ?? ''), 2)[0]));
            if ($type !== 'application/json') {
                return self::reject($log, 415, 'Unsupported Media Type: Content-Type must be application/json', ['type' => mb_substr($type, 0, 100)]);
            }
            $ctx = new Context($config, RateLimit::fromConfig($config, $log, $link), $log, $started + self::TOOL_SECONDS);
            return ($mcp ?? new Mcp())->handle($method, $headers, $body, $ctx);
        } catch (\Throwable $e) {
            $log->error('internal_error', [
                'exception' => get_class($e),
                'message' => mb_substr($e->getMessage(), 0, 500),
                'at' => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
            return Response::json(500, Mcp::errorJson(null, Mcp::INTERNAL_ERROR, 'Internal error'));
        }
    }

    /** Wrong key or no usable config: the same answer as for a route that does not exist. */
    private static function notFound(): Response
    {
        return Response::json(404, '{}');
    }

    /** An HTTP-level rejection; the body is a JSON-RPC error without an id, as the MCP spec allows. */
    private static function reject(Log $log, int $status, string $message, array $fields = []): Response
    {
        $log->info('http', ['status' => $status] + $fields);
        return Response::json($status, Mcp::errorJson(null, Mcp::INVALID_REQUEST, $message));
    }
}
