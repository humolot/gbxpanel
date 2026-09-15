<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Schedule;

Schedule::command('gbx:collect-metrics')->everyMinute()->withoutOverlapping();

// Antivirus scheduled scans (Security > Antivirus)
Schedule::command('gbx:malware-scan --scheduled')->dailyAt('03:30')
    ->when(fn () => Setting::get('av_schedule', 'off') === 'daily');
Schedule::command('gbx:malware-scan --scheduled')->weeklyOn(0, '04:00')
    ->when(fn () => Setting::get('av_schedule', 'off') === 'weekly');
