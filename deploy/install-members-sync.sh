#!/usr/bin/env bash
# Refresh who may sign in to the connector every hour (deploy/deploy-office.sh members), so a new member
# gets in within an hour of their first sign-in instead of the next morning. Run it yourself on homebox:
#   bash ~/library-connector/deploy/install-members-sync.sh
# Safe to run again. Undo: crontab -e, delete the two "library members" lines.
set -euo pipefail
mkdir -p "$HOME/.local/state"
{
  crontab -l 2>/dev/null | grep -v -F 'deploy-office.sh members' | grep -v -F '# Library connector: refresh members' || true
  echo '# Library connector: refresh members (who may sign in) every hour'
  echo '41 * * * * $HOME/library-connector/deploy/deploy-office.sh members > $HOME/.local/state/library-members.log 2>&1'
} | crontab -
echo "Hourly member refresh scheduled (minute 41):"
crontab -l | tail -2
