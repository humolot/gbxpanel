<?php

namespace App\Http\Controllers;

use App\Models\FtpAccount;
use App\Models\Website;
use App\Services\FileManager;
use App\Services\FtpManager;
use App\Services\SystemStats;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class FtpController extends Controller
{
    public function __construct(protected FtpManager $ftp) {}

    public function index(SystemStats $stats)
    {
        return view('ftp.index', [
            'accounts' => FtpAccount::query()->with('website:id,domain')->orderBy('username')->get(),
            'websites' => Website::query()->orderBy('domain')->get(['id', 'domain', 'root_path']),
            'installed' => $this->ftp->installed(),
            'status' => $this->ftp->installed() ? $this->ftp->status() : null,
            'host' => $stats->publicIp(),
            'wwwRoot' => config('gbx.paths.www'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'regex:/^[a-zA-Z0-9_.-]{3,32}$/', 'unique:ftp_accounts,username'],
            'password' => ['required', 'string', Password::min(8)],
            'path' => ['required', 'string', 'max:255'],
            'website_id' => ['nullable', 'exists:websites,id'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $data['path'] = FileManager::normalize($data['path']);
        if (in_array($data['path'], ['/', '/etc', '/root', '/usr', '/var', '/boot', '/proc', '/sys'], true)) {
            return $this->fail('This directory cannot be used for FTP.');
        }

        $result = $this->ftp->create($data['username'], $data['password'], $data['path']);
        if ($result->failed()) {
            return $this->fail($result->message());
        }
        FtpAccount::query()->create($data);
        $this->audit('ftp', "Created FTP account {$data['username']}", $data['path']);

        return $this->ok('FTP account created');
    }

    public function update(Request $request, FtpAccount $ftp)
    {
        $data = $request->validate([
            'password' => ['nullable', 'string', Password::min(8)],
            'path' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($data['password'])) {
            $r = $this->ftp->changePassword($ftp->username, $data['password']);
            if ($r->failed()) {
                return $this->fail($r->message());
            }
            $ftp->password = $data['password'];
        }

        $path = FileManager::normalize($data['path']);
        if ($path !== $ftp->path) {
            $r = $this->ftp->changePath($ftp->username, $path);
            if ($r->failed()) {
                return $this->fail($r->message());
            }
            $ftp->path = $path;
        }
        $ftp->notes = $data['notes'] ?? null;
        $ftp->save();
        $this->audit('ftp', "Updated FTP account {$ftp->username}");

        return $this->ok('FTP account updated');
    }

    public function toggle(FtpAccount $ftp)
    {
        $result = $this->ftp->setActive($ftp->username, ! $ftp->is_active);
        if ($result->failed()) {
            return $this->fail($result->message());
        }
        $ftp->update(['is_active' => ! $ftp->is_active]);

        return $this->ok($ftp->is_active ? 'Account enabled' : 'Account disabled');
    }

    public function destroy(FtpAccount $ftp)
    {
        $this->ftp->delete($ftp->username);
        $this->audit('ftp', "Deleted FTP account {$ftp->username}");
        $ftp->delete();

        return $this->ok('FTP account deleted');
    }
}
