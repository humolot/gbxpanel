<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\DnsController as PanelDns;
use App\Models\DnsZone;
use Illuminate\Http\Request;

class DnsController extends ApiController
{
    public function providers()
    {
        return $this->forward(PanelDns::class, 'providers');
    }

    public function zones()
    {
        return $this->forward(PanelDns::class, 'zones');
    }

    public function records(DnsZone $zone)
    {
        return $this->forward(PanelDns::class, 'records', [], ['zone' => $zone]);
    }

    public function recordStore(Request $request, DnsZone $zone)
    {
        return $this->forward(PanelDns::class, 'recordStore', $this->record($request), ['zone' => $zone]);
    }

    public function recordUpdate(Request $request, DnsZone $zone, string $record)
    {
        return $this->forward(PanelDns::class, 'recordUpdate', $this->record($request), ['zone' => $zone, 'record' => $record]);
    }

    public function recordDestroy(Request $request, DnsZone $zone, string $record)
    {
        return $this->forward(PanelDns::class, 'recordDestroy', [
            'type' => $request->input('type'),
            'name' => $request->input('name'),
            'label' => $request->input('name'),
        ], ['zone' => $zone, 'record' => $record]);
    }

    protected function record(Request $request): array
    {
        return [
            'type' => $request->input('type'),
            'name' => $request->input('name', '@'),
            'content' => $request->input('content'),
            'ttl' => $request->input('ttl'),
            'priority' => $request->input('priority'),
            'proxied' => $this->flag($request, 'proxied'),
        ];
    }

    /** Point the domain (and by default www) to this server or to a given address. */
    public function point(Request $request, DnsZone $zone)
    {
        $hosts = $request->input('hosts');
        if (! is_array($hosts)) {
            $hosts = $request->boolean('www', true) ? [$zone->name, 'www.'.$zone->name] : [$zone->name];
        }

        return $this->forward(PanelDns::class, 'point', [
            'hosts' => $hosts,
            'ipv6' => $this->flag($request, 'ipv6', true),
            'proxied' => $this->flag($request, 'proxied'),
        ], ['zone' => $zone]);
    }
}
