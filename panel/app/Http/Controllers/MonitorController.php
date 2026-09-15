<?php

namespace App\Http\Controllers;

use App\Models\Metric;
use Illuminate\Http\Request;

class MonitorController extends Controller
{
    public function index()
    {
        return view('home.monitor');
    }

    public function data(Request $request)
    {
        $hours = min(168, max(1, (int) $request->query('hours', 24)));
        $rows = Metric::query()->where('created_at', '>=', now()->subHours($hours))->orderBy('id')->get();

        // down-sample to at most ~360 points
        $step = max(1, (int) ceil($rows->count() / 360));
        $rows = $rows->values()->filter(fn ($r, $i) => $i % $step === 0)->values();

        return response()->json([
            'labels' => $rows->map(fn ($r) => $r->created_at->format($hours > 24 ? 'd/m H:i' : 'H:i'))->all(),
            'cpu' => $rows->pluck('cpu')->all(),
            'memory' => $rows->pluck('memory')->all(),
            'load' => $rows->pluck('load1')->all(),
            'disk' => $rows->pluck('disk')->all(),
            'net_rx' => $rows->pluck('net_rx')->all(),
            'net_tx' => $rows->pluck('net_tx')->all(),
        ]);
    }
}
