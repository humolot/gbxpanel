<?php

namespace App\Http\Controllers\Client;

use App\Models\FtpAccount;
use App\Models\Website;
use App\Services\FileManager;
use App\Services\FtpManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** FTP accounts of the client, always inside one of its websites. */
class ClientFtpController extends ClientPanelController
{
    public function __construct(protected FtpManager $ftp) {}

    public function index()
    {
        $client = $this->client()->load('package');

        return view('client.ftp', [
            'client' => $client,
            'accounts' => $client->ftpAccounts()->with('website:id,domain')->orderBy('username')->get(),
            'sites' => $client->websites()->orderBy('domain')->get(['id', 'domain', 'root_path']),
            'installed' => $this->ftp->installed(),
            'canAdd' => $client->canAdd('ftpAccounts', 'max_ftp'),
        ]);
    }

    /** Resolve a folder inside a website of the client. */
    protected function pathIn(Website $site, ?string $sub): string
    {
        $path = FileManager::normalize($site->root_path.'/'.ltrim((string) $sub, '/'));
        abort_unless($path === $site->root_path || str_starts_with($path, rtrim($site->root_path, '/').'/'), 422, 'The folder must be inside the website.');

        return $path;
    }

    public function store(Request $request)
    {
        if ($error = $this->ensureCanAdd('ftpAccounts', 'max_ftp', 'FTP account(s)')) {
            return $error;
        }
        $data = $request->validate([
            'username' => ['required', 'string', 'regex:/^[a-zA-Z0-9_.-]{3,32}$/', 'unique:ftp_accounts,username'],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'website_id' => ['required', 'integer'],
            'folder' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $site = $this->owned(Website::query()->findOrFail($data['website_id']));
        $path = $this->pathIn($site, $data['folder'] ?? '');

        $result = $this->ftp->create($data['username'], $data['password'], $path);
        if ($result->failed()) {
            return $this->fail($result->message());
        }
        FtpAccount::query()->create([
            'username' => $data['username'], 'password' => $data['password'], 'path' => $path,
            'website_id' => $site->id, 'notes' => $data['notes'] ?? null, 'client_id' => $this->client()->id,
        ]);
        $this->audit('ftp', "Created FTP account {$data['username']}", $path);

        return $this->ok('FTP account created');
    }

    public function password(Request $request, FtpAccount $ftp)
    {
        $this->owned($ftp);
        $data = $request->validate(['password' => ['required', 'string', Password::min(8)->letters()->numbers()]]);
        $result = $this->ftp->changePassword($ftp->username, $data['password']);
        if ($result->ok()) {
            $ftp->update(['password' => $data['password']]);
        }

        return $this->result($result, 'Password changed', 'ftp', $ftp->username);
    }

    public function toggle(FtpAccount $ftp)
    {
        $this->owned($ftp);
        $result = $this->ftp->setActive($ftp->username, ! $ftp->is_active);
        if ($result->ok()) {
            $ftp->update(['is_active' => ! $ftp->is_active]);
        }

        return $this->result($result, $ftp->is_active ? 'FTP account enabled' : 'FTP account disabled', 'ftp', $ftp->username);
    }

    public function destroy(FtpAccount $ftp)
    {
        $this->owned($ftp);
        $result = $this->ftp->delete($ftp->username);
        if ($result->ok()) {
            $ftp->delete();
        }

        return $this->result($result, 'FTP account deleted', 'ftp', $ftp->username);
    }
}
