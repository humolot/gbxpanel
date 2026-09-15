<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Reusable script from the Script library (Home > Cron Jobs). */
class CronScript extends Model
{
    public const CATEGORIES = [
        'service' => 'Service Management',
        'process' => 'Process Monitor',
        'alarm' => 'Alarm notification',
        'load' => 'Load Monitoring',
        'website' => 'Website Monitoring',
        'other' => 'Other',
        'custom' => 'Custom',
    ];

    public const LANGUAGES = ['bash' => '/bin/bash', 'python3' => '/usr/bin/python3', 'php' => '/usr/bin/php'];

    protected $fillable = ['name', 'category', 'language', 'content', 'remark', 'success_match', 'args_hint', 'is_builtin', 'last_run_at', 'last_task_id'];

    protected function casts(): array
    {
        return ['is_builtin' => 'boolean', 'last_run_at' => 'datetime'];
    }
}
