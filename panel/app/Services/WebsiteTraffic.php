<?php

namespace App\Services;

use App\Models\Website;
use Illuminate\Support\Facades\Cache;

/**
 * Request statistics read from the Apache access logs (combined format).
 */
class WebsiteTraffic
{
    public const LOG_REGEX = '/^(\S+) \S+ \S+ \[([^\]]+)\] "(?:(\S+) (\S+)[^"]*|[^"]*)" (\d{3}) (\d+|-)(?: "([^"]*)" "([^"]*)")?/';

    /**
     * Requests per hour over the last 24 hours for each site, oldest hour first.
     *
     * @param  iterable<Website>  $sites
     * @return array<int, array{hours: list<int>, total: int}>
     */
    public function hourly(iterable $sites): array
    {
        $sites = collect($sites)->keyBy('id');
        $key = 'gbx.traffic.'.md5($sites->keys()->implode(','));

        return Cache::remember($key, 300, function () use ($sites) {
            $hours = [];
            for ($i = 23; $i >= 0; $i--) {
                $hours[] = date('d/M/Y:H', time() - $i * 3600);
            }

            $counts = Shell::simulating() ? $this->fakeCounts($sites, $hours) : $this->countHours($sites);

            $result = [];
            foreach ($sites as $id => $site) {
                $series = array_map(fn ($h) => (int) ($counts[$site->domain][$h] ?? 0), $hours);
                $result[$id] = ['hours' => $series, 'total' => array_sum($series)];
            }

            return $result;
        });
    }

    /** One pass over the tail of every access log: "domain hour count". */
    protected function countHours($sites): array
    {
        $script = '';
        foreach ($sites as $site) {
            $log = Shell::arg($site->logPath('access'));
            $script .= 'echo '.Shell::arg('@@'.$site->domain)."\n"
                ."[ -f {$log} ] && tail -n 200000 {$log} | awk -F'[][]' '{print substr(\$2,1,14)}' | sort | uniq -c\n";
        }
        if ($script === '') {
            return [];
        }

        $counts = [];
        $domain = null;
        foreach (explode("\n", Shell::run($script, 60)->output) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '@@')) {
                $domain = substr($line, 2);
            } elseif ($domain && preg_match('/^(\d+) (\S+)$/', $line, $m)) {
                $counts[$domain][$m[2]] = (int) $m[1];
            }
        }

        return $counts;
    }

    protected function fakeCounts($sites, array $hours): array
    {
        $counts = [];
        foreach ($sites as $site) {
            $seed = crc32($site->domain);
            foreach ($hours as $i => $h) {
                $wave = sin(($i + $seed % 24) / 3.8) + 1.2;
                $counts[$site->domain][$h] = (int) max(0, round($wave * (($seed % 90) + 10) + (($seed >> ($i % 16)) & 31)));
            }
        }

        return $counts;
    }

    /**
     * Usage report built from the most recent requests.
     *
     * @return array<string, mixed>
     */
    public function report(Website $site, string $range = '24h', int $lines = 50000): array
    {
        $content = Shell::simulating()
            ? $this->sampleLog($site)
            : Shell::run('tail -n '.max(1000, min(200000, $lines)).' '.Shell::arg($site->logPath('access')).' 2>/dev/null', 60)->output;

        $since = match ($range) {
            'today' => strtotime('today'),
            '7d' => time() - 7 * 86400,
            'all' => 0,
            default => time() - 86400,
        };
        $byHour = $range === '7d' || $range === 'all' ? false : true;

        $total = 0;
        $bytes = 0;
        $ips = $urls = $status = $agents = $referrers = $notFound = $timeline = [];
        $first = null;

        foreach (explode("\n", $content) as $line) {
            if ($line === '' || ! preg_match(self::LOG_REGEX, $line, $m)) {
                continue;
            }
            $time = \DateTime::createFromFormat('d/M/Y:H:i:s O', $m[2]);
            $ts = $time ? $time->getTimestamp() : 0;
            if ($ts < $since) {
                continue;
            }
            $first = $first === null ? $ts : min($first, $ts);
            $total++;
            $bytes += $m[6] === '-' ? 0 : (int) $m[6];
            $ips[$m[1]] = ($ips[$m[1]] ?? 0) + 1;
            $url = strtok($m[4] ?? '-', '?') ?: '-';
            $urls[$url] = ($urls[$url] ?? 0) + 1;
            $code = $m[5];
            $status[$code[0].'xx'] = ($status[$code[0].'xx'] ?? 0) + 1;
            if ($code === '404') {
                $notFound[$url] = ($notFound[$url] ?? 0) + 1;
            }
            $agent = $m[8] ?? '';
            if ($agent !== '' && $agent !== '-') {
                $agents[$this->agentName($agent)] = ($agents[$this->agentName($agent)] ?? 0) + 1;
            }
            $ref = $m[7] ?? '';
            if ($ref !== '' && $ref !== '-' && ($host = parse_url($ref, PHP_URL_HOST)) && ! in_array($host, array_merge([$site->domain], $site->aliasList()), true)) {
                $referrers[$host] = ($referrers[$host] ?? 0) + 1;
            }
            $bucket = date($byHour ? 'Y-m-d H:00' : 'Y-m-d', $ts);
            $timeline[$bucket] = ($timeline[$bucket] ?? 0) + 1;
        }

        ksort($timeline);
        $top = function (array $rows, int $n = 10) {
            arsort($rows);

            return array_map(fn ($k, $v) => ['name' => (string) $k, 'count' => $v], array_keys(array_slice($rows, 0, $n, true)), array_slice($rows, 0, $n, true));
        };

        return [
            'range' => $range,
            'total' => $total,
            'unique_ips' => count($ips),
            'bytes' => $bytes,
            'bandwidth' => SystemStats::bytes($bytes, 1),
            'status' => array_merge(['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0], $status),
            'timeline' => ['labels' => array_keys($timeline), 'values' => array_values($timeline)],
            'top_ips' => $top($ips),
            'top_urls' => $top($urls),
            'not_found' => $top($notFound),
            'agents' => $top($agents, 8),
            'referrers' => $top($referrers, 8),
            'since' => $first ? date('Y-m-d H:i', $first) : null,
            'access_log' => (bool) $site->setting('access_log', true),
        ];
    }

    protected function agentName(string $agent): string
    {
        $bots = ['Googlebot', 'bingbot', 'YandexBot', 'Baiduspider', 'AhrefsBot', 'SemrushBot', 'facebookexternalhit', 'GPTBot', 'ClaudeBot', 'Applebot', 'DuckDuckBot'];
        foreach ($bots as $bot) {
            if (stripos($agent, $bot) !== false) {
                return $bot;
            }
        }
        if (preg_match('/bot|crawl|spider|curl|wget|python|go-http|java\//i', $agent)) {
            return 'Other bots and tools';
        }

        return match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Other',
        };
    }

    /** Realistic access log used in simulation mode. */
    public function sampleLog(Website $site): string
    {
        $paths = ['/', '/index.php', '/assets/app.css', '/assets/app.js', '/api/v1/items', '/login', '/wp-login.php', '/favicon.ico', '/robots.txt', '/images/logo.png'];
        $agents = ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0 Safari/537.36', 'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15', 'Mozilla/5.0 (compatible; Googlebot/2.1)', 'curl/8.5.0', 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0'];
        $lines = [];
        mt_srand(crc32($site->domain));
        for ($i = 0; $i < 1500; $i++) {
            $ts = time() - mt_rand(0, 86400 * 2);
            $path = $paths[mt_rand(0, count($paths) - 1)];
            $code = $path === '/wp-login.php' ? 404 : (mt_rand(0, 30) === 0 ? 500 : (mt_rand(0, 10) === 0 ? 304 : 200));
            $lines[] = sprintf('203.0.113.%d - - [%s] "GET %s HTTP/1.1" %d %d "%s" "%s"', mt_rand(1, 60), date('d/M/Y:H:i:s O', $ts), $path, $code, mt_rand(200, 90000), mt_rand(0, 4) ? '-' : 'https://www.google.com/', $agents[mt_rand(0, count($agents) - 1)]);
        }
        mt_srand();

        return implode("\n", $lines);
    }
}
