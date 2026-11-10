<?php
/**
 * Plain-PHP test runner (no PHPUnit). Loads every tests/php/*_test.php and runs each function
 * whose name starts with test_ (namespaced functions too), in file order.
 *
 *   tools/php tests/php/run.php              all tests
 *   tools/php tests/php/run.php mcp http     only files whose name contains "mcp" or "http"
 *
 * Exit code 0 if every test passed (skips allowed), 1 otherwise. PHP warnings and notices fail the
 * test that raised them. Helpers for test files: assert_true, assert_false, assert_same,
 * assert_contains, assert_not_contains, assert_throws, col_skip, col_src (require plugin src
 * files), col_tmpdir, col_error_log (what the code wrote to PHP's error log).
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

// The plugin files exit unless loaded by WordPress; this marks the tests as a legitimate loader.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
define('COL_TESTS_DIR', __DIR__);
define('COL_PLUGIN_DIR', dirname(__DIR__, 2) . '/plugin/col-library-connector');

final class AssertionFailed extends Exception
{
}

final class TestSkipped extends Exception
{
}

/** Ends the current test as skipped (not failed), e.g. when an optional file is missing. */
function col_skip(string $why): never
{
    throw new TestSkipped($why);
}

$GLOBALS['col_assertions'] = 0;

function col_export(mixed $v): string
{
    if (is_string($v)) {
        return strlen($v) > 400 ? var_export(substr($v, 0, 400), true) . '…(' . strlen($v) . ' bytes)' : var_export($v, true);
    }
    $s = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($s === false || $v === null || is_bool($v) || is_float($v)) {
        $s = var_export($v, true);
    }
    return strlen($s) > 400 ? substr($s, 0, 400) . '…' : $s;
}

function assert_true(mixed $cond, string $message = ''): void
{
    $GLOBALS['col_assertions']++;
    if ($cond !== true) {
        throw new AssertionFailed(($message !== '' ? "$message: " : '') . 'expected true, got ' . col_export($cond));
    }
}

function assert_false(mixed $cond, string $message = ''): void
{
    $GLOBALS['col_assertions']++;
    if ($cond !== false) {
        throw new AssertionFailed(($message !== '' ? "$message: " : '') . 'expected false, got ' . col_export($cond));
    }
}

/** Strict comparison (===); arrays must have the same keys in the same order. */
function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    $GLOBALS['col_assertions']++;
    if ($expected !== $actual) {
        throw new AssertionFailed(($message !== '' ? "$message: " : '') . 'expected ' . col_export($expected)
            . ', got ' . col_export($actual));
    }
}

function assert_contains(string $needle, ?string $haystack, string $message = ''): void
{
    $GLOBALS['col_assertions']++;
    if ($haystack === null || !str_contains($haystack, $needle)) {
        throw new AssertionFailed(($message !== '' ? "$message: " : '') . col_export($needle) . ' not found in '
            . col_export($haystack));
    }
}

function assert_not_contains(string $needle, ?string $haystack, string $message = ''): void
{
    $GLOBALS['col_assertions']++;
    if ($haystack !== null && str_contains($haystack, $needle)) {
        throw new AssertionFailed(($message !== '' ? "$message: " : '') . col_export($needle) . ' unexpectedly found in '
            . col_export($haystack));
    }
}

/** Runs $fn and checks that it throws $class; returns the exception. */
function assert_throws(string $class, callable $fn, string $message = ''): Throwable
{
    $GLOBALS['col_assertions']++;
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new AssertionFailed(($message !== '' ? "$message: " : '') . "expected $class, got " . get_class($e)
            . ': ' . $e->getMessage());
    }
    throw new AssertionFailed(($message !== '' ? "$message: " : '') . "expected $class, nothing was thrown");
}

/** require_once plugin source files by class name: col_src('Config', 'Mcp'). */
function col_src(string ...$names): void
{
    foreach ($names as $name) {
        require_once COL_PLUGIN_DIR . "/src/$name.php";
    }
}

/** A new empty directory (under the system temp dir) for one test. */
function col_tmpdir(string $prefix = 'col'): string
{
    static $n = 0;
    $dir = sys_get_temp_dir() . "/$prefix-" . getmypid() . '-' . (++$n) . '-' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0700, true)) {
        throw new RuntimeException("cannot create $dir");
    }
    return $dir;
}

/** What the code under test wrote to PHP's error log (redirected to a temp file by this runner). */
function col_error_log(): string
{
    clearstatcache();
    return (string) @file_get_contents((string) ini_get('error_log'));
}

// PHP's error log goes to a temp file, so expected error_log() lines do not clutter the output.
ini_set('error_log', col_tmpdir('errlog') . '/php-error.log');

// Warnings and notices become exceptions, so they fail the test that caused them.
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;   // silenced with @
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$filters = array_slice($argv, 1);
$files = glob(__DIR__ . '/*_test.php') ?: [];
sort($files);
if ($filters) {
    $files = array_values(array_filter($files, function (string $f) use ($filters): bool {
        foreach ($filters as $flt) {
            if (str_contains(basename($f), $flt)) {
                return true;
            }
        }
        return false;
    }));
}

$passed = 0;
$skipped = 0;
$failed = [];
$started = microtime(true);
foreach ($files as $file) {
    $before = get_defined_functions()['user'];
    try {
        require_once $file;
    } catch (Throwable $e) {
        $failed[] = basename($file) . ' (load)';
        printf("%s\n  FAIL loading: %s: %s\n    at %s:%d\n", basename($file), get_class($e), $e->getMessage(),
            $e->getFile(), $e->getLine());
        continue;
    }
    $tests = array_values(array_filter(array_diff(get_defined_functions()['user'], $before),
        fn (string $fn): bool => (bool) preg_match('/(^|\\\\)test_\w*$/', $fn)));
    echo basename($file), "\n";
    foreach ($tests as $fn) {
        $name = (new ReflectionFunction($fn))->getShortName();
        $t0 = microtime(true);
        try {
            $fn();
            $passed++;
            printf("  ok   %s (%d ms)\n", $name, (int) round((microtime(true) - $t0) * 1000));
        } catch (TestSkipped $e) {
            $skipped++;
            printf("  skip %s: %s\n", $name, $e->getMessage());
        } catch (Throwable $e) {
            $failed[] = basename($file) . " $name";
            $where = $e->getFile() . ':' . $e->getLine();
            // For assertion failures, show the line in the test file rather than in this runner.
            if ($e instanceof AssertionFailed) {
                foreach ($e->getTrace() as $frame) {
                    if (($frame['file'] ?? __FILE__) !== __FILE__) {
                        $where = $frame['file'] . ':' . ($frame['line'] ?? 0);
                        break;
                    }
                }
            }
            $kind = $e instanceof AssertionFailed ? '' : get_class($e) . ': ';
            printf("  FAIL %s: %s%s\n    at %s\n", $name, $kind, $e->getMessage(), $where);
        }
    }
}

printf("\n%d passed, %d failed, %d skipped, %d assertions, %.1f s\n", $passed, count($failed), $skipped,
    $GLOBALS['col_assertions'], microtime(true) - $started);
foreach ($failed as $f) {
    echo "  failed: $f\n";
}
if ($passed + count($failed) + $skipped === 0) {
    echo "no tests found\n";
    exit(1);
}
exit($failed ? 1 : 0);
