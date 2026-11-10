#!/usr/bin/env bash
# library-connector install (built __BUILT__ on homebox from ~/library-connector/deploy/office): runs the
# Christian Open Library connector on the office server without WordPress: nginx + PHP-FPM (sandboxed),
# a filtering proxy for Voyage, a Cloudflare tunnel for mcp.christianopenlibrary.org, and a restricted deploy
# account for homebox. Shows the plan, asks for yes, keeps .bak copies and writes an undo script to /root.
#   sudo bash ~/library-setup/connector.sh 2>&1 | tee ~/library-setup/connector.txt
set -u
export LC_ALL=C SYSTEMD_PAGER= DEBIAN_FRONTEND=noninteractive HOME=/root
cd / || exit 1
[ "$(id -u)" = 0 ] || { echo "Run it with sudo: sudo bash $0"; exit 1; }
[ "$(hostname)" = library ] || { echo "This is for the office server (library), not $(hostname)."; exit 1; }
[ -x /usr/local/sbin/library-health ] || { echo "Run setup.sh first."; exit 1; }
STAGE=/home/matt/library-setup/stage
if [ ! -s /srv/library/config/config.php ]; then   # a re-run keeps the settings already installed
  for f in url_key voyage.key voyage.base links.json; do
    [ -s "$STAGE/$f" ] || { echo "Missing $STAGE/$f (Claude stages it from homebox)."; exit 1; }
  done
fi
TS=$(date +%Y%m%d-%H%M%S)
WORK=$(mktemp -d /root/library-connector-XXXXXX)
install -m 600 "$0" "$WORK/connector.sh"
UNDO=/root/library-connector-undo-$TS.sh
HOST=mcp.christianopenlibrary.org

{
cat <<'PLAN'
This installs the library connector on the office server:
  1. Packages: nginx, PHP 8.5 (FPM and CLI), tinyproxy, and cloudflared from Cloudflare's own
     repository (kept up to date by the automatic updates).
  2. Accounts: libsrv runs the connector (no login); libdeploy receives updates from homebox
     (homebox's key only, only from homebox, only upload + a few deploy commands).
  3. The connector: PHP-FPM sandboxed by systemd (read-only system, writes only its state,
     network only to this PC), nginx on 127.0.0.1:8080 only, and a proxy that lets it reach
     api.voyageai.com and nothing else. Settings in /srv/library/config (root-only secrets).
  4. Cloudflare tunnel "library" for mcp.christianopenlibrary.org: you open one link to approve
     it. No ports are opened; the tunnel only connects outward.
  5. Alerts: the health check also watches the connector's services.
Changed files get .bak copies, and an undo script goes in /root.
PLAN
} </dev/null
printf 'Type yes to go ahead: '
read -r ANSWER </dev/tty
[ "$ANSWER" = yes ] || { echo "Stopped; nothing changed."; exit 1; }

{
fail() { echo "STOPPED: $*"; echo "Undo what was done so far: sudo bash $UNDO"; exit 1; }
printf '#!/bin/bash\n# Undo library-connector install %s (run with sudo). Lines run in reverse order of the changes.\n' "$TS" > "$UNDO"
chmod 700 "$UNDO"
undo() { printf '%s\n' "$*" >> "$WORK/undo.lines"; }
finish_undo() { tac "$WORK/undo.lines" 2>/dev/null >> "$UNDO"; echo 'echo "library-connector install undone."' >> "$UNDO"; }
trap finish_undo EXIT
put() { # put MODE OWNER:GROUP SOURCE TARGET: install a file, keeping a .bak of anything it replaces
  local mode=$1 own=$2 src=$3 dst=$4
  if [ -e "$dst" ]; then cp -a "$dst" "$dst.bak-$TS"; undo "mv -f '$dst.bak-$TS' '$dst'"
  else undo "rm -f '$dst'"; fi
  install -m "$mode" -o "${own%:*}" -g "${own#*:}" "$src" "$dst"
}

echo "== Unpacking"
sed -n '/^__PAYLOAD_BELOW__$/,$p' "$WORK/connector.sh" | tail -n +2 | base64 -d > "$WORK/payload.tgz" || fail "payload missing"
echo "__SHA__  $WORK/payload.tgz" | sha256sum -c --quiet || fail "payload damaged"
P=$WORK/payload; mkdir -p "$P"; tar -xzf "$WORK/payload.tgz" -C "$P" || fail "payload unreadable"

echo "== 1. Packages"
if [ ! -f /usr/share/keyrings/cloudflare-main.gpg ]; then
  curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg -o "$WORK/cloudflare-main.gpg" || fail "Cloudflare key download"
  install -m 644 "$WORK/cloudflare-main.gpg" /usr/share/keyrings/cloudflare-main.gpg
  undo "rm -f /usr/share/keyrings/cloudflare-main.gpg"
fi
printf 'deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared any main\n' > "$WORK/cloudflared.list"
put 644 root:root "$WORK/cloudflared.list" /etc/apt/sources.list.d/cloudflared.list
apt-get update -q || fail "apt-get update"
apt-get -y -q install --no-install-recommends nginx php8.5-fpm php8.5-cli php8.5-sqlite3 php8.5-intl php8.5-mbstring \
  php8.5-curl php8.5-apcu tinyproxy cloudflared || fail "apt-get install"
undo "apt-get -y -q purge cloudflared tinyproxy nginx nginx-common 'php8.5-*' >/dev/null"
put 644 root:root "$P/52library-unattended" /etc/apt/apt.conf.d/52library-unattended
# Our own PHP-FPM instance replaces the package's default one.
systemctl disable --now php8.5-fpm.service 2>&1 | cat; systemctl mask php8.5-fpm.service 2>&1 | cat
undo "systemctl unmask php8.5-fpm.service"
CFD=$(command -v cloudflared) || fail "cloudflared not found"
echo "cloudflared: $($CFD --version 2>&1 | head -1)"

echo "== 2. Accounts and folders"
for u in libsrv cloudflared; do
  id "$u" >/dev/null 2>&1 || { useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin "$u"; undo "userdel $u"; }
done
if ! id libdeploy >/dev/null 2>&1; then
  useradd --system --create-home --home-dir /home/libdeploy --shell /bin/sh libdeploy && undo "userdel -r libdeploy"
fi
usermod -aG libsrv libdeploy
install -d -m 755 -o root -g root /srv/library
install -d -m 750 -o libdeploy -g libsrv /srv/library/builds /srv/library/links
# nginx resolves the app link to the real release folder ($realpath_root), so it may pass through these
# folders (o=x) without listing them; every file stays unreadable to it.
install -d -m 751 -o libdeploy -g libsrv /srv/library/releases /srv/library/live
chmod 751 /srv/library/releases /srv/library/live
find /srv/library/releases -mindepth 1 -maxdepth 2 -type d -exec chmod o+x {} +
install -d -m 700 -o libdeploy -g libdeploy /srv/library/incoming /srv/library/incoming/builds /srv/library/incoming/releases
install -d -m 700 -o libsrv -g libsrv /srv/library/state
install -d -m 750 -o root -g libsrv /srv/library/config
undo "rm -rf /srv/library"
usermod -s /bin/sh libdeploy   # not bash: bash reads ~/.bashrc before the forced command
key=$(cat "$P/homebox.pub")
printf 'restrict,from="__DEPLOY_FROM__",command="/usr/local/sbin/library-deploy-shell" %s\n' "$key" > "$WORK/ak"
# The key file is root-owned and sshd forces the deploy command (sshd-libdeploy.conf), so the account
# can't change its own restrictions.
install -d -m 755 -o root -g root /etc/ssh/authorized_keys
put 644 root:root "$WORK/ak" /etc/ssh/authorized_keys/libdeploy
put 644 root:root "$P/sshd-libdeploy.conf" /etc/ssh/sshd_config.d/20-library-libdeploy.conf
rm -f /home/libdeploy/.ssh/authorized_keys
sshd -t && systemctl reload ssh

echo "== 3. The connector"
for f in library-deploy-shell library-deploy library-check-build; do put 755 root:root "$P/$f" "/usr/local/sbin/$f"; done
# Settings: secrets readable only by root and libsrv. The staged copies are removed afterwards.
if [ -s "$STAGE/url_key" ] && [ -s "$STAGE/voyage.key" ]; then
{
  printf '<?php\n// Library connector settings (office server). Secrets: readable by root and libsrv only.\nreturn [\n'
  printf "    'url_key'         => '%s',\n" "$(tr -dc 'A-Za-z0-9_-' < "$STAGE/url_key")"
  printf "    'voyage_api_key'  => '%s',\n" "$(tr -dc 'A-Za-z0-9_.-' < "$STAGE/voyage.key")"
  printf "    'voyage_base_url' => '%s',\n" "$(tr -dc 'A-Za-z0-9:/._-' < "$STAGE/voyage.base")"
  printf "    'data_dir'        => '/srv/library/live/current',\n    'state_dir'       => '/srv/library/state',\n"
  printf "    'links_file'      => '/srv/library/links/links.json',\n"
  printf "    'max_calls_per_minute'      => 600,\n    'max_calls_per_day'         => 100000,\n"
  printf "    'max_voyage_tokens_per_day' => 15000000,\n"
  printf "    'log_level'       => 'info',\n    'log_queries'     => false,\n];\n"
} > "$WORK/config.php"
php8.5 -l "$WORK/config.php" >/dev/null || fail "config.php"
put 640 root:libsrv "$WORK/config.php" /srv/library/config/config.php
shred -u "$STAGE/url_key" "$STAGE/voyage.key" 2>/dev/null || rm -f "$STAGE/url_key" "$STAGE/voyage.key"
else
  [ -s /srv/library/config/config.php ] || fail "no settings to install"
  echo "settings: keeping /srv/library/config/config.php"
fi
[ -s "$STAGE/links.json" ] && put 640 libdeploy:libsrv "$STAGE/links.json" /srv/library/links/links.json
install -d -m 755 /etc/library
put 644 root:root "$P/php-fpm.conf" /etc/library/php-fpm.conf
runuser -u libsrv -- php-fpm8.5 -t --fpm-config /etc/library/php-fpm.conf 2>&1 | tail -1
runuser -u libsrv -- php-fpm8.5 -t --fpm-config /etc/library/php-fpm.conf >/dev/null 2>&1 || fail "PHP-FPM settings"
put 644 root:root "$P/tmpfiles-library-php.conf" /etc/tmpfiles.d/library-php.conf
systemd-tmpfiles --create /etc/tmpfiles.d/library-php.conf || fail "socket folder"
put 644 root:root "$P/library-php.service" /etc/systemd/system/library-php.service
put 644 root:root "$P/tinyproxy.conf" /etc/tinyproxy/tinyproxy.conf
put 644 root:root "$P/tinyproxy-filter" /etc/tinyproxy/filter
if [ -d /etc/apparmor.d/local ] && command -v apparmor_parser >/dev/null && [ -f /etc/apparmor.d/tinyproxy ]; then
  undo "apparmor_parser -r /etc/apparmor.d/tinyproxy"
  put 644 root:root "$P/apparmor-tinyproxy-local" /etc/apparmor.d/local/tinyproxy
  apparmor_parser -r /etc/apparmor.d/tinyproxy || fail "AppArmor reload for tinyproxy"
fi
put 644 root:root "$P/nginx-library.conf" /etc/nginx/sites-available/library
if [ -L /etc/nginx/sites-enabled/default ]; then
  rm -f /etc/nginx/sites-enabled/default; undo "ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default"
fi
ln -sfn /etc/nginx/sites-available/library /etc/nginx/sites-enabled/library; undo "rm -f /etc/nginx/sites-enabled/library"
nginx -t || fail "nginx settings"
undo "systemctl daemon-reload"
systemctl daemon-reload
undo "systemctl disable --now library-php.service"
systemctl enable library-php.service 2>&1 | cat
systemctl restart library-php.service
systemctl restart tinyproxy nginx
for u in library-php tinyproxy nginx; do systemctl is-active --quiet "$u" || fail "$u didn't start (journalctl -u $u)"; done
code=$(curl -s -o /dev/null -m 10 -w '%{http_code}' http://127.0.0.1:8080/)
echo "local answer: HTTP $code (404 expected before the first deploy)"
[ "$code" = 404 ] || fail "nginx can't reach PHP (tail /var/log/nginx/error.log)"
v=$(curl -s -o /dev/null -m 20 -w '%{http_code}' -x http://127.0.0.1:8888 https://api.voyageai.com/v1/models)
o=$(curl -s -o /dev/null -m 20 -w '%{http_code}' -x http://127.0.0.1:8888 https://example.com/ 2>/dev/null)
echo "proxy: api.voyageai.com HTTP $v (anything but 000 = reachable); example.com HTTP $o (000 = blocked)"
[ "$v" != 000 ] || fail "the proxy can't reach Voyage"
[ "$o" = 000 ] || fail "the proxy let example.com through"

echo "== 4. SSH for libdeploy"
put 644 root:root "$P/10-library-hardening.conf" /etc/ssh/sshd_config.d/10-library-hardening.conf
mkdir -p /run/sshd
sshd -t || fail "the SSH settings didn't pass sshd's own test"
undo "systemctl try-reload-or-restart ssh.service"
systemctl try-reload-or-restart ssh.service | cat

echo "== 5. Health checks"
for f in library-health library-explain; do put 755 root:root "$P/$f" "/usr/local/sbin/$f"; done
} </dev/null

echo "== 6. Cloudflare tunnel"
mkdir -p /etc/cloudflared /root/.cloudflared
if [ ! -s /etc/cloudflared/config.yml ]; then
  printf '\n==== Approve the tunnel ====\nA link appears below. Open it (phone or Mac), sign in to Cloudflare,\nchoose christianopenlibrary.org and click Authorize. This screen continues by itself.\n\n' >/dev/tty
  $CFD tunnel login </dev/null 2>&1 | grep -E --line-buffered 'https://|You have successfully|already' >/dev/tty || true
  [ -s /root/.cloudflared/cert.pem ] || { echo "STOPPED: the tunnel wasn't approved. Run this script again."; exit 1; }
  {
  out=$($CFD tunnel create library 2>&1) || fail "tunnel create: $(printf '%s' "$out" | tail -1)"
  printf '%s\n' "$out" | tail -1
  creds=$(ls -t /root/.cloudflared/*.json 2>/dev/null | head -1)
  [ -n "$creds" ] || fail "no tunnel credentials"
  uuid=$(basename "$creds" .json)
  out=$($CFD tunnel route dns library "$HOST" 2>&1) || fail "DNS route: $(printf '%s' "$out" | tail -1)"
  printf '%s\n' "$out" | tail -1
  install -m 640 -o root -g cloudflared "$creds" /etc/cloudflared/library.json && rm -f "$creds"
  printf 'tunnel: %s\ncredentials-file: /etc/cloudflared/library.json\nno-autoupdate: true\ningress:\n  - hostname: %s\n    service: http://127.0.0.1:8080\n  - service: http_status:404\n' "$uuid" "$HOST" > "$WORK/config.yml"
  put 644 root:root "$WORK/config.yml" /etc/cloudflared/config.yml
  undo "rm -rf /etc/cloudflared   # the tunnel itself stays in Cloudflare (delete it there)"
  shred -u /root/.cloudflared/cert.pem 2>/dev/null || rm -f /root/.cloudflared/cert.pem
  } </dev/null
fi
{
sed "s#/usr/bin/cloudflared#$CFD#" "$P/cloudflared-library.service" > "$WORK/cloudflared-library.service"
put 644 root:root "$WORK/cloudflared-library.service" /etc/systemd/system/cloudflared-library.service
systemctl daemon-reload
undo "systemctl disable --now cloudflared-library.service"
systemctl enable --now cloudflared-library.service 2>&1 | cat
sleep 8
systemctl is-active --quiet cloudflared-library || fail "the tunnel didn't start (journalctl -u cloudflared-library)"
for i in 1 2 3 4 5 6; do
  code=$(curl -s -o /dev/null -m 15 -w '%{http_code}' "https://$HOST/" 2>/dev/null); [ "$code" = 404 ] && break; sleep 10
done
echo "https://$HOST/ answers HTTP ${code:-none} (404 expected: the connector is reachable, no index yet)"

echo "== 7. Break-in check baseline (the new accounts, key and services are expected)"
/usr/local/sbin/library-security baseline | sed -n '/^SSH keys:/,$p'
echo
echo "Done. Next, Claude deploys the search index and the app from homebox."
echo "Undo: sudo bash $UNDO"
} </dev/null
