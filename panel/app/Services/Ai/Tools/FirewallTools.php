<?php

namespace App\Services\Ai\Tools;

use App\Services\FirewallManager;
use App\Services\ShellResult;

class FirewallTools extends ToolGroup
{
    public function __construct(protected FirewallManager $firewall) {}

    public function tools(): array
    {
        return [
            'get_firewall' => self::tool('UFW status, default policies and numbered rules, SSH settings, Fail2ban jails/bans and recent failed SSH logins.', self::params(['include_failed_logins' => self::bool('Also scan failed SSH logins (last 24h)')]), false, false, fn () => 'Checked firewall and SSH'),

            'open_port' => self::tool('Allow incoming traffic on a port or range (e.g. 8080, 3000:3100), optionally only from an IP/CIDR.', self::params(['port' => self::str('Port or range'), 'protocol' => self::str('tcp, udp or both', ['tcp', 'udp', 'both']), 'source' => self::str('Only from this IP/CIDR (empty = anywhere)'), 'comment' => self::str('Comment')], ['port']), true, false, fn ($a) => 'Open port '.self::labelArg($a, 'port').'/'.($a['protocol'] ?? 'tcp')),
            'close_port' => self::tool('Remove every ALLOW rule for a port (IPv4 and IPv6). The SSH and panel ports are refused.', self::params(['port' => self::str('Port or range'), 'protocol' => self::str('tcp, udp or any', ['tcp', 'udp', 'any'])], ['port']), true, false, fn ($a) => 'Close port '.self::labelArg($a, 'port')),
            'add_firewall_rule' => self::tool('Add a custom UFW rule (allow, deny, reject or limit for rate limiting).', self::params(['action' => self::str('Action', ['allow', 'deny', 'reject', 'limit']), 'port' => self::str('Port or range'), 'protocol' => self::str('tcp, udp or both', ['tcp', 'udp', 'both']), 'source' => self::str('Source IP/CIDR'), 'comment' => self::str('Comment')], ['action', 'port']), true, false, fn ($a) => ucfirst(self::labelArg($a, 'action')).' port '.self::labelArg($a, 'port')),
            'delete_firewall_rule' => self::tool('Delete a UFW rule by its number (from get_firewall).', self::params(['number' => self::int('Rule number')], ['number']), true, false, fn ($a) => 'Delete firewall rule #'.self::labelArg($a, 'number')),
            'block_ip' => self::tool('Block all traffic from an IP address or CIDR.', self::params(['ip' => self::str('IP or CIDR'), 'comment' => self::str('Reason')], ['ip']), true, false, fn ($a) => 'Block IP '.self::labelArg($a, 'ip')),
            'unblock_ip' => self::tool('Remove firewall DENY/REJECT rules for an IP and unban it from Fail2ban jails.', self::params(['ip' => self::str('IP or CIDR')], ['ip']), true, false, fn ($a) => 'Unblock IP '.self::labelArg($a, 'ip')),
            'set_firewall_enabled' => self::tool('Enable or disable UFW. Enabling always allows SSH and the panel port first.', self::params(['enabled' => self::bool('true to enable')], ['enabled']), true, true, fn ($a) => ! empty($a['enabled']) ? 'Enable firewall' : 'Disable firewall'),
            'update_ssh_settings' => self::tool('Change SSH port, root login and authentication methods. The new port is opened in UFW first. Can lock users out: confirm key access first.', self::params(['port' => self::int('SSH port'), 'permit_root_login' => self::str('Root login', ['yes', 'no', 'prohibit-password']), 'password_authentication' => self::str('Password auth', ['yes', 'no']), 'pubkey_authentication' => self::str('Public key auth', ['yes', 'no'])]), true, true, fn () => 'Update SSH settings'),
        ];
    }

    public function handle(string $name, array $a): mixed
    {
        switch ($name) {
            case 'get_firewall':
                return ['ufw' => $this->firewall->status(), 'ssh' => $this->firewall->sshConfig(), 'panel_port' => config('gbx.port'), 'fail2ban' => $this->firewall->fail2ban()]
                    + (self::a($a, 'include_failed_logins') ? ['failed_ssh_logins' => $this->firewall->failedLogins()] : []);

            case 'open_port':
                return $this->shell($this->firewall->addRule('allow', (string) $a['port'], (string) self::a($a, 'protocol', 'tcp'), self::a($a, 'source') ?: null, self::a($a, 'comment') ?: 'Opened by AI'), "Port {$a['port']} opened");

            case 'close_port':
                $port = str_replace('-', ':', (string) $a['port']);
                $protected = [(string) config('gbx.port'), (string) ($this->firewall->sshConfig()['port'] ?? 22)];
                if (in_array($port, $protected, true)) {
                    return ['ok' => false, 'error' => 'Refusing to close the SSH or panel port.'];
                }
                $proto = self::a($a, 'protocol', 'any');
                $numbers = [];
                foreach ($this->firewall->status()['rules'] as $rule) {
                    $to = trim(str_replace('(v6)', '', $rule['to']));
                    [$rulePort, $ruleProto] = array_pad(explode('/', $to), 2, null);
                    if ($rule['action'] === 'ALLOW' && $rulePort === $port && ($proto === 'any' || ! $ruleProto || $ruleProto === $proto)) {
                        $numbers[] = $rule['num'];
                    }
                }

                return $this->deleteNumbers($numbers, "Port {$port} closed");

            case 'add_firewall_rule':
                return $this->shell($this->firewall->addRule((string) $a['action'], (string) $a['port'], (string) self::a($a, 'protocol', 'tcp'), self::a($a, 'source') ?: null, self::a($a, 'comment') ?: 'Added by AI'), 'Rule added');

            case 'delete_firewall_rule':
                return $this->shell($this->firewall->deleteRule((int) $a['number']), 'Rule deleted');

            case 'block_ip':
                if ($a['ip'] === request()->ip()) {
                    return ['ok' => false, 'error' => 'Refusing to block the IP of the current panel user.'];
                }

                return $this->shell($this->firewall->blockIp((string) $a['ip'], self::a($a, 'comment') ?: 'Blocked by AI'), "IP {$a['ip']} blocked");

            case 'unblock_ip':
                $ip = (string) $a['ip'];
                $numbers = [];
                foreach ($this->firewall->status()['rules'] as $rule) {
                    if (in_array($rule['action'], ['DENY', 'REJECT'], true) && trim(str_replace('(v6)', '', $rule['from'])) === $ip) {
                        $numbers[] = $rule['num'];
                    }
                }
                $result = $this->deleteNumbers($numbers, "Removed firewall blocks for {$ip}");
                foreach (array_keys($this->firewall->fail2ban()['jails'] ?? []) as $jail) {
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        $this->firewall->unban($jail, $ip);
                    }
                }

                return $result + ['fail2ban' => 'unban requested in all jails'];

            case 'set_firewall_enabled':
                return $this->shell($this->firewall->setEnabled((bool) $a['enabled']), $a['enabled'] ? 'Firewall enabled' : 'Firewall disabled');

            case 'update_ssh_settings':
                $current = $this->firewall->sshConfig();

                return $this->shell($this->firewall->saveSshConfig(
                    (int) self::a($a, 'port', $current['port']),
                    (string) self::a($a, 'permit_root_login', $current['permit_root_login'] === 'without-password' ? 'prohibit-password' : $current['permit_root_login']),
                    (string) self::a($a, 'password_authentication', $current['password_authentication']),
                    (string) self::a($a, 'pubkey_authentication', $current['pubkey_authentication']),
                ), 'SSH settings applied');
        }

        return ['error' => "Unknown tool {$name}"];
    }

    /** Delete rules from the highest number down so numbering does not shift. */
    protected function deleteNumbers(array $numbers, string $success): array
    {
        if (! $numbers) {
            return ['ok' => false, 'error' => 'No matching rules found.'];
        }
        rsort($numbers);
        $errors = [];
        foreach ($numbers as $n) {
            $r = $this->firewall->deleteRule($n);
            if ($r->failed()) {
                $errors[] = "#{$n}: ".$r->message();
            }
        }

        return $errors ? ['ok' => false, 'error' => implode('; ', $errors)] : ['ok' => true, 'message' => $success, 'deleted_rules' => count($numbers)];
    }
}
