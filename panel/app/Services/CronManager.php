<?php

namespace App\Services;

use App\Models\CronJob;
use App\Models\CronScript;
use App\Models\MysqlDatabase;
use App\Models\Task;
use App\Models\Website;
use App\Services\Databases\DatabaseEngine;
use App\Services\Databases\Engines;
use App\Services\Databases\SqlServerEngine;

/**
 * Cron module. Jobs are stored in SQLite; each one is rendered to a root-only script in
 * /usr/local/gbxpanel/cron/jobs/<id>.sh and scheduled in /etc/cron.d/gbxpanel through a small
 * runner that logs every execution, records exit code and duration and prevents overlaps.
 */
class CronManager
{
    public const FILE = '/etc/cron.d/gbxpanel';

    public const TYPES = [
        'shell' => 'Shell Script',
        'site_backup' => 'Backup Website',
        'db_backup' => 'Backup Database',
        'path_backup' => 'Backup Directory',
        'log_cut' => 'Log Cutting',
        'url' => 'Access URL',
        'free_memory' => 'Release Memory',
        'script' => 'Script Library',
        'laravel' => 'Laravel Scheduler',
        'flow' => 'Task Scheduling',
    ];

    public const PRESETS = [
        '* * * * *' => 'Every minute',
        '*/5 * * * *' => 'Every 5 minutes',
        '*/15 * * * *' => 'Every 15 minutes',
        '0 * * * *' => 'Every hour',
        '0 */6 * * *' => 'Every 6 hours',
        '0 3 * * *' => 'Every day at 03:00',
        '0 4 * * 0' => 'Every Sunday at 04:00',
        '0 5 1 * *' => 'First day of month at 05:00',
    ];

    public const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /** Commands refused in shell scripts, as in the notice on the Cron Job form. */
    public const FORBIDDEN = '/(^|[\s;&|(`])(shutdown|halt|poweroff|init\s+0|mkfs(\.\w+)?|mke2fs|passwd|chpasswd)(\s|$|;)|--stdin\b/m';

    public const STAMP = '__GBX_STAMP__';

    public static function dir(): string
    {
        return rtrim(config('gbx.root'), '/').'/cron';
    }

    public static function logDir(): string
    {
        return rtrim(config('gbx.root'), '/').'/logs/cron';
    }

    /* =================================================================== validation */

    public static function validSchedule(string $expr): bool
    {
        $expr = trim($expr);
        if (in_array($expr, ['@reboot', '@yearly', '@annually', '@monthly', '@weekly', '@daily', '@hourly'], true)) {
            return true;
        }
        $fields = preg_split('/\s+/', $expr);
        if (count($fields) !== 5) {
            return false;
        }
        foreach ($fields as $f) {
            if (! preg_match('/^(\*|\d+(-\d+)?)(\/\d+)?(,(\*|\d+(-\d+)?)(\/\d+)?)*$|^[a-zA-Z]{3}(-[a-zA-Z]{3})?$/', $f)) {
                return false;
            }
        }

        return true;
    }

    public static function validUser(string $user): bool
    {
        return (bool) preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $user);
    }

    public static function forbidden(string $script): ?string
    {
        return preg_match(self::FORBIDDEN, $script, $m) ? trim($m[2] ?? $m[0]) : null;
    }

    /* ======================================================================= cycles */

    /**
     * Normalize an execution cycle and return it with its cron expression.
     * Types: minute_n, hour_n, day, day_n, week, month, custom.
     */
    public static function cycle(array $c): array
    {
        $type = (string) ($c['type'] ?? 'day');
        $n = max(1, (int) ($c['n'] ?? 1));
        $hour = min(23, max(0, (int) ($c['hour'] ?? 0)));
        $minute = min(59, max(0, (int) ($c['minute'] ?? 0)));
        $weekday = min(6, max(0, (int) ($c['weekday'] ?? 1)));
        $day = min(31, max(1, (int) ($c['day'] ?? 1)));

        $expr = match ($type) {
            'minute_n' => '*/'.min(59, $n).' * * * *',
            'hour_n' => $minute.' '.($n === 1 ? '*' : '*/'.min(23, $n)).' * * *',
            'day' => "{$minute} {$hour} * * *",
            'day_n' => "{$minute} {$hour} */".min(31, $n).' * *',
            'week' => "{$minute} {$hour} * * {$weekday}",
            'month' => "{$minute} {$hour} {$day} * *",
            'custom' => preg_replace('/\s+/', ' ', trim((string) ($c['expr'] ?? ''))),
            default => throw new \InvalidArgumentException('Unknown execution cycle.'),
        };
        if (! self::validSchedule($expr)) {
            throw new \InvalidArgumentException('Invalid cron expression: '.$expr);
        }

        return array_filter(compact('type', 'n', 'hour', 'minute', 'weekday', 'day') + ['expr' => $type === 'custom' ? $expr : null], fn ($v) => $v !== null) + ['cron' => $expr];
    }

    public static function describeCycle(array $c): string
    {
        $time = sprintf('%02d:%02d', $c['hour'] ?? 0, $c['minute'] ?? 0);
        $n = (int) ($c['n'] ?? 1);

        return match ($c['type'] ?? 'custom') {
            'minute_n' => $n === 1 ? 'Every minute' : "Every {$n} minutes",
            'hour_n' => ($n === 1 ? 'Every hour' : "Every {$n} hours").' at minute '.(int) ($c['minute'] ?? 0),
            'day' => "Daily at {$time}",
            'day_n' => "Every {$n} days at {$time}",
            'week' => 'Every '.self::WEEKDAYS[(int) ($c['weekday'] ?? 0)]." at {$time}",
            'month' => 'Monthly on day '.(int) ($c['day'] ?? 1)." at {$time}",
            default => self::PRESETS[$c['cron'] ?? $c['expr'] ?? ''] ?? 'Cron: '.($c['cron'] ?? $c['expr'] ?? ''),
        };
    }

    /** Cycles of a job; jobs created before the module (or by the AI) only have schedule. */
    public static function cyclesOf(CronJob $job): array
    {
        return $job->cycles ?: [['type' => 'custom', 'expr' => $job->schedule, 'cron' => $job->schedule]];
    }

    public static function describe(CronJob $job): string
    {
        return implode('; ', array_map([self::class, 'describeCycle'], self::cyclesOf($job)));
    }

    /* ======================================================================= scripts */

    protected static function heredoc(string $content): string
    {
        $tag = 'GBX_'.strtoupper(bin2hex(random_bytes(4)));

        return "<<'{$tag}'\n".str_replace("\r\n", "\n", rtrim($content, "\n"))."\n{$tag}";
    }

    public static function splitArgs(string $args): array
    {
        preg_match_all('/"((?:\\\\.|[^"\\\\])*)"|\'([^\']*)\'|(\S+)/', $args, $m, PREG_SET_ORDER);

        return array_map(fn ($x) => isset($x[3]) && $x[3] !== '' ? $x[3] : (($x[2] ?? '') !== '' ? $x[2] : stripcslashes($x[1] ?? '')), $m);
    }

    /** Shell line that runs a library script with arguments (script read from a heredoc). */
    public static function libraryCall(CronScript $script, string $args = '', string $redirect = ''): string
    {
        $argv = implode(' ', array_map(fn ($a) => Shell::arg($a), self::splitArgs($args)));
        $interpreter = match ($script->language) {
            'python3' => 'python3 -',
            'php' => 'php --',
            default => 'bash -s --',
        };

        return trim("{$interpreter} {$argv} {$redirect}").' '.self::heredoc($script->content);
    }

    /** Delete all but the newest $keep files matching a glob. */
    protected static function retention(string $glob, int $keep): string
    {
        return 'ls -1t '.$glob.' 2>/dev/null | tail -n +'.($keep + 1).' | xargs -r rm -f --';
    }

    /**
     * Timestamps must be computed when the job runs, not when it is saved: file names built
     * by the backup helpers carry a placeholder that becomes $STAMP here.
     */
    protected static function stamped(string $script): string
    {
        $script = preg_replace("/'([^']*)".self::STAMP."([^']*)'/", "'\$1'\"\$STAMP\"'\$2'", $script);

        return str_replace(self::STAMP, '${STAMP}', $script);
    }

    /** Bash body executed by the runner for a job. */
    public function script(CronJob $job): string
    {
        $keep = max(1, (int) ($job->keep ?: 3));
        $p = $job->params ?? [];
        $backup = rtrim(config('gbx.paths.backup'), '/');

        $body = match ($job->type) {
            'shell' => (string) $job->command,

            'site_backup' => (function () use ($p, $keep, $backup) {
                $sites = ($p['website'] ?? 'all') === 'all' ? Website::query()->orderBy('domain')->get() : Website::query()->whereKey($p['website'])->get();
                $out = '';
                foreach ($sites as $site) {
                    [$script] = app(BackupManager::class)->websiteBackupScript($site, (bool) ($p['databases'] ?? true), array_filter(array_map('trim', explode(',', (string) ($p['exclude'] ?? '')))), self::STAMP);
                    $out .= "echo '==> {$site->domain}'\n(\n{$script}\n)\n[ \$? -eq 0 ] || { echo 'Backup of {$site->domain} failed'; FAILED=1; }\n"
                        .self::retention(Shell::arg($backup.'/site/'.$site->domain.'_').'*.tar.gz', $keep)."\n";
                }

                return $out ? "FAILED=0\n{$out}exit \$FAILED" : "echo 'No websites to back up'";
            })(),

            'db_backup' => (function () use ($p, $keep) {
                $query = MysqlDatabase::query()->whereNull('server_id')->whereIn('engine', ['mysql', 'pgsql', 'mongodb']);
                if (($p['engine'] ?? 'all') !== 'all') {
                    $query->where('engine', $p['engine']);
                }
                if (($p['database'] ?? 'all') !== 'all') {
                    $query->whereKey($p['database']);
                }
                $out = '';
                DatabaseEngine::$stampOverride = self::STAMP;
                try {
                    foreach ($query->orderBy('engine')->orderBy('name')->get() as $db) {
                        $engine = Engines::get($db->engine);
                        $out .= "echo '==> {$db->engine} {$db->name}'\n(\n".$engine->scheduledBackupScript($db->name)."\n)\n[ \$? -eq 0 ] || { echo 'Backup of {$db->name} failed'; FAILED=1; }\n"
                            .self::retention(Shell::arg($engine->backupDir().'/'.$db->name.'_').'*.'.$engine->dumpExtension(), $keep)."\n";
                    }
                } finally {
                    DatabaseEngine::$stampOverride = null;
                }

                return $out ? "FAILED=0\n{$out}exit \$FAILED" : "echo 'No local databases to back up'";
            })(),

            'path_backup' => (function () use ($p, $keep, $backup) {
                $path = FileManager::normalize((string) ($p['path'] ?? ''));
                $label = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', basename($path)) ?: 'backup';
                $excludes = '';
                foreach (array_filter(array_map('trim', explode(',', (string) ($p['exclude'] ?? '')))) as $pattern) {
                    if (preg_match('/^[\w.*\/-]+$/', $pattern)) {
                        $excludes .= ' --exclude='.Shell::arg($pattern);
                    }
                }
                $target = $backup.'/path/'.$label.'_'.self::STAMP.'.tar.gz';

                return 'mkdir -p '.Shell::arg($backup.'/path')."\n"
                    .'tar -czf '.Shell::arg($target).$excludes.' -C '.Shell::arg(dirname($path)).' '.Shell::arg(basename($path))."\n"
                    .'ls -lh '.Shell::arg($target)."\n"
                    .self::retention(Shell::arg($backup.'/path/'.$label.'_').'*.tar.gz', $keep);
            })(),

            'log_cut' => (function () use ($p, $keep) {
                $sites = ($p['website'] ?? 'all') === 'all' ? Website::query()->orderBy('domain')->get() : Website::query()->whereKey($p['website'])->get();
                $history = rtrim(config('gbx.paths.logs'), '/').'/history';
                $out = '';
                foreach ($sites as $site) {
                    $dir = Shell::arg($history.'/'.$site->domain);
                    foreach (['access', 'error'] as $type) {
                        $log = Shell::arg($site->logPath($type));
                        $rotated = Shell::arg($history.'/'.$site->domain.'/'.$type.'_'.self::STAMP.'.log');
                        $out .= "if [ -s {$log} ]; then mkdir -p {$dir} && cp {$log} {$rotated} && : > {$log} && gzip -f {$rotated} && echo 'Rotated {$site->domain} {$type} log'; fi\n"
                            .self::retention($dir.'/'.$type.'_*.log.gz', $keep)."\n";
                    }
                }

                return $out ?: "echo 'No websites'";
            })(),

            'url' => 'CODE=$(curl -s -o /dev/null -L -m '.max(5, min(600, (int) ($p['timeout'] ?? 60))).' -w "%{http_code}" -X '.(($p['method'] ?? 'GET') === 'POST' ? 'POST' : 'GET').' '.Shell::arg((string) ($p['url'] ?? ''))." -A 'GBX-Panel-Cron')\n"
                .'echo "HTTP $CODE"'."\n".'[ "$CODE" -ge 200 ] && [ "$CODE" -lt 400 ]',

            'free_memory' => "free -m\nsync && echo 3 > /proc/sys/vm/drop_caches\necho 'Page cache released'\nfree -m",

            'script' => (function () use ($p) {
                $script = CronScript::query()->find($p['script_id'] ?? 0);

                return $script ? self::libraryCall($script, (string) ($p['args'] ?? '')) : "echo 'The library script no longer exists'; exit 1";
            })(),

            'laravel' => 'cd '.Shell::arg((string) ($p['path'] ?? '/')).' && '.(! empty($p['php']) ? 'php'.preg_replace('/[^0-9.]/', '', $p['php']) : 'php').' artisan schedule:run --no-interaction',

            'flow' => $this->flowScript($job),

            default => "echo 'Unknown task type'; exit 1",
        };

        return "#!/bin/bash\n# GBX Panel cron job #{$job->id}: ".str_replace(["\r", "\n"], ' ', $job->name)."\ncd /tmp\nSTAMP=\$(date +%Y%m%d_%H%M%S)\n".self::stamped($body)."\n";
    }

    /** Task Scheduling: run a library script, then act when its output matches a condition. */
    protected function flowScript(CronJob $job): string
    {
        $p = $job->params ?? [];
        $script = CronScript::query()->find($p['script_id'] ?? 0);
        if (! $script) {
            return "echo 'The library script no longer exists'; exit 1";
        }
        $match = (string) ($p['match'] ?? '');
        $test = match ($p['condition'] ?? 'always') {
            'contains' => 'printf "%s" "$OUT" | grep -qF -- '.Shell::arg($match),
            'not_contains' => '! printf "%s" "$OUT" | grep -qF -- '.Shell::arg($match),
            'failed' => '[ "$RC" -ne 0 ]',
            default => 'true',
        };

        $then = '';
        $next = ! empty($p['then_script_id']) ? CronScript::query()->find($p['then_script_id']) : null;
        if ($next) {
            $then .= "    echo '==> Running {$this->quoteEcho($next->name)}'\n    ".self::libraryCall($next, (string) ($p['then_args'] ?? ''))."\n";
        }
        if (! empty($p['webhook'])) {
            $then .= '    printf "%s" "$OUT" | tail -c 3000 | python3 -c '.Shell::arg('import json, socket, sys; print(json.dumps({"task": sys.argv[1], "host": socket.gethostname(), "exit_code": int(sys.argv[2]), "output": sys.stdin.read()}))')
                .' '.Shell::arg($job->name).' "$RC" | curl -fsS -m 20 -H "Content-Type: application/json" --data-binary @- '.Shell::arg((string) $p['webhook'])." && echo '==> Webhook sent'\n";
        }
        if ($then === '') {
            $then = "    :\n";
        }

        return 'OUT=$('.self::libraryCall($script, (string) ($p['args'] ?? ''), '2>&1')."\n)\nRC=\$?\nprintf '%s\\n' \"\$OUT\"\n"
            ."if {$test}; then\n    echo '==> Condition met'\n{$then}else\n    echo '==> Condition not met'\nfi\nexit 0";
    }

    protected function quoteEcho(string $text): string
    {
        return str_replace("'", '', $text);
    }

    /* ===================================================================== rendering */

    public function runner(): string
    {
        $dir = self::dir();
        $logs = self::logDir();

        return <<<BASH
#!/bin/bash
# GBX Panel cron runner: run <job id> <user>. Generated by the panel.
ID="\$1"; RUNAS="\${2:-root}"
case "\$ID" in ''|*[!0-9]*) echo "invalid job id"; exit 2 ;; esac
DIR={$dir}
LOG={$logs}/\$ID.log
mkdir -p {$logs} "\$DIR/state"
[ -f "\$DIR/jobs/\$ID.sh" ] || { echo "job \$ID not found"; exit 2; }
exec 9>"\$DIR/state/\$ID.lock"
if ! flock -n 9; then echo "=== \$(date '+%F %T') skipped: the previous run is still active ===" >> "\$LOG"; exit 0; fi
START=\$(date +%s)
echo "=== \$(date '+%F %T') start ===" >> "\$LOG"
if [ "\$RUNAS" = "root" ]; then
    bash "\$DIR/jobs/\$ID.sh" < /dev/null >> "\$LOG" 2>&1
    RC=\$?
else
    # the job directory is root-only: give the user a private copy of the script
    COPY=\$(mktemp /tmp/gbx-cron-XXXXXX) && install -m 0500 -o "\$RUNAS" "\$DIR/jobs/\$ID.sh" "\$COPY"
    runuser -u "\$RUNAS" -- bash "\$COPY" < /dev/null >> "\$LOG" 2>&1
    RC=\$?
    rm -f "\$COPY"
fi
END=\$(date +%s)
echo "=== \$(date '+%F %T') exit \$RC after \$((END - START))s ===" >> "\$LOG"
echo "\$END \$RC \$((END - START))" > "\$DIR/state/\$ID"
if [ "\$(stat -c %s "\$LOG" 2>/dev/null || echo 0)" -gt 5242880 ]; then tail -c 2097152 "\$LOG" > "\$LOG.tmp" && mv "\$LOG.tmp" "\$LOG"; fi
exit \$RC

BASH;
    }

    public function render(): string
    {
        $lines = [
            '# Managed by GBX Panel - changes are overwritten from the panel database',
            'SHELL=/bin/bash',
            'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            '',
        ];
        $runner = self::dir().'/run';

        foreach (CronJob::query()->where('is_active', true)->orderBy('id')->get() as $job) {
            $lines[] = '# '.$job->id.': '.str_replace(["\r", "\n"], ' ', $job->name);
            foreach (self::cyclesOf($job) as $cycle) {
                $lines[] = ($cycle['cron'] ?? $cycle['expr']).' root '.$runner.' '.$job->id.' '.$job->run_as.' >/dev/null 2>&1';
            }
        }

        return implode("\n", $lines)."\n";
    }

    public function sync(): ShellResult
    {
        if (Shell::simulating()) {
            return new ShellResult(0, $this->render(), '');
        }
        $dir = self::dir();
        Shell::run('mkdir -p '.Shell::arg($dir.'/jobs').' '.Shell::arg($dir.'/state').' '.Shell::arg(self::logDir()).' && chmod 700 '.Shell::arg($dir).' '.Shell::arg($dir.'/jobs').' && chmod 755 '.Shell::arg(self::logDir()));
        $result = Shell::writeFile($dir.'/run', $this->runner(), '0700', 'root:root');
        if ($result->failed()) {
            return $result;
        }

        $ids = [];
        foreach (CronJob::query()->orderBy('id')->get() as $job) {
            $ids[] = $job->id.'.sh';
            $write = Shell::writeFile($dir.'/jobs/'.$job->id.'.sh', $this->script($job), '0600', 'root:root');
            if ($write->failed()) {
                return $write;
            }
        }
        // remove scripts of deleted jobs
        Shell::run('cd '.Shell::arg($dir.'/jobs').' && for f in *.sh; do case " '.implode(' ', $ids).' " in *" $f "*) ;; *) rm -f "$f" ;; esac; done', 30);

        return Shell::writeFile(self::FILE, $this->render(), '0644', 'root:root');
    }

    /* ==================================================================== execution */

    /** Last run of every job written by the runner: [id => [time, exit code, duration]]. */
    public function states(): array
    {
        if (Shell::simulating()) {
            return [];
        }
        $out = Shell::out('cd '.Shell::arg(self::dir().'/state').' 2>/dev/null && for f in [0-9]*; do [ -f "$f" ] && case "$f" in *.lock) ;; *) echo "$f $(cat "$f")" ;; esac; done', 15);
        $states = [];
        foreach (array_filter(explode("\n", $out)) as $line) {
            $p = preg_split('/\s+/', trim($line));
            if (count($p) >= 4) {
                $states[(int) $p[0]] = ['time' => (int) $p[1], 'status' => (int) $p[2], 'duration' => (int) $p[3]];
            }
        }

        return $states;
    }

    /** Copy runner results into the database so the lists can sort and show them. */
    public function refreshStates(): void
    {
        foreach ($this->states() as $id => $state) {
            CronJob::query()->whereKey($id)->where(fn ($q) => $q->whereNull('last_run_at')->orWhere('last_run_at', '<', date('Y-m-d H:i:s', $state['time'])))
                ->update(['last_run_at' => date('Y-m-d H:i:s', $state['time']), 'last_status' => $state['status'], 'last_duration' => $state['duration']]);
        }
    }

    public function runNow(CronJob $job): Task
    {
        $log = Shell::arg($job->logFile());
        $script = Shell::simulating()
            ? $this->script($job)
            : 'bash '.Shell::arg(self::dir().'/run').' '.$job->id.' '.Shell::arg($job->run_as)."\nRC=\$?\ntac {$log} | sed '/=== .* start ===/q' | tac\nexit \$RC";

        return TaskRunner::dispatch('Run cron: '.$job->name, $script, 'cron', ['cron_id' => $job->id, 'on_finish' => 'cron_run']);
    }

    public function runScript(CronScript $script, string $args = ''): Task
    {
        return TaskRunner::dispatch('Run script: '.$script->name, self::libraryCall($script, $args), 'cron', ['cron_script_id' => $script->id, 'on_finish' => 'cron_script_run']);
    }

    public function log(CronJob $job, int $lines = 300): string
    {
        if (Shell::simulating()) {
            return "=== 2026-09-14 03:00:00 start ===\nBackup finished.\n=== 2026-09-14 03:00:04 exit 0 after 4s ===\n";
        }

        return Shell::run('tail -n '.(int) $lines.' '.Shell::arg($job->logFile()).' 2>/dev/null', 15)->output;
    }

    public function clearLog(CronJob $job): ShellResult
    {
        return Shell::run('truncate -s 0 '.Shell::arg($job->logFile()).' 2>/dev/null || true');
    }

    /** System accounts that can run jobs: root, www-data and regular users. */
    public function users(): array
    {
        if (Shell::simulating()) {
            return ['root', 'www-data', 'gbxpanel', 'deploy'];
        }
        $out = Shell::out("getent passwd | awk -F: '\$3 == 0 || \$1 == \"www-data\" || (\$3 >= 1000 && \$3 < 65534) {print \$1}'", 10);

        return array_values(array_unique(array_merge(['root', 'www-data'], array_filter(explode("\n", $out)))));
    }
}
