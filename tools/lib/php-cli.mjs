// Runs the PHP 8.3 CLI (WebAssembly build from WordPress Playground) with only selected host
// directories visible to PHP. Called by tools/php; arguments are passed to PHP unchanged, so
// "tools/php -d memory_limit=1G script.php a b" works like the native php binary.
//
// What PHP can see:
//   ~/library-connector   read-write (the project, at its real path)
//   ~/library-work        read-only (writes are refused by Node's permission
//                                      model, which tools/php turns on)
//   everything else                    an in-memory filesystem (/tmp works, but is discarded)
// Environment: only variables whose names start with COL_ are passed to PHP.
// Expects node-preload.mjs to be loaded first (tools/php does that).
import fs from 'node:fs';
import path from 'node:path';
import tls from 'node:tls';
import { once } from 'node:events';
import { PHP, setPhpIniEntries } from '@php-wasm/universal';
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';

const PROJECT = path.resolve(import.meta.dirname, '..', '..');
const LIBRARY_WORK = path.resolve(PROJECT, '..', 'library-work');
const PHP_VERSION = '8.3';
const CA_BUNDLE = '/internal/shared/ca-bundle.crt';   // in PHP's in-memory filesystem

const mounts = [PROJECT];
if (fs.existsSync(LIBRARY_WORK)) mounts.push(LIBRARY_WORK);

// Copy a web ReadableStream to a Node stream, respecting backpressure.
async function copy(readable, out) {
    for await (const chunk of readable) {
        if (!out.write(chunk)) await once(out, 'drain');
    }
}

function insideMount(dir) {
    return mounts.some((m) => dir === m || dir.startsWith(m + path.sep));
}

async function main() {
    // processId only has to be unique among php-wasm runtimes in this Node process (one here).
    const php = new PHP(await loadNodeRuntime(PHP_VERSION, {
        extensions: ['intl'],
        emscriptenOptions: { processId: 1 },
    }));
    for (const dir of mounts) {
        php.mkdir(dir);
        await php.mount(dir, createNodeFsMountHandler(dir));
    }
    // The host's CA store is not visible to PHP; give curl/openssl Node's bundled Mozilla CA list,
    // as the WordPress Playground server does.
    php.writeFile(CA_BUNDLE, tls.rootCertificates.join('\n'));
    // CLI-friendly settings on top of php-wasm's defaults (memory_limit 256M, display_errors 1).
    await setPhpIniEntries(php, {
        html_errors: '0',
        'date.timezone': 'UTC',
        'curl.cainfo': CA_BUNDLE,
        'openssl.cafile': CA_BUNDLE,
    });

    // Keep the caller's working directory when PHP can see it, else use the project root.
    php.chdir(insideMount(process.cwd()) ? process.cwd() : PROJECT);
    const env = {};
    for (const [k, v] of Object.entries(process.env)) {
        if (k.startsWith('COL_')) env[k] = v;
    }

    const response = await php.cli(['php', ...process.argv.slice(2)], { env });
    await Promise.all([copy(response.stdout, process.stdout), copy(response.stderr, process.stderr)]);
    return await response.exitCode;
}

let code;
try {
    code = await main();
} catch (e) {
    // PHP's own errors arrive on stderr; this is for runtime/loader failures.
    process.stderr.write(`tools/php: ${e?.stack || e}\n`);
    code = 255;
}
// php-wasm keeps a helper server open, so exit explicitly once output is written.
process.stdout.write('', () => process.exit(code));
