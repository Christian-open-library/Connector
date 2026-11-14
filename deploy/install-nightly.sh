#!/usr/bin/env bash
# Schedule the nightly library update (deploy/update.sh) at 12:30 a.m., box time (Central).
# 12:30 and not 2:23 (2026-11-14): on a night with a pending kernel update the box reboots at 4:00 and
# cut a 2:23 run off. A usual night takes about 2 hours, a night that holds a new work back about 3.
# Nothing else heavy runs then (GitHub backup 1:30, other backups 2:30 to 3:00, website list 5:37).
# Run it yourself: bash ~/library-connector/deploy/install-nightly.sh
# Safe to run again: it replaces any earlier line for update.sh. Undo: crontab -e, delete the two lines.
set -euo pipefail
script="$HOME/library-connector/deploy/update.sh"
[ -x "$script" ] || { echo "missing or not executable: $script" >&2; exit 1; }
mkdir -p "$HOME/.local/state"
{
  crontab -l 2>/dev/null | grep -v -F 'library-connector/deploy/update.sh' | grep -v -F '# Library connector: nightly' || true
  echo '# Library connector: nightly rebuild + deploy when the books repo changes (logs: ~/library-connector/data/logs/)'
  echo '30 0 * * * $HOME/library-connector/deploy/update.sh > $HOME/.local/state/library-update.log 2>&1'
} | crontab -
echo "Nightly update scheduled for 12:30 a.m.:"
crontab -l | tail -2
