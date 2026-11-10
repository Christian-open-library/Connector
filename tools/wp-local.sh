#!/usr/bin/env bash
# Local WordPress for testing the connector plugin end to end:  tools/wp-local.sh start|stop|status
#
# Runs WordPress Playground (PHP 8.3 as WebAssembly, WordPress on SQLite) on
# http://127.0.0.1:9400, reachable from this machine only. Each start is a fresh site, kept in
# tools/run/tmp and deleted on stop (login admin / password). Mounted at their real paths:
#   plugin/col-library-connector   as wp-content/plugins/col-library-connector, activated
#   tools/local-config.php         passed to the plugin by the COL_LIBRARY_CONFIG constant
#   data/                          builds (data_dir) and data/local-state (state_dir)
# Node's permission model lets the server write only tools/run/ (cache, temp, log) and
# data/local-state/, so PHP cannot change the plugin source or the builds.
#
# Optional environment:
#   WP_LOCAL_PORT=9400             port
#   WP_LOCAL_WORKERS=1             PHP worker threads (with 1, the server uses ~500 MB of RAM)
#   COL_PLUGIN_DIR=<dir>           mount another plugin directory instead of the real plugin
#   WP_LOCAL_EXTRA_PLUGINS="<dir> ..."  more plugin directories to mount and activate
set -euo pipefail

TOOLS="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
PROJECT="$(dirname "$TOOLS")"
NODE="$TOOLS/node/bin/node"
CLI="$TOOLS/node_modules/@wp-playground/cli/wp-playground.js"
CONFIG="$TOOLS/local-config.php"
DATA="$PROJECT/data"
STATE="$DATA/local-state"
RUN="$TOOLS/run"
PIDFILE="$RUN/wp-local.pid"
LOG="$RUN/wp-local.log"
BLUEPRINT="$RUN/wp-local-blueprint.json"
PORT="${WP_LOCAL_PORT:-9400}"
WORKERS="${WP_LOCAL_WORKERS:-1}"
PLUGIN_DIR="${COL_PLUGIN_DIR:-$PROJECT/plugin/col-library-connector}"
BASE="http://127.0.0.1:$PORT"

die() { echo "wp-local: $*" >&2; exit 1; }

running() {
    [ -f "$PIDFILE" ] || return 1
    local pid
    pid="$(cat "$PIDFILE")"
    [[ "$pid" =~ ^[0-9]+$ ]] && kill -0 "$pid" 2>/dev/null \
        && tr '\0' ' ' <"/proc/$pid/cmdline" 2>/dev/null | grep -q 'wp-playground.js server'
}

url_key() {
    sed -n "s/^[[:space:]]*'url_key'[[:space:]]*=>[[:space:]]*'\([A-Za-z0-9_-]*\)'.*/\1/p" "$CONFIG"
}

safe_path() {   # paths go into JSON and --mount=host:vfs arguments unquoted
    [[ "$1" =~ ^/[A-Za-z0-9._/-]+$ ]] || die "unsupported characters in path: $1"
}

start() {
    [ -x "$NODE" ] && [ -f "$CLI" ] || die "not installed yet; run: bash $TOOLS/setup.sh"
    if running; then
        echo "already running (pid $(cat "$PIDFILE")): $BASE"
        return 0
    fi
    if (exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null; then
        die "port $PORT is already in use (set WP_LOCAL_PORT)"
    fi
    mkdir -p "$RUN/home" "$RUN/tmp" "$STATE"

    # Mounts, and blueprint steps that run after WordPress is installed.
    local mounts=(--mount="$DATA:$DATA" --mount="$CONFIG:$CONFIG")
    local steps="{\"step\": \"defineWpConfigConsts\", \"consts\": {\"COL_LIBRARY_CONFIG\": \"$CONFIG\"}}"
    local dir name
    for dir in "$PLUGIN_DIR" ${WP_LOCAL_EXTRA_PLUGINS:-}; do
        dir="$(readlink -m "$dir")"
        safe_path "$dir"
        if [ ! -d "$dir" ]; then
            echo "wp-local: WARNING: plugin directory $dir does not exist; starting without it" >&2
            continue
        fi
        name="$(basename "$dir")"
        mounts+=(--mount="$dir:/wordpress/wp-content/plugins/$name")
        steps+=", {\"step\": \"activatePlugin\", \"pluginPath\": \"/wordpress/wp-content/plugins/$name\"}"
    done
    # The versions must be in the blueprint: with --blueprint, the CLI ignores --php/--wp.
    echo "{\"preferredVersions\": {\"php\": \"8.3\", \"wp\": \"latest\"}, \"steps\": [$steps]}" >"$BLUEPRINT"

    # setsid: own session, so the server outlives the calling shell; stop kills the group.
    # Workers run PHP; the fs-ext addon gives SQLite real file locks (see tools/php).
    # PERM0001/PERM0006 are Node's notices that addons and workers weaken the permission model.
    HOME="$RUN/home" TMPDIR="$RUN/tmp" setsid "$NODE" \
        --import "$TOOLS/lib/node-preload.mjs" \
        --permission --allow-worker --allow-addons \
        --disable-warning=PERM0001 --disable-warning=PERM0006 \
        --allow-fs-read="$PROJECT" \
        --allow-fs-write="$RUN" --allow-fs-write="$STATE" \
        "$CLI" server --port="$PORT" --php=8.3 --wp=latest --workers="$WORKERS" \
        --blueprint="$BLUEPRINT" "${mounts[@]}" \
        >"$LOG" 2>&1 </dev/null &
    echo $! >"$PIDFILE"

    # First start downloads WordPress (~30 MB, cached in tools/run/home); later starts take ~30 s.
    local waited=0
    until curl -fsS -o /dev/null --max-time 10 "$BASE/wp-json/" 2>/dev/null; do
        if ! running; then
            tail -n 30 "$LOG" >&2
            rm -f "$PIDFILE"
            die "server exited during startup (log: $LOG)"
        fi
        [ "$waited" -lt 300 ] || die "not ready after 300 s (log: $LOG)"
        sleep 2
        waited=$((waited + 2))
    done
    status
}

stop() {
    if ! running; then
        rm -f "$PIDFILE"
        echo "not running"
        return 0
    fi
    local pid i
    pid="$(cat "$PIDFILE")"
    kill -TERM -- "-$pid" 2>/dev/null || kill -TERM "$pid"
    for i in $(seq 1 20); do
        kill -0 "$pid" 2>/dev/null || break
        sleep 0.5
    done
    if kill -0 "$pid" 2>/dev/null; then
        kill -KILL -- "-$pid" 2>/dev/null || kill -KILL "$pid"
    fi
    rm -f "$PIDFILE"
    echo "stopped"
}

status() {
    if ! running; then
        echo "not running"
        return 3
    fi
    local index code plugin="not loaded"
    index="$(curl -s -w '\n%{http_code}' --max-time 20 "$BASE/wp-json/" || true)"
    code="${index##*$'\n'}"
    if grep -q '"col\\/v1"' <<<"$index"; then
        plugin="loaded (REST namespace col/v1 registered)"
    fi
    echo "running (pid $(cat "$PIDFILE")): $BASE  (wp-json HTTP $code)"
    echo "plugin: $plugin"
    echo "MCP endpoint: $BASE/wp-json/col/v1/mcp/$(url_key)"
    echo "log: $LOG"
}

case "${1:-}" in
    start) start ;;
    stop) stop ;;
    status) status ;;
    *) echo "usage: $0 start|stop|status" >&2; exit 2 ;;
esac
