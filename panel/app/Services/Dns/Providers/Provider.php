<?php

namespace App\Services\Dns\Providers;

use App\Services\Dns\DnsException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * DNS hosting API driver.
 *
 * Zones: ['id' => string, 'name' => string, 'manageable' => bool, 'note' => ?string, 'records' => ?int]
 * Records are normalized to:
 *   ['id' => string, 'type' => 'A', 'name' => '@'|'www', 'content' => string, 'ttl' => int, 'priority' => ?int, 'proxied' => ?bool]
 * TXT content is stored without surrounding quotes, CAA content as: 0 issue "letsencrypt.org".
 */
abstract class Provider
{
    /** Requests per minute when the API-limit option is on. */
    protected const RATE_PER_MINUTE = 60;

    public function __construct(protected array $credentials, protected bool $rateLimit = false, protected string $cacheKey = 'dns') {}

    /** Check the credentials; returns a label of the account. */
    abstract public function verify(): string;

    /** @return list<array{id: string, name: string, manageable: bool, note: ?string, records: ?int}> */
    abstract public function zones(): array;

    /** @return list<array> normalized records */
    abstract public function records(array $zone): array;

    abstract public function create(array $zone, array $record): void;

    abstract public function update(array $zone, string $id, array $record): void;

    abstract public function delete(array $zone, string $id): void;

    /** Record types the provider accepts through its API. */
    public static function types(): array
    {
        return ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'CAA', 'NS'];
    }

    /** Lowest TTL in seconds accepted by the provider. */
    public static function minTtl(): int
    {
        return 60;
    }

    protected function credential(string $key, string $default = ''): string
    {
        return trim((string) ($this->credentials[$key] ?? $default));
    }

    /* ================================================================== HTTP */

    protected function client(): PendingRequest
    {
        return Http::timeout(30)->connectTimeout(10)->acceptJson()->withUserAgent('GBX-Panel');
    }

    /** Space requests when the API-limit option is enabled. */
    protected function throttle(): void
    {
        if (! $this->rateLimit || app()->runningUnitTests()) {
            return;
        }
        $interval = 60 / static::RATE_PER_MINUTE;
        $key = 'dns.throttle.'.$this->cacheKey;
        $last = (float) Cache::get($key, 0);
        $wait = $last + $interval - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1e6));
        }
        Cache::put($key, microtime(true), 120);
    }

    /** Send a request, retrying once when the API answers 429. */
    protected function send(callable $request): Response
    {
        $this->throttle();
        try {
            $response = $request($this->client());
            if ($response->status() === 429) {
                sleep(min(10, max(1, (int) $response->header('Retry-After') ?: 3)));
                $response = $request($this->client());
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new DnsException('Could not reach the provider API: '.$e->getMessage());
        }

        return $response;
    }

    /* =============================================================== helpers */

    /** Relative record name from a fully qualified name. */
    protected static function relative(string $fqdn, string $zone): string
    {
        $fqdn = rtrim(strtolower($fqdn), '.');
        $zone = strtolower($zone);
        if ($fqdn === '' || $fqdn === '@' || $fqdn === $zone) {
            return '@';
        }

        return str_ends_with($fqdn, '.'.$zone) ? substr($fqdn, 0, -strlen($zone) - 1) : $fqdn;
    }

    protected static function fqdn(string $name, string $zone): string
    {
        return $name === '@' || $name === '' ? $zone : $name.'.'.$zone;
    }

    protected static function unquote(string $value): string
    {
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            // long TXT values come split in quoted chunks: "abc" "def"
            return preg_replace('/"\s+"/', '', substr($value, 1, -1));
        }

        return $value;
    }

    protected static function quote(string $value): string
    {
        // strings longer than 255 characters must be split in chunks
        return implode(' ', array_map(fn ($chunk) => '"'.str_replace('"', '\"', $chunk).'"', str_split($value, 255) ?: ['']));
    }

    /** @return array{flags: int, tag: string, value: string} */
    protected static function parseCaa(string $content): array
    {
        if (! preg_match('/^(\d+)\s+(issue|issuewild|iodef)\s+"?([^"]*)"?$/i', trim($content), $m)) {
            throw new DnsException('CAA records look like: 0 issue "letsencrypt.org"');
        }

        return ['flags' => (int) $m[1], 'tag' => strtolower($m[2]), 'value' => $m[3]];
    }

    protected static function caa(int $flags, string $tag, string $value): string
    {
        return $flags.' '.$tag.' "'.trim($value, '"').'"';
    }

    /** Host targets (CNAME, MX, NS) with a trailing dot, as some APIs require. */
    protected static function dotted(string $host): string
    {
        return $host === '@' ? $host : rtrim($host, '.').'.';
    }

    protected static function record(string $id, string $type, string $name, string $content, int $ttl, ?int $priority = null, ?bool $proxied = null): array
    {
        return ['id' => $id, 'type' => strtoupper($type), 'name' => $name, 'content' => $content, 'ttl' => $ttl, 'priority' => $priority, 'proxied' => $proxied];
    }

    /** Extract a readable message from a JSON error response. */
    protected static function errorOf(Response $response, string $fallback = 'The provider rejected the request'): string
    {
        $json = $response->json();
        $message = null;
        if (is_array($json)) {
            $message = $json['errors'][0]['message'] ?? $json['errors'][0]['reason'] ?? $json['error']['message'] ?? $json['error'] ?? $json['message'] ?? null;
            if (is_array($message)) {
                $message = json_encode($message);
            }
        }

        return ($message ?: $fallback).' (HTTP '.$response->status().')';
    }
}
