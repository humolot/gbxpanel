<?php

namespace App\Http\Controllers;

use App\Services\FirewallManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SecurityController extends Controller
{
    public function __construct(protected FirewallManager $firewall) {}

    public function index()
    {
        return view('security.index', [
            'installed' => $this->firewall->installed(),
            'ssh' => $this->firewall->sshConfig(),
            'panelPort' => config('gbx.port'),
        ]);
    }

    public function data()
    {
        return response()->json([
            'firewall' => $this->firewall->status(),
            'fail2ban' => $this->firewall->fail2ban(),
        ]);
    }

    public function toggle(Request $request)
    {
        Cache::forget('gbx.home.extra');
        $enable = $request->boolean('enabled');

        return $this->result($this->firewall->setEnabled($enable), $enable ? 'Firewall enabled' : 'Firewall disabled', 'security');
    }

    public function addRule(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', 'in:allow,deny,reject,limit'],
            'port' => ['required', 'string', 'max:11'],
            'protocol' => ['required', 'in:tcp,udp,both'],
            'source' => ['nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:60'],
        ]);
        Cache::forget('gbx.home.extra');

        return $this->result(
            $this->firewall->addRule($data['action'], trim($data['port']), $data['protocol'], $data['source'] ?? null, $data['comment'] ?? null),
            'Rule added', 'security', strtoupper($data['action']).' '.$data['port'].'/'.$data['protocol'].' from '.(($data['source'] ?? null) ?: 'any')
        );
    }

    public function deleteRule(int $number)
    {
        Cache::forget('gbx.home.extra');

        return $this->result($this->firewall->deleteRule($number), 'Rule deleted', 'security', "Rule #{$number}");
    }

    public function block(Request $request)
    {
        $data = $request->validate(['ip' => ['required', 'string', 'max:64'], 'comment' => ['nullable', 'string', 'max:60']]);
        if ($data['ip'] === $request->ip()) {
            return $this->fail('You cannot block your own IP address.');
        }

        return $this->result($this->firewall->blockIp($data['ip'], $data['comment'] ?? null), "IP {$data['ip']} blocked", 'security');
    }

    public function ssh(Request $request)
    {
        $data = $request->validate([
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'permit_root_login' => ['required', 'in:yes,no,prohibit-password'],
            'password_authentication' => ['required', 'in:yes,no'],
            'pubkey_authentication' => ['required', 'in:yes,no'],
        ]);

        if ($data['password_authentication'] === 'no' && $data['pubkey_authentication'] === 'no') {
            return $this->fail('At least one authentication method must stay enabled.');
        }

        return $this->result(
            $this->firewall->saveSshConfig((int) $data['port'], $data['permit_root_login'], $data['password_authentication'], $data['pubkey_authentication']),
            'SSH settings applied', 'security', json_encode($data)
        );
    }

    public function failed()
    {
        return response()->json(['data' => $this->firewall->failedLogins()]);
    }

    public function unban(Request $request)
    {
        $data = $request->validate(['jail' => ['required', 'string'], 'ip' => ['required', 'ip']]);

        return $this->result($this->firewall->unban($data['jail'], $data['ip']), "{$data['ip']} unbanned", 'security');
    }
}
