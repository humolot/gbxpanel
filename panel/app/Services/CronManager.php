<?php

namespace App\Services;

use App\Models\CronJob;

/**
 * Cron jobs are stored in SQLite and rendered to /etc/cron.d/gbxpanel.
 */
class CronManager
{
    public const FILE = '/etc/cron.d/gbxpanel';

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

    public function render(): string
    {
        $lines = [
            '# Managed by GBX Panel - changes are overwritten from the panel database',
            'SHELL=/bin/bash',
            'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            '',
        ];
        $logDir = rtrim(config('gbx.root'), '/').'/logs/cron';

        foreach (CronJob::query()->where('is_active', true)->orderBy('id')->get() as $job) {
            $command = str_replace(["\r", "\n"], ' ', $job->command);
            $log = $logDir.'/'.$job->id.'.log';
            $lines[] = '# '.$job->id.': '.str_replace(["\r", "\n"], ' ', $job->name);
            $lines[] = sprintf('%s %s { echo "=== $(date \'+\%%F \%%T\') ==="; %s ; } >> %s 2>&1', $job->schedule, $job->run_as, str_replace('%', '\%', $command), $log);
        }

        return implode("\n", $lines)."\n";
    }

    public function sync(): ShellResult
    {
        $logDir = rtrim(config('gbx.root'), '/').'/logs/cron';
        Shell::run('mkdir -p '.Shell::arg($logDir).' && chmod 755 '.Shell::arg($logDir));

        return Shell::writeFile(self::FILE, $this->render(), '0644', 'root:root');
    }

    public function runNow(CronJob $job): \App\Models\Task
    {
        return TaskRunner::dispatch('Run cron: '.$job->name, $job->run_as === 'root'
            ? $job->command
            : 'su -s /bin/bash '.Shell::arg($job->run_as).' -c '.Shell::arg($job->command), 'cron', ['cron_id' => $job->id]);
    }

    public function log(CronJob $job, int $lines = 300): string
    {
        if (Shell::simulating()) {
            return "=== 2026-09-14 03:00:00 ===\nBackup finished.\n";
        }

        return Shell::run('tail -n '.(int) $lines.' '.Shell::arg($job->logFile()).' 2>/dev/null', 15)->output;
    }

    public function clearLog(CronJob $job): ShellResult
    {
        return Shell::run('truncate -s 0 '.Shell::arg($job->logFile()).' 2>/dev/null || true');
    }
}
