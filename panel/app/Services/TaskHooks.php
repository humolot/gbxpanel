<?php

namespace App\Services;

use App\Models\MalwareScan;
use App\Models\Task;
use App\Models\Website;

/**
 * Follow-up work executed when a background task finishes
 * (by the queue worker, or immediately in simulation mode).
 */
class TaskHooks
{
    public static function finished(Task $task): void
    {
        $meta = $task->meta ?? [];

        try {
            if (($meta['on_success'] ?? null) === 'ssl_issued' && $task->status === 'success' && isset($meta['website_id'])) {
                $site = Website::query()->find($meta['website_id']);
                if ($site) {
                    $site->update(['ssl_enabled' => true, 'ssl_provider' => 'letsencrypt']);
                    app(ApacheManager::class)->write($site);
                    app(SslManager::class)->refreshExpiry($site);
                }
            }

            if (($meta['on_success'] ?? null) === 'git_deployed' && isset($meta['website_id'])) {
                $site = Website::query()->find($meta['website_id']);
                if ($site) {
                    preg_match('/^Commit: (.+)$/m', $task->output(), $m);
                    $site->putSetting('git.last_deploy', [
                        'at' => now()->toDateTimeString(),
                        'status' => $task->status,
                        'task_id' => $task->id,
                        'commit' => $m[1] ?? null,
                    ])->save();
                }
            }

            if (($meta['on_finish'] ?? null) === 'malware_scan' && isset($meta['malware_scan_id'])) {
                $scan = MalwareScan::query()->find($meta['malware_scan_id']);
                if ($scan) {
                    app(ClamAvManager::class)->finalize($scan, $task);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
