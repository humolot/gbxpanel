<?php

namespace App\Http\Controllers;

use App\Services\ServiceManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ServiceController extends Controller
{
    public function __construct(protected ServiceManager $services) {}

    public function index()
    {
        return view('home.services');
    }

    public function data()
    {
        return response()->json(['data' => $this->services->list()]);
    }

    public function action(Request $request)
    {
        $data = $request->validate([
            'service' => ['required', 'string', 'max:64'],
            'action' => ['required', 'in:start,stop,restart,reload,enable,disable'],
        ]);

        if ($data['action'] === 'stop' && in_array($data['service'], ['apache2', 'php8.4-fpm', 'ssh', 'supervisor'], true)) {
            return $this->fail("Stopping {$data['service']} would make the panel or SSH unreachable. Use restart instead.");
        }

        Cache::forget('gbx.software');

        return $this->result($this->services->action($data['service'], $data['action']), ucfirst($data['action'])." {$data['service']}: done", 'service');
    }

    public function journal(Request $request)
    {
        $service = (string) $request->query('service');
        if (! ServiceManager::validName($service)) {
            return $this->fail('Invalid service');
        }

        return $this->ok('ok', ['log' => $this->services->journal($service)]);
    }
}
