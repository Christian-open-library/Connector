#!/usr/bin/env bash
# deploy-office.sh: deploy the connector to the office server (https://mcp.christianopenlibrary.org).
# Runs on homebox (the build machine) as the maintainer over Tailscale to libdeploy@library, whose key may only upload into
# /srv/library/incoming and run library-deploy's commands (see deploy/office/connector/).
#
#   deploy/deploy-office.sh release              app code at git HEAD (plugin src + server/www), then smoke
#   deploy/deploy-office.sh build BUILD_DIR      a finished index (data/build/<id>): upload, check, switch, smoke
#   deploy/deploy-office.sh links                the personal-links list (hashes only, from deploy/links.py)
#   deploy/deploy-office.sh members              who may sign in (WorkOS users who are GitHub members; deploy/members.py),
#                                                with the cost card's costs and gifts (deploy/funding.py)
#   deploy/deploy-office.sh smoke | status | rollback
set -euo pipefail
cd "$(dirname "$0")/.."
# user@host of the deploy account (on the maintainer's machine: ~/.config/library-connector/office-host)
HOST=${LIBRARY_DEPLOY_HOST:-$(cat "$HOME/.config/library-connector/office-host" 2>/dev/null || echo libdeploy@library)}
CONF=$HOME/.config/library-connector
URL_FILE=$CONF/connector-url-office
SSH=(ssh -o BatchMode=yes -o ConnectTimeout=20)
DEPLOYED=data/deployed-office.json

url_file() {   # the owner URL on the office domain
    if [ ! -s "$URL_FILE" ]; then
        local key; key=$(sed -E 's#.*/mcp/##' "$CONF/connector-url" | tr -dc 'A-Za-z0-9_-')
        (umask 077; printf 'https://mcp.christianopenlibrary.org/mcp/%s\n' "$key" > "$URL_FILE")
    fi
    printf '%s' "$URL_FILE"
}
record() { python3 -I - "$DEPLOYED" "$1" "$2" <<'PY'
import json, sys, datetime
path, key, value = sys.argv[1:4]
try:
    d = json.load(open(path))
except (OSError, ValueError):
    d = {}
d[key] = value
d['when'] = datetime.datetime.now().isoformat(timespec='seconds')
json.dump(d, open(path, 'w'), indent=1)
PY
}

case "${1:-}" in
  release)
    git diff --quiet HEAD -- plugin/col-library-connector/src server || { echo "commit first: releases use the last commit" >&2; exit 1; }
    rev=$(git rev-parse --short=12 HEAD)
    tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
    git archive HEAD plugin/col-library-connector/src server/www server/oauth.php server/authkit-metadata.json | tar -x -C "$tmp"
    mkdir "$tmp/r" && mv "$tmp/plugin/col-library-connector/src" "$tmp/r/src" && mv "$tmp/server/www" "$tmp/r/www"
    mv "$tmp/server/oauth.php" "$tmp/server/authkit-metadata.json" "$tmp/r/"
    printf "<?php\n// Written by deploy/deploy-office.sh: the git revision of this release.\ndefined('ABSPATH') || exit;\nreturn '%s';\n" "$rev" > "$tmp/r/revision.php"
    rsync -rt --chmod=Du=rwx,Dg=rx,Do=,Fu=rw,Fg=r,Fo= -e "${SSH[*]}" "$tmp/r/" "$HOST:releases/$rev/"
    "${SSH[@]}" "$HOST" activate-release "$rev"
    record release "$rev"
    python3 -I deploy/smoke.py "$(url_file)" "$rev" ;;
  build)
    dir=${2:?build directory}; id=$(basename "$dir")
    for f in manifest.json library.sqlite vectors.bin ids.bin; do [ -f "$dir/$f" ] || { echo "missing $dir/$f" >&2; exit 1; }; done
    # Text aimed at an AI (THREAT-MODEL.md, T3): every passage reaches someone's AI chat, so a build with an
    # unreviewed hit is not deployed. Reviewed false positives: indexer/injection-allow.tsv. Book text in the
    # output is printed without control characters.
    if ! scan=$(.venv/bin/python -m indexer.injection "$dir" 2>&1); then
        printf '%s\n' "$scan" | head -n 40 | tr -d '\000-\010\013-\037\177' >&2
        echo "the injection check found text aimed at an AI in $id; nothing was deployed" >&2; exit 1
    fi
    echo "injection check: $(printf '%s\n' "$scan" | grep -c '^allowed') allowed hit(s), none new"
    rsync -t --partial --info=progress2 -e "${SSH[*]}" "$dir/manifest.json" "$dir/library.sqlite" "$dir/vectors.bin" \
        "$dir/ids.bin" "$HOST:builds/$id/"
    "${SSH[@]}" "$HOST" activate-build "$id"
    record build "$id"
    record repo_commit "$(python3 -I -c 'import json,sys; print(json.load(open(sys.argv[1]))["meta"]["repo_commit"])' "$dir/manifest.json")"
    python3 -I deploy/smoke.py "$(url_file)" ;;
  links)
    python3 -I deploy/links.py server-json | "${SSH[@]}" "$HOST" links ;;
  members)
    python3 -I deploy/members.py json | python3 -I deploy/funding.py attach | "${SSH[@]}" "$HOST" members ;;
  smoke)
    python3 -I deploy/smoke.py "$(url_file)" "$(python3 -I -c 'import json,sys; print(json.load(open(sys.argv[1])).get("release",""))' "$DEPLOYED" 2>/dev/null || true)" ;;
  status|rollback)
    "${SSH[@]}" "$HOST" "$1" ;;
  *)
    sed -n '2,10p' "$0"; exit 1 ;;
esac
