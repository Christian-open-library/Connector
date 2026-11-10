#!/usr/bin/env bash
# Schedule the nightly library update (deploy/update.sh) at 2:23 a.m., box time (Central).
# Run it yourself: bash ~/library-connector/deploy/install-nightly.sh
# Safe to run again: it replaces any earlier line for update.sh. Undo: crontab -e, delete the two lines.
set -euo pipefail
script="$HOME/library-connector/deploy/update.sh"
[ -x "$script" ] || { echo "missing or not executable: $script" >&2; exit 1; }
mkdir -p "$HOME/.local/state"
{
  crontab -l 2>/dev/null | grep -v -F 'library-connector/deploy/update.sh' | grep -v -F '# Library connector: nightly' || true
  echo '# Library connector: nightly rebuild + deploy when the books repo changes (logs: ~/library-connector/data/logs/)'
  echo '23 2 * * * $HOME/library-connector/deploy/update.sh > $HOME/.local/state/library-update.log 2>&1'
} | crontab -
echo "Nightly update scheduled for 2:23 a.m.:"
crontab -l | tail -2
