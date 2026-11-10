#!/usr/bin/env bash
# Installs the local PHP tooling into tools/ (no root, nothing global):
#   1. Node.js 24 LTS from nodejs.org. SHASUMS256.txt must carry a valid signature from a
#      Node.js release key (keyring pinned to a commit of github.com/nodejs/release-keys), and
#      the tarball must match the sha256 pinned below.
#   2. WordPress Playground packages (PHP 8.3 compiled to WebAssembly) from npm, exactly as
#      pinned in package-lock.json (npm checks each package's integrity hash), with install
#      scripts disabled.
# Safe to re-run; it skips what is already in place.
set -euo pipefail

TOOLS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NODE_VERSION=24.22.2
NODE_DIST="node-v${NODE_VERSION}-linux-x64"
NODE_SHA256=48cc0e21e048790e54c0cf5749c2725005313d652651c40b7a21feb9697aaf75
KEYS_COMMIT=481637f813e912c4aa3622d7964ab426c97b8e8d

if [ ! -x "$TOOLS/$NODE_DIST/bin/node" ]; then
    # Download and unpack in a fresh directory next to the target, then move into place, so an
    # interrupted run never leaves a half-unpacked Node.js behind.
    dl="$(mktemp -d "$TOOLS/.download.XXXXXX")"
    trap 'rm -rf "$dl"' EXIT
    base="https://nodejs.org/dist/v${NODE_VERSION}"
    echo "Downloading Node.js ${NODE_VERSION}..."
    curl -fsSL --max-time 60 -o "$dl/SHASUMS256.txt" "$base/SHASUMS256.txt"
    curl -fsSL --max-time 60 -o "$dl/SHASUMS256.txt.sig" "$base/SHASUMS256.txt.sig"
    curl -fsSL --max-time 60 -o "$dl/nodejs-keys.kbx" \
        "https://raw.githubusercontent.com/nodejs/release-keys/${KEYS_COMMIT}/gpg-only-active-keys/pubring.kbx"
    gpgv --keyring "$dl/nodejs-keys.kbx" "$dl/SHASUMS256.txt.sig" "$dl/SHASUMS256.txt"
    if ! grep -qx "${NODE_SHA256}  ${NODE_DIST}.tar.xz" "$dl/SHASUMS256.txt"; then
        echo "setup.sh: the signed SHASUMS256.txt does not list the pinned sha256" >&2
        exit 1
    fi
    curl -fsSL --max-time 600 -o "$dl/${NODE_DIST}.tar.xz" "$base/${NODE_DIST}.tar.xz"
    (cd "$dl" && echo "${NODE_SHA256}  ${NODE_DIST}.tar.xz" | sha256sum -c --quiet)
    tar -xJf "$dl/${NODE_DIST}.tar.xz" -C "$dl" --no-same-owner
    rm -rf "$TOOLS/${NODE_DIST}"
    mv "$dl/${NODE_DIST}" "$TOOLS/${NODE_DIST}"
    echo "Node.js ${NODE_VERSION} installed in tools/${NODE_DIST}"
fi
ln -sfn "$NODE_DIST" "$TOOLS/node"

cd "$TOOLS"
export PATH="$TOOLS/node/bin:$PATH"
# npm ci wipes and reinstalls node_modules; skip it when the install already matches the lock.
if [ ! -f node_modules/.package-lock.json ] || [ package-lock.json -nt node_modules/.package-lock.json ]; then
    npm ci --ignore-scripts --no-audit --no-fund --loglevel=warn
fi
mkdir -p "$TOOLS/run"
echo "OK: node $(node --version); $(npm ls --depth=0 2>/dev/null | tail -n +2 | tr -s ' \n' ' ')"
