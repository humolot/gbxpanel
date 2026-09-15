<?php

namespace App\Console\Commands;

use App\Services\Dns\DnsException;
use App\Services\Dns\DnsManager;
use Illuminate\Console\Command;

/**
 * certbot manual hooks for the DNS-01 challenge (wildcard certificates):
 * creates or removes the _acme-challenge TXT record through the DNS provider API.
 */
class GbxDnsChallenge extends Command
{
    protected $signature = 'gbx:dns-challenge
        {action : auth|cleanup}
        {--domain= : Domain (defaults to CERTBOT_DOMAIN)}
        {--value= : Validation token (defaults to CERTBOT_VALIDATION)}
        {--wait=240 : Seconds to wait for the record to be visible in public DNS}';

    protected $description = 'Create or remove the ACME DNS-01 TXT record through the DNS provider API';

    public function handle(DnsManager $dns): int
    {
        $domain = (string) ($this->option('domain') ?: getenv('CERTBOT_DOMAIN'));
        $value = (string) ($this->option('value') ?: getenv('CERTBOT_VALIDATION'));
        if ($domain === '' || $value === '') {
            $this->error('Domain and validation value are required (CERTBOT_DOMAIN and CERTBOT_VALIDATION).');

            return self::FAILURE;
        }
        $host = DnsManager::challengeName($domain);

        try {
            if ($this->argument('action') === 'cleanup') {
                $removed = $dns->removeChallenge($domain, $value);
                $this->line("Removed {$removed} TXT record(s) {$host}");

                return self::SUCCESS;
            }
            if ($this->argument('action') !== 'auth') {
                $this->error('Unknown action');

                return self::FAILURE;
            }

            $zone = $dns->addChallenge($domain, $value);
            $this->line("Created TXT {$host} in ".$zone->provider->label());

            $deadline = time() + max(0, (int) $this->option('wait'));
            while (time() < $deadline) {
                if ($dns->txtVisible($host, $value)) {
                    $this->line("{$host} is visible in public DNS");
                    sleep(5); // let the remaining authoritative servers catch up

                    return self::SUCCESS;
                }
                sleep(10);
            }
            $this->warn("{$host} is not visible yet in public DNS; Let's Encrypt will try anyway.");

            return self::SUCCESS;
        } catch (DnsException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
