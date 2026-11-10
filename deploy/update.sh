#!/usr/bin/env bash
# Nightly update of the Christian Open Library connector (cron; see README, "Nightly updates").
#
#   deploy/update.sh
#
# Runs `python -m indexer.pipeline nightly`: pull the books repo; if its commit differs from the one
# in data/deployed.json, rebuild, embed only the new passages (the embedding cache keeps the rest,
# at most $3 per night), rebuild the vector index, verify, and deploy with deploy/deploy.sh.
# Around it:
#  - a lock (data/update.lock): one run at a time; a run that finds the lock held exits quietly;
#  - low CPU and disk priority, and a time limit of 6 hours;
#  - a log per day, data/logs/update-YYYYMMDD.log (appended), of which the newest 14 are kept.
# It prints nothing when the update succeeds or there was nothing to do; on failure it prints one
# line to stderr (cron mails it, if mail is set up). Exit status: the pipeline's.
set -uo pipefail
umask 077

PROJECT="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/.." && pwd)"
LOGS="$PROJECT/data/logs"
KEEP_LOGS=14
TIME_LIMIT=6h

mkdir -p "$LOGS"
LOG="$LOGS/update-$(date +%Y%m%d).log"
stamp() { date '+%Y-%m-%d %H:%M:%S'; }

exec 9>"$PROJECT/data/update.lock"
if ! flock -n 9; then
    echo "$(stamp) another update is still running: this one was skipped" >>"$LOG"
    exit 0
fi

# cron starts with a minimal environment. No prompts: git and ssh must fail rather than wait.
export PATH="/usr/local/bin:/usr/bin:/bin:${PATH:-}"
export GIT_TERMINAL_PROMPT=0 GIT_SSH_COMMAND='ssh -o BatchMode=yes -o ConnectTimeout=30'
cd "$PROJECT" || exit 1

started=$(date +%s)
echo "=== $(stamp) update started" >>"$LOG"
nice_cmd=(nice -n 10)
if command -v ionice >/dev/null 2>&1; then nice_cmd+=(ionice -c 2 -n 7); fi
"${nice_cmd[@]}" timeout --kill-after=5m "$TIME_LIMIT" \
    "$PROJECT/.venv/bin/python" -u -m indexer.pipeline nightly >>"$LOG" 2>&1 </dev/null
status=$?
minutes=$(( ($(date +%s) - started + 59) / 60 ))
if [ "$status" = 124 ]; then
    echo "$(stamp) stopped: the update took longer than $TIME_LIMIT" >>"$LOG"
fi
echo "=== $(stamp) update finished: exit $status after $minutes min" >>"$LOG"

# Keep the newest logs only (names sort by date).
mapfile -t logs < <(find "$LOGS" -maxdepth 1 -type f -name 'update-*.log' | sort)
if [ "${#logs[@]}" -gt "$KEEP_LOGS" ]; then
    rm -f -- "${logs[@]:0:${#logs[@]}-KEEP_LOGS}"
fi

# One line for a watchdog (THREAT-MODEL.md, T3 and T8): "ok|failed <epoch> <exit> <log>". A deploy the
# injection check blocks fails here, so a stale library shows up instead of passing silently.
STATUS_FILE="$HOME/.local/state/library-update.status"
mkdir -p "$(dirname "$STATUS_FILE")"
if [ "$status" = 0 ]; then word=ok; else word=failed; fi
printf '%s %s %s %s\n' "$word" "$(date +%s)" "$status" "$LOG" >"$STATUS_FILE.tmp" && mv -f "$STATUS_FILE.tmp" "$STATUS_FILE"

if [ "$status" != 0 ]; then
    echo "library-connector nightly update failed (exit $status); log: $LOG" >&2
fi
exit "$status"
