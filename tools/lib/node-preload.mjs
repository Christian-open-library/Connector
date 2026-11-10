// Loaded with "node --import" into every tools/ Node process (main thread and worker threads)
// before any other code. Four adjustments:
//
// 1. Loopback only. WordPress Playground's HTTP server calls listen(port) without a host, which
//    would accept connections on every network interface (LAN, Tailscale). Any listen() call
//    that names no host or socket path is bound to 127.0.0.1 instead.
// 2. Node's permission model (--permission) blocks process.binding(), which Emscripten's NODEFS
//    uses only to read the open() flag constants. Serve those from the public fs.constants.
// 3. The callback form of fs.realpath() lstat()s every parent directory (/home, ...), which the
//    permission model denies outside the allowed trees. The native realpath(3) version gives the
//    same answer and only needs access to the path itself.
// 4. The permission model reports a denied file operation as ERR_ACCESS_DENIED, which NODEFS has
//    no errno for: PHP would see the failed write "succeed" (or get a bogus file descriptor).
//    Re-throw such errors from the sync fs calls NODEFS uses with code EACCES, so PHP gets an
//    ordinary "Permission denied" failure.
import fs from 'node:fs';
import net from 'node:net';
import { syncBuiltinESMExports } from 'node:module';

const LOOPBACK = '127.0.0.1';
const listen = net.Server.prototype.listen;

net.Server.prototype.listen = function (...args) {
    const first = args[0];
    if (first !== null && typeof first === 'object') {
        // listen(options[, cb]); leave handles, fds and unix socket paths alone.
        const isHandle = '_handle' in first || 'fd' in first || first.path !== undefined;
        if (!isHandle && first.host === undefined) args[0] = { ...first, host: LOOPBACK };
    } else if (typeof first === 'function') {
        args.unshift(0, LOOPBACK);                  // listen(cb): random port
    } else if (first === undefined || typeof first === 'number' || /^\d+$/.test(String(first))) {
        if (typeof args[1] !== 'string') {          // listen(port[, backlog][, cb]): no host given
            if (args.length === 0) args.push(0);
            args.splice(1, 0, LOOPBACK);
        }
    }
    return listen.apply(this, args);
};

const nodeBinding = process.binding;
process.binding = (name) => (name === 'constants' ? { fs: fs.constants } : nodeBinding.call(process, name));

const nativeRealpath = fs.realpath.native;
fs.realpath = Object.assign((...args) => nativeRealpath(...args), { native: nativeRealpath });

const NODEFS_CALLS = [
    'openSync', 'closeSync', 'readSync', 'writeSync', 'fstatSync', 'lstatSync', 'statSync',
    'statfsSync', 'readdirSync', 'readlinkSync', 'mkdirSync', 'rmdirSync', 'unlinkSync',
    'renameSync', 'symlinkSync', 'readFileSync', 'writeFileSync', 'chmodSync', 'fchmodSync',
    'utimesSync', 'futimesSync', 'truncateSync', 'ftruncateSync',
];
for (const name of NODEFS_CALLS) {
    const original = fs[name];
    fs[name] = function (...args) {
        try {
            return original.apply(this, args);
        } catch (e) {
            if (e?.code !== 'ERR_ACCESS_DENIED') throw e;
            const denied = new Error(`${e.message}: ${e.resource ?? ''}`, { cause: e });
            denied.code = 'EACCES';
            throw denied;
        }
    };
}
syncBuiltinESMExports();
