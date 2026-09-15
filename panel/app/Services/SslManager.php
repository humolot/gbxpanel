<?php

namespace App\Services;

use App\Models\Task;
use App\Models\Website;
use Carbon\Carbon;

class SslManager
{
    public function __construct(protected ApacheManager $apache) {}

    /** Issue a Let's Encrypt certificate using the webroot challenge. */
    public function issue(Website $site, string $email, bool $includeAliases = true): Task
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid e-mail is required by Let\'s Encrypt.');
        }

        $domains = array_merge([$site->domain], $includeAliases ? $site->aliasList() : []);
        $args = implode(' ', array_map(fn ($d) => '-d '.Shell::arg($d), $domains));
        $root = Shell::arg($site->root_path);

        $script = "set -e\n"
            ."command -v certbot >/dev/null || { export DEBIAN_FRONTEND=noninteractive; apt-get update -y; apt-get install -y certbot; }\n"
            ."mkdir -p {$root}/.well-known/acme-challenge\n"
            ."certbot certonly --webroot -w {$root} {$args} --non-interactive --agree-tos -m ".Shell::arg($email)
            .' --cert-name '.Shell::arg($site->domain)." --expand --keep-until-expiring --deploy-hook 'systemctl reload apache2'\n"
            ."echo 'Certificate issued for: ".implode(', ', $domains)."'";

        return TaskRunner::dispatch("Issue SSL for {$site->domain}", $script, 'ssl', ['website_id' => $site->id, 'on_success' => 'ssl_issued']);
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
