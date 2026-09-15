#!/usr/bin/env bash
#
#  GBX Panel uninstaller
#  Removes the panel itself. Websites (/www), databases, Apache, PHP and other
#  installed software are kept unless you remove them yourself.
#
set -uo pipefail

GBX_ROOT="/usr/local/gbxpanel"
PHP_V="8.4"

[ "$(id -u)" -eq 0 ] || { echo "Run as root."; exit 1; }

echo "This removes GBX Panel from ${GBX_ROOT}."
echo "Websites in /www, databases and installed software are NOT removed."
read -r -p "Type 'uninstall' to continue: " answer </dev/tty
[ "$answer" = "uninstall" ] || { echo "Cancelled."; exit 0; }

PORT="$(grep -E '^GBX_PORT=' "$GBX_ROOT/panel/.env" 2>/dev/null | cut -d= -f2)"

supervisorctl stop 'gbxpanel-worker:*' >/dev/null 2>&1
rm -f /etc/supervisor/conf.d/gbxpanel.conf
supervisorctl reread >/dev/null 2>&1; supervisorctl update >/dev/null 2>&1

a2dissite -q 000-gbxpanel >/dev/null 2>&1
rm -f /etc/apache2/sites-available/000-gbxpanel.conf
systemctl reload apache2 >/dev/null 2>&1

rm -f "/etc/php/${PHP_V}/fpm/pool.d/gbxpanel.conf"
systemctl restart "php${PHP_V}-fpm" >/dev/null 2>&1

rm -f /etc/cron.d/gbxpanel-scheduler /etc/sudoers.d/gbxpanel /etc/logrotate.d/gbxpanel /usr/bin/gbx
echo "Cron jobs created in the panel are kept in /etc/cron.d/gbxpanel (delete the file to remove them)."

[ -n "$PORT" ] && command -v ufw >/dev/null && ufw delete allow "${PORT}/tcp" >/dev/null 2>&1

BACKUP="/root/gbxpanel-backup-$(date +%Y%m%d%H%M%S).tar.gz"
tar -czf "$BACKUP" -C "$GBX_ROOT" panel/.env panel/database default.txt 2>/dev/null && echo "Panel data backup: $BACKUP"

rm -rf "$GBX_ROOT"
userdel gbxpanel >/dev/null 2>&1

echo "GBX Panel removed."
