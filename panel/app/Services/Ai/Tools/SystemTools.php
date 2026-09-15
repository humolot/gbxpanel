<?php

namespace App\Services\Ai\Tools;

use App\Models\Task;
use App\Services\FileManager;
use App\Services\PanelManager;
use App\Services\ServiceManager;
use App\Services\Shell;
use App\Services\SoftwareManager;
use App\Services\SystemStats;
use App\Services\TaskRunner;
use Illuminate\Support\Str;

class SystemTools extends ToolGroup
{
    /** Binaries allowed in run_readonly_command (every pipe segment is checked). */
    public const READONLY_BINARIES = [
        'df', 'du', 'free', 'uptime', 'uname', 'hostname', 'hostnamectl', 'timedatectl', 'whoami', 'id', 'date', 'lsb_release', 'nproc', 'lscpu', 'lsblk', 'blkid', 'findmnt', 'mount',
        'ps', 'pgrep', 'top', 'vmstat', 'iostat', 'mpstat', 'lsof', 'ss', 'netstat', 'ip', 'ping', 'dig', 'nslookup', 'host', 'traceroute', 'curl', 'wget',
        'systemctl', 'journalctl', 'service', 'crontab', 'last', 'lastlog', 'who', 'w',
        'ls', 'stat', 'file', 'cat', 'head', 'tail', 'grep', 'egrep', 'zgrep', 'wc', 'sort', 'uniq', 'cut', 'awk', 'tr', 'find', 'tree', 'md5sum', 'sha256sum', 'zcat', 'column', 'jq',
        'apache2ctl', 'apachectl', 'apache2', 'php', 'php7.4', 'php8.0', 'php8.1', 'php8.2', 'php8.3', 'php8.4', 'php8.5', 'php-fpm8.4', 'composer', 'node', 'npm', 'pm2', 'git',
        'mysql', 'mysqladmin', 'mysqld', 'mariadb', 'redis-cli', 'docker', 'ufw', 'fail2ban-client', 'certbot', 'openssl', 'sshd', 'apt', 'apt-cache', 'dpkg', 'dpkg-query', 'supervisorctl', 'getent', 'which', 'whereis', 'dmesg',
    ];

    /** Binaries that can also change the system: their arguments must match the pattern. */
    protected const BINARY_RULES = [
        'systemctl' => '/^(status|is-active|is-enabled|is-failed|list-units|list-unit-files|list-timers|show|cat|--failed|--version)\b/',
        'service' => '/^\S+\s+status$/',
        'docker' => '/^(ps|images|logs|inspect|info|version|stats\s+--no-stream|network\s+(ls|inspect)|volume\s+(ls|inspect)|compose\s+(ps|ls|logs|config|images))\b/',
        'ufw' => '/^(status|app\s+list|show\s+\w+)\b/',
        'certbot' => '/^certificates\b/',
        'crontab' => '/^(-u\s+\S+\s+)?-l$/',
        'apt' => '/^(list|show|policy|search)\b/',
        'apt-cache' => '/^(policy|show|search|depends|rdepends)\b/',
        'dpkg' => '/^(-l|-s|-L|--list|--status|--listfiles|--get-selections)\b/',
        'pm2' => '/^(list|ls|status|jlist|describe|show|info|-v|--version)\b/',
        'npm' => '/^(-v|--version|ls|list|view|config\s+list|outdated)\b/',
        'composer' => '/^(--version|-V|show|diagnose|outdated|licenses)\b/',
        'node' => '/^(-v|--version)$/',
        'git' => '/^(-C\s+\S+\s+)?(status|log|remote\s+-v|branch|show|diff|rev-parse|describe)\b/',
        'supervisorctl' => '/^(status|avail)\b/',
        'fail2ban-client' => '/^(status|ping|version|get)\b/',
        'redis-cli' => '/^(-\w+\s+\S+\s+)*(info|ping|dbsize|client\s+list|slowlog\s+get|memory\s+stats|config\s+get)\b/i',
        'mysql' => '/^(-\S+\s+)*-e\s+["\']\s*(select|show|explain|describe|desc)\b[^;]*["\']$/i',
        'mariadb' => '/^(-\S+\s+)*-e\s+["\']\s*(select|show|explain|describe|desc)\b[^;]*["\']$/i',
        'mysqladmin' => '/^(-\S+\s+)*(status|version|processlist|extended-status|variables|ping)$/',
        'mysqld' => '/^(--version|-V|--verbose\s+--help)$/',
        'php' => '/^(-v|-m|-i|--ini|--version)$/',
        'php-fpm8.4' => '/^(-t|-v|-i|-m)$/',
        'apache2ctl' => '/^(-S|-M|-t|-v|-V|configtest|status|-t\s+-D\s+DUMP_\w+)$/',
        'apachectl' => '/^(-S|-M|-t|-v|-V|configtest|status)$/',
        'apache2' => '/^(-v|-V|-M|-l|-L)$/',
        'sshd' => '/^(-T|-t)$/',
        'openssl' => '/^(x509|s_client|version|verify|req\s+-noout|rsa\s+-noout|ciphers)\b/',
        'ip' => '/^(-\w+\s+)*(a|addr|address|r|route|link|neigh)(\s+(show|list|ls))?(\s+\S+)?$/',
        'awk' => '/^(?!.*\b(system|getline)\b)/',
        'find' => '/^(?!.*(-delete|-exec|-execdir|-ok|-okdir|-fprint|-fls|-fprintf))/',
        'curl' => '/^(?!.*(\s|^)(-o|-O|-T|-X|-d|-F|--output|--remote-name|--upload-file|--data\S*|--request|--form)(\s|=|$))/',
        'wget' => '/^(-q\s+)?(--spider|-S\s+--spider|-qO-|-O\s+-)\s+\S+$/',
        'hostname' => '/^(-I|-i|-f|-d|-s)?$/',
        'hostnamectl' => '/^(status)?$/',
        'timedatectl' => '/^(status|show|list-timezones)?$/',
        'date' => '/^(?!.*(-s|--set))/',
        'mount' => '/^(-l)?$/',
        'dmesg' => '/^(?!.*(-C|-c|--clear|-D|-E|-n|--console))/',
        'journalctl' => '/^(?!.*--(vacuum|rotate|flush|sync|relinquish|setup-keys))/',
        'top' => '/-b/',
        'lastlog' => '/^(?!.*(-C|--clear|-S|--set))/',
    ];

    public function __construct(
        protected SystemStats $stats,
        protected ServiceManager $services,
        protected SoftwareManager $software,
        protected PanelManager $panel,
    ) {}

    public function tools(): array
    {
        return [
            // ------------------------------------------------------------------ read
            'get_server_overview' => self::tool('Hostname, OS, kernel, CPU usage, load average, memory, swap, disks and uptime.', self::params(), false, false, fn () => 'Checked server overview'),
            'get_memory_usage' => self::tool('Detailed RAM and swap usage plus the processes using the most memory.', self::params(['limit' => self::int('Top processes, default 15')]), false, false, fn () => 'Checked memory usage'),
            'get_disk_usage' => self::tool('Disk space and inode usage per mount. When path is given, also lists the biggest sub-directories of that path.', self::params(['path' => self::str('Directory to analyse, e.g. /var or /www/wwwroot')]), false, false, fn ($a) => 'Checked disk usage'.(empty($a['path']) ? '' : ' of '.$a['path'])),
            'find_large_files' => self::tool('Find the largest files under a directory (same filesystem).', self::params(['path' => self::str('Directory, default /'), 'min_size_mb' => self::int('Minimum size in MB, default 100'), 'limit' => self::int('Max results, default 30')]), false, false, fn ($a) => 'Searched large files in '.($a['path'] ?? '/')),
            'get_network_status' => self::tool('Listening ports with their process, network interfaces/IPs, established connection count and public IP.', self::params(), false, false, fn () => 'Checked network and open ports'),
            'list_processes' => self::tool('Running processes sorted by CPU or memory, optionally filtered by name/command.', self::params(['sort' => self::str('cpu or memory', ['cpu', 'memory']), 'filter' => self::str('Text to match in the command'), 'limit' => self::int('Max rows, default 25')]), false, false, fn () => 'Listed processes'),
            'list_services' => self::tool('Known systemd services (web server, PHP-FPM, databases, docker, cron...) with state.', self::params(), false, false, fn () => 'Listed services'),
            'get_service_status' => self::tool('State of one systemd service and its last journal lines.', self::params(['service' => self::str('Service name, e.g. apache2, mysql, php8.4-fpm, docker'), 'lines' => self::int('Journal lines, default 60')], ['service']), false, false, fn ($a) => 'Checked service '.self::labelArg($a, 'service')),
            'read_log' => self::tool('Last lines of a log under /var/log, /www/wwwlogs or the panel logs, optionally filtered.', self::params(['path' => self::str('Absolute log path'), 'lines' => self::int('Lines, default 100, max 2000'), 'search' => self::str('Case-insensitive filter')], ['path']), false, false, fn ($a) => 'Read log '.self::labelArg($a, 'path')),
            'run_readonly_command' => self::tool('Run a read-only diagnostic command (e.g. "ss -tulpn", "apache2ctl -S", "systemctl status mysql", "tail -n 50 /var/log/syslog | grep -i error"). Only inspection programs; no ; && > $( allowed.', self::params(['command' => self::str('Command line')], ['command']), false, false, fn ($a) => 'Ran: '.self::labelArg($a, 'command')),
            'check_updates' => self::tool('List upgradable packages (security updates flagged) and whether a reboot is required. refresh=true runs apt-get update first.', self::params(['refresh' => self::bool('Refresh package lists first (slower)')]), false, false, fn () => 'Checked for system updates'),
            'list_software' => self::tool('Software catalog (PHP versions, MySQL, MariaDB, Node.js, PM2, Docker, Redis, Composer, Fail2ban...) with installed/running state and install keys.', self::params(), false, false, fn () => 'Checked installed software'),
            'get_task_status' => self::tool('Status and output of a background task (installs, upgrades, backups, deploys). Use wait_seconds to wait until it finishes.', self::params(['task_id' => self::int('Task id'), 'wait_seconds' => self::int('Wait up to N seconds (max 120) for the task to finish'), 'tail_lines' => self::int('Output lines to return, default 60')], ['task_id']), false, false, fn ($a) => 'Checked task #'.self::labelArg($a, 'task_id')),

            // ------------------------------------------------------------------ write
            'run_command' => self::tool('Run any shell command as root (bash). Use when no specific tool fits. Requires approval.', self::params(['command' => self::str('Shell command'), 'reason' => self::str('Why it is needed'), 'cwd' => self::str('Working directory'), 'timeout' => self::int('Seconds, default 300, max 1800')], ['command', 'reason']), true, true, fn ($a) => 'Run command: '.self::labelArg($a, 'command')),
            'service_action' => self::tool('Start, stop, restart, reload, enable or disable a systemd service.', self::params(['service' => self::str('Service name'), 'action' => self::str('Action', ['start', 'stop', 'restart', 'reload', 'enable', 'disable'])], ['service', 'action']), true, false, fn ($a) => ucfirst(self::labelArg($a, 'action')).' service '.self::labelArg($a, 'service')),
            'kill_process' => self::tool('Send a signal to a process by PID.', self::params(['pid' => self::int('Process ID'), 'signal' => self::str('Signal', ['TERM', 'HUP', 'KILL'])], ['pid']), true, false, fn ($a) => 'Send '.($a['signal'] ?? 'TERM').' to PID '.self::labelArg($a, 'pid')),
            'manage_software' => self::tool('Install or uninstall a package from the software catalog (see list_software). Runs as a background task.', self::params(['action' => self::str('Action', ['install', 'uninstall']), 'key' => self::str('Catalog key: php, apache, mysql, mariadb, phpmyadmin, nodejs, pm2, composer, redis, memcached, pureftpd, docker, certbot, fail2ban, supervisor'), 'version' => self::str('Version for versioned packages, e.g. 8.3 (php) or 22 (nodejs)')], ['action', 'key']), true, false, fn ($a) => ucfirst(self::labelArg($a, 'action')).' '.self::labelArg($a, 'key').' '.self::labelArg($a, 'version')),
            'upgrade_system' => self::tool('Upgrade system packages with apt (all, or only the given packages). Runs as a background task.', self::params(['packages' => self::list('Only these packages (empty = full upgrade)'), 'autoremove' => self::bool('Remove unused packages afterwards')]), true, false, fn ($a) => empty($a['packages']) ? 'Upgrade all system packages' : 'Upgrade '.implode(', ', self::strings($a['packages']))),
            'reboot_server' => self::tool('Reboot the server after a delay. All services and the panel go offline until it is back.', self::params(['delay_seconds' => self::int('Delay before reboot, default 10, min 5'), 'reason' => self::str('Why')]), true, true, fn () => 'Reboot the server'),
            'configure_system' => self::tool('Change the server hostname and/or timezone.', self::params(['hostname' => self::str('New hostname'), 'timezone' => self::str('Timezone, e.g. America/Sao_Paulo')]), true, true, fn () => 'Change hostname / timezone'),
        ];
    }

    public function handle(string $name, array $a): mixed
    {
        switch ($name) {
            case 'get_server_overview':
                $s = $this->stats->snapshot();

                return [
                    'info' => $this->stats->info(),
                    'cpu_percent' => $s['cpu']['percent'],
                    'load' => $s['load'],
                    'memory' => ['used' => SystemStats::bytes($s['memory']['used']), 'total' => SystemStats::bytes($s['memory']['total']), 'percent' => $s['memory']['percent'], 'swap_used' => SystemStats::bytes($s['memory']['swap_used']), 'swap_total' => SystemStats::bytes($s['memory']['swap_total'])],
                    'disks' => array_map(fn ($d) => ['mount' => $d['mount'], 'used' => SystemStats::bytes($d['used']), 'total' => SystemStats::bytes($d['total']), 'percent' => $d['percent'], 'inodes_percent' => $d['inodes_percent']], $s['disks']),
                ];

            case 'get_memory_usage':
                $m = $this->stats->memory();
                $procs = $this->stats->processes(400);
                usort($procs, fn ($x, $y) => $y['rss'] <=> $x['rss']);
                $byName = [];
                foreach ($procs as $p) {
                    $byName[$p['name']] = ($byName[$p['name']] ?? 0) + $p['rss'];
                }
                arsort($byName);

                return [
                    'total' => SystemStats::bytes($m['total']), 'used' => SystemStats::bytes($m['used']), 'percent' => $m['percent'],
                    'cached' => SystemStats::bytes($m['cached']), 'buffers' => SystemStats::bytes($m['buffers']), 'free' => SystemStats::bytes($m['free']),
                    'swap_total' => SystemStats::bytes($m['swap_total']), 'swap_used' => SystemStats::bytes($m['swap_used']),
                    'top_processes' => array_map(fn ($p) => ['pid' => $p['pid'], 'user' => $p['user'], 'rss' => SystemStats::bytes($p['rss']), 'mem_percent' => $p['mem'], 'command' => Str::limit($p['command'], 120)], array_slice($procs, 0, min(50, max(1, (int) self::a($a, 'limit', 15))))),
                    'by_program' => array_map(fn ($b) => SystemStats::bytes($b), array_slice($byName, 0, 10, true)),
                ];

            case 'get_disk_usage':
                $out = ['disks' => array_map(fn ($d) => $d + ['used_h' => SystemStats::bytes($d['used']), 'total_h' => SystemStats::bytes($d['total'])], $this->stats->disks())];
                if ($path = self::a($a, 'path')) {
                    $path = FileManager::normalize($path);
                    $out['largest_in_'.$path] = Shell::simulating()
                        ? "1.2G\t{$path}/wwwroot\n480M\t{$path}/backup"
                        : Shell::run('du -xh --max-depth=1 '.Shell::arg($path).' 2>/dev/null | sort -rh | head -n 25', 180)->output;
                }

                return $out;

            case 'find_large_files':
                $path = FileManager::normalize(self::a($a, 'path', '/'));
                $min = max(1, (int) self::a($a, 'min_size_mb', 100));
                $limit = min(200, max(1, (int) self::a($a, 'limit', 30)));
                if (Shell::simulating()) {
                    return [['size' => '2.1 GB', 'path' => '/var/lib/docker/overlay2/abc/merged/data.bin']];
                }
                $lines = Shell::run('find '.Shell::arg($path).' -xdev -type f -size +'.$min."M -printf '%s\t%TY-%Tm-%Td\t%p\n' 2>/dev/null | sort -rn | head -n ".$limit, 300)->lines();

                return array_map(function ($l) {
                    [$size, $date, $file] = array_pad(explode("\t", $l, 3), 3, '');

                    return ['size' => SystemStats::bytes((int) $size), 'modified' => $date, 'path' => $file];
                }, $lines);

            case 'get_network_status':
                if (Shell::simulating()) {
                    return ['public_ip' => $this->stats->publicIp(), 'listening' => "tcp LISTEN 0.0.0.0:22 sshd\ntcp LISTEN *:80 apache2\ntcp LISTEN 127.0.0.1:3306 mysqld", 'interfaces' => 'eth0 UP 142.93.121.40/20', 'established_connections' => 42];
                }

                return [
                    'public_ip' => $this->stats->publicIp(),
                    'listening' => Shell::run('ss -tulpnH 2>/dev/null | awk \'{print $1, $2, $5, $7}\'', 20)->output,
                    'interfaces' => Shell::out('ip -brief address 2>/dev/null', 10, false),
                    'established_connections' => (int) Shell::out('ss -tanH state established 2>/dev/null | wc -l', 10, false),
                    'connections_by_remote_ip' => Shell::run("ss -tanH state established 2>/dev/null | awk '{print \$4}' | sed -E 's/:[0-9]+$//' | sort | uniq -c | sort -rn | head -n 10", 10)->output,
                ];

            case 'list_processes':
                $rows = $this->stats->processes(500);
                if (self::a($a, 'sort') === 'memory') {
                    usort($rows, fn ($x, $y) => $y['mem'] <=> $x['mem']);
                }
                if ($filter = self::a($a, 'filter')) {
                    $rows = array_values(array_filter($rows, fn ($p) => stripos($p['command'], $filter) !== false));
                }

                return ['total' => count($rows), 'processes' => array_map(fn ($p) => ['pid' => $p['pid'], 'user' => $p['user'], 'cpu' => $p['cpu'], 'mem' => $p['mem'], 'rss' => SystemStats::bytes($p['rss']), 'running' => SystemStats::humanDuration($p['elapsed']), 'command' => Str::limit($p['command'], 160)], array_slice($rows, 0, min(100, max(1, (int) self::a($a, 'limit', 25)))))];

            case 'list_services':
                return array_map(fn ($s) => ['name' => $s['name'], 'active' => $s['active'], 'enabled' => $s['enabled'], 'state' => $s['state'], 'memory' => SystemStats::bytes($s['memory'])], $this->services->list());

            case 'get_service_status':
                $svc = (string) self::a($a, 'service');
                if (! ServiceManager::validName($svc)) {
                    return ['error' => 'Invalid service name'];
                }

                return ['status' => $this->services->status($svc), 'journal' => Str::limit($this->services->journal($svc, min(300, (int) self::a($a, 'lines', 60))), 12000)];

            case 'read_log':
                $path = FileManager::normalize(self::a($a, 'path', ''));
                $allowed = ['/var/log/', rtrim(config('gbx.paths.logs'), '/').'/', rtrim(config('gbx.root'), '/').'/logs/', storage_path('logs').'/'];
                if (! collect($allowed)->contains(fn ($p) => str_starts_with($path, $p))) {
                    return ['error' => 'Only logs under '.implode(', ', $allowed).' can be read. Use read_file for other files.'];
                }
                $lines = min(2000, max(1, (int) self::a($a, 'lines', 100)));
                $cmd = ($search = self::a($a, 'search'))
                    ? 'grep -i -F -- '.Shell::arg($search).' '.Shell::arg($path).' | tail -n '.$lines
                    : 'tail -n '.$lines.' '.Shell::arg($path);

                return Shell::simulating() ? "[simulation] {$path}\nSep 16 10:00:00 server app[1]: sample log line" : Shell::run($cmd.' 2>&1', 30)->output;

            case 'run_readonly_command':
                $command = (string) self::a($a, 'command', '');
                if ($reason = self::rejectReadonly($command)) {
                    return ['error' => $reason.' Use run_command (requires approval) if a change is needed.'];
                }
                $r = Shell::run($command, 90);

                return ['exit_code' => $r->exitCode, 'output' => Str::limit($r->output.$r->error, 16000)];

            case 'check_updates':
                if (Shell::simulating()) {
                    return ['upgradable' => 12, 'security' => 3, 'reboot_required' => false, 'packages' => [['package' => 'openssl', 'from' => '3.0.13-0ubuntu3.4', 'to' => '3.0.13-0ubuntu3.5', 'security' => true]]];
                }
                if (self::a($a, 'refresh')) {
                    Shell::run('apt-get update -qq', 300);
                }
                $packages = [];
                foreach (Shell::run('apt list --upgradable 2>/dev/null', 120)->lines() as $line) {
                    if (preg_match('#^([^/]+)/(\S+)\s+(\S+)\s+\S+\s+\[upgradable from:\s*([^\]]+)\]#', $line, $m)) {
                        $packages[] = ['package' => $m[1], 'from' => $m[4], 'to' => $m[3], 'security' => str_contains($m[2], 'security')];
                    }
                }

                return [
                    'upgradable' => count($packages),
                    'security' => count(array_filter($packages, fn ($p) => $p['security'])),
                    'reboot_required' => $this->panel->rebootRequired(),
                    'reboot_required_by' => Shell::out('cat /var/run/reboot-required.pkgs 2>/dev/null', 5, false) ?: null,
                    'packages' => array_slice($packages, 0, 150),
                ];

            case 'list_software':
                return array_map(fn ($s) => ['key' => $s['key'], 'version' => $s['version'], 'name' => $s['name'], 'installed' => $s['installed'], 'installed_version' => $s['installed_version'], 'running' => $s['running']], $this->software->all(true));

            case 'get_task_status':
                $task = Task::query()->find((int) self::a($a, 'task_id'));
                if (! $task) {
                    return ['error' => 'Task not found'];
                }
                $deadline = time() + min(120, max(0, (int) self::a($a, 'wait_seconds', 0)));
                while (! $task->isFinished() && time() < $deadline) {
                    sleep(2);
                    $task->refresh();
                }
                $lines = min(400, max(5, (int) self::a($a, 'tail_lines', 60)));
                $output = implode("\n", array_slice(explode("\n", rtrim($task->output())), -$lines));

                return ['task_id' => $task->id, 'title' => $task->title, 'status' => $task->status, 'exit_code' => $task->exit_code, 'finished' => $task->isFinished(), 'started_at' => $task->started_at?->toDateTimeString(), 'finished_at' => $task->finished_at?->toDateTimeString(), 'output_tail' => $output];

            // ------------------------------------------------------------ write
            case 'run_command':
                $cwd = self::a($a, 'cwd') ? FileManager::normalize(self::a($a, 'cwd')) : '/root';

                return $this->shell(Shell::run('cd '.Shell::arg($cwd).' && '.(string) $a['command'], min(1800, max(10, (int) self::a($a, 'timeout', 300)))), 'Command finished');

            case 'service_action':
                return $this->shell($this->services->action((string) $a['service'], (string) $a['action']), ucfirst((string) $a['action']).' '.$a['service'].': done');

            case 'kill_process':
                $signal = in_array(self::a($a, 'signal', 'TERM'), ['TERM', 'HUP', 'KILL'], true) ? self::a($a, 'signal', 'TERM') : 'TERM';
                if ((int) $a['pid'] < 2) {
                    return ['error' => 'Invalid PID'];
                }

                return $this->shell(Shell::run('kill -'.$signal.' '.(int) $a['pid'], 10), 'Signal sent');

            case 'manage_software':
                $version = self::a($a, 'version') ?: null;
                $task = self::a($a, 'action') === 'uninstall'
                    ? $this->software->uninstall((string) $a['key'], $version)
                    : $this->software->install((string) $a['key'], $version);

                return $this->queued($task, ucfirst((string) $a['action']).' '.$a['key']);

            case 'upgrade_system':
                $packages = self::strings($a['packages'] ?? []);
                foreach ($packages as $pkg) {
                    if (! preg_match('/^[a-z0-9][a-z0-9+.:-]*$/', $pkg)) {
                        return ['error' => "Invalid package name: {$pkg}"];
                    }
                }
                $opts = '-o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold';
                $script = "export DEBIAN_FRONTEND=noninteractive\napt-get update -y\n"
                    .($packages ? "apt-get install --only-upgrade -y {$opts} ".implode(' ', array_map([Shell::class, 'arg'], $packages)) : "apt-get upgrade -y {$opts}")."\n"
                    .(self::a($a, 'autoremove') ? "apt-get autoremove -y\n" : '')
                    ."[ -f /var/run/reboot-required ] && echo 'NOTICE: a reboot is required to finish the update.' || echo 'No reboot required.'";

                return $this->queued(TaskRunner::dispatch($packages ? 'Upgrade '.implode(', ', $packages) : 'Upgrade system packages', $script, 'system'), 'The upgrade');

            case 'reboot_server':
                $delay = max(5, (int) self::a($a, 'delay_seconds', 10));

                return $this->shell($this->panel->delayed('/sbin/reboot', $delay), "The server will reboot in {$delay} seconds.");

            case 'configure_system':
                $done = [];
                if ($hostname = self::a($a, 'hostname')) {
                    $r = $this->panel->setHostname($hostname);
                    if ($r->failed()) {
                        return ['ok' => false, 'error' => $r->message()];
                    }
                    $done[] = "hostname set to {$hostname}";
                }
                if ($tz = self::a($a, 'timezone')) {
                    $r = $this->panel->setTimezone($tz);
                    if ($r->failed()) {
                        return ['ok' => false, 'error' => $r->message()];
                    }
                    $done[] = "timezone set to {$tz}";
                }

                return ['ok' => (bool) $done, 'message' => $done ? implode(', ', $done) : 'Nothing to change'];
        }

        return ['error' => "Unknown tool {$name}"];
    }

    /** Returns a reason when the command is not strictly read-only. */
    public static function rejectReadonly(string $command): ?string
    {
        $command = trim($command);
        if ($command === '' || strlen($command) > 1000) {
            return 'Empty or too long command.';
        }
        if (preg_match('/[;&><`\n\r]|\$\(|\|\|/', $command)) {
            return 'Only simple commands and pipes are allowed (no ; & > < ` $( or ||).';
        }

        foreach (explode('|', $command) as $segment) {
            $segment = trim($segment);
            $first = (string) strtok($segment, " \t");
            $bin = basename($first);
            if ($first !== $bin && ! preg_match('#^/(usr/)?(s)?bin/#', $first)) {
                return "Use the plain binary name instead of {$first}.";
            }
            if (! in_array($bin, self::READONLY_BINARIES, true)) {
                return "\"{$bin}\" is not in the read-only allow list.";
            }
            $rule = self::BINARY_RULES[preg_match('/^php\d\.\d$/', $bin) ? 'php' : $bin] ?? null;
            $args = trim(substr($segment, strlen($first)));
            if ($rule && ! preg_match($rule, $args)) {
                return "These \"{$bin}\" arguments are not allowed in read-only mode.";
            }
        }

        return null;
    }
}
