<?php

namespace App\Services;

class FirewallManager
{
    public function installed(): bool
    {
        return Shell::simulating() || Shell::commandExists('ufw');
    }

    public function status(): array
    {
        if (Shell::simulating()) {
            return [
                'active' => true,
                'default_in' => 'deny', 'default_out' => 'allow',
                'rules' => [
                    ['num' => 1, 'to' => '22/tcp', 'action' => 'ALLOW', 'direction' => 'IN', 'from' => 'Anywhere', 'comment' => 'SSH', 'v6' => false],
                    ['num' => 2, 'to' => '80/tcp', 'action' => 'ALLOW', 'direction' => 'IN', 'from' => 'Anywhere', 'comment' => 'HTTP', 'v6' => false],
                    ['num' => 3, 'to' => '443', 'action' => 'ALLOW', 'direction' => 'IN', 'from' => 'Anywhere', 'comment' => 'HTTPS', 'v6' => false],
                    ['num' => 4, 'to' => config('gbx.port').'/tcp', 'action' => 'ALLOW', 'direction' => 'IN', 'from' => 'Anywhere', 'comment' => 'GBX Panel', 'v6' => false],
                    ['num' => 5, 'to' => 'Anywhere', 'action' => 'DENY', 'direction' => 'IN', 'from' => '45.155.205.12', 'comment' => 'Blocked IP', 'v6' => false],
                ],
            ];
        }

        $out = Shell::run('ufw status numbered 2>&1; echo "---"; ufw status verbose 2>&1 | grep -i "^Default"', 20)->output;
        [$numbered, $verbose] = array_pad(explode('---', $out, 2), 2, '');

        $rules = [];
        foreach (explode("\n", $numbered) as $line) {
            if (preg_match('/^\[\s*(\d+)\]\s+(.+?)\s{2,}(ALLOW|DENY|REJECT|LIMIT)\s*(IN|OUT|FWD)?\s+(.+?)\s*$/', rtrim($line), $m)) {
                $from = $m[5];
                $comment = '';
                if (str_contains($from, '#')) {
                    [$from, $comment] = array_map('trim', explode('#', $from, 2));
                }
                $rules[] = [
                    'num' => (int) $m[1], 'to' => trim($m[2]), 'action' => $m[3], 'direction' => $m[4] ?: 'IN',
                    'from' => trim($from), 'comment' => $comment, 'v6' => str_contains($m[2], '(v6)'),
                ];
            }
        }

        preg_match('/Default:\s*(\w+)\s*\(incoming\),\s*(\w+)\s*\(outgoing\)/i', $verbose, $d);

        return [
            'active' => str_contains($numbered, 'Status: active'),
            'default_in' => $d[1] ?? 'deny',
            'default_out' => $d[2] ?? 'allow',
            'rules' => $rules,
        ];
    }

    public function setEnabled(bool $enabled): ShellResult
    {
        if (! $enabled) {
            return Shell::run('ufw disable', 30);
        }

        $ssh = $this->sshConfig()['port'] ?? 22;
        $panel = (int) config('gbx.port');

        return Shell::run("ufw allow {$ssh}/tcp comment 'SSH' && ufw allow {$panel}/tcp comment 'GBX Panel' && ufw --force enable", 30);
    }

    public function addRule(string $action, string $port, string $protocol, ?string $source, ?string $comment): ShellResult
    {
        if (! in_array($action, ['allow', 'deny', 'reject', 'limit'], true)) {
            return new ShellResult(1, '', 'Invalid action');
        }
        if (! preg_match('/^\d{1,5}([:-]\d{1,5})?$/', $port)) {
            return new ShellResult(1, '', 'Invalid port or range (use 8080 or 8000:8100)');
        }
        $port = str_replace('-', ':', $port);
        if (! in_array($protocol, ['tcp', 'udp', 'both'], true)) {
            return new ShellResult(1, '', 'Invalid protocol');
        }
        if (str_contains($port, ':') && $protocol === 'both') {
            return new ShellResult(1, '', 'Port ranges require a protocol (tcp or udp)');
        }
        if ($source && ! $this->validSource($source)) {
            return new ShellResult(1, '', 'Invalid source IP / CIDR');
        }

        $cmd = 'ufw '.$action;
        $cmd .= ' from '.($source ? Shell::arg($source) : 'any').' to any port '.Shell::arg($port);
        if ($protocol !== 'both') {
            $cmd .= ' proto '.$protocol;
        }
        if ($comment) {
            $cmd .= ' comment '.Shell::arg(mb_substr(preg_replace('/[^\w\s.\-]/u', '', $comment), 0, 60));
        }

        return Shell::run($cmd, 30);
    }

    public function blockIp(string $ip, ?string $comment = null): ShellResult
    {
        if (! $this->validSource($ip)) {
            return new ShellResult(1, '', 'Invalid IP / CIDR');
        }
        $status = $this->status();
        $position = count($status['rules']) > 0 ? 'insert 1 ' : '';

        return Shell::run('ufw '.$position.'deny from '.Shell::arg($ip).' to any comment '.Shell::arg($comment ?: 'Blocked IP'), 30);
    }

    public function deleteRule(int $number): ShellResult
    {
        return Shell::run('ufw --force delete '.(int) $number, 30);
    }

    public function validSource(string $source): bool
    {
        [$ip, $mask] = array_pad(explode('/', $source, 2), 2, null);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return $mask === null || (ctype_digit($mask) && (int) $mask <= (str_contains($ip, ':') ? 128 : 32));
    }

    /* ---------------------------------------------------------------- SSH */

    public function sshConfig(): array
    {
        if (Shell::simulating()) {
            return ['port' => 22, 'permit_root_login' => 'prohibit-password', 'password_authentication' => 'yes', 'pubkey_authentication' => 'yes', 'active' => true];
        }

        $out = Shell::run('sshd -T 2>/dev/null | grep -Ei "^(port|permitrootlogin|passwordauthentication|pubkeyauthentication) "', 15)->output;
        $cfg = [];
        foreach (explode("\n", $out) as $line) {
            $p = preg_split('/\s+/', trim($line), 2);
            if (count($p) === 2) {
                $cfg[strtolower($p[0])] = $p[1];
            }
        }

        return [
            'port' => (int) ($cfg['port'] ?? 22),
            'permit_root_login' => $cfg['permitrootlogin'] ?? 'prohibit-password',
            'password_authentication' => $cfg['passwordauthentication'] ?? 'yes',
            'pubkey_authentication' => $cfg['pubkeyauthentication'] ?? 'yes',
            'active' => app(ServiceManager::class)->status('ssh')['active'],
        ];
    }

    public function saveSshConfig(int $port, string $rootLogin, string $passwordAuth, string $pubkeyAuth): ShellResult
    {
        if ($port < 1 || $port > 65535 || ! in_array($rootLogin, ['yes', 'no', 'prohibit-password'], true)
            || ! in_array($passwordAuth, ['yes', 'no'], true) || ! in_array($pubkeyAuth, ['yes', 'no'], true)) {
            return new ShellResult(1, '', 'Invalid SSH settings');
        }

        $content = "# Managed by GBX Panel\nPort {$port}\nPermitRootLogin {$rootLogin}\nPasswordAuthentication {$passwordAuth}\nPubkeyAuthentication {$pubkeyAuth}\n";
        $file = '/etc/ssh/sshd_config.d/00-gbxpanel.conf';

        Shell::run('mkdir -p /etc/ssh/sshd_config.d && grep -q "^Include /etc/ssh/sshd_config.d" /etc/ssh/sshd_config || sed -i "1i Include /etc/ssh/sshd_config.d/*.conf" /etc/ssh/sshd_config');
        Shell::run('[ -f '.$file.' ] && cp '.$file.' '.$file.'.bak || true');
        Shell::writeFile($file, $content, '0600')->throw('Unable to write SSH config');

        $test = Shell::run('sshd -t 2>&1', 20);
        if ($test->failed()) {
            Shell::run('if [ -f '.$file.'.bak ]; then mv '.$file.'.bak '.$file.'; else rm -f '.$file.'; fi');

            return new ShellResult(1, '', 'sshd config test failed: '.$test->message());
        }

        $reload = "command -v ufw >/dev/null && ufw allow {$port}/tcp comment 'SSH' >/dev/null; "
            .'if systemctl is-active --quiet ssh.socket; then systemctl daemon-reload && systemctl restart ssh.socket && systemctl restart ssh; '
            .'else systemctl reload ssh 2>/dev/null || systemctl reload sshd; fi';

        return Shell::run($reload, 60);
    }

    /** Failed SSH login attempts grouped by IP (last 24h). */
    public function failedLogins(): array
    {
        if (Shell::simulating()) {
            return [['ip' => '45.155.205.12', 'count' => 312, 'last' => 'Sep 14 09:12:44'], ['ip' => '218.92.0.31', 'count' => 87, 'last' => 'Sep 14 08:55:02']];
        }

        $out = Shell::run('(journalctl -u ssh -u sshd --since "24 hours ago" --no-pager 2>/dev/null || tail -n 20000 /var/log/auth.log) | grep -E "Failed password|Invalid user|authentication failure" | tail -n 5000', 30)->output;
        $ips = [];
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/from\s+([0-9a-fA-F:.]+)/', $line, $m) || preg_match('/rhost=([0-9a-fA-F:.]+)/', $line, $m)) {
                $ips[$m[1]]['count'] = ($ips[$m[1]]['count'] ?? 0) + 1;
                $ips[$m[1]]['last'] = substr($line, 0, 15);
            }
        }
        $rows = [];
        foreach ($ips as $ip => $v) {
            $rows[] = ['ip' => $ip, 'count' => $v['count'], 'last' => $v['last']];
        }
        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_slice($rows, 0, 50);
    }

    /* ----------------------------------------------------------- Fail2ban */

    public function fail2ban(): array
    {
        if (Shell::simulating()) {
            return ['installed' => true, 'active' => true, 'jails' => ['sshd' => ['banned' => ['61.177.172.140'], 'total' => 14]]];
        }
        if (! Shell::fileExists('/usr/bin/fail2ban-client')) {
            return ['installed' => false, 'active' => false, 'jails' => []];
        }

        $jails = [];
        $list = Shell::out('fail2ban-client status 2>/dev/null | grep "Jail list" | sed "s/.*Jail list:\s*//"', 15);
        foreach (array_filter(array_map('trim', explode(',', $list))) as $jail) {
            if (! preg_match('/^[\w.-]+$/', $jail)) {
                continue;
            }
            $s = Shell::out('fail2ban-client status '.Shell::arg($jail), 15);
            preg_match('/Banned IP list:\s*(.*)/', $s, $b);
            preg_match('/Total banned:\s*(\d+)/', $s, $t);
            $jails[$jail] = ['banned' => array_values(array_filter(preg_split('/\s+/', trim($b[1] ?? '')))), 'total' => (int) ($t[1] ?? 0)];
        }

        return ['installed' => true, 'active' => app(ServiceManager::class)->status('fail2ban')['active'], 'jails' => $jails];
    }

    public function unban(string $jail, string $ip): ShellResult
    {
        if (! preg_match('/^[\w.-]+$/', $jail) || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return new ShellResult(1, '', 'Invalid jail or IP');
        }

        return Shell::run('fail2ban-client set '.Shell::arg($jail).' unbanip '.Shell::arg($ip), 20);
    }
}
