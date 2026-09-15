# GBX Panel

GBX Panel is a self-hosted control panel for Linux servers. It manages websites, databases, FTP, Docker, firewall, antivirus, cron jobs and system services from a single web interface, and ships with an AI operator that can inspect and administer the host through function calling.

- **Backend:** Laravel 12, PHP 8.4, SQLite
- **Frontend:** Bootstrap 5, jQuery, Bootstrap Icons (no build step, no CDN at runtime)
- **Managed stack:** Apache 2 (mpm_event), PHP-FPM 7.4 – 8.5, MySQL / MariaDB, Pure-FTPd, Docker, UFW, Fail2ban, ClamAV, Certbot, Supervisor, PM2, Node.js

---

## Table of contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [Accessing the panel](#accessing-the-panel)
4. [Command line tool](#command-line-tool-gbx)
5. [Features](#features)
6. [AI operator](#ai-operator)
7. [Antivirus (ClamAV)](#antivirus-clamav)
8. [Web terminal](#web-terminal)
9. [Architecture](#architecture)
10. [Configuration reference](#configuration-reference)
11. [Security model](#security-model)
12. [Development](#development)
13. [Uninstall](#uninstall)

---

## Requirements

| | |
| --- | --- |
| Operating system | Ubuntu 22.04 / 24.04, Debian 12 / 13 |
| Architecture | x86_64, aarch64 |
| Memory | 1 GB minimum, 2 GB or more recommended (the ClamAV daemon needs about 1.5 GB) |
| Disk | 5 GB free |
| Access | root, on a fresh server |

## Installation

```bash
wget -O install.sh https://raw.githubusercontent.com/humolot/gbxpanel/main/install.sh && sudo bash install.sh
```

The installer asks for confirmation, logs to `/tmp/gbxpanel-install.log` and performs the following steps:

1. Installs base packages, the PHP 8.4 repository (`ppa:ondrej/php` or `packages.sury.org`), PHP 8.4-FPM, Apache, Composer, Certbot, Supervisor and UFW.
2. Creates the `gbxpanel` system user and the directories `/usr/local/gbxpanel`, `/www/wwwroot`, `/www/wwwlogs` and `/www/backup`.
3. Deploys the application, installs dependencies, runs the migrations and creates an administrator with a random username and password.
4. Creates a dedicated PHP-FPM pool, a self-signed certificate and the panel virtual host on a random port with a random security entrance.
5. Configures the queue worker (Supervisor), the scheduler (cron), log rotation and the firewall.
6. Runs a health check and prints the access information.

### Installer options

| Option | Description |
| --- | --- |
| `-y`, `--yes` | Non-interactive installation |
| `--port <port>` | Panel port (default: random 10000–60000) |
| `--entry <path>` | Security entrance (default: random, 8 characters) |
| `--user <name>` / `--password <pass>` | Administrator credentials (default: random) |
| `--http` | Serve the panel over plain HTTP |
| `--no-firewall` | Do not enable UFW |
| `--source <dir\|url>` | Install from a local checkout or a `.tar.gz` archive |

The `GBX_REPO` and `GBX_BRANCH` environment variables override the source repository (default `https://github.com/humolot/gbxpanel`, branch `main`).

Running the installer again on an existing installation keeps the SQLite database, port, entrance and administrator.

## Accessing the panel

At the end of the installation the address and credentials are printed and stored in `/usr/local/gbxpanel/default.txt` (readable by root only):

```
External panel address : https://203.0.113.10:35655/fe08a6b5
Username               : kqzvmtra
Password               : 7Hq2mLxW9pRt4NcZ
```

- Open the panel port in any provider-level firewall or security group.
- The default certificate is self-signed, so browsers show a warning on the first visit. Bind a domain and issue a trusted certificate with `gbx domain` and `gbx ssl letsencrypt`.
- Without the security entrance path every URL returns `404`.

## Command line tool (gbx)

Run `gbx` as root to open the interactive menu, `gbx <number>` to run an option directly, or use the sub-commands below.

```
 (1)  Restart panel                 (12) Enable panel SSL
 (2)  Stop panel                    (13) Disable panel SSL
 (3)  Start panel                   (14) Let's Encrypt cert for panel domain
 (4)  Panel status                  (15) Bind domain to panel
 (5)  Change panel password         (16) Unbind panel domain
 (6)  Change panel username         (17) Clear panel cache
 (7)  Panel URL and login           (18) Clear login limit
 (8)  Initial login (if unchanged)  (19) Clear IP allow list
 (9)  Change panel port             (20) Repair permissions
 (10) Change security entrance      (21) Update panel
 (11) Disable security entrance     (22) View error logs
                                    (23) Uninstall panel
                                    (24) Diagnose problems
```

| Command | Description |
| --- | --- |
| `gbx info` | Panel URL, username (password masked), SSL, bound domain, entrance and service status |
| `gbx default` | Show the initial credentials, only while the initial password is still in use |
| `gbx password [pass]` | Change the administrator password (random when omitted) |
| `gbx username <name>` | Change the administrator username |
| `gbx port <port>` | Change the panel port (virtual host and UFW) |
| `gbx entry <path\|off>` | Change or disable the security entrance |
| `gbx ssl on\|off` | Enable (self-signed) or disable HTTPS |
| `gbx ssl letsencrypt <email>` | Issue a Let's Encrypt certificate for the bound domain |
| `gbx domain <name\|off>` | Restrict the panel to a host name, or remove the restriction |
| `gbx cache` | Clear the application cache |
| `gbx unlock-login` | Remove failed-login lockouts |
| `gbx unlock-ip` | Clear the IP allow list |
| `gbx restart\|stop\|start\|status` | Control the panel services |
| `gbx update` | Update from the repository (database backup, migrations, restart) |
| `gbx fix` | Repair file ownership and permissions |
| `gbx logs` | Show recent application, Apache and worker errors |
| `gbx vhost` | Regenerate the panel virtual host from `.env` |
| `gbx fpm` | Recreate the dedicated `gbxpanel-fpm` service and the virtual host |
| `gbx terminal [on\|off\|status\|restart]` | Manage the real-time web terminal service (`gbxpanel-terminal`) |
| `gbx doctor` | Check services, sockets, the terminal daemon, permissions, sudo and the login page, and suggest fixes |

The panel virtual host is generated by `gbx` from `.env` (`GBX_PORT`, `GBX_SSL`, `GBX_DOMAIN`, `GBX_SSL_CERT`, `GBX_SSL_KEY`) and validated with `apache2ctl configtest` before Apache is reloaded. The previous configuration is restored if the test fails.

## Features

| Section | Capabilities |
| --- | --- |
| **Home** | Live CPU, load, memory, disk, network and disk I/O; installed software; system update and reboot. **Monitor:** 1 h – 7 d history. **Processes:** sortable list, TERM/HUP/KILL. **Services:** start, stop, restart, enable, journal. **Software:** install and uninstall from the catalog, PHP settings and extensions. **Cron:** jobs with logs and manual runs. |
| **Websites** | Apache virtual hosts with a per-site PHP-FPM version, static sites or a reverse proxy to local applications (WebSocket aware), aliases, start/stop, Let's Encrypt or custom certificates, HTTPS redirect, vhost editor with configtest and rollback, access and error logs, malware scan, optional database and FTP account on creation. |
| **FTP** | Pure-FTPd virtual users mapped to the web user, password and directory management, enable/disable. |
| **Databases** | MySQL/MariaDB databases and users, credentials, password rotation, compressed backups, SQL imports, phpMyAdmin. |
| **Docker** | Containers (logs, inspect, start/stop/restart/remove, CPU and memory), images, volumes, networks, container creation, compose deployments, prune. |
| **Security** | **Firewall & SSH:** UFW rules, quick ports, IP blocking, SSH port, root login and authentication methods, Fail2ban jails, failed SSH logins. **Antivirus:** ClamAV scans, upload protection, quarantine, scheduled scans. |
| **Files** | File manager with drag-and-drop uploads (scanned by the antivirus), CodeMirror editor, copy/move, permissions and ownership, zip/tar archives, malware scan, downloads. |
| **Logs** | System, service, website and panel logs with search and live follow; panel activity log. |
| **Terminal** | Real-time root shell (xterm.js over WebSocket) with multiple sessions, full-screen programs (htop, nano, vim, mysql), resize, copy/paste and a touch key bar. Falls back to a command console when the terminal service is stopped. |
| **Accounts** | Users with roles (administrator, operator, read-only), login history, active sessions. |
| **AI** | AI operator with function calling (see [AI operator](#ai-operator)). |
| **Settings** | Panel name, port, entrance, SSL and domain; IP allow list and session lifetime; AI provider; hostname and timezone; maintenance actions. |

### Software catalog

The catalog is defined in `panel/config/software.php`. Each entry declares detection, version, service, configuration file, install and uninstall scripts. Installations run as background tasks with live output.

| Category | Packages |
| --- | --- |
| Runtime | PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5 · Node.js 18, 20, 22, 24 (with npm) · PM2 · Composer |
| Web server | Apache |
| Database | MySQL · MariaDB · phpMyAdmin |
| Cache | Redis · Memcached |
| Containers | Docker Engine with the compose plugin |
| Security | Certbot · Fail2ban · ClamAV |
| System and FTP | Supervisor · Pure-FTPd |

## AI operator

The AI section is an operator for the server, backed by [Venice AI](https://venice.ai) (OpenAI-compatible API) with function calling.

| Setting | Value |
| --- | --- |
| Endpoint | `https://api.venice.ai/api/v1/chat/completions` |
| Chat models | `deepseek-v4-1-flash`, `deepseek-v4-flash`, `qwen-3-8-flash`, `openai-gpt-56-luna`, `openai-gpt-56-luna-pro` |
| Vision model | `qwen3-vl-235b-a22b` (reads screenshots attached to a message) |
| Venice system prompt | Disabled (`venice_parameters.include_venice_system_prompt = false`) |
| API key | Entered in **Settings → AI** and stored encrypted; `VENICE_AI_API_KEY` is an optional fallback |

### Execution model

```
user message ─► model ─► tool calls
                          ├─ read tool  ─► executed immediately ─► result returned to the model
                          └─ write tool ─► approval card in the chat
                                             ├─ Approve ─► executed ─► result returned to the model
                                             └─ Reject  ─► rejection returned to the model
```

- Read tools run automatically. Tools that change the server wait for approval, individually or with **Approve all**. Administrators can enable auto-approval in Settings.
- Long operations (installs, upgrades, backups, deployments, scans, certificates) run as background tasks. The operator follows them with `get_task_status` or `get_malware_scan` before reporting success, and the chat links to the task output.
- Tools are filtered by role. Read-only users receive read tools only. `run_command`, `write_file`, `edit_file`, `docker_exec`, `git_deploy`, `reboot_server`, `configure_system`, `configure_antivirus`, `set_firewall_enabled`, `update_ssh_settings` and `save_website_vhost` are restricted to administrators.
- `run_readonly_command` accepts inspection binaries only, with per-binary argument rules (for example `systemctl status` is allowed and `systemctl stop` is not). Command chaining, redirections and command substitution are rejected.
- Every executed action is written to the activity log (category `ai`).

### Tools (94)

| Group | Read (automatic) | Actions (approval) |
| --- | --- | --- |
| System | `get_server_overview`, `get_memory_usage`, `get_disk_usage`, `find_large_files`, `get_network_status`, `list_processes`, `list_services`, `get_service_status`, `read_log`, `run_readonly_command`, `check_updates`, `list_software`, `get_task_status` | `run_command`, `service_action`, `kill_process`, `manage_software`, `upgrade_system`, `reboot_server`, `configure_system` |
| Files | `list_directory`, `read_file`, `search_in_files` | `write_file`, `edit_file`, `create_directory`, `delete_path`, `move_or_copy_path`, `rename_path`, `set_permissions`, `compress_files`, `extract_archive` |
| Backups | `list_backups` | `backup_website`, `backup_database`, `backup_path`, `restore_database`, `restore_archive`, `delete_backup` |
| Websites | `list_websites`, `get_website` | `create_website`, `update_website`, `delete_website`, `manage_website_ssl`, `save_website_vhost` |
| Databases and FTP | `list_databases`, `query_database` (SELECT/SHOW only), `list_ftp_accounts` | `create_database`, `delete_database`, `change_database_password`, `manage_ftp_account` |
| Cron | `list_cron_jobs`, `get_cron_log` | `manage_cron_job` |
| Firewall and SSH | `get_firewall` | `open_port`, `close_port`, `add_firewall_rule`, `delete_firewall_rule`, `block_ip`, `unblock_ip`, `set_firewall_enabled`, `update_ssh_settings` |
| Antivirus | `get_antivirus_status`, `list_malware_detections`, `get_malware_scan`, `scan_for_malware` | `handle_malware_detections`, `update_antivirus_signatures`, `configure_antivirus` |
| Docker | `list_docker`, `get_container_logs`, `inspect_docker`, `get_compose_project` | `docker_run_container`, `docker_container_action`, `docker_compose_deploy`, `docker_compose_action`, `docker_image_action`, `docker_prune`, `docker_exec` |
| Supervisor, PM2 and deploy | `list_supervisor_programs`, `get_supervisor_program`, `list_pm2_processes`, `get_pm2_logs` | `save_supervisor_program`, `supervisor_action`, `delete_supervisor_program`, `pm2_start`, `pm2_action`, `pm2_enable_startup`, `git_deploy` |

Example request: *"Deploy Uptime Kuma with Docker on status.example.com with SSL."* The operator checks Docker, writes a compose file bound to `127.0.0.1`, deploys it, creates a reverse-proxy website, issues the certificate and confirms each background task.

## Antivirus (ClamAV)

Install **ClamAV** from **Home → Software**. The install script installs `clamav`, `clamav-daemon` and `clamav-freshclam`, downloads the signatures and tunes `/etc/clamav/clamd.conf` (`LocalSocketMode 666`, `StreamMaxLength 200M`, `MaxFileSize 200M`, `MaxScanSize 400M`, pseudo filesystems excluded).

### Scanning pipeline

```
Upload (file manager)                        Directory scan (website, path, schedule, AI)
        │                                                  │
        ▼                                                  ▼
Laravel ── unix socket, INSTREAM ──► clamd      Background task: clamdscan --multiscan --fdpass
        │                              │                   │
        │                              ▼                   ▼
        │                       ClamAV engine      clamd ─► ClamAV engine
        ▼                                                  │
 clean    → saved to the destination                       ▼
 infected → rejected and recorded             output parsed → detections → optional quarantine
```

- **Uploads** are streamed to clamd over `/var/run/clamav/clamd.ctl` with the `zINSTREAM` command (4-byte big-endian chunk length followed by the data, terminated by a zero length) before the file is moved into the destination folder. No process is spawned and signatures stay loaded in memory. Files larger than `StreamMaxLength` are scanned with `clamdscan --fdpass`.
- **Directories** are scanned as root by `clamdscan --multiscan --fdpass`. clamd receives file descriptors, so it can scan files that its own user cannot open.
- **Fallback:** when clamd is not running, `clamscan` is executed through Symfony Process.
- **Result codes** follow clamscan: `0` clean, `1` infected, `2` error.

```php
use App\Services\ClamAvManager;

$result = app(ClamAvManager::class)->scanFile($path);

match ($result['code']) {
    ClamAvManager::CLEAN    => $this->store($path),
    ClamAvManager::INFECTED => $this->reject($result['signature']),
    ClamAvManager::ERROR    => $this->report($result['message']),
};
```

### Detections and quarantine

| Status | Meaning |
| --- | --- |
| `detected` | Found by a scan and waiting for a decision |
| `blocked` | Infected upload rejected before it was saved |
| `quarantined` | Moved to `/www/quarantine` with mode `000`; original owner and mode are kept for restore |
| `restored` | Returned to its original path |
| `deleted` | Removed permanently |
| `ignored` | Marked as a false positive |

Protection settings (**Security → Antivirus**): upload scanning, blocking uploads when a scan fails, automatic quarantine, and scheduled scans (daily at 03:30 or weekly on Sunday at 04:00) with a configurable path. Scans can also be started from a website page, from the file manager context menu, from the CLI (`php artisan gbx:malware-scan /www/wwwroot`) or by the AI operator.

To verify the integration, upload the [EICAR test file](https://www.eicar.org/download-anti-malware-testfile/) through the file manager. The upload must be rejected.

## Web terminal

The Terminal page streams an interactive login shell in real time. There is no per-command timeout: long-running and full-screen programs behave as they do over SSH.

```
Browser (xterm.js)
   │  wss://<panel>:<port>/gbx-terminal/?token=…
   ▼
Apache (mod_proxy_wstunnel, panel virtual host)
   │  ws://127.0.0.1:17878
   ▼
gbxpanel-terminal.service  (scripts/gbx-terminal, Python 3 standard library)
   │  pty.fork()
   ▼
/bin/bash -l  (root, TERM=xterm-256color)
```

| Aspect | Details |
| --- | --- |
| Daemon | `/usr/local/gbxpanel/bin/gbx-terminal`, asyncio WebSocket server bound to `127.0.0.1` only; no third-party dependencies |
| Authorization | Before connecting, the page requests a token from `POST /terminal/token` (administrators only, CSRF protected, audited). The token is `base64url(payload).base64url(HMAC-SHA256(APP_KEY, payload))` with user, expiry (60 s), a random nonce and the client IP |
| Validation | The daemon checks the signature with `APP_KEY` from `panel/.env`, the expiry, that the nonce was never used, and that the client address (last `X-Forwarded-For` hop added by Apache) matches the token |
| Protocol | Client sends JSON text frames: `{"t":"i","d":…}` input, `{"t":"r","c":cols,"r":rows}` resize, `{"t":"k"}` keepalive. The server sends raw terminal output as binary frames and a close frame when the shell exits |
| Limits | Sessions close after 60 minutes without input (`--idle`), at most 20 concurrent sessions (`--max-sessions`), 1 MB maximum frame size |
| Lifecycle | Closing the tab or the session sends `SIGHUP` to the shell process group; `SIGKILL` follows if it does not exit |
| Fallback | When the daemon is not reachable, the page switches to command mode (one non-interactive command per request) and shows how to enable the service |

```bash
gbx terminal            # install/start the service and add the proxy to the panel virtual host
gbx terminal status
gbx terminal off        # stop it; the Terminal page uses command mode
```

## Architecture

```
install.sh                     Installer
uninstall.sh                   Removes the panel (keeps websites, databases and software)
scripts/gbx                    Server CLI
scripts/gbx-terminal           Real-time terminal daemon (WebSocket + PTY)
tests/terminal/                Protocol tests for the terminal daemon
panel/                         Laravel application
├── app/Http/Controllers/      One controller per section
├── app/Http/Middleware/       SecurityEntrance (entrance, domain binding, IP allow list), PanelAccess (roles)
├── app/Services/              Shell, SystemStats, ApacheManager, MysqlManager, FtpManager,
│                              FirewallManager, DockerManager, FileManager, CronManager, SslManager,
│                              SoftwareManager, PanelManager, SupervisorManager, Pm2Manager,
│                              BackupManager, ClamAvManager, TerminalManager, TaskRunner, TaskHooks,
│                              AiAssistant
├── app/Services/Ai/           VeniceClient, ServerTools (tool registry)
├── app/Services/Ai/Tools/     System, File, Backup, Website, Database, Cron, Firewall,
│                              Antivirus, Docker, ProcessManager (Supervisor, PM2, Git)
├── app/Jobs/RunTaskJob.php    Background task executor
├── app/Console/Commands/      gbx:setup, gbx:user, gbx:info, gbx:collect-metrics,
│                              gbx:malware-scan, gbx:clear-login-limit, gbx:reset-access
├── config/gbx.php             Panel configuration
├── config/software.php        Software catalog
├── config/ai.php              AI models and limits
└── public/assets/             Panel CSS/JS and vendored libraries
```

### Runtime

| Component | Details |
| --- | --- |
| Web | Apache virtual host on the panel port → dedicated PHP-FPM master `gbxpanel-fpm.service` (`/run/gbxpanel/php-fpm.sock`) running as user `gbxpanel`. It is separate from the distribution `php8.4-fpm.service`, whose `ProtectSystem=full` sandbox makes `/usr` and `/etc` read-only; websites keep using the hardened service |
| Terminal | `gbxpanel-terminal.service` runs the terminal daemon as root on `127.0.0.1:GBX_TERMINAL_PORT`; Apache proxies `/gbx-terminal/` to it |
| Privileged operations | `App\Services\Shell` runs `sudo -n /bin/bash -c` with a controlled environment; every system change goes through this class |
| Background tasks | Stored in SQLite and executed by `queue:work` (Supervisor program `gbxpanel-worker`, 2 processes); output is written to `storage/app/tasks/<id>.log` and polled by the UI |
| Scheduler | `/etc/cron.d/gbxpanel-scheduler` runs `schedule:run` every minute (metrics collection with 8-day retention, scheduled malware scans) |
| Cron jobs | Rendered from the database to `/etc/cron.d/gbxpanel`; output in `/usr/local/gbxpanel/logs/cron/<id>.log` |
| Database | SQLite in WAL mode (`panel/database/database.sqlite`) |

### Filesystem layout

| Path | Purpose |
| --- | --- |
| `/usr/local/gbxpanel/panel` | Application |
| `/usr/local/gbxpanel/bin/gbx` | CLI (symlinked to `/usr/bin/gbx`) |
| `/usr/local/gbxpanel/bin/gbx-terminal` | Terminal daemon (`gbxpanel-terminal.service`) |
| `/usr/local/gbxpanel/fpm/php-fpm.conf` | Panel PHP-FPM configuration (`gbxpanel-fpm.service`) |
| `/usr/local/gbxpanel/ssl` | Panel self-signed certificate |
| `/usr/local/gbxpanel/logs` | Panel, Apache, worker, PHP and cron logs |
| `/www/wwwroot/<domain>` | Website document roots |
| `/www/wwwlogs` | Website access and error logs |
| `/www/backup/{site,database,path}` | Backups |
| `/www/server/ssl/<domain>` | Custom website certificates |
| `/www/docker/<project>` | Docker compose projects |
| `/www/quarantine` | Quarantined files |
| `/etc/apache2/sites-available/gbx-<domain>.conf` | Website virtual hosts |
| `/etc/apache2/sites-available/000-gbxpanel.conf` | Panel virtual host |

## Configuration reference

Environment variables in `panel/.env`:

| Variable | Default | Description |
| --- | --- | --- |
| `GBX_PORT` | random | Panel port |
| `GBX_ENTRY` | random | Security entrance path; empty disables it |
| `GBX_SSL` | `true` | HTTPS on the panel port |
| `GBX_SSL_CERT` / `GBX_SSL_KEY` | self-signed | Panel certificate paths |
| `GBX_DOMAIN` | empty | Restrict the panel to this host name |
| `GBX_ROOT` | `/usr/local/gbxpanel` | Installation root |
| `GBX_SIMULATE` | `false` | Do not execute system commands (development) |
| `GBX_USE_SUDO` | `true` | Prefix privileged commands with `sudo -n` |
| `GBX_DEFAULT_PHP` | `8.4` | Default PHP version for new websites |
| `GBX_TERMINAL_PORT` | `17878` | Loopback port of the terminal daemon |
| `GBX_TERMINAL_URL` | empty | WebSocket URL override for development; empty uses `/gbx-terminal/` on the panel origin |
| `GBX_CLAMD_SOCKET` | `/var/run/clamav/clamd.ctl` | clamd unix socket |
| `GBX_QUARANTINE` | `/www/quarantine` | Quarantine directory |
| `VENICE_AI_API_KEY` | empty | Fallback AI key (Settings → AI takes precedence) |
| `VENICE_AI_VISION_MODEL` | `qwen3-vl-235b-a22b` | Vision model |
| `DB_QUEUE_RETRY_AFTER` | `3700` | Must be greater than the longest task timeout (3600 s) |

## Security model

- **Security entrance:** without the entrance path the panel returns a neutral `404` for every request, including `/login`.
- **Domain binding:** when `GBX_DOMAIN` is set, requests for any other host name, including the IP address, return `404`.
- **Authentication:** bcrypt password hashes, encrypted sessions, secure cookies over HTTPS; 5 failed attempts per username and IP lock the pair for 5 minutes.
- **IP allow list:** optional; the current IP must be in the list when it is saved. `gbx unlock-ip` clears it.
- **Roles:** administrators have full access; operators manage resources but cannot open Terminal, Accounts or Settings; read-only users can only view.
- **Privileges:** the `gbxpanel` user has password-less sudo because the panel administers the whole server. Treat panel access as root access: keep the entrance secret, use strong passwords, and prefer an IP allow list, a bound domain with a trusted certificate, or VPN-only access.
- **Command safety:** user input passed to the shell is escaped with `escapeshellarg`; identifiers such as domains, database names, services, containers, cron expressions and paths are validated; system directories are protected from deletion; Apache and SSH configuration changes are validated and rolled back on failure, and PHP-FPM changes are validated before the service is reloaded.
- **Web terminal:** the daemon listens on loopback only and accepts a session only with a signed, single-use, IP-bound token that expires after 60 seconds; tokens are issued to administrators and every session is recorded in the activity log.
- **Malware protection:** uploads are scanned before they are written to the destination; detections can be quarantined automatically.
- **Audit:** logins and every change made through the UI, background tasks or the AI operator are recorded in the activity log.

## Development

On Windows and macOS the panel runs in **simulation mode** automatically: system commands are not executed and realistic sample data is returned, so the whole interface can be developed and tested locally.

```bash
cd panel
composer install
cp .env.example .env
php artisan key:generate
# in .env: GBX_SIMULATE=true and GBX_ENTRY=devpanel
touch database/database.sqlite
php artisan migrate
php artisan gbx:setup --username=admin --password=Admin12345 --entry=devpanel
php artisan serve
```

Open `http://127.0.0.1:8000/devpanel`.

### Tests

```bash
cd panel
php artisan test

# terminal daemon protocol (Python 3.8+, any OS; uses the echo backend)
python3 tests/terminal/test_gbx_terminal.py
```

To try the live terminal locally, run the daemon with the echo backend and point the page at it:

```bash
python3 scripts/gbx-terminal --backend echo --env panel/.env
# in panel/.env: GBX_TERMINAL_URL=ws://127.0.0.1:17878/
```

The PHP suite covers authentication and the security entrance, role permissions, domain binding, website provisioning and reverse proxies, the AI tool loop (Venice responses are faked with `Http::fake`), the schema of every AI tool, read-only command validation, clamd response parsing, upload blocking, scans and quarantine, and terminal token issuing and access. The Python suite covers the WebSocket handshake, framing, resize, single-use tokens, expiry, client address binding and token compatibility with Laravel.

> Desktop antivirus software such as Windows Defender blocks the complete EICAR string. The test suite therefore uses a simulation-only marker instead of the real EICAR file.

## Uninstall

```bash
sudo /usr/local/gbxpanel/bin/uninstall.sh
```

This removes the panel, its virtual host, PHP-FPM pool, Supervisor program, scheduler, sudoers entry and CLI. Websites in `/www`, databases and installed software are kept. A backup of `.env`, the SQLite database and `default.txt` is written to `/root`.
