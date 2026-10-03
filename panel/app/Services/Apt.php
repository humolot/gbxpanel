<?php

namespace App\Services;

/**
 * Package manager locks.
 *
 * Only one program at a time may install packages. Automatic security updates, a shell session
 * or a second panel task can hold that lock, and apt then fails right away with
 * "Could not get lock /var/lib/dpkg/lock-frontend". Every script of the panel that touches apt
 * starts with this preamble: apt waits for the other program instead of giving up, the log says
 * what is holding the lock, and two package tasks of the panel never run at the same time.
 */
class Apt
{
    /** Lock used between the tasks of the panel. */
    public const LOCK = '/var/lock/gbx-apt.lock';

    /** apt reads this and waits for the system lock instead of failing. */
    public const CONF = '/etc/apt/apt.conf.d/99gbx-lock-timeout';

    public const WAIT_SECONDS = 900;

    public static function preamble(int $seconds = self::WAIT_SECONDS): string
    {
        $seconds = max(30, min(3600, $seconds));
        $holder = '$(ps -o pid=,comm= -p $(fuser /var/lib/dpkg/lock-frontend 2>/dev/null) 2>/dev/null | tr \'\n\' \' \')';

        return "export DEBIAN_FRONTEND=noninteractive\n"
            ."# apt waits for the system lock (dpkg, unattended-upgrades, another shell) instead of failing\n"
            .'printf \'DPkg::Lock::Timeout "'.$seconds.'";\n\' > '.self::CONF." 2>/dev/null || true\n"
            ."# and the package tasks of the panel wait for each other\n"
            .'exec 9>'.self::LOCK."\n"
            ."if ! flock -w {$seconds} 9; then\n"
            ."    echo 'Another package task of the panel is still running. Open the task list, wait for it to finish and start this one again.'\n"
            ."    exit 100\n"
            ."fi\n"
            ."if command -v fuser >/dev/null 2>&1 && fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1; then\n"
            ."    echo \"Waiting for another program that is installing packages ({$holder})...\"\n"
            ."fi\n";
    }

    /** Put the preamble in front of a script that uses apt. */
    public static function guard(string $script, int $seconds = self::WAIT_SECONDS): string
    {
        return self::preamble($seconds).$script;
    }

    /** Make sure the configuration exists for apt calls made outside a task. */
    public static function ensureConfig(): void
    {
        if (! Shell::simulating() && ! Shell::fileExists(self::CONF)) {
            Shell::writeFile(self::CONF, 'DPkg::Lock::Timeout "'.self::WAIT_SECONDS."\";\n", '0644', 'root:root');
        }
    }
}
