<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Schedule;

Schedule::command('gbx:collect-metrics')->everyMinute()->withoutOverlapping();

// Antivirus scheduled scans (Security > Antivirus)
Schedule::command('gbx:malware-scan --scheduled')->dailyAt('03:30')
    ->when(fn () => Setting::get('av_schedule', 'off') === 'daily');
Schedule::command('gbx:malware-scan --scheduled')->weeklyOn(0, '04:00')
    ->when(fn () => Setting::get('av_schedule', 'off') === 'weekly');

// Clients: bandwidth from the access logs, disk usage, expiration and quotas
Schedule::command('gbx:clients-usage')->hourlyAt(7)->withoutOverlapping();
Schedule::command('gbx:clients-usage --disk')->dailyAt('01:20')->withoutOverlapping();

// Websites with an expiration date are stopped the day after it (Websites > Expiration)
Schedule::command('gbx:expire-websites')->dailyAt('00:05');

// Databases: automatic backups at the configured time and recycle bin cleanup
Schedule::command('gbx:backup-databases --scheduled')->dailyAt(Setting::get('db_backup_time', '02:30'))->withoutOverlapping()
    ->when(fn () => (bool) Setting::get('db_backup_enabled', false));
Schedule::command('gbx:backup-databases --purge-recycle')->dailyAt('04:10');

// Webhooks of the API: deliveries waiting for another attempt, and old history
Schedule::command('gbx:webhooks --retry')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('gbx:webhooks --purge')->dailyAt('04:40');

// Backup storages: interrupted transfers, transfer history and the space used at every destination
Schedule::command('gbx:backup-housekeeping')->hourlyAt(12);
Schedule::command('gbx:backup-housekeeping --usage')->dailyAt('05:40');
