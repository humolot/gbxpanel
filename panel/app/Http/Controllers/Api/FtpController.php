<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\FtpController as PanelFtp;
use App\Models\FtpAccount;
use Illuminate\Http\Request;

class FtpController extends ApiController
{
    public static function resource(FtpAccount $ftp): array
    {
        return [
            'id' => $ftp->id,
            'username' => $ftp->username,
            'path' => $ftp->path,
            'is_active' => (bool) $ftp->is_active,
            'website_id' => $ftp->website_id,
            'client_id' => $ftp->client_id,
            'notes' => $ftp->notes,
            'created_at' => $ftp->created_at?->toIso8601String(),
        ];
    }

    public function index(Request $request)
    {
        $query = FtpAccount::query()->orderBy('username');
        if ($request->filled('website_id')) {
            $query->where('website_id', (int) $request->query('website_id'));
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', (int) $request->query('client_id'));
        }

        return $this->page($query, $request, fn (FtpAccount $ftp) => self::resource($ftp));
    }

    public function store(Request $request)
    {
        $response = $this->forward(PanelFtp::class, 'store', [
            'username' => $request->input('username'),
            'password' => $request->input('password'),
            'path' => $request->input('path'),
            'website_id' => $request->input('website_id'),
            'notes' => $request->input('notes'),
        ]);

        $account = FtpAccount::query()->where('username', $request->input('username'))->first();
        if ($account && $request->filled('client_id')) {
            $account->update(['client_id' => (int) $request->input('client_id')]);
        }
        if ($account && $response->isSuccessful()) {
            return $this->message('FTP account created', self::resource($account->fresh()));
        }

        return $response;
    }

    public function update(Request $request, FtpAccount $ftp)
    {
        return $this->forward(PanelFtp::class, 'update', [
            'path' => $request->input('path', $ftp->path),
            'password' => $request->input('password'),
            'notes' => $request->input('notes', $ftp->notes),
        ], ['ftp' => $ftp]);
    }

    public function toggle(FtpAccount $ftp)
    {
        return $this->forward(PanelFtp::class, 'toggle', [], ['ftp' => $ftp]);
    }

    public function destroy(FtpAccount $ftp)
    {
        return $this->forward(PanelFtp::class, 'destroy', [], ['ftp' => $ftp]);
    }
}
