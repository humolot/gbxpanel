<?php

namespace App\Services\Dns\Providers;

use App\Services\Dns\DnsException;
use App\Services\SystemStats;
use Illuminate\Http\Client\PendingRequest;

/**
 * Namecheap XML API. Requests must come from an IPv4 address in the API whitelist.
 *
 * The API has no per-record operations: domains.dns.setHosts replaces the whole host list, so every
 * change reads the current records, applies the change and writes the complete list back.
 */
class NamecheapProvider extends Provider
{
    public const API = 'https://api.namecheap.com/xml.response';

    public const SANDBOX = 'https://api.sandbox.namecheap.com/xml.response';

    // Namecheap allows 20 calls per minute
    protected const RATE_PER_MINUTE = 20;

    public static function types(): array
    {
        return ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'CAA', 'NS'];
    }

    protected function clientIp(): string
    {
        return $this->credential('client_ip') ?: app(SystemStats::class)->publicIp();
    }

    protected function call(string $command, array $params = [], bool $post = false): \SimpleXMLElement
    {
        $query = [
            'ApiUser' => $this->credential('api_user'),
            'ApiKey' => $this->credential('api_key'),
            'UserName' => $this->credential('username') ?: $this->credential('api_user'),
            'ClientIp' => $this->clientIp(),
            'Command' => $command,
        ] + $params;
        $url = $this->credential('sandbox') ? self::SANDBOX : self::API;

        $response = $this->send(fn (PendingRequest $c) => $post ? $c->asForm()->post($url, $query) : $c->get($url, $query));
        if ($response->failed()) {
            throw new DnsException('Namecheap: HTTP '.$response->status());
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body());
        libxml_use_internal_errors($previous);
        if (! $xml) {
            throw new DnsException('Namecheap: invalid response from the API.');
        }
        if ((string) $xml['Status'] !== 'OK') {
            $error = $xml->Errors->Error ?? null;
            $message = $error ? trim((string) $error).' ['.(string) $error['Number'].']' : 'request failed';
            if (str_contains(strtolower($message), 'ip')) {
                $message .= '. Add '.$this->clientIp().' to Profile > Tools > Namecheap API Access > Whitelisted IPs.';
            }
            throw new DnsException('Namecheap: '.$message);
        }

        return $xml->CommandResponse;
    }

    /** @return array{0: string, 1: string} second-level name and TLD (example, co.uk) */
    protected static function split(string $domain): array
    {
        $parts = explode('.', $domain, 2);
        if (count($parts) !== 2) {
            throw new DnsException('Invalid domain '.$domain);
        }

        return $parts;
    }

    public function verify(): string
    {
        foreach (['api_user', 'api_key'] as $key) {
            if ($this->credential($key) === '') {
                throw new DnsException('Enter the API user and API key.');
            }
        }
        $this->call('namecheap.domains.getList', ['PageSize' => 10, 'Page' => 1]);

        return $this->credential('username') ?: $this->credential('api_user');
    }

    public function zones(): array
    {
        $zones = [];
        $page = 1;
        do {
            $result = $this->call('namecheap.domains.getList', ['PageSize' => 100, 'Page' => $page]);
            foreach ($result->DomainGetListResult->Domain ?? [] as $d) {
                $ours = strtolower((string) $d['IsOurDNS']) === 'true';
                $zones[] = [
                    'id' => (string) $d['ID'],
                    'name' => strtolower((string) $d['Name']),
                    'manageable' => $ours,
                    'note' => $ours ? null : 'Uses custom nameservers: records are managed where the nameservers point.',
                    'records' => null,
                ];
            }
            $total = (int) ($result->Paging->TotalItems ?? 0);
        } while ($page++ * 100 < $total && $page <= 50);

        return $zones;
    }

    /** @return array{0: list<array>, 1: string} records and e-mail type */
    protected function hosts(array $zone): array
    {
        [$sld, $tld] = self::split($zone['name']);
        $result = $this->call('namecheap.domains.dns.getHosts', ['SLD' => $sld, 'TLD' => $tld])->DomainDNSGetHostsResult;
        $records = [];
        foreach ($result->host ?? [] as $h) {
            $type = strtoupper((string) $h['Type']);
            $records[] = self::record(
                (string) $h['HostId'], $type, (string) $h['Name'] ?: '@',
                $type === 'TXT' ? (string) $h['Address'] : rtrim((string) $h['Address'], $type === 'CAA' ? '' : '.'),
                (int) $h['TTL'], $type === 'MX' ? (int) $h['MXPref'] : null
            );
        }

        return [$records, (string) ($result['EmailType'] ?? '')];
    }

    public function records(array $zone): array
    {
        return $this->hosts($zone)[0];
    }

    /** Write the complete host list back. */
    protected function setHosts(array $zone, array $records, string $emailType): void
    {
        [$sld, $tld] = self::split($zone['name']);
        $params = ['SLD' => $sld, 'TLD' => $tld];
        $i = 0;
        $hasMx = false;
        foreach ($records as $r) {
            $i++;
            $params['HostName'.$i] = $r['name'];
            $params['RecordType'.$i] = $r['type'];
            $params['Address'.$i] = in_array($r['type'], ['CNAME', 'MX', 'NS'], true) ? self::dotted($r['content']) : $r['content'];
            $params['TTL'.$i] = $r['ttl'] <= 1 ? 1799 : max(60, min(60000, (int) $r['ttl']));
            if ($r['type'] === 'MX') {
                $params['MXPref'.$i] = (int) ($r['priority'] ?? 10);
                $hasMx = true;
            }
        }
        // MX records are only served when the e-mail type is MX
        $params['EmailType'] = $hasMx ? 'MX' : ($emailType === 'MX' ? 'NONE' : ($emailType ?: 'NONE'));
        if ($params['EmailType'] === 'NONE') {
            unset($params['EmailType']);
        }

        $this->call('namecheap.domains.dns.setHosts', $params, true);
    }

    public function create(array $zone, array $record): void
    {
        [$records, $emailType] = $this->hosts($zone);
        $records[] = $record;
        $this->setHosts($zone, $records, $emailType);
    }

    public function update(array $zone, string $id, array $record): void
    {
        [$records, $emailType] = $this->hosts($zone);
        $found = false;
        foreach ($records as $i => $r) {
            if ($r['id'] === $id) {
                $records[$i] = ['id' => $id] + $record;
                $found = true;
            }
        }
        if (! $found) {
            throw new DnsException('The record no longer exists. Reload the records.');
        }
        $this->setHosts($zone, $records, $emailType);
    }

    public function delete(array $zone, string $id): void
    {
        [$records, $emailType] = $this->hosts($zone);
        $left = array_values(array_filter($records, fn ($r) => $r['id'] !== $id));
        if (count($left) === count($records)) {
            throw new DnsException('The record no longer exists. Reload the records.');
        }
        $this->setHosts($zone, $left, $emailType);
    }
}
