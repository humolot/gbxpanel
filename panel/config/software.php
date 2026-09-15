<?php

/*
|--------------------------------------------------------------------------
| Software catalog
|--------------------------------------------------------------------------
| Every entry describes how to detect, install and remove a package.
| Scripts run as root through the task queue (see App\Jobs\RunTaskJob).
| {v} is replaced by the selected version for versioned packages.
*/

$aptPrelude = 'export DEBIAN_FRONTEND=noninteractive; ';

$phpRepo = $aptPrelude.'
if ! grep -rqs "ondrej/php\|packages.sury.org/php" /etc/apt/sources.list /etc/apt/sources.list.d/; then
  . /etc/os-release
  if [ "$ID" = "ubuntu" ]; then
    apt-get install -y software-properties-common && LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php
  else
    apt-get install -y lsb-release ca-certificates curl
    curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list
  fi
fi
apt-get update -y
';

return [

    'php' => [
        'name' => 'PHP',
        'category' => 'Runtime',
        'icon' => 'bi-filetype-php',
        'description' => 'PHP-FPM with common extensions, served to Apache through FastCGI.',
        'versions' => ['8.5', '8.4', '8.3', '8.2', '8.1', '8.0', '7.4'],
        'service' => 'php{v}-fpm',
        'detect' => 'test -x /usr/sbin/php-fpm{v}',
        'version_cmd' => '/usr/bin/php{v} -r "echo PHP_VERSION;"',
        'config_file' => '/etc/php/{v}/fpm/php.ini',
        'install' => $phpRepo.'
apt-get install -y php{v}-fpm php{v}-cli php{v}-common php{v}-mysql php{v}-sqlite3 php{v}-curl php{v}-gd php{v}-mbstring php{v}-xml php{v}-zip php{v}-bcmath php{v}-intl php{v}-readline php{v}-imagick php{v}-redis || \
apt-get install -y php{v}-fpm php{v}-cli php{v}-common php{v}-mysql php{v}-sqlite3 php{v}-curl php{v}-gd php{v}-mbstring php{v}-xml php{v}-zip php{v}-bcmath php{v}-intl
systemctl enable --now php{v}-fpm
a2enmod proxy_fcgi setenvif >/dev/null 2>&1; systemctl reload apache2 || true
php{v} -v',
        'uninstall' => $aptPrelude.'systemctl disable --now php{v}-fpm || true; apt-get purge -y "php{v}-*"; apt-get autoremove -y',
    ],

    'apache' => [
        'name' => 'Apache',
        'category' => 'Web Server',
        'icon' => 'bi-hdd-network',
        'description' => 'Apache HTTP Server with mpm_event, SSL, HTTP/2 and rewrite.',
        'service' => 'apache2',
        'core' => true,
        'detect' => 'test -x /usr/sbin/apache2',
        'version_cmd' => "apache2 -v | head -1 | sed -E 's#.*Apache/([0-9.]+).*#\\1#'",
        'config_file' => '/etc/apache2/apache2.conf',
        'install' => $aptPrelude.'apt-get update -y && apt-get install -y apache2 && a2enmod ssl rewrite headers http2 proxy_fcgi proxy_http proxy_wstunnel setenvif expires && systemctl enable --now apache2',
        'uninstall' => null,
    ],

    'mysql' => [
        'name' => 'MySQL',
        'category' => 'Database',
        'icon' => 'bi-database',
        'description' => 'MySQL Server 8 from the distribution repository.',
        'service' => 'mysql',
        'conflicts' => ['mariadb'],
        'detect' => 'test -x /usr/sbin/mysqld && ! (mysqld --version 2>/dev/null | grep -qi mariadb)',
        'version_cmd' => "mysqld --version | sed -E 's#.*Ver ([0-9.]+).*#\\1#'",
        'config_file' => '/etc/mysql/mysql.conf.d/mysqld.cnf',
        'install' => $aptPrelude.'apt-get update -y && apt-get install -y mysql-server mysql-client && systemctl enable --now mysql && mysql -e "SELECT VERSION();"',
        'uninstall' => $aptPrelude.'systemctl stop mysql || true; apt-get purge -y "mysql-server*" "mysql-client*"; apt-get autoremove -y',
    ],

    'mariadb' => [
        'name' => 'MariaDB',
        'category' => 'Database',
        'icon' => 'bi-database-gear',
        'description' => 'MariaDB Server, drop-in MySQL replacement.',
        'service' => 'mariadb',
        'conflicts' => ['mysql'],
        'detect' => 'mariadb --version >/dev/null 2>&1 && test -x /usr/sbin/mariadbd',
        'version_cmd' => "mariadbd --version | sed -E 's#.*Ver ([0-9.]+).*#\\1#'",
        'config_file' => '/etc/mysql/mariadb.conf.d/50-server.cnf',
        'install' => $aptPrelude.'apt-get update -y && apt-get install -y mariadb-server mariadb-client && systemctl enable --now mariadb',
        'uninstall' => $aptPrelude.'systemctl stop mariadb || true; apt-get purge -y "mariadb-*"; apt-get autoremove -y',
    ],

    'phpmyadmin' => [
        'name' => 'phpMyAdmin',
        'category' => 'Database',
        'icon' => 'bi-table',
        'description' => 'Web interface for MySQL/MariaDB available at /phpmyadmin on the panel port.',
        'service' => null,
        'detect' => 'test -f /usr/local/gbxpanel/phpmyadmin/index.php',
        'version_cmd' => "grep -oP \"VERSION = '\\K[^']+\" /usr/local/gbxpanel/phpmyadmin/libraries/classes/Version.php 2>/dev/null || grep -oP \"VERSION = '\\K[^']+\" /usr/local/gbxpanel/phpmyadmin/src/Version.php",
        'install' => $aptPrelude.'set -e
cd /tmp && rm -rf pma && mkdir pma && cd pma
curl -fsSL https://www.phpmyadmin.net/downloads/phpMyAdmin-latest-all-languages.tar.gz -o pma.tgz
tar xzf pma.tgz && rm -rf /usr/local/gbxpanel/phpmyadmin && mv phpMyAdmin-*/ /usr/local/gbxpanel/phpmyadmin
SECRET=$(openssl rand -base64 32 | tr -d "\n")
cp /usr/local/gbxpanel/phpmyadmin/config.sample.inc.php /usr/local/gbxpanel/phpmyadmin/config.inc.php
sed -i "s|\$cfg\[.blowfish_secret.\] = .*|\$cfg[\x27blowfish_secret\x27] = \x27${SECRET:0:32}\x27;|" /usr/local/gbxpanel/phpmyadmin/config.inc.php
mkdir -p /usr/local/gbxpanel/phpmyadmin/tmp && chown -R gbxpanel:gbxpanel /usr/local/gbxpanel/phpmyadmin
systemctl reload apache2
echo "phpMyAdmin installed"',
        'uninstall' => 'rm -rf /usr/local/gbxpanel/phpmyadmin && systemctl reload apache2',
    ],

    'nodejs' => [
        'name' => 'Node.js',
        'category' => 'Runtime',
        'icon' => 'bi-hexagon',
        'description' => 'Node.js with npm from NodeSource. Installing another version replaces the current one.',
        'versions' => ['24', '22', '20', '18'],
        'single_version' => true,
        'service' => null,
        'detect' => 'command -v node >/dev/null 2>&1 && node -v | grep -q "^v{v}\."',
        'version_cmd' => "node -v | tr -d v; echo -n ' / npm '; npm -v",
        'install' => $aptPrelude.'set -e
apt-get install -y ca-certificates curl gnupg
apt-get purge -y nodejs >/dev/null 2>&1 || true
rm -f /etc/apt/sources.list.d/nodesource.list
curl -fsSL https://deb.nodesource.com/setup_{v}.x | bash -
apt-get install -y nodejs
node -v && npm -v',
        'uninstall' => $aptPrelude.'apt-get purge -y nodejs; rm -f /etc/apt/sources.list.d/nodesource.list; apt-get autoremove -y',
    ],

    'pm2' => [
        'name' => 'PM2',
        'category' => 'Runtime',
        'icon' => 'bi-diagram-3',
        'description' => 'Process manager for Node.js applications (requires Node.js).',
        'service' => null,
        'detect' => 'command -v pm2 >/dev/null 2>&1',
        'version_cmd' => 'pm2 -v 2>/dev/null | tail -1',
        'install' => 'command -v npm >/dev/null || { echo "Install Node.js first"; exit 1; }; npm install -g pm2 && pm2 startup systemd -u root --hp /root',
        'uninstall' => 'npm uninstall -g pm2',
    ],

    'composer' => [
        'name' => 'Composer',
        'category' => 'Runtime',
        'icon' => 'bi-box-seam',
        'description' => 'Dependency manager for PHP.',
        'service' => null,
        'detect' => 'test -x /usr/local/bin/composer',
        'version_cmd' => "COMPOSER_ALLOW_SUPERUSER=1 composer --version 2>/dev/null | awk '{print $3}'",
        'install' => 'set -e; cd /tmp; curl -fsSL https://getcomposer.org/installer -o composer-setup.php; php composer-setup.php --install-dir=/usr/local/bin --filename=composer; rm composer-setup.php',
        'uninstall' => 'rm -f /usr/local/bin/composer',
    ],

    'redis' => [
        'name' => 'Redis',
        'category' => 'Cache',
        'icon' => 'bi-lightning-charge',
        'description' => 'In-memory key/value store.',
        'service' => 'redis-server',
        'detect' => 'test -x /usr/bin/redis-server',
        'version_cmd' => "redis-server -v | sed -E 's#.*v=([0-9.]+).*#\\1#'",
        'config_file' => '/etc/redis/redis.conf',
        'install' => $aptPrelude.'apt-get update -y && apt-get install -y redis-server && systemctl enable --now redis-server',
        'uninstall' => $aptPrelude.'apt-get purge -y redis-server redis-tools; apt-get autoremove -y',
    ],

    'memcached' => [
        'name' => 'Memcached',
        'category' => 'Cache',
        'icon' => 'bi-memory',
        'description' => 'Distributed memory object caching system.',
        'service' => 'memcached',
        'detect' => 'test -x /usr/bin/memcached',
        'version_cmd' => "memcached -V | awk '{print $2}'",
        'config_file' => '/etc/memcached.conf',
        'install' => $aptPrelude.'apt-get update -y && apt-get install -y memcached && systemctl enable --now memcached',
        'uninstall' => $aptPrelude.'apt-get purge -y memcached; apt-get autoremove -y',
    ],

    'pureftpd' => [
        'name' => 'Pure-FTPd',
        'category' => 'FTP',
        'icon' => 'bi-folder-symlink',
        'description' => 'Secure FTP server with virtual users (required by the FTP page).',
        'service' => 'pure-ftpd',
        'detect' => 'test -x /usr/sbin/pure-ftpd',
        'version_cmd' => "dpkg -s pure-ftpd 2>/dev/null | grep ^Version | awk '{print $2}'",
        'install' => $aptPrelude.'set -e
apt-get update -y && apt-get install -y pure-ftpd
touch /etc/pure-ftpd/pureftpd.passwd
pure-pw mkdb /etc/pure-ftpd/pureftpd.pdb -f /etc/pure-ftpd/pureftpd.passwd
ln -sf /etc/pure-ftpd/conf/PureDB /etc/pure-ftpd/auth/50puredb
echo no > /etc/pure-ftpd/conf/PAMAuthentication
echo no > /etc/pure-ftpd/conf/UnixAuthentication
echo yes > /etc/pure-ftpd/conf/ChrootEveryone
echo "39000 40000" > /etc/pure-ftpd/conf/PassivePortRange
echo 30 > /etc/pure-ftpd/conf/MinUID
command -v ufw >/dev/null && { ufw allow 21/tcp; ufw allow 39000:40000/tcp; } || true
systemctl enable pure-ftpd && systemctl restart pure-ftpd',
        'uninstall' => $aptPrelude.'apt-get purge -y pure-ftpd pure-ftpd-common; apt-get autoremove -y',
    ],

    'docker' => [
        'name' => 'Docker',
        'category' => 'Containers',
        'icon' => 'bi-boxes',
        'description' => 'Docker Engine with the compose plugin (official repository).',
        'service' => 'docker',
        'detect' => 'command -v docker >/dev/null 2>&1',
        'version_cmd' => "docker version --format '{{.Server.Version}}' 2>/dev/null || docker -v",
        'install' => $aptPrelude.'set -e; curl -fsSL https://get.docker.com | sh; systemctl enable --now docker; docker version',
        'uninstall' => $aptPrelude.'systemctl stop docker || true; apt-get purge -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin; apt-get autoremove -y',
    ],

    'certbot' => [
        'name' => 'Certbot',
        'category' => 'Security',
        'icon' => 'bi-shield-lock',
        'description' => "Let's Encrypt client used to issue free SSL certificates.",
        'service' => null,
        'core' => true,
        'detect' => 'command -v certbot >/dev/null 2>&1',
        'version_cmd' => "certbot --version 2>&1 | awk '{print $2}'",
        'install' => $aptPrelude.'apt-get update -y && apt-get install -y certbot python3-certbot-apache',
        'uninstall' => $aptPrelude.'apt-get purge -y certbot python3-certbot-apache; apt-get autoremove -y',
    ],

    'fail2ban' => [
        'name' => 'Fail2ban',
        'category' => 'Security',
        'icon' => 'bi-shield-exclamation',
        'description' => 'Bans IPs that show malicious signs such as repeated SSH failures.',
        'service' => 'fail2ban',
        'detect' => 'test -x /usr/bin/fail2ban-server',
        'version_cmd' => 'fail2ban-server -V 2>/dev/null | head -1',
        'config_file' => '/etc/fail2ban/jail.local',
        'install' => $aptPrelude.'apt-get update -y && apt-get install -y fail2ban && { [ -f /etc/fail2ban/jail.local ] || printf "[DEFAULT]\nbantime = 1h\nfindtime = 10m\nmaxretry = 5\nbackend = systemd\n\n[sshd]\nenabled = true\n" > /etc/fail2ban/jail.local; } && systemctl enable --now fail2ban && systemctl restart fail2ban',
        'uninstall' => $aptPrelude.'apt-get purge -y fail2ban; apt-get autoremove -y',
    ],

    'clamav' => [
        'name' => 'ClamAV',
        'category' => 'Security',
        'icon' => 'bi-bug',
        'description' => 'Antivirus engine with the clamd daemon and freshclam signature updates. Scans uploads and website folders. Needs about 1.5 GB of RAM.',
        'service' => 'clamav-daemon',
        'detect' => 'test -x /usr/sbin/clamd',
        'version_cmd' => "clamdscan --version 2>/dev/null | cut -d/ -f1 | awk '{print \$2}' || clamscan --version | awk '{print \$2}'",
        'config_file' => '/etc/clamav/clamd.conf',
        'install' => $aptPrelude.'set -e
MEM=$(awk \'/MemTotal/ {printf "%d", $2/1024}\' /proc/meminfo)
[ "$MEM" -lt 1800 ] && echo "WARNING: ${MEM} MB of RAM detected. clamd needs about 1.5 GB; consider adding swap." || true
apt-get update -y
apt-get install -y clamav clamav-daemon clamav-freshclam
CONF=/etc/clamav/clamd.conf
setopt() { if grep -q "^$1 " "$CONF"; then sed -i "s|^$1 .*|$1 $2|" "$CONF"; else echo "$1 $2" >> "$CONF"; fi; }
setopt LocalSocketMode 666
setopt StreamMaxLength 200M
setopt MaxFileSize 200M
setopt MaxScanSize 400M
setopt MaxThreads 4
grep -q "^ExcludePath \^/proc/" "$CONF" || printf "ExcludePath ^/proc/\nExcludePath ^/sys/\nExcludePath ^/dev/\nExcludePath ^/run/\n" >> "$CONF"
mkdir -p /www/quarantine && chmod 700 /www/quarantine
echo "Downloading virus signatures (first run can take a few minutes)..."
systemctl stop clamav-freshclam || true
freshclam --stdout || echo "freshclam reported an error, the daemon will retry"
systemctl enable --now clamav-freshclam
systemctl enable clamav-daemon
systemctl restart clamav-daemon
echo "Waiting for clamd to load signatures..."
for i in $(seq 1 90); do [ -S /var/run/clamav/clamd.ctl ] && break; sleep 2; done
[ -S /var/run/clamav/clamd.ctl ] && echo "clamd is ready" || echo "clamd socket not ready yet, check: journalctl -u clamav-daemon"
clamdscan --version || true',
        'uninstall' => $aptPrelude.'systemctl disable --now clamav-daemon clamav-freshclam || true; apt-get purge -y clamav clamav-daemon clamav-freshclam clamav-base; apt-get autoremove -y',
    ],

    'supervisor' => [
        'name' => 'Supervisor',
        'category' => 'System',
        'icon' => 'bi-activity',
        'description' => 'Process control system (runs the panel queue worker).',
        'service' => 'supervisor',
        'core' => true,
        'detect' => 'command -v supervisord >/dev/null 2>&1',
        'version_cmd' => 'supervisord -v',
        'install' => $aptPrelude.'apt-get install -y supervisor && systemctl enable --now supervisor',
        'uninstall' => null,
    ],

];
