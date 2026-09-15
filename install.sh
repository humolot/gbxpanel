#!/usr/bin/env bash
#
#  GBX Panel installer
#  -------------------------------------------------------------------------
#  Supported: Ubuntu 22.04 / 24.04, Debian 12 / 13 (x86_64, aarch64)
#
#  Quick install:
#    wget -O install.sh https://raw.githubusercontent.com/humolot/gbxpanel/main/install.sh && sudo bash install.sh
#
#  Options:
#    -y, --yes              Do not ask for confirmation
#    --port <port>          Panel port (random 10000-60000 by default)
#    --entry <path>         Security entrance (random by default)
#    --user <name>          Admin username (random by default)
#    --password <pass>      Admin password (random by default)
#    --http                 Serve the panel over plain HTTP (no self-signed SSL)
#    --no-firewall          Do not enable UFW
#    --source <dir|url>     Local panel directory or .tar.gz URL of the repository
#
set -Eeuo pipefail

# ------------------------------------------------------------------ settings
GBX_REPO="${GBX_REPO:-https://github.com/humolot/gbxpanel}"
GBX_BRANCH="${GBX_BRANCH:-main}"
GBX_ROOT="/usr/local/gbxpanel"
PANEL_DIR="$GBX_ROOT/panel"
GBX_USER="gbxpanel"
PHP_V="8.4"
LOG_FILE="/tmp/gbxpanel-install.log"

ASSUME_YES=0
PANEL_PORT=""
PANEL_ENTRY=""
ADMIN_USER=""
ADMIN_PASS=""
USE_SSL=1
USE_FIREWALL=1
SOURCE=""

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || pwd)"

# ------------------------------------------------------------------ output
if [ -t 1 ]; then
    C_RESET="\033[0m"; C_DIM="\033[2m"; C_BOLD="\033[1m"; C_GREEN="\033[32m"; C_RED="\033[31m"; C_YELLOW="\033[33m"; C_CYAN="\033[36m"; C_WHITE="\033[97m"
else
    C_RESET=""; C_DIM=""; C_BOLD=""; C_GREEN=""; C_RED=""; C_YELLOW=""; C_CYAN=""; C_WHITE=""
fi

STEP=0
TOTAL_STEPS=14
step()  { STEP=$((STEP + 1)); echo -e "\n${C_WHITE}${C_BOLD}[${STEP}/${TOTAL_STEPS}] $*${C_RESET}"; }
info()  { echo -e "  ${C_DIM}-${C_RESET} $*"; }
ok()    { echo -e "  ${C_GREEN}ok${C_RESET} $*"; }
warn()  { echo -e "  ${C_YELLOW}warning${C_RESET} $*"; }
fail()  { echo -e "\n${C_RED}${C_BOLD}Error:${C_RESET} $*"; echo -e "Full log: ${LOG_FILE}\n"; exit 1; }

trap 'fail "installation failed at line $LINENO (command: $BASH_COMMAND)"' ERR

run() {
    # run a command quietly, keeping the output in the log file
    if ! "$@" >>"$LOG_FILE" 2>&1; then
        echo -e "  ${C_RED}failed${C_RESET} $*"
        tail -n 25 "$LOG_FILE" | sed 's/^/    /'
        return 1
    fi
}

apt_install() {
    DEBIAN_FRONTEND=noninteractive run apt-get install -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold "$@"
}

random_string() { tr -dc "$1" </dev/urandom 2>/dev/null | head -c "$2" || true; }

port_in_use() { ss -ltnH 2>/dev/null | awk '{print $4}' | grep -Eq "[:.]$1\$"; }

# SSH port without relying on "sshd -T" alone: with socket activation (Ubuntu 22.10+)
# sshd is not running, /run/sshd does not exist and "sshd -T" fails.
detect_ssh_port() {
    local port=""
    if command -v sshd >/dev/null 2>&1; then
        mkdir -p /run/sshd 2>/dev/null || true
        port="$(sshd -T 2>/dev/null | awk '/^port / {print $2; exit}' || true)"
    fi
    if [ -z "$port" ] && systemctl is-active --quiet ssh.socket 2>/dev/null; then
        port="$(systemctl show ssh.socket -p Listen --value 2>/dev/null | grep -oE '[0-9]+ \(Stream\)' | head -1 | cut -d' ' -f1 || true)"
    fi
    if [ -z "$port" ]; then
        port="$(grep -hsE '^[[:space:]]*Port[[:space:]]+[0-9]+' /etc/ssh/sshd_config.d/*.conf /etc/ssh/sshd_config 2>/dev/null | awk '{print $2; exit}' || true)"
    fi
    if [ -z "$port" ]; then
        port="$(ss -tlnpH 2>/dev/null | awk '/"sshd"/ {n=split($4,a,":"); print a[n]; exit}' || true)"
    fi
    [[ "$port" =~ ^[0-9]+$ ]] && echo "$port" || echo 22
}

banner() {
    echo -e "${C_WHITE}"
    cat <<'EOF'
   ____  ____  __  __     ____                       _
  / ___|| __ ) \ \/ /    |  _ \  __ _  _ __    ___  | |
 | |  _ |  _ \  \  /     | |_) |/ _` || '_ \  / _ \ | |
 | |_| || |_) | /  \     |  __/| (_| || | | ||  __/ | |
  \____||____/ /_/\_\    |_|    \__,_||_| |_| \___| |_|

EOF
    echo -e "${C_RESET}${C_DIM}  Linux server control panel installer${C_RESET}\n"
}

# ------------------------------------------------------------------ arguments
while [ $# -gt 0 ]; do
    case "$1" in
        -y|--yes) ASSUME_YES=1 ;;
        --port) PANEL_PORT="${2:-}"; shift ;;
        --entry) PANEL_ENTRY="${2:-}"; shift ;;
        --user) ADMIN_USER="${2:-}"; shift ;;
        --password) ADMIN_PASS="${2:-}"; shift ;;
        --http) USE_SSL=0 ;;
        --no-firewall) USE_FIREWALL=0 ;;
        --source) SOURCE="${2:-}"; shift ;;
        -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
    shift
done

: >"$LOG_FILE"
chmod 600 "$LOG_FILE"

clear 2>/dev/null || true
banner

# ------------------------------------------------------------------ pre-flight
[ "$(id -u)" -eq 0 ] || fail "run this installer as root (sudo bash install.sh)"

[ -f /etc/os-release ] || fail "unsupported operating system"
. /etc/os-release
OS_ID="${ID:-}"
OS_VER="${VERSION_ID:-}"
case "$OS_ID:$OS_VER" in
    ubuntu:22.04|ubuntu:24.04|debian:12|debian:13) ;;
    *) fail "unsupported system: ${PRETTY_NAME:-unknown}. Supported: Ubuntu 22.04/24.04, Debian 12/13." ;;
esac

ARCH="$(uname -m)"
case "$ARCH" in x86_64|aarch64) ;; *) fail "unsupported architecture: $ARCH" ;; esac

MEM_MB=$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)
DISK_GB=$(df -Pk / | awk 'NR==2 {printf "%d", $4/1024/1024}')

if [ -d "$PANEL_DIR" ] && [ -f "$PANEL_DIR/.env" ]; then
    echo -e "${C_YELLOW}GBX Panel is already installed in ${PANEL_DIR}.${C_RESET}"
    echo -e "Use ${C_BOLD}gbx${C_RESET} to manage it or ${C_BOLD}gbx update${C_RESET} to update."
    if [ "$ASSUME_YES" -eq 0 ]; then
        read -r -p "Reinstall anyway? Panel data (SQLite database) is kept. [y/N] " answer </dev/tty || answer="n"
        [[ "$answer" =~ ^[Yy]$ ]] || exit 0
    fi
    REINSTALL=1
else
    REINSTALL=0
fi

echo -e "  System     : ${C_BOLD}${PRETTY_NAME}${C_RESET} (${ARCH})"
echo -e "  Memory     : ${MEM_MB} MB"
echo -e "  Free disk  : ${DISK_GB} GB"
echo -e "  Install to : ${GBX_ROOT}"
echo -e "  Components : Apache 2, PHP ${PHP_V}-FPM, Supervisor, UFW, Certbot, Composer, SQLite"
echo
[ "$MEM_MB" -lt 700 ] && warn "less than 1 GB of RAM detected; the panel works but installing MySQL may be slow."
[ "$DISK_GB" -lt 5 ] && warn "less than 5 GB of free disk space."

if [ "$ASSUME_YES" -eq 0 ]; then
    read -r -p "Do you want to install GBX Panel now? [y/N] " answer </dev/tty || answer="n"
    [[ "$answer" =~ ^[Yy]$ ]] || { echo "Installation cancelled."; exit 0; }
fi

START_TS=$(date +%s)

# ------------------------------------------------------------------ 1. base packages
step "Updating package lists and installing base packages"
export DEBIAN_FRONTEND=noninteractive
run apt-get update -y
apt_install ca-certificates curl wget unzip zip tar git rsync gnupg lsb-release openssl sqlite3 acl \
    cron supervisor ufw sudo software-properties-common apt-transport-https logrotate iproute2 procps
systemctl enable --now cron >>"$LOG_FILE" 2>&1 || true
systemctl enable --now supervisor >>"$LOG_FILE" 2>&1 || true
ok "base packages installed"

# ------------------------------------------------------------------ 2. PHP
step "Installing PHP ${PHP_V}"
if ! grep -rqs "ondrej/php\|packages.sury.org/php" /etc/apt/sources.list /etc/apt/sources.list.d/; then
    if [ "$OS_ID" = "ubuntu" ]; then
        LC_ALL=C.UTF-8 run add-apt-repository -y ppa:ondrej/php
    else
        run curl -fsSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
        echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" >/etc/apt/sources.list.d/php.list
    fi
    run apt-get update -y
    info "PHP repository added"
fi
apt_install php${PHP_V}-fpm php${PHP_V}-cli php${PHP_V}-common php${PHP_V}-sqlite3 php${PHP_V}-mysql php${PHP_V}-curl \
    php${PHP_V}-mbstring php${PHP_V}-xml php${PHP_V}-zip php${PHP_V}-bcmath php${PHP_V}-intl php${PHP_V}-gd php${PHP_V}-readline php${PHP_V}-opcache
update-alternatives --set php /usr/bin/php${PHP_V} >>"$LOG_FILE" 2>&1 || true
systemctl enable php${PHP_V}-fpm >>"$LOG_FILE" 2>&1
ok "$(php${PHP_V} -r 'echo "PHP ".PHP_VERSION;') installed"

# ------------------------------------------------------------------ 3. Apache
step "Installing Apache"
apt_install apache2
for mod in php${PHP_V} mpm_prefork mpm_worker; do a2dismod -q "$mod" >>"$LOG_FILE" 2>&1 || true; done
run a2enmod -q mpm_event proxy proxy_fcgi proxy_http proxy_wstunnel setenvif ssl rewrite headers http2 expires alias
systemctl enable apache2 >>"$LOG_FILE" 2>&1
ok "$(apache2 -v | head -1 | sed -E 's#.*(Apache/[0-9.]+).*#\1#') with mpm_event, http2, ssl, rewrite"

# ------------------------------------------------------------------ 4. extra tools
step "Installing Composer and Certbot"
if [ ! -x /usr/local/bin/composer ]; then
    run curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    run php${PHP_V} /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi
apt_install certbot
ok "composer $(COMPOSER_ALLOW_SUPERUSER=1 composer --version 2>/dev/null | awk '{print $3}'), $(certbot --version 2>&1)"

# ------------------------------------------------------------------ 5. users & directories
step "Preparing users and directories"
if ! id "$GBX_USER" >/dev/null 2>&1; then
    useradd --system --home-dir "$GBX_ROOT" --shell /usr/sbin/nologin "$GBX_USER"
    info "system user ${GBX_USER} created"
fi
mkdir -p "$GBX_ROOT"/{bin,ssl,logs/cron,pages/stopped,tmp,backup}
mkdir -p /www/wwwroot/default /www/wwwlogs /www/backup/database /www/server/ssl /www/docker
chmod 755 /www /www/wwwroot /www/wwwlogs
chown www-data:www-data /www/wwwroot /www/wwwroot/default
chmod 700 /www/backup /www/server/ssl
chown "$GBX_USER:$GBX_USER" "$GBX_ROOT" "$GBX_ROOT"/{logs,tmp,backup}

cat >"$GBX_ROOT/pages/stopped/index.html" <<'EOF'
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Website stopped</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0d0f12;color:#e8eaed;font-family:"Segoe UI",system-ui,Roboto,Arial,sans-serif}div{text-align:center;padding:2rem}h1{font-size:1.8rem;margin:0 0 .5rem}p{color:#858d97}</style>
</head><body><div><h1>This website is currently stopped</h1><p>Please contact the site administrator.</p></div></body></html>
EOF
cat >/www/wwwroot/default/index.html <<'EOF'
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Server ready</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0d0f12;color:#e8eaed;font-family:"Segoe UI",system-ui,Roboto,Arial,sans-serif}div{text-align:center;padding:2rem}h1{font-size:1.8rem;margin:0 0 .5rem}p{color:#858d97}</style>
</head><body><div><h1>Server is ready</h1><p>No website is configured for this address yet.</p></div></body></html>
EOF
chmod -R 755 "$GBX_ROOT/pages"
ok "directories created (/www/wwwroot, /www/wwwlogs, /www/backup)"

# ------------------------------------------------------------------ 6. panel source
step "Deploying panel files"
TMP_SRC=""
if [ -z "$SOURCE" ] && [ -f "$SCRIPT_DIR/panel/artisan" ]; then
    SOURCE="$SCRIPT_DIR"
fi
if [ -z "$SOURCE" ]; then
    SOURCE="${GBX_REPO%/}/archive/refs/heads/${GBX_BRANCH}.tar.gz"
fi

if [ -d "$SOURCE" ]; then
    SRC_DIR="$SOURCE"
    info "using local source ${SRC_DIR}"
else
    TMP_SRC="$(mktemp -d)"
    info "downloading ${SOURCE}"
    run curl -fsSL "$SOURCE" -o "$TMP_SRC/panel.tar.gz" || fail "could not download the panel from ${SOURCE}. Set GBX_REPO or use --source."
    run tar -xzf "$TMP_SRC/panel.tar.gz" -C "$TMP_SRC"
    SRC_DIR="$(dirname "$(find "$TMP_SRC" -maxdepth 3 -path '*/panel/artisan' | head -1)")"
    SRC_DIR="${SRC_DIR%/panel}"
fi
[ -f "$SRC_DIR/panel/artisan" ] || fail "panel sources not found in ${SRC_DIR}"

mkdir -p "$PANEL_DIR"
run rsync -a --delete \
    --exclude='.env' --exclude='database/*.sqlite*' --exclude='storage/logs/*' --exclude='storage/app/*' \
    --exclude='storage/framework/sessions/*' --exclude='storage/framework/cache/*' --exclude='node_modules' --exclude='.git' \
    "$SRC_DIR/panel/" "$PANEL_DIR/"
install -m 755 "$SRC_DIR/scripts/gbx" "$GBX_ROOT/bin/gbx"
[ -f "$SRC_DIR/uninstall.sh" ] && install -m 700 "$SRC_DIR/uninstall.sh" "$GBX_ROOT/bin/uninstall.sh"
ln -sf "$GBX_ROOT/bin/gbx" /usr/bin/gbx
[ -n "$TMP_SRC" ] && rm -rf "$TMP_SRC"
ok "panel copied to ${PANEL_DIR}"

# ------------------------------------------------------------------ 7. application
step "Configuring the application"
mkdir -p "$PANEL_DIR"/storage/{app/tasks,app/uploads,app/imports,framework/cache/data,framework/sessions,framework/views,logs} "$PANEL_DIR/bootstrap/cache"
chown -R "$GBX_USER:$GBX_USER" "$PANEL_DIR"

as_panel() { runuser -u "$GBX_USER" -- env HOME="$GBX_ROOT" COMPOSER_HOME="$GBX_ROOT/.composer" "$@"; }

if [ ! -f "$PANEL_DIR/vendor/autoload.php" ] || [ "$REINSTALL" -eq 1 ]; then
    info "installing PHP dependencies with composer (this can take a minute)"
    ( cd "$PANEL_DIR" && as_panel php${PHP_V} /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-progress ) >>"$LOG_FILE" 2>&1 \
        || fail "composer install failed"
fi

env_value() { grep -E "^$1=" "$PANEL_DIR/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"' || true; }
KEEP_ADMIN=0
if [ "$REINSTALL" -eq 1 ]; then
    [ -z "$PANEL_PORT" ] && PANEL_PORT="$(env_value GBX_PORT)"
    [ -z "$PANEL_ENTRY" ] && PANEL_ENTRY="$(env_value GBX_ENTRY)"
    if [ -z "$ADMIN_USER" ] && [ -z "$ADMIN_PASS" ]; then
        EXISTING_ADMIN="$(sqlite3 "$PANEL_DIR/database/database.sqlite" "SELECT username FROM users WHERE role='admin' ORDER BY id LIMIT 1;" 2>/dev/null || true)"
        if [ -f "$GBX_ROOT/default.txt" ] && [ -n "$EXISTING_ADMIN" ]; then
            KEEP_ADMIN=1
        elif [ -n "$EXISTING_ADMIN" ]; then
            # the previous run stopped before the credentials were shown: reset the password
            ADMIN_USER="$EXISTING_ADMIN"
            info "previous installation was incomplete, a new password will be generated for ${EXISTING_ADMIN}"
        fi
    fi
fi

if [ -z "$PANEL_PORT" ]; then
    for _ in $(seq 1 50); do
        PANEL_PORT=$(( (RANDOM * 32768 + RANDOM) % 50000 + 10000 ))
        port_in_use "$PANEL_PORT" || break
    done
fi
port_in_use "$PANEL_PORT" && [ "$REINSTALL" -eq 0 ] && fail "port ${PANEL_PORT} is already in use"
[ -z "$PANEL_ENTRY" ] && PANEL_ENTRY="$(random_string 'a-f0-9' 8)"
[ -z "$ADMIN_USER" ] && ADMIN_USER="$(random_string 'a-z' 8)"
[ -z "$ADMIN_PASS" ] && ADMIN_PASS="$(random_string 'A-Za-z0-9' 16)"

SCHEME="https"; [ "$USE_SSL" -eq 0 ] && SCHEME="http"

if [ ! -f "$PANEL_DIR/.env" ]; then
    cp "$PANEL_DIR/.env.example" "$PANEL_DIR/.env"
    chown "$GBX_USER:$GBX_USER" "$PANEL_DIR/.env"
    ( cd "$PANEL_DIR" && as_panel php${PHP_V} artisan key:generate --force ) >>"$LOG_FILE" 2>&1
fi
sed -i "s#^APP_URL=.*#APP_URL=${SCHEME}://127.0.0.1:${PANEL_PORT}#" "$PANEL_DIR/.env"
sed -i "s#^SESSION_SECURE_COOKIE=.*#SESSION_SECURE_COOKIE=$([ "$USE_SSL" -eq 1 ] && echo true || echo false)#" "$PANEL_DIR/.env"
sed -i "s#^APP_TIMEZONE=.*#APP_TIMEZONE=$(timedatectl show -p Timezone --value 2>/dev/null || echo UTC)#" "$PANEL_DIR/.env"

touch "$PANEL_DIR/database/database.sqlite"
chown "$GBX_USER:$GBX_USER" "$PANEL_DIR/database/database.sqlite"
( cd "$PANEL_DIR" && as_panel php${PHP_V} artisan migrate --force ) >>"$LOG_FILE" 2>&1 || fail "database migration failed"
if [ "$KEEP_ADMIN" -eq 1 ]; then
    ( cd "$PANEL_DIR" && as_panel php${PHP_V} artisan gbx:info --set-entry="${PANEL_ENTRY:-off}" ) >>"$LOG_FILE" 2>&1 || true
    sed -i "s#^GBX_PORT=.*#GBX_PORT=${PANEL_PORT}#" "$PANEL_DIR/.env"
    ADMIN_USER="(unchanged)"; ADMIN_PASS="(unchanged, run: gbx password)"
else
( cd "$PANEL_DIR" && as_panel php${PHP_V} artisan gbx:setup --username="$ADMIN_USER" --password="$ADMIN_PASS" --port="$PANEL_PORT" --entry="$PANEL_ENTRY" --json ) >>"$LOG_FILE" 2>&1 \
    || fail "could not create the administrator"
fi
( cd "$PANEL_DIR" && as_panel php${PHP_V} artisan optimize:clear && as_panel php${PHP_V} artisan view:cache ) >>"$LOG_FILE" 2>&1 || true

# permissions: Apache (www-data) only needs to read public assets
find "$PANEL_DIR" -type d -not -path '*/storage/*' -exec chmod 755 {} +
find "$PANEL_DIR" -type f -not -path '*/storage/*' -exec chmod 644 {} +
chmod 755 "$PANEL_DIR/artisan"
chmod -R u+rwX,g+rwX,o-rwx "$PANEL_DIR/storage" "$PANEL_DIR/bootstrap/cache"
chmod 700 "$PANEL_DIR/database"
chmod 600 "$PANEL_DIR/.env" "$PANEL_DIR/database/database.sqlite"
chown -R "$GBX_USER:$GBX_USER" "$PANEL_DIR"
ok "application configured (SQLite at ${PANEL_DIR}/database/database.sqlite)"

# ------------------------------------------------------------------ 8. privileges
step "Configuring privileges"
cat >/etc/sudoers.d/gbxpanel <<EOF
# GBX Panel runs system administration commands as root.
Defaults:${GBX_USER} !requiretty
Defaults:${GBX_USER} !syslog
${GBX_USER} ALL=(root) NOPASSWD: ALL
EOF
chmod 440 /etc/sudoers.d/gbxpanel
visudo -cf /etc/sudoers.d/gbxpanel >>"$LOG_FILE" 2>&1 || { rm -f /etc/sudoers.d/gbxpanel; fail "invalid sudoers file"; }
ok "sudo rules installed for ${GBX_USER}"

# ------------------------------------------------------------------ 9. PHP-FPM pool
step "Configuring PHP-FPM pool"
cat >/etc/php/${PHP_V}/fpm/pool.d/gbxpanel.conf <<EOF
; GBX Panel pool - managed by the installer
[gbxpanel]
user = ${GBX_USER}
group = ${GBX_USER}
listen = /run/php/gbxpanel.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = ondemand
pm.max_children = 12
pm.process_idle_timeout = 30s
pm.max_requests = 500
request_terminate_timeout = 3600

php_admin_value[memory_limit] = 512M
php_admin_value[upload_max_filesize] = 2048M
php_admin_value[post_max_size] = 2048M
php_admin_value[max_execution_time] = 3600
php_admin_value[max_input_time] = 3600
php_admin_value[error_log] = ${GBX_ROOT}/logs/php-error.log
php_admin_flag[log_errors] = on
php_admin_value[upload_tmp_dir] = ${GBX_ROOT}/tmp
php_admin_value[session.save_path] = ${GBX_ROOT}/tmp
EOF
run php-fpm${PHP_V} -t
run systemctl restart php${PHP_V}-fpm
ok "pool gbxpanel listening on /run/php/gbxpanel.sock"

# ------------------------------------------------------------------ 10. SSL + Apache vhost
step "Configuring Apache virtual host on port ${PANEL_PORT}"
SERVER_IP="$(curl -4 -fsS --max-time 5 https://api.ipify.org 2>/dev/null || true)"
LOCAL_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
[ -z "$SERVER_IP" ] && SERVER_IP="$LOCAL_IP"

if [ "$USE_SSL" -eq 1 ] && [ ! -f "$GBX_ROOT/ssl/panel.crt" ]; then
    run openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
        -keyout "$GBX_ROOT/ssl/panel.key" -out "$GBX_ROOT/ssl/panel.crt" \
        -subj "/O=GBX Panel/CN=${SERVER_IP:-gbxpanel}" \
        -addext "subjectAltName=IP:${SERVER_IP:-127.0.0.1},IP:127.0.0.1,DNS:localhost"
    chmod 600 "$GBX_ROOT/ssl/panel.key"
    info "self-signed certificate generated"
fi

cat >/etc/apache2/sites-available/000-default.conf <<'EOF'
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot /www/wwwroot/default
    <Directory /www/wwwroot/default>
        Options -Indexes
        AllowOverride None
        Require all granted
    </Directory>
    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

# "ServerTokens Prod" and no signature
if [ -f /etc/apache2/conf-available/security.conf ]; then
    sed -i 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf
fi
grep -q "^ServerName" /etc/apache2/apache2.conf || echo "ServerName localhost" >>/etc/apache2/apache2.conf

run a2ensite -q 000-default

# panel SSL state lives in .env and the vhost is generated by "gbx vhost"
env_put() { grep -qE "^$1=" "$PANEL_DIR/.env" && sed -i "s#^$1=.*#$1=$2#" "$PANEL_DIR/.env" || echo "$1=$2" >>"$PANEL_DIR/.env"; }
if [ "$REINSTALL" -eq 0 ] || [ "$USE_SSL" -eq 0 ]; then
    env_put GBX_SSL "$([ "$USE_SSL" -eq 1 ] && echo true || echo false)"
fi
if [ -z "$(env_value GBX_SSL_CERT)" ] || [ ! -f "$(env_value GBX_SSL_CERT)" ]; then
    env_put GBX_SSL_CERT "$GBX_ROOT/ssl/panel.crt"
    env_put GBX_SSL_KEY "$GBX_ROOT/ssl/panel.key"
fi
"$GBX_ROOT/bin/gbx" vhost >>"$LOG_FILE" 2>&1 || fail "could not generate the panel virtual host (see ${LOG_FILE})"
run systemctl restart apache2
SCHEME="https"; [ "$(env_value GBX_SSL)" = "false" ] && SCHEME="http"
PANEL_HOST="$(env_value GBX_DOMAIN)"
ok "Apache listening on port ${PANEL_PORT} (${SCHEME})"

# ------------------------------------------------------------------ 11. supervisor + scheduler
step "Configuring queue worker and scheduler"
cat >/etc/supervisor/conf.d/gbxpanel.conf <<EOF
[program:gbxpanel-worker]
command=/usr/bin/php${PHP_V} ${PANEL_DIR}/artisan queue:work --sleep=1 --tries=1 --timeout=3600 --memory=256
directory=${PANEL_DIR}
user=${GBX_USER}
environment=HOME="${GBX_ROOT}"
process_name=%(program_name)s_%(process_num)02d
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=3610
redirect_stderr=true
stdout_logfile=${GBX_ROOT}/logs/worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
EOF
run supervisorctl reread
run supervisorctl update
supervisorctl restart 'gbxpanel-worker:*' >>"$LOG_FILE" 2>&1 || true

cat >/etc/cron.d/gbxpanel-scheduler <<EOF
# GBX Panel scheduler (metrics collection, cleanup)
* * * * * ${GBX_USER} /usr/bin/php${PHP_V} ${PANEL_DIR}/artisan schedule:run >> /dev/null 2>&1
EOF
chmod 644 /etc/cron.d/gbxpanel-scheduler
touch /etc/cron.d/gbxpanel && chmod 644 /etc/cron.d/gbxpanel

cat >/etc/logrotate.d/gbxpanel <<EOF
${GBX_ROOT}/logs/*.log ${GBX_ROOT}/logs/cron/*.log /www/wwwlogs/*.log {
    weekly
    rotate 8
    missingok
    notifempty
    compress
    delaycompress
    copytruncate
}
EOF
ok "supervisor worker (2 processes) and cron scheduler configured"

# ------------------------------------------------------------------ 12. firewall
step "Configuring firewall"
SSH_PORT="$(detect_ssh_port)"
if [ "$USE_FIREWALL" -eq 1 ]; then
    # a firewall problem (e.g. no iptables inside a container) must not abort the installation
    if run ufw allow "${SSH_PORT}/tcp" comment 'SSH' \
        && run ufw allow 80/tcp comment 'HTTP' \
        && run ufw allow 443/tcp comment 'HTTPS' \
        && run ufw allow "${PANEL_PORT}/tcp" comment 'GBX Panel' \
        && run ufw --force enable; then
        ok "UFW enabled (SSH ${SSH_PORT}, 80, 443, ${PANEL_PORT})"
    else
        warn "UFW could not be configured (see ${LOG_FILE}). Open ports ${SSH_PORT}, 80, 443 and ${PANEL_PORT} manually."
    fi
else
    warn "firewall left untouched (--no-firewall). Make sure port ${PANEL_PORT} is reachable."
fi

# ------------------------------------------------------------------ 13. health check
step "Checking the panel"
sleep 1
CHECK_HOST="${PANEL_HOST:-127.0.0.1}"
HTTP_CODE="$(curl -k -s -o /dev/null -w "%{http_code}" --resolve "${CHECK_HOST}:${PANEL_PORT}:127.0.0.1" "${SCHEME}://${CHECK_HOST}:${PANEL_PORT}/${PANEL_ENTRY}" || true)"
if [ "$HTTP_CODE" = "302" ] || [ "$HTTP_CODE" = "200" ]; then
    ok "panel responds (HTTP ${HTTP_CODE})"
else
    warn "panel returned HTTP ${HTTP_CODE:-no response}; check ${GBX_ROOT}/logs/apache-error.log and ${PANEL_DIR}/storage/logs"
fi

# ------------------------------------------------------------------ 14. summary
step "Saving access information"
cat >"$GBX_ROOT/default.txt" <<EOF
GBX Panel - installed $(date '+%Y-%m-%d %H:%M:%S')
External URL : ${SCHEME}://${PANEL_HOST:-$SERVER_IP}:${PANEL_PORT}/${PANEL_ENTRY}
Internal URL : ${SCHEME}://${LOCAL_IP}:${PANEL_PORT}/${PANEL_ENTRY}
Username     : ${ADMIN_USER}
Password     : ${ADMIN_PASS}
EOF
chmod 600 "$GBX_ROOT/default.txt"
cp "$LOG_FILE" "$GBX_ROOT/logs/install.log" 2>/dev/null || true
ok "saved to ${GBX_ROOT}/default.txt (readable by root only)"

ELAPSED=$(( $(date +%s) - START_TS ))
LINE="=================================================================="
echo
echo -e "${C_GREEN}${LINE}${C_RESET}"
echo -e "${C_GREEN}${C_BOLD}  GBX Panel installed successfully${C_RESET}  ${C_DIM}(${ELAPSED}s)${C_RESET}"
echo -e "${C_GREEN}${LINE}${C_RESET}"
echo -e "  External panel address : ${C_BOLD}${C_CYAN}${SCHEME}://${PANEL_HOST:-$SERVER_IP}:${PANEL_PORT}/${PANEL_ENTRY}${C_RESET}"
echo -e "  Internal panel address : ${SCHEME}://${LOCAL_IP}:${PANEL_PORT}/${PANEL_ENTRY}"
echo -e "  Username               : ${C_BOLD}${ADMIN_USER}${C_RESET}"
echo -e "  Password               : ${C_BOLD}${ADMIN_PASS}${C_RESET}"
echo -e "${C_GREEN}${LINE}${C_RESET}"
echo -e "  ${C_YELLOW}Important:${C_RESET}"
echo -e "  - If your provider has a cloud firewall / security group, open TCP port ${C_BOLD}${PANEL_PORT}${C_RESET}."
[ "$USE_SSL" -eq 1 ] && echo -e "  - The panel uses a self-signed certificate. Your browser will show a warning; choose \"Advanced\" and continue."
echo -e "  - Run ${C_BOLD}gbx${C_RESET} on this server at any time to see the address or reset the password."
echo -e "${C_GREEN}${LINE}${C_RESET}"
echo
