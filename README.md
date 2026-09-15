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
9. [Code editor](#code-editor)
10. [Databases](#databases)
11. [Cron jobs](#cron-jobs)
12. [Docker](#docker)
13. [DNS](#dns)
14. [Architecture](#architecture)
15. [Configuration reference](#configuration-reference)
16. [Security model](#security-model)
17. [Development](#development)
18. [Uninstall](#uninstall)

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
| `gbx 2fa-off [user]` | Disable two-factor authentication of an account whose phone and recovery codes were lost |
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
| `gbx tools-access public\|private` | Serve phpMyAdmin and Adminer to everyone, or only to browsers that open them from the panel |
| `gbx doctor` | Check services, sockets, the terminal daemon, permissions, sudo and the login page, and suggest fixes |

The panel virtual host is generated by `gbx` from `.env` (`GBX_PORT`, `GBX_SSL`, `GBX_DOMAIN`, `GBX_SSL_CERT`, `GBX_SSL_KEY`) and validated with `apache2ctl configtest` before Apache is reloaded. The previous configuration is restored if the test fails.

## Features

| Section | Capabilities |
| --- | --- |
| **Home** | Live CPU, load, memory, disk, network and disk I/O; installed software; system update and reboot. **Monitor:** 1 h – 7 d history. **Processes:** sortable list, TERM/HUP/KILL. **Services:** start, stop, restart, enable, journal. **Software:** install and uninstall from the catalog, PHP settings and extensions. **Cron:** typed jobs (shell, website, database and directory backups, log cutting, URL, Laravel scheduler) with multiple cycles, task scheduling with conditions and a script library; see [Cron jobs](#cron-jobs). |
| **DNS** | Records of domains at Cloudflare, Namecheap, GoDaddy, DigitalOcean, Hetzner, Linode, Vultr and Porkbun through their APIs; automatic records for new websites; DNS verification and wildcard SSL; see [DNS](#dns). |
| **Websites** | Apache virtual hosts listed with status, backups, quick actions, expiration date, SSL days left and a 24 hour request sparkline, plus bulk start/stop/backup. The **Conf** modal manages domains, site and running directory, open_basedir, access log, password-protected paths, denied file types, URL rewrite (.htaccess templates with automatic rollback on HTTP 500), default documents, vhost config, SSL (Let's Encrypt or custom), PHP version, Git deployments (public, token or SSH deploy key, optional deploy script), Composer, redirects, multiple reverse proxies (WebSocket aware), hotlink protection and maintenance mode. The **Log** modal shows a usage report (requests, visitors, bandwidth, status codes, top pages, IPs, 404s, bots, referrers) and the access and error logs. |
| **FTP** | Pure-FTPd virtual users mapped to the web user, password and directory management, enable/disable. |
| **Databases** | Tabs for MySQL/MariaDB, PostgreSQL, MongoDB, Redis, SQL Server and Qdrant (vector database), each on this server or on remote/cloud servers. Databases with users, passwords, sizes, notes, backups and imports, recycle bin, automatic daily backups, root passwords, MySQL access hosts and table tools, phpMyAdmin/Adminer with panel-only access, MongoDB access control, a Redis key browser and settings, and Qdrant collections, points, snapshots and API key (see [Databases](#databases)). |
| **Docker** | Overview, containers, One-Click Install (38 apps), Docker Hub search, local images (pull, import, build, push, export), compose projects with templates and file editor, networks, volumes, private repositories and daemon settings; see [Docker](#docker). |
| **Security** | **Firewall & SSH:** UFW rules, quick ports, IP blocking, SSH port, root login and authentication methods, Fail2ban jails, failed SSH logins. **Antivirus:** ClamAV scans, upload protection, quarantine, scheduled scans. |
| **Files** | File manager with drag-and-drop uploads (scanned by the antivirus), copy/move, permissions and ownership, zip/tar archives, malware scan and downloads. |
| **Code editor** | Monaco-based editor in a large modal: lazy file tree with context menu, tabs, search in files (contents or names, regex, include patterns), go to file, command palette, minimap, multi-cursor, encodings (UTF-8, BOM, Windows-1252, GBK, ...), line endings, and conflict detection when a file changed on the server. |
| **Logs** | System, service, website and panel logs with search and live follow; panel activity log. |
| **Terminal** | Real-time root shell (xterm.js over WebSocket) with multiple sessions, full-screen programs (htop, nano, vim, mysql), resize, copy/paste and a touch key bar. Falls back to a command console when the terminal service is stopped. |
| **Accounts** | Users with roles (administrator, operator, read-only), two-factor status and reset, policy that requires two-factor authentication for every account, login history, active sessions. Each user enables an authenticator app in Account security. |
| **AI** | AI operator with function calling (see [AI operator](#ai-operator)). |
| **Settings** | Panel name, port, entrance, SSL and domain; IP allow list and session lifetime; AI provider; hostname and timezone; maintenance actions. |

### Software catalog

The catalog is defined in `panel/config/software.php`. Each entry declares detection, version, service, configuration file, install and uninstall scripts. Installations run as background tasks with live output.

| Category | Packages |
| --- | --- |
| Runtime | PHP 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5 · Node.js 18, 20, 22, 24 (with npm) · PM2 · Composer |
| Web server | Apache |
| Database | MySQL · MariaDB · PostgreSQL · MongoDB 8 · Qdrant · SQL Server 2022 Express (Docker) · SQL Server command-line tools · phpMyAdmin · Adminer |
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

### Tools (98)

| Group | Read (automatic) | Actions (approval) |
| --- | --- | --- |
| System | `get_server_overview`, `get_memory_usage`, `get_disk_usage`, `find_large_files`, `get_network_status`, `list_processes`, `list_services`, `get_service_status`, `read_log`, `run_readonly_command`, `check_updates`, `list_software`, `get_task_status` | `run_command`, `service_action`, `kill_process`, `manage_software`, `upgrade_system`, `reboot_server`, `configure_system` |
| Files | `list_directory`, `read_file`, `search_in_files` | `write_file`, `edit_file`, `create_directory`, `delete_path`, `move_or_copy_path`, `rename_path`, `set_permissions`, `compress_files`, `extract_archive` |
| Backups | `list_backups` | `backup_website`, `backup_database`, `backup_path`, `restore_database`, `restore_archive`, `delete_backup` |
| Websites | `list_websites`, `get_website` | `create_website`, `update_website`, `delete_website`, `manage_website_ssl`, `save_website_vhost` |
| Databases and FTP | `list_databases`, `query_database` (SELECT/SHOW only), `list_ftp_accounts` | `create_database`, `delete_database`, `change_database_password`, `manage_ftp_account` |
| Cron | `list_cron_jobs`, `get_cron_log` | `manage_cron_job` |
| DNS | `list_dns_zones`, `get_dns_records` | `manage_dns_record`, `point_domain_to_server` |
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

## Code editor

Files open in a modal code editor from the file manager (**Edit**, double click or **Editor**), from the website list and from any page that links a configuration file. The editor page (`/files/editor?embed=1`) is loaded once in an iframe and reused, so open tabs survive closing and reopening the modal.

| Aspect | Details |
| --- | --- |
| Engine | Monaco Editor 0.52 (the editor of VS Code), vendored in `public/assets/vendor/monaco`, loaded with its AMD loader; no build step |
| Languages | Syntax highlighting for PHP, JavaScript/TypeScript (with syntax diagnostics), HTML, CSS/SCSS/Less, JSON, YAML, SQL, shell, Python, Markdown, Dockerfile, INI/.env and more, plus built-in Apache configuration and log file modes |
| Files | `GET /files/open` returns UTF-8 content with size, mode, owner, mtime, detected encoding and line endings; binary files and files over 3 MB are refused. `POST /files/write` converts back to the chosen encoding and keeps owner and mode |
| Conflicts | Saves send the mtime seen when the file was opened. If the file changed on the server, the save is rejected with `409` and the editor offers **Overwrite** or **Reload from server** |
| Search | `GET /files/search` runs `grep -rInZ` (contents) or `find -iname` (names) below the current directory with a 60 s timeout and a 500 result limit; `vendor`, `node_modules`, `.git` and cache folders can be skipped |
| Settings | Font, tab size, word wrap, whitespace, minimap, sticky scroll, bracket colors, auto save, trim trailing whitespace and final newline on save; stored per browser. Tabs are restored on the next visit |
| Access | Operators and administrators can edit; read-only users can browse and read files. Every save is written to the activity log |

Shortcuts: `Ctrl+S` save, `Ctrl+Alt+S` save all, `Ctrl+P` go to file, `Ctrl+Shift+F` search in files, `Ctrl+B` toggle explorer, `Alt+W` close tab, `F1` command palette.

## Databases

Each engine is a tab. Engines that are not installed show an install button (software catalog) and **Add Remote DB**. Remote servers (including managed cloud services) are stored with encrypted credentials and appear as locations next to Localhost.

| Engine | Local management | Features |
| --- | --- | --- |
| MySQL / MariaDB | `mysql` client as root (socket authentication or `/root/.my.cnf`) | Databases and users, access hosts (localhost, `%`, IP list), charset, table tools (optimize, repair, analyze, convert InnoDB/MyISAM), root password, phpMyAdmin |
| PostgreSQL | `psql` as the `postgres` system user | Databases owned by their role, lowercase identifiers, VACUUM/ANALYZE, table sizes, root (`postgres`) password, Adminer |
| MongoDB | `mongosh` scripts | Users with readWrite and dbAdmin roles, collection stats, root user, security authentication (access control in `/etc/mongod.conf` with automatic rollback) |
| SQL Server | `sqlcmd` (mssql-tools18) or the `gbx-mssql` container | SQL Server 2022 Express in Docker on 127.0.0.1:1433 registered as a server; logins in `db_owner`; `.bak` backups and restores for the local container |
| Redis | RESP over TCP from PHP | Keyspace per logical database, key browser (SCAN with pattern), view and edit string/hash/list/set/zset values, TTL, delete, FLUSHDB, max memory, eviction policy, AOF, password (`CONFIG REWRITE`), RDB backup and restore |
| Qdrant | REST API with the API key | Collections (vector size, distance, on-disk storage, embedding presets), status and points, point browser with payloads, snapshots (create, download, delete, restore by upload), API key rotation, local-only or public listening (firewall ports 6333/6334) |

Common features for MySQL, PostgreSQL, MongoDB and SQL Server:

- **Database list** with user, masked password (show and copy), size, backup count, location and note; filter by location, search and bulk backup or delete.
- **Get DB from server** imports databases that exist on a server but are not tracked; **Sync all** applies the stored passwords (and MySQL hosts) to every user.
- **Backups** per database (`/www/backup/database/<engine>`): backup now, restore, download, delete and import uploads (`.sql`, `.sql.gz`, `.zip`, `.dump`, `.archive.gz`, `.bak` depending on the engine).
- **Automatic backup**: daily at a chosen time for every database, keeping the last N copies (`gbx:backup-databases`).
- **Recycle bin**: deleting a database dumps it first, drops it only after the dump succeeds and keeps the dump for 7 days; restoring recreates the database and its user with the stored password.

Credentials are never passed on command lines, where every local user could read them in `/proc`: client option files, `PGPASSFILE`, mongosh scripts, tool configuration files and `SQLCMDPASSWORD` values are written to root-only temporary files under `/root/.gbx-secrets` and removed when the command ends.

phpMyAdmin and Adminer are served by the panel virtual host at `/phpmyadmin` and `/adminer`. With public access disabled (`gbx tools-access private`), Apache only serves them to browsers that hold the `gbx_tools` cookie, which the panel sets when a signed-in user opens them.

## Cron jobs

Home > Cron Jobs has three tabs.

**Cron Job** lists scheduled tasks with status (click to enable or disable), execution cycles, type, number of copies kept, last run with exit code and duration, and Execute, Edit, Log and Delete actions. Tasks can be filtered by type and searched, run, enabled, disabled, exported or deleted in bulk, and exported to or imported from JSON (library scripts used by the tasks travel with the file).

| Task type | What it runs |
| --- | --- |
| Shell Script | A bash script; **Select script** inserts a script from the library |
| Backup Website | `tar.gz` of one or all websites to `/www/backup/site`, optionally with their local databases and exclude patterns |
| Backup Database | Dumps of local MySQL, PostgreSQL and MongoDB databases (one engine, one database or all) |
| Backup Directory | `tar.gz` of any directory to `/www/backup/path` |
| Log Cutting | Rotates website access and error logs to `/www/wwwlogs/history/<domain>` (gzip) |
| Access URL | GET or POST with a timeout; fails when the status is not 2xx/3xx |
| Release Memory | `sync` and page cache drop |
| Script Library | A library script with arguments |
| Laravel Scheduler | `php artisan schedule:run` in a project, with an optional PHP version |

Backup and log tasks keep the newest N files and always run as root; the other types can run as another system user. Every task can have up to ten execution cycles (every N minutes, every N hours, daily, every N days, weekly, monthly or a cron expression). Scripts containing `shutdown`, `halt`, `poweroff`, `init 0`, `mkfs`, `passwd`, `chpasswd` or `--stdin` are refused.

**Task Scheduling** runs a library script on a cycle and, when its output contains or does not contain a text, or when it fails, runs another script and/or posts a JSON webhook (`task`, `host`, `exit_code`, `output`). Example: run *Check a process* for `nginx` every 5 minutes and, when the output does not contain `running`, run *Keep a service running*.

**Script library** groups reusable bash, python3 and php scripts by category (Service Management, Process Monitor, Alarm notification, Load Monitoring, Website Monitoring, Other, Custom). It ships with built-in scripts for Apache, MySQL, PHP-FPM, Redis and Supervisor, process checks, disk, memory, CPU load and failed-unit alerts, top processes, website status and SSL expiry checks and cleanup. Scripts can be created, edited, executed with arguments (the output opens as a task) and deleted when no task uses them.

How tasks run:

- Each task is rendered to `/usr/local/gbxpanel/cron/jobs/<id>.sh` (root only) and scheduled in `/etc/cron.d/gbxpanel` through `/usr/local/gbxpanel/cron/run`.
- The runner takes a lock so a run is skipped while the previous one is still active, writes start, exit code and duration to `/usr/local/gbxpanel/logs/cron/<id>.log` (trimmed at 5 MB) and records the result shown in the list.
- Tasks for other users run from a private copy of the script. Backup file names are generated when the task runs, and scheduled MongoDB dumps create their connection file at run time.
## Docker

Docker has ten tabs:

| Tab | Features |
| --- | --- |
| Overview | Containers, compose projects, images, networks, volumes and repositories with the disk space used; cards for every container with live CPU and memory, filter and search |
| Container | Table with ID, status (click to start or stop), image, IP, IPv6, published ports, creation time and notes. **Manage** opens details (networks, ports, compose project, command), logs (lines, time range, clear), environment, mounts, live stats and settings (restart policy, CPU and memory limits, rename, save as image). **Terminal** opens `docker exec` in the web terminal (administrators). Bulk start, stop, restart and delete; **Log Manage** shows the log size of each container and truncates logs; **Clear Container** removes stopped containers |
| One-Click Install | 38 apps in categories (BuildWebsite, Database, Storage, AI, Tools, NAS, Middleware, DevOps, Media, Email, Monitoring, Security), for example WordPress, Ghost, MySQL, PostgreSQL, MongoDB, Redis, Qdrant, MinIO, Ollama, Open WebUI, n8n, Flowise, Evolution API, GOWA, Apache Tika, Nextcloud, Gitea, Portainer, Uptime Kuma, Grafana and Vaultwarden. The install form asks for ports and passwords (generated when empty), checks that ports are free, writes `compose.yaml` and `.env` to `/www/docker/<project>` and starts the project. Ports listen on 127.0.0.1 unless external access is enabled, which also opens them in the firewall |
| Cloud image | Docker Hub search with stars, pulls and official badge; pull with a tag chosen from the repository tags |
| Local image | Pull from Docker Hub or a private repository, import (`docker load` from an upload or a file on the server), build from a Dockerfile with an optional context directory, push to a repository, export (`docker save` to `/www/backup/docker`), delete with force, batch delete and prune |
| Docker Compose | Projects from `/www/docker` and projects started elsewhere (`docker compose ls`), with status, notes and batch delete. Each project shows its containers (terminal and logs), the compose logs and an editor for the compose file and `.env`: a change is validated with `docker compose config` and the previous file is restored when it is invalid. Start, stop, restart, update images (pull and recreate) and delete (optionally with volumes). **Template List** stores compose templates for new projects |
| Network | Create bridge, macvlan and ipvlan networks with IPv4/IPv6 subnets, gateways, IP range, internal flag and labels; delete, batch delete and prune |
| Volume | Mount point, containers using the volume, driver, labels; create with driver options (for example NFS), delete, batch delete and prune |
| Repository | Registries (Docker Hub accounts, GHCR, GitLab, Harbor, cloud or self-hosted) with namespace and a login test. Passwords are stored encrypted |
| Settings | Service status, versions, resources, start/stop/restart and start at boot; `daemon.json` form (registry mirrors, insecure registries, log driver with max size and files, live restore, iptables, IPv6) or raw JSON. Docker validates the file (`dockerd --validate`) and restarts; the previous file is restored when Docker does not start |

Registry credentials never appear on a command line: tasks create a temporary `DOCKER_CONFIG` directory, pass the password to `docker login --password-stdin` from a root-only file and delete both when the task ends. Read-only users see containers, projects and logs, but environment values and `.env` contents are masked.
## DNS

DNS manages the records of your domains at the DNS provider through its API; no DNS server runs on this machine.

| Provider | Credentials | Notes |
| --- | --- | --- |
| Cloudflare | API token (Zone.DNS edit + Zone read), or account e-mail and Global API Key | Proxy (orange cloud) for A, AAAA and CNAME |
| Namecheap | API user and API key | The server IPv4 must be in Whitelisted IPs; the API replaces the whole host list, so the panel reads and merges before writing |
| GoDaddy | API key and secret | API access only for eligible accounts; records are changed per type and name |
| DigitalOcean | Personal access token | |
| Hetzner DNS | DNS Console API token | |
| Linode (Akamai) | Personal access token (Domains read/write) | TTL rounded to the values Linode accepts |
| Vultr | API key | Allow the server IP in the key access control |
| Porkbun | API key and secret key | Enable API access on each domain |

- **DNS API** tab: accounts with status, alias, account name, number of domains, last check and the API-Limit option (spaces requests to stay under the provider rate limit, and retries once on HTTP 429). Credentials are verified before they are saved and stored encrypted; only administrators can add, edit or remove accounts.
- **Domains** tab: zones of every account with the websites that use them and the current public A/AAAA records (resolved over DNS over HTTPS). **Records** opens the record editor (A, AAAA, CNAME, MX, TXT, CAA and NS, with TTL, MX priority and Cloudflare proxy); **Point to server** creates or updates the A/AAAA records of the domain and `www`.
- **Websites:** when a new website belongs to a managed zone, **Create DNS records** adds the A/AAAA records of the domain and its aliases.
- **SSL:** Let's Encrypt can verify through DNS: certbot calls `/usr/local/gbxpanel/bin/gbx-dns-hook`, which runs `php artisan gbx:dns-challenge` as the panel user to create the `_acme-challenge` TXT record, waits until Cloudflare and Google public DNS return it and removes it afterwards. This works without port 80 and allows wildcard certificates (`*.example.com`); renewals use the same hook.
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
├── app/Services/Databases/    DatabaseEngine, PostgresEngine, MongoEngine, SqlServerEngine,
│                              RedisManager (RespClient), QdrantManager, Engines (registry)
├── app/Services/Ai/           VeniceClient, ServerTools (tool registry)
├── app/Services/Ai/Tools/     System, File, Backup, Website, Database, Cron, Firewall,
│                              Antivirus, Docker, ProcessManager (Supervisor, PM2, Git)
├── app/Jobs/RunTaskJob.php    Background task executor
├── app/Console/Commands/      gbx:setup, gbx:user, gbx:info, gbx:collect-metrics,
│                              gbx:malware-scan, gbx:expire-websites, gbx:backup-databases, gbx:clear-login-limit,
│                              gbx:reset-access
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
| Scheduler | `/etc/cron.d/gbxpanel-scheduler` runs `schedule:run` every minute (metrics collection with 8-day retention, scheduled malware scans, stopping expired websites at 00:05) |
| Cron jobs | Rendered from the database to `/usr/local/gbxpanel/cron/jobs/<id>.sh` and `/etc/cron.d/gbxpanel`; output in `/usr/local/gbxpanel/logs/cron/<id>.log` |
| Database | SQLite in WAL mode (`panel/database/database.sqlite`) |

### Filesystem layout

| Path | Purpose |
| --- | --- |
| `/usr/local/gbxpanel/panel` | Application |
| `/usr/local/gbxpanel/bin/gbx` | CLI (symlinked to `/usr/bin/gbx`) |
| `/usr/local/gbxpanel/bin/gbx-terminal` | Terminal daemon (`gbxpanel-terminal.service`) |
| `/usr/local/gbxpanel/bin/gbx-dns-hook` | certbot DNS-01 hook (created when a certificate is issued with DNS verification) |
| `/usr/local/gbxpanel/fpm/php-fpm.conf` | Panel PHP-FPM configuration (`gbxpanel-fpm.service`) |
| `/usr/local/gbxpanel/ssl` | Panel self-signed certificate |
| `/usr/local/gbxpanel/logs` | Panel, Apache, worker, PHP and cron logs |
| `/usr/local/gbxpanel/cron` | Cron runner, rendered task scripts (`jobs/`) and last results (`state/`) |
| `/www/wwwroot/<domain>` | Website document roots |
| `/www/wwwlogs` | Website access and error logs |
| `/www/backup/{site,database,path}` | Backups (`database/<engine>` per engine, `database/recycle` for the recycle bin) |
| `/www/server/mssql` | SQL Server container data |
| `/var/lib/qdrant`, `/etc/qdrant/config.yaml` | Qdrant storage, snapshots and configuration |
| `/www/server/ssl/<domain>` | Custom website certificates |
| `/www/docker/<project>` | Docker compose projects (`compose.yaml`, `.env`, `.gbx-app` for One-Click apps) |
| `/www/backup/docker` | Exported Docker images |
| `/www/quarantine` | Quarantined files |
| `/usr/local/gbxpanel/sites/<domain>` | Per-site private data: htpasswd files, Git deploy key and token, deploy script, maintenance page |
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
| `GBX_TOOLS_PUBLIC` | `true` | phpMyAdmin and Adminer reachable by everyone (`false` requires opening them from the panel) |
| `GBX_TOOLS_TOKEN` | generated | Cookie value Apache checks when tools are private (set by `gbx tools-access private`) |
| `GBX_DOCKER_PROJECTS` | `/www/docker` | Folder of compose projects created by the panel and One-Click Install |
| `GBX_SYSTEM_USER` | `gbxpanel` | User that runs the panel (used by the certbot DNS hook) |
| `GBX_PHP_CLI` | `/usr/bin/php8.4` | PHP binary used by hooks that call artisan |
| `GBX_CLAMD_SOCKET` | `/var/run/clamav/clamd.ctl` | clamd unix socket |
| `GBX_QUARANTINE` | `/www/quarantine` | Quarantine directory |
| `VENICE_AI_API_KEY` | empty | Fallback AI key (Settings → AI takes precedence) |
| `VENICE_AI_VISION_MODEL` | `qwen3-vl-235b-a22b` | Vision model |
| `DB_QUEUE_RETRY_AFTER` | `3700` | Must be greater than the longest task timeout (3600 s) |

## Security model

- **Security entrance:** without the entrance path the panel returns a neutral `404` for every request, including `/login`.
- **Two-factor authentication:** optional per account or required for all accounts. After the password, the panel asks for a TOTP code (RFC 6238: SHA-1, 30 seconds, 6 digits) from Google Authenticator, Microsoft Authenticator, Authy, 1Password, Bitwarden or any compatible app. Codes accept one period of clock drift and cannot be reused; five invalid codes cancel the sign-in for five minutes. The secret is encrypted in the database and confirmed with a code before it is enabled. Ten single-use recovery codes are stored as HMAC hashes. A browser can be trusted for 30 days with a signed cookie bound to the current secret, so resetting two-factor authentication revokes it. Enabling or disabling requires the current password (disabling also a code); administrators reset lost devices in Accounts or with `gbx 2fa-off`.
- **Domain binding:** when `GBX_DOMAIN` is set, requests for any other host name, including the IP address, return `404`.
- **Authentication:** bcrypt password hashes, encrypted sessions, secure cookies over HTTPS; 5 failed attempts per username and IP lock the pair for 5 minutes.
- **IP allow list:** optional; the current IP must be in the list when it is saved. `gbx unlock-ip` clears it.
- **Roles:** administrators have full access; operators manage resources but cannot open Terminal, Accounts or Settings; read-only users can only view.
- **Privileges:** the `gbxpanel` user has password-less sudo because the panel administers the whole server. Treat panel access as root access: keep the entrance secret, use strong passwords, and prefer an IP allow list, a bound domain with a trusted certificate, or VPN-only access.
- **Command safety:** user input passed to the shell is single-quoted for bash; identifiers such as domains, database names, services, containers, cron expressions and paths are validated; system directories are protected from deletion; Apache and SSH configuration changes are validated and rolled back on failure, and PHP-FPM changes are validated before the service is reloaded.
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

The PHP suite covers authentication and the security entrance, role permissions, domain binding, website provisioning and reverse proxies, the AI tool loop (Venice responses are faked with `Http::fake`), the schema of every AI tool, read-only command validation, clamd response parsing, upload blocking, scans and quarantine, terminal token issuing and access, the code editor endpoints (encodings, BOM, binary detection, save conflicts, search), website settings, and the database engines (credential-free scripts, remote servers, recycle bin, backups, permissions, phpMyAdmin/Adminer cookie gate, Redis protocol and keys, Qdrant collections), the cron module (cycles, typed tasks, run-time backup names, task scheduling, script library, import and export), the DNS providers (request formats, record normalization, Namecheap host list merge, GoDaddy record sets, credential checks, point to server, wildcard SSL hook and challenge), two-factor authentication (RFC 6238 vectors, replay and brute-force protection, recovery codes, trusted browsers, enrollment, required policy, CLI reset), and the Docker module (inspect parsing, escaping of container, network and volume commands, registry credentials, One-Click apps, compose projects and templates, daemon.json merge, Docker Hub). The Python suite covers the WebSocket handshake, framing, resize, single-use tokens, expiry, client address binding and token compatibility with Laravel.

> Desktop antivirus software such as Windows Defender blocks the complete EICAR string. The test suite therefore uses a simulation-only marker instead of the real EICAR file.

## Uninstall

```bash
sudo /usr/local/gbxpanel/bin/uninstall.sh
```

This removes the panel, its virtual host, PHP-FPM pool, Supervisor program, scheduler, sudoers entry and CLI. Websites in `/www`, databases and installed software are kept. A backup of `.env`, the SQLite database and `default.txt` is written to `/root`.
