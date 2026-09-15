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

    'postgresql' => [
        'name' => 'PostgreSQL',
        'category' => 'Database',
        'icon' => 'bi-database-fill',
        'description' => 'PostgreSQL server from the distribution repository (Databases > PostgreSQL).',
        'service' => 'postgresql',
        'detect' => 'command -v psql >/dev/null 2>&1 && test -d /etc/postgresql',
        'version_cmd' => "psql --version | grep -oE '[0-9]+(\\.[0-9]+)+' | head -1",
        'install' => $aptPrelude.'apt-get update -y && apt-get install -y postgresql postgresql-contrib && systemctl enable --now postgresql && runuser -u postgres -- psql -c "SELECT version();"',
        'uninstall' => $aptPrelude.'systemctl stop postgresql || true; apt-get purge -y "postgresql*"; apt-get autoremove -y; echo "Data kept in /var/lib/postgresql"',
    ],

    'mongodb' => [
        'name' => 'MongoDB',
        'category' => 'Database',
        'icon' => 'bi-diagram-2',
        'description' => 'MongoDB Community 8.0 from the official repository. Requires a CPU with AVX.',
        'service' => 'mongod',
        'detect' => 'test -x /usr/bin/mongod',
        'version_cmd' => "mongod --version | grep -oE '[0-9]+\\.[0-9]+\\.[0-9]+' | head -1",
        'config_file' => '/etc/mongod.conf',
        'install' => $aptPrelude.'set -e
. /etc/os-release
apt-get install -y gnupg curl
curl -fsSL https://www.mongodb.org/static/pgp/server-8.0.asc | gpg --dearmor --yes -o /usr/share/keyrings/mongodb-server-8.0.gpg
if [ "$ID" = "ubuntu" ]; then
  CODENAME="$VERSION_CODENAME"; case "$CODENAME" in noble|jammy|focal) ;; *) CODENAME=noble ;; esac
  echo "deb [ arch=amd64,arm64 signed-by=/usr/share/keyrings/mongodb-server-8.0.gpg ] https://repo.mongodb.org/apt/ubuntu $CODENAME/mongodb-org/8.0 multiverse" > /etc/apt/sources.list.d/mongodb-org-8.0.list
else
  echo "deb [ signed-by=/usr/share/keyrings/mongodb-server-8.0.gpg ] https://repo.mongodb.org/apt/debian bookworm/mongodb-org/8.0 main" > /etc/apt/sources.list.d/mongodb-org-8.0.list
fi
apt-get update -y
apt-get install -y mongodb-org
systemctl enable --now mongod
sleep 3
mongosh --quiet --eval "db.runCommand({ping: 1})"',
        'uninstall' => $aptPrelude.'systemctl stop mongod || true; apt-get purge -y "mongodb-org*"; rm -f /etc/apt/sources.list.d/mongodb-org-8.0.list; apt-get autoremove -y; echo "Data kept in /var/lib/mongodb"',
    ],

    'qdrant' => [
        'name' => 'Qdrant',
        'category' => 'Database',
        'icon' => 'bi-bounding-box-circles',
        'description' => 'Vector database for AI search and RAG. Listens on 127.0.0.1:6333 with an API key (Databases > Qdrant).',
        'service' => 'qdrant',
        'detect' => 'test -x /usr/local/bin/qdrant',
        'version_cmd' => "/usr/local/bin/qdrant --version 2>/dev/null | grep -oE '[0-9]+\\.[0-9]+\\.[0-9]+' | head -1",
        'config_file' => '/etc/qdrant/config.yaml',
        'install' => $aptPrelude.'set -e
apt-get install -y curl unzip ca-certificates
case "$(uname -m)" in
  x86_64) ASSET=qdrant-x86_64-unknown-linux-gnu.tar.gz ;;
  aarch64|arm64) ASSET=qdrant-aarch64-unknown-linux-musl.tar.gz ;;
  *) echo "Unsupported architecture $(uname -m)"; exit 1 ;;
esac
rm -rf /tmp/gbx-qdrant && mkdir -p /tmp/gbx-qdrant && cd /tmp/gbx-qdrant
curl -fsSL "https://github.com/qdrant/qdrant/releases/latest/download/$ASSET" -o qdrant.tgz
tar xzf qdrant.tgz
install -m 755 qdrant /usr/local/bin/qdrant
id qdrant >/dev/null 2>&1 || useradd --system --home-dir /var/lib/qdrant --shell /usr/sbin/nologin qdrant
mkdir -p /var/lib/qdrant/storage /var/lib/qdrant/snapshots /etc/qdrant
if curl -fsSL https://github.com/qdrant/qdrant-web-ui/releases/latest/download/dist-qdrant.zip -o ui.zip; then
  rm -rf ui /var/lib/qdrant/static && unzip -q ui.zip -d ui && mkdir -p /var/lib/qdrant/static && cp -r ui/dist/* /var/lib/qdrant/static/ || true
fi
chown -R qdrant:qdrant /var/lib/qdrant
if [ ! -f /etc/qdrant/config.yaml ]; then
  printf "storage:\n  storage_path: /var/lib/qdrant/storage\n  snapshots_path: /var/lib/qdrant/snapshots\nservice:\n  host: 127.0.0.1\n  http_port: 6333\n  grpc_port: 6334\ntelemetry_disabled: true\n" > /etc/qdrant/config.yaml
fi
chown root:qdrant /etc/qdrant/config.yaml && chmod 640 /etc/qdrant/config.yaml
cat > /etc/systemd/system/qdrant.service <<UNIT
[Unit]
Description=Qdrant vector database
After=network.target

[Service]
User=qdrant
Group=qdrant
WorkingDirectory=/var/lib/qdrant
ExecStart=/usr/local/bin/qdrant --config-path /etc/qdrant/config.yaml
Restart=on-failure
LimitNOFILE=65535

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now qdrant
sleep 3
curl -fsS http://127.0.0.1:6333/ && echo
rm -rf /tmp/gbx-qdrant',
        'uninstall' => 'systemctl disable --now qdrant || true; rm -f /etc/systemd/system/qdrant.service /usr/local/bin/qdrant; systemctl daemon-reload; echo "Data kept in /var/lib/qdrant and /etc/qdrant"',
    ],

    'sqlserver' => [
        'name' => 'SQL Server (Docker)',
        'category' => 'Database',
        'icon' => 'bi-server',
        'description' => 'Microsoft SQL Server 2022 Express in a Docker container on 127.0.0.1:1433. Requires Docker and 2 GB of RAM.',
        'service' => null,
        'detect' => 'docker inspect gbx-mssql >/dev/null 2>&1',
        'version_cmd' => "docker inspect -f '{{.Config.Image}}' gbx-mssql 2>/dev/null",
        'install' => 'set -e
command -v docker >/dev/null || { echo "Install Docker first (Home > Software)."; exit 1; }
mkdir -p /www/server/mssql /www/backup/database/sqlserver
chown -R 10001:0 /www/server/mssql /www/backup/database/sqlserver
if [ ! -s /root/.gbx-mssql-sa ]; then
  umask 077
  printf "%s" "$(openssl rand -base64 24 | tr -dc A-Za-z0-9 | head -c 20)Aa1#" > /root/.gbx-mssql-sa
fi
docker rm -f gbx-mssql >/dev/null 2>&1 || true
MSSQL_SA_PASSWORD="$(cat /root/.gbx-mssql-sa)" docker run -d --name gbx-mssql --restart unless-stopped \
  -e ACCEPT_EULA=Y -e MSSQL_SA_PASSWORD -e MSSQL_PID=Express -p 127.0.0.1:1433:1433 \
  -v /www/server/mssql:/var/opt/mssql -v /www/backup/database/sqlserver:/var/opt/mssql/backup \
  mcr.microsoft.com/mssql/server:2022-latest
for i in $(seq 1 90); do docker logs gbx-mssql 2>&1 | grep -q "SQL Server is now ready" && break; sleep 2; done
docker logs gbx-mssql 2>&1 | grep -q "SQL Server is now ready" || { docker logs --tail 30 gbx-mssql; exit 1; }
echo "SQL Server is running on 127.0.0.1:1433"',
        'uninstall' => 'docker rm -f gbx-mssql || true; echo "Data kept in /www/server/mssql"',
    ],

    'mssql-tools' => [
        'name' => 'SQL Server command-line tools',
        'category' => 'Database',
        'icon' => 'bi-terminal',
        'description' => 'sqlcmd (mssql-tools18) used to manage remote SQL Server databases.',
        'service' => null,
        'detect' => 'test -x /opt/mssql-tools18/bin/sqlcmd',
        'version_cmd' => "/opt/mssql-tools18/bin/sqlcmd -? 2>/dev/null | grep -oE 'Version [0-9.]+' | head -1",
        'install' => $aptPrelude.'set -e
. /etc/os-release
apt-get install -y curl gnupg
curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor --yes -o /usr/share/keyrings/microsoft-prod.gpg
if [ "$ID" = "ubuntu" ]; then REPO="https://packages.microsoft.com/ubuntu/$VERSION_ID/prod"; else REPO="https://packages.microsoft.com/debian/${VERSION_ID%%.*}/prod"; fi
echo "deb [arch=amd64,arm64 signed-by=/usr/share/keyrings/microsoft-prod.gpg] $REPO $VERSION_CODENAME main" > /etc/apt/sources.list.d/mssql-release.list
apt-get update -y
ACCEPT_EULA=Y apt-get install -y mssql-tools18 unixodbc
/opt/mssql-tools18/bin/sqlcmd -? | head -2',
        'uninstall' => $aptPrelude.'apt-get purge -y mssql-tools18 msodbcsql18; rm -f /etc/apt/sources.list.d/mssql-release.list',
    ],

    'adminer' => [
        'name' => 'Adminer',
        'category' => 'Database',
        'icon' => 'bi-window-stack',
        'description' => 'Single-file database manager for MySQL and PostgreSQL at /adminer on the panel port.',
        'service' => null,
        'detect' => 'test -f /usr/local/gbxpanel/adminer/index.php',
        'version_cmd' => "grep -oE 'Adminer [0-9]+\\.[0-9]+\\.[0-9]+' /usr/local/gbxpanel/adminer/index.php | head -1",
        'install' => $aptPrelude.'set -e
mkdir -p /usr/local/gbxpanel/adminer
curl -fsSL https://www.adminer.org/latest.php -o /usr/local/gbxpanel/adminer/index.php
apt-get install -y php8.4-pgsql || echo "php8.4-pgsql not available, PostgreSQL support disabled in Adminer"
chown -R gbxpanel:gbxpanel /usr/local/gbxpanel/adminer
systemctl restart gbxpanel-fpm || true
/usr/local/gbxpanel/bin/gbx vhost
echo "Adminer installed"',
        'uninstall' => 'rm -rf /usr/local/gbxpanel/adminer && systemctl reload apache2',
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
