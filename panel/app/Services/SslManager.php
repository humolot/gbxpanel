<?php

namespace App\Services;

use App\Models\Task;
use App\Models\Website;
use Carbon\Carbon;

class SslManager
{
    public function __construct(protected ApacheManager $apache) {}

    public const DNS_HOOK = '/usr/local/gbxpanel/bin/gbx-dns-hook';

    /** Issue a Let's Encrypt certificate using the webroot challenge, or the DNS challenge through a DNS provider API. */
    public function issue(Website $site, string $email, bool $includeAliases = true, string $method = 'http', bool $wildcard = false): Task
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid e-mail is required by Let\'s Encrypt.');
        }
        if ($method === 'dns') {
            return $this->issueDns($site, $email, $includeAliases, $wildcard);
        }

        $domain = Shell::arg($site->domain);
        $aliases = implode(' ', array_map(fn ($d) => Shell::arg($d), $includeAliases ? $site->aliasList() : []));
        $root = Shell::arg($site->root_path);

        // Let's Encrypt rejects the whole certificate when a single name has no DNS record
        // (e.g. a www alias that was never created), so names are checked first: the main
        // domain must resolve, aliases without DNS are skipped with a warning.
        $script = "set -e\n"
            ."command -v certbot >/dev/null || { export DEBIAN_FRONTEND=noninteractive; apt-get update -y; apt-get install -y certbot; }\n"
            ."resolves() { { getent ahosts \"\$1\" 2>/dev/null || true; } | awk '{print \$1}' | sort -u | tr '\\n' ' '; }\n"
            ."IPS=\$(resolves {$domain})\n"
            ."if [ -z \"\$IPS\" ]; then echo \"Error: {$site->domain} has no DNS record (A/AAAA). Point it to this server and try again.\"; exit 1; fi\n"
            ."echo \"{$site->domain} resolves to \$IPS\"\n"
            ."ARGS=\"-d {$site->domain}\"; NAMES=\"{$site->domain}\"\n"
            ."for NAME in {$aliases}; do\n"
            ."    IPS=\$(resolves \"\$NAME\")\n"
            ."    if [ -n \"\$IPS\" ]; then echo \"\$NAME resolves to \$IPS\"; ARGS=\"\$ARGS -d \$NAME\"; NAMES=\"\$NAMES, \$NAME\";\n"
            ."    else echo \"Warning: skipping alias \$NAME (no DNS record). Create the record and issue the certificate again to include it.\"; fi\n"
            ."done\n"
            ."mkdir -p {$root}/.well-known/acme-challenge\n"
            ."certbot certonly --webroot -w {$root} \$ARGS --non-interactive --agree-tos -m ".Shell::arg($email)
            .' --cert-name '.$domain." --expand --keep-until-expiring --deploy-hook 'systemctl reload apache2'\n"
            ."echo \"Certificate issued for: \$NAMES\"";

        return TaskRunner::dispatch("Issue SSL for {$site->domain}", $script, 'ssl', ['website_id' => $site->id, 'on_success' => 'ssl_issued']);
    }

    /**
     * DNS-01 challenge: certbot calls the panel hook, which creates the _acme-challenge TXT record through
     * the DNS provider API. Works without port 80 and allows wildcard names. Renewals reuse the hook.
     */
    protected function issueDns(Website $site, string $email, bool $includeAliases, bool $wildcard): Task
    {
        $dns = app(\App\Services\Dns\DnsManager::class);
        $zone = $dns->zoneFor($site->domain) ?? throw new \InvalidArgumentException("{$site->domain} is not in a DNS zone managed by the panel. Add the DNS API account in DNS, or use HTTP verification.");

        $names = [$site->domain];
        $skipped = [];
        if ($wildcard) {
            $names[] = '*.'.$site->domain;
        }
        foreach ($includeAliases ? $site->aliasList() : [] as $alias) {
            if ($wildcard && preg_match('/^[^.*]+\.'.preg_quote($site->domain, '/').'$/', $alias)) {
                continue; // covered by the wildcard
            }
            $dns->zoneFor($alias) ? $names[] = $alias : $skipped[] = $alias;
        }
        $names = array_values(array_unique($names));

        $this->writeDnsHook();
        $args = implode(' ', array_map(fn ($n) => '-d '.Shell::arg($n), $names));
        $script = "set -e\n"
            ."command -v certbot >/dev/null || { export DEBIAN_FRONTEND=noninteractive; apt-get update -y; apt-get install -y certbot; }\n"
            .'echo '.Shell::arg('DNS verification through '.$zone->provider->label().' for: '.implode(', ', $names))."\n"
            .($skipped ? 'echo '.Shell::arg('Warning: skipping aliases outside the managed DNS zones: '.implode(', ', $skipped))."\n" : '')
            .'certbot certonly --manual --preferred-challenges dns'
            .' --manual-auth-hook '.Shell::arg(self::DNS_HOOK.' auth')
            .' --manual-cleanup-hook '.Shell::arg(self::DNS_HOOK.' cleanup')
            .' '.$args.' --non-interactive --agree-tos -m '.Shell::arg($email)
            .' --cert-name '.Shell::arg($site->domain)." --expand --keep-until-expiring --deploy-hook 'systemctl reload apache2'\n"
            .'echo '.Shell::arg('Certificate issued for: '.implode(', ', $names));

        return TaskRunner::dispatch("Issue SSL (DNS) for {$site->domain}", $script, 'ssl', ['website_id' => $site->id, 'on_success' => 'ssl_issued']);
    }

    /** Root-only hook called by certbot; runs the panel command as the panel user. */
    public function writeDnsHook(): void
    {
        $panel = Shell::arg(base_path());
        $user = Shell::arg((string) config('gbx.system_user', 'gbxpanel'));
        $php = Shell::arg((string) config('gbx.php_cli', '/usr/bin/php8.4'));
        $hook = "#!/bin/bash\n# GBX Panel: certbot DNS-01 hook (auth|cleanup) using the DNS provider APIs configured in the panel\n"
            ."cd {$panel} || exit 1\n"
            ."exec runuser -u {$user} -- env CERTBOT_DOMAIN=\"\$CERTBOT_DOMAIN\" CERTBOT_VALIDATION=\"\$CERTBOT_VALIDATION\" {$php} artisan gbx:dns-challenge \"\$1\"\n";
        Shell::writeFile(self::DNS_HOOK, $hook, '0700', 'root:root')->throw('Unable to write the DNS hook');
    }

    /** Store a custom certificate and key pasted by the user. */
    public function saveCustom(Website $site, string $cert, string $key): ShellResult
    {
        if (! str_contains($cert, 'BEGIN CERTIFICATE') || ! preg_match('/BEGIN (RSA |EC )?PRIVATE KEY/', $key)) {
            return new ShellResult(1, '', 'Invalid certificate or private key (PEM format expected).');
        }

        $dir = $site->sslDir();
        Shell::writeFile($dir.'/fullchain.pem', trim($cert)."\n", '0644')->throw('Unable to save certificate');
        Shell::writeFile($dir.'/privkey.pem', trim($key)."\n", '0600')->throw('Unable to save key');

        if (! Shell::simulating()) {
            $check = Shell::run('K=$(openssl pkey -in '.Shell::arg($dir.'/privkey.pem').' -pubout -outform der 2>/dev/null | md5sum); P=$(openssl x509 -in '.Shell::arg($dir.'/fullchain.pem').' -pubkey -noout 2>/dev/null | openssl pkey -pubin -outform der 2>/dev/null | md5sum); [ -n "$K" ] && [ "$K" = "$P" ]');
            if ($check->failed()) {
                return new ShellResult(1, '', 'The private key does not match the certificate.');
            }
        }

        $site->update(['ssl_enabled' => true, 'ssl_provider' => 'custom']);
        $this->refreshExpiry($site);

        return $this->apache->write($site);
    }

    public function disable(Website $site): ShellResult
    {
        $site->update(['ssl_enabled' => false, 'force_https' => false, 'ssl_expires_at' => null]);

        return $this->apache->write($site);
    }

    public function refreshExpiry(Website $site): void
    {
        if (Shell::simulating()) {
            $site->update(['ssl_expires_at' => now()->addDays(89)]);

            return;
        }
        $paths = $this->apache->certPaths($site);
        $out = Shell::out('openssl x509 -enddate -noout -in '.Shell::arg($paths['cert']).' | cut -d= -f2', 10);
        if ($out) {
            try {
                $site->update(['ssl_expires_at' => Carbon::parse($out)]);
            } catch (\Throwable) {
            }
        }
    }

    public function certificateInfo(Website $site): ?array
    {
        if (! $site->ssl_enabled) {
            return null;
        }
        if (Shell::simulating()) {
            return ['subject' => 'CN = '.$site->domain, 'issuer' => "C = US, O = Let's Encrypt, CN = R11", 'expires' => now()->addDays(89)->toDateTimeString(), 'san' => 'DNS:'.$site->domain];
        }
        $paths = $this->apache->certPaths($site);
        $out = Shell::out('openssl x509 -noout -subject -issuer -enddate -ext subjectAltName -in '.Shell::arg($paths['cert']).' 2>/dev/null', 10);
        preg_match('/subject=(.*)/', $out, $s);
        preg_match('/issuer=(.*)/', $out, $i);
        preg_match('/notAfter=(.*)/', $out, $e);
        preg_match('/(DNS:.*)/', $out, $san);

        return ['subject' => trim($s[1] ?? ''), 'issuer' => trim($i[1] ?? ''), 'expires' => trim($e[1] ?? ''), 'san' => trim($san[1] ?? '')];
    }
}
