#!/usr/bin/env bash
# Refresh who may sign in to the connector every hour (deploy/deploy-office.sh members), so a new member
# gets in within an hour of their first sign-in instead of the next morning. Second accounts are blocked in the
# same run and emailed afterwards (deploy/dupes.py, where present: it is not published). Run it yourself on homebox:
#   bash ~/library-connector/deploy/install-members-sync.sh
# Safe to run again. Undo: crontab -e, delete the two "library members" lines.
set -euo pipefail
mkdir -p "$HOME/.local/state"
{
  crontab -l 2>/dev/null | grep -v -F 'deploy-office.sh members' | grep -v -F '# Library connector: refresh members' || true
  echo '# Library connector: refresh members (who may sign in) every hour'
  echo '41 * * * * cd $HOME/library-connector && deploy/deploy-office.sh members > $HOME/.local/state/library-members.log 2>&1 && { [ ! -f deploy/dupes.py ] || python3 -I deploy/dupes.py notify >> $HOME/.local/state/library-members.log 2>&1; }'
} | crontab -
echo "Hourly member refresh scheduled (minute 41):"
crontab -l | tail -2
