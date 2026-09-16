<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\SecurityController as PanelSecurity;
use App\Services\FirewallManager;
use Illuminate\Http\Request;

class SecurityController extends ApiController
{
    public function firewall(FirewallManager $firewall)
    {
        return $this->data(['firewall' => $firewall->status(), 'fail2ban' => $firewall->fail2ban()]);
    }

    public function addRule(Request $request)
    {
        return $this->forward(PanelSecurity::class, 'addRule', [
            'action' => $request->input('action', 'allow'),
            'port' => (string) $request->input('port'),
            'protocol' => $request->input('protocol', 'both'),
            'source' => $request->input('source'),
            'comment' => $request->input('notes', $request->input('comment')),
        ]);
    }

    public function deleteRule(string $number)
    {
        return $this->forward(PanelSecurity::class, 'deleteRule', [], ['number' => $number]);
    }

    /** Block an address in the firewall. Remove the block by deleting its rule. */
    public function block(Request $request)
    {
        return $this->forward(PanelSecurity::class, 'block', [
            'ip' => $request->input('ip'),
            'comment' => $request->input('notes', $request->input('comment')),
        ]);
    }

    public function unban(Request $request)
    {
        return $this->forward(PanelSecurity::class, 'unban', [
            'jail' => $request->input('jail'),
            'ip' => $request->input('ip'),
        ]);
    }
}
