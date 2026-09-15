<?php

namespace App\Http\Controllers;

use App\Services\Shell;
use App\Services\SystemStats;
use Illuminate\Http\Request;

class ProcessController extends Controller
{
    public function index()
    {
        return view('home.processes');
    }

    public function data(SystemStats $stats)
    {
        return response()->json(['data' => $stats->processes(400)]);
    }

    public function kill(Request $request)
    {
        $data = $request->validate([
            'pid' => ['required', 'integer', 'min:2'],
            'signal' => ['required', 'in:TERM,KILL,HUP'],
        ]);

        if ($data['pid'] === getmypid()) {
            return $this->fail('Refusing to kill the panel process.');
        }

        return $this->result(Shell::run('kill -'.$data['signal'].' '.(int) $data['pid'], 10), "Signal {$data['signal']} sent to PID {$data['pid']}", 'process');
    }
}
