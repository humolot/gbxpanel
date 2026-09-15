<?php

namespace App\Services\Clients;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientUsage;
use App\Models\CronJob;
use App\Models\DnsZone;
use App\Models\FtpAccount;
use App\Models\MysqlDatabase;
use App\Models\Setting;
use App\Models\Website;
use App\Services\ApacheManager;
use App\Services\FtpManager;
use App\Services\MysqlManager;
use App\Services\Shell;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Hosting customers: suspension, resource ownership and usage accounting.
 * Option B: resources keep the shared web user; isolation per client comes later (clients.isolated).
 */
class ClientManager
{
    /** Resources a client can own: key => [model, label]. */
    public const RESOURCES = [
        'websites' => [Website::class, 'Websites'],
        'databases' => [MysqlDatabase::class, 'Databases'],
        'ftp' => [FtpAccount::class, 'FTP accounts'],
        'cron' => [CronJob::class, 'Cron jobs'],
        'dns' => [DnsZone::class, 'DNS zones'],
    ];

    public function __construct(protected ApacheManager $apache, protected FtpManager $ftp) {}

    public static function portalEnabled(): bool
    {
        return (bool) Setting::get('client_portal', true)
            && Cache::remember('gbx.clients.exist', 60, fn () => rescue(fn () => Client::query()->exists(), false, false));
    }

    public static function forgetPortalCache(): void
    {
        Cache::forget('gbx.clients.exist');
    }

    /** Portal and enforcement options (Clients > Settings). */
    public static function settings(): array
    {
        return [
            'client_portal' => (bool) Setting::get('client_portal', true),
            'client_portal_title' => (string) Setting::get('client_portal_title', 'Client Panel'),
            'client_on_expire' => (string) Setting::get('client_on_expire', 'suspend'),       // suspend | none
            'client_over_disk' => (string) Setting::get('client_over_disk', 'block'),         // none | block | suspend
            'client_over_bandwidth' => (string) Setting::get('client_over_bandwidth', 'block'),
        ];
    }

    /* ============================================================ ownership */

    /** Give or remove resources; returns the number changed. */
    public function assign(Client $client, string $resource, array $ids, bool $attach): int
    {
        [$model] = self::RESOURCES[$resource] ?? throw new \InvalidArgumentException('Unknown resource');
        $query = $model::query()->whereKey($ids);
        $attach ? $query->where(fn ($q) => $q->whereNull('client_id')->orWhere('client_id', $client->id)) : $query->where('client_id', $client->id);
        $changed = $query->update(['client_id' => $attach ? $client->id : null]);

        // databases and FTP accounts of assigned websites follow them
        if ($resource === 'websites') {
            foreach (['databases' => MysqlDatabase::class, 'ftp' => FtpAccount::class] as $class) {
                $class::query()->whereIn('website_id', $ids)->where(fn ($q) => $attach ? $q->whereNull('client_id') : $q->where('client_id', $client->id))
                    ->update(['client_id' => $attach ? $client->id : null]);
            }
        }

        return $changed;
    }

    /** Remove the account; resources stay on the server and return to the administrator. */
    public function delete(Client $client): void
    {
        if (! $client->isActive()) {
            $this->unsuspend($client);
        }
        DB::transaction(function () use ($client) {
            foreach (self::RESOURCES as [$model]) {
                $model::query()->where('client_id', $client->id)->update(['client_id' => null]);
            }
            $client->delete();
        });
        self::forgetPortalCache();
    }

    /* =========================================================== suspension */

    public function suspend(Client $client, string $reason): void
    {
        if (! $client->isActive()) {
            $client->update(['suspended_reason' => $reason]);

            return;
        }
        $state = ['websites' => [], 'ftp' => []];
        foreach ($client->websites()->where('status', 'active')->get() as $site) {
            $site->status = 'stopped';
            if ($this->apache->write($site)->ok()) {
                $site->save();
                $state['websites'][] = $site->id;
            }
        }
        foreach ($client->ftpAccounts()->where('is_active', true)->get() as $account) {
            if ($this->ftp->setActive($account->username, false)->ok()) {
                $account->update(['is_active' => false]);
                $state['ftp'][] = $account->id;
            }
        }
        $client->update(['status' => 'suspended', 'suspended_reason' => $reason, 'suspended_state' => $state]);
        ActivityLog::record('client', "Suspended client {$client->username}", $reason);
    }

    /** Reactivate the account and restart what the suspension stopped. */
    public function unsuspend(Client $client): void
    {
        $state = $client->suspended_state ?? [];
        foreach (Website::query()->whereKey($state['websites'] ?? [])->where('client_id', $client->id)->get() as $site) {
            $site->status = 'active';
            if ($this->apache->write($site)->ok()) {
                $site->save();
            }
        }
        foreach (FtpAccount::query()->whereKey($state['ftp'] ?? [])->where('client_id', $client->id)->get() as $account) {
            if ($this->ftp->setActive($account->username, true)->ok()) {
                $account->update(['is_active' => true]);
            }
        }
        $client->update(['status' => 'active', 'suspended_reason' => null, 'suspended_state' => null]);
        ActivityLog::record('client', "Reactivated client {$client->username}");
    }

    /* ================================================================ usage */

    public function monthBandwidth(Client $client): int
    {
        return (int) $client->usage()->where('day', '>=', now()->startOfMonth()->toDateString())->sum('bandwidth');
    }

    /** Disk used by the websites (document roots) and local MySQL databases of the client. */
    public function measureDisk(Client $client): int
    {
        $sites = $client->websites()->pluck('root_path')->filter(fn ($p) => str_starts_with((string) $p, '/'))->unique()->values();
        if (Shell::simulating()) {
            $bytes = $sites->sum(fn ($p) => 40 * 1048576 + crc32($p) % (180 * 1048576));
        } else {
            $bytes = 0;
            if ($sites->isNotEmpty()) {
                $out = Shell::out('du -sb '.$sites->map(fn ($p) => Shell::arg($p))->implode(' ').' 2>/dev/null', 300);
                foreach (explode("\n", $out) as $line) {
                    $bytes += (int) strtok($line, "\t");
                }
            }
        }

        $names = $client->databases()->where('engine', 'mysql')->whereNull('server_id')->pluck('name');
        if ($names->isNotEmpty()) {
            $sizes = app(MysqlManager::class)->databases();
            foreach ($names as $name) {
                $bytes += (int) ($sizes[$name]['size'] ?? 0);
            }
        }

        return $bytes;
    }

    /**
     * Add the requests and bytes of one hour of access logs to the daily usage.
     * $hour is a timestamp inside the hour to count.
     */
    public function countHour(int $hour): void
    {
        $stamp = date('d/M/Y:H', $hour);
        $day = date('Y-m-d', $hour);
        $clients = Client::query()->with('websites:id,client_id,domain')->whereHas('websites')->get();

        foreach ($clients as $client) {
            $requests = 0;
            $bytes = 0;
            foreach ($client->websites as $site) {
                if (Shell::simulating()) {
                    $seed = crc32($site->domain.$stamp);
                    $requests += $seed % 400;
                    $bytes += ($seed % 400) * 18000;

                    continue;
                }
                $out = Shell::out('f='.Shell::arg($site->logPath('access')).'; [ -f "$f" ] && grep -F '.Shell::arg('['.$stamp.':').' "$f" | awk \'{n++; if ($10 ~ /^[0-9]+$/) b+=$10} END {printf "%d %d", n, b}\'', 120);
                [$n, $b] = array_pad(array_map('intval', explode(' ', trim($out))), 2, 0);
                $requests += $n;
                $bytes += $b;
            }
            $row = ClientUsage::query()->firstOrCreate(['client_id' => $client->id, 'day' => $day]);
            $row->increment('requests', $requests);
            $row->increment('bandwidth', $bytes);
        }
    }

    /** Hourly job: count the hours not processed yet, refresh totals and apply the limits. */
    public function refresh(bool $measureDisk = false): array
    {
        $last = (int) Setting::get('client_usage_hour', 0);
        $current = intdiv(time(), 3600) * 3600;
        $from = $last ? $last + 3600 : $current - 3600;
        $from = max($from, $current - 48 * 3600);
        $hours = 0;
        for ($hour = $from; $hour < $current; $hour += 3600) {
            $this->countHour($hour);
            $hours++;
        }
        if ($hours) {
            Setting::put('client_usage_hour', $current - 3600);
        }

        $settings = self::settings();
        $actions = [];
        foreach (Client::query()->with('package')->get() as $client) {
            $update = ['bandwidth_used' => $this->monthBandwidth($client), 'usage_updated_at' => now()];
            if ($measureDisk || ! $client->usage_updated_at || $client->disk_used === 0) {
                $update['disk_used'] = $this->measureDisk($client);
                ClientUsage::query()->updateOrCreate(['client_id' => $client->id, 'day' => now()->toDateString()], ['disk' => $update['disk_used']]);
            }
            $client->update($update);

            if (! $client->isActive()) {
                continue;
            }
            if ($client->isExpired() && $settings['client_on_expire'] === 'suspend') {
                $this->suspend($client, 'Expired on '.$client->expires_at->toDateString());
                $actions[] = "{$client->username}: suspended (expired)";
            } elseif ($settings['client_over_disk'] === 'suspend' && $client->diskLimitBytes() > 0 && $client->disk_used > $client->diskLimitBytes()) {
                $this->suspend($client, 'Disk quota exceeded');
                $actions[] = "{$client->username}: suspended (disk)";
            } elseif ($settings['client_over_bandwidth'] === 'suspend' && $client->bandwidthLimitBytes() > 0 && $client->bandwidth_used > $client->bandwidthLimitBytes()) {
                $this->suspend($client, 'Monthly bandwidth exceeded');
                $actions[] = "{$client->username}: suspended (bandwidth)";
            }
        }

        return ['hours' => $hours, 'actions' => $actions];
    }

    /** Why the client cannot create more resources right now, or null. */
    public function blockedReason(Client $client): ?string
    {
        $settings = self::settings();
        if ($settings['client_over_disk'] !== 'none' && $client->diskLimitBytes() > 0 && $client->disk_used > $client->diskLimitBytes()) {
            return 'Your disk quota is full. Free space or upgrade your package.';
        }
        if ($settings['client_over_bandwidth'] !== 'none' && $client->bandwidthLimitBytes() > 0 && $client->bandwidth_used > $client->bandwidthLimitBytes()) {
            return 'Your monthly bandwidth is used up. Upgrade your package or wait for next month.';
        }

        return null;
    }
}
