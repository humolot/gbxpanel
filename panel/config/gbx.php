<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GBX Panel core
    |--------------------------------------------------------------------------
    */

    'version' => '1.0.0',

    // Base install dir on the server.
    'root' => env('GBX_ROOT', '/usr/local/gbxpanel'),

    // Security entrance (e.g. /fe08a6b5). Empty disables the entrance check.
    'entry' => env('GBX_ENTRY', ''),

    // Panel port (informative; Apache listens on it).
    'port' => (int) env('GBX_PORT', 8888),

    // Panel served over HTTPS (managed by the gbx CLI).
    'ssl' => (bool) env('GBX_SSL', true),
    'ssl_cert' => env('GBX_SSL_CERT', ''),

    // When set, the panel only answers on this domain (gbx domain <name>).
    'domain' => env('GBX_DOMAIN', ''),

    // When true, shell commands are not executed and fake data is returned.
    // Automatically enabled on non-Linux hosts (local development).
    'simulate' => env('GBX_SIMULATE', PHP_OS_FAMILY !== 'Linux'),

    // Prefix commands with "sudo -n" when the web user is not root.
    'use_sudo' => env('GBX_USE_SUDO', true),

    /*
    |--------------------------------------------------------------------------
    | Server layout
    |--------------------------------------------------------------------------
    */

    'paths' => [
        'www' => env('GBX_WWW', '/www/wwwroot'),
        'logs' => env('GBX_WWW_LOGS', '/www/wwwlogs'),
        'backup' => env('GBX_BACKUP', '/www/backup'),
        'ssl' => env('GBX_SSL_DIR', '/www/server/ssl'),
        'apache_sites' => '/etc/apache2/sites-available',
        'apache_enabled' => '/etc/apache2/sites-enabled',
        'php_base' => '/etc/php',
    ],

    // ClamAV integration (Laravel -> clamd unix socket -> ClamAV engine).
    'clamav' => [
        'socket' => env('GBX_CLAMD_SOCKET', '/var/run/clamav/clamd.ctl'),
        'quarantine' => env('GBX_QUARANTINE', '/www/quarantine'),
        // files larger than this are scanned by clamdscan --fdpass instead of INSTREAM
        'stream_max_bytes' => (int) env('GBX_CLAMD_STREAM_MAX', 200 * 1048576),
        'timeout' => (int) env('GBX_CLAMD_TIMEOUT', 300),
    ],

    // Real-time web terminal (scripts/gbx-terminal, proxied by Apache at /gbx-terminal/).
    'terminal' => [
        'port' => (int) env('GBX_TERMINAL_PORT', 17878),
        // Browser WebSocket URL override; empty uses the same origin at /gbx-terminal/.
        'url' => env('GBX_TERMINAL_URL', ''),
        'token_ttl' => 60,
    ],

    // phpMyAdmin and Adminer (/phpmyadmin, /adminer on the panel port). When not public, Apache only
    // serves them to browsers holding the gbx_tools cookie set by Databases (gbx tools-access).
    'tools' => [
        'token' => env('GBX_TOOLS_TOKEN', ''),
        'public' => env('GBX_TOOLS_TOKEN', '') === '' || filter_var(env('GBX_TOOLS_PUBLIC', false), FILTER_VALIDATE_BOOL),
    ],

    // Automatic database backups (Databases > Auto backup)
    'database_backup_keep' => 7,

    // Docker compose projects created from the panel and One-Click Install
    'docker' => [
        'projects' => env('GBX_DOCKER_PROJECTS', '/www/docker'),
    ],

    // User that owns website files and runs PHP-FPM pools for sites.
    'web_user' => env('GBX_WEB_USER', 'www-data'),

    // Default PHP version for new websites.
    'default_php' => env('GBX_DEFAULT_PHP', '8.4'),

    /*
    |--------------------------------------------------------------------------
    | Log files available on the Logs page
    |--------------------------------------------------------------------------
    */

    'log_files' => [
        'System' => [
            'syslog' => '/var/log/syslog',
            'auth' => '/var/log/auth.log',
            'kern' => '/var/log/kern.log',
            'dpkg' => '/var/log/dpkg.log',
            'ufw' => '/var/log/ufw.log',
            'fail2ban' => '/var/log/fail2ban.log',
        ],
        'Web' => [
            'apache_error' => '/var/log/apache2/error.log',
            'apache_access' => '/var/log/apache2/access.log',
            'php84_fpm' => '/var/log/php8.4-fpm.log',
        ],
        'Services' => [
            'mysql_error' => '/var/log/mysql/error.log',
            'supervisor' => '/var/log/supervisor/supervisord.log',
            'docker' => '/var/log/docker.log',
            'pureftpd' => '/var/log/pure-ftpd/transfer.log',
        ],
    ],

];
