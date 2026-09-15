<?php

namespace App\Http\Controllers;

use App\Services\PhpManager;
use App\Services\SoftwareManager;
use App\Services\TaskRunner;
use Illuminate\Http\Request;

class SoftwareController extends Controller
{
    public function __construct(protected SoftwareManager $software) {}

    public function index()
    {
        return view('home.software');
    }

    public function data(Request $request)
    {
        return response()->json(['data' => $this->software->all($request->boolean('fresh'))]);
    }

    public function install(Request $request)
    {
        $data = $request->validate(['key' => ['required', 'string'], 'version' => ['nullable', 'string']]);
        $item = $this->software->get($data['key']);
        if (! $item) {
            return $this->fail('Unknown package');
        }

        $installedKeys = collect($this->software->all(true))->where('installed', true)->pluck('key')->all();
        foreach ($item['conflicts'] ?? [] as $conflict) {
            if (in_array($conflict, $installedKeys, true)) {
                return $this->fail("{$item['name']} conflicts with the installed ".$this->software->get($conflict)['name'].'.');
            }
        }

        return $this->task($this->software->install($data['key'], $data['version'] ?? null), 'Installation started');
    }

    public function uninstall(Request $request)
    {
        $data = $request->validate(['key' => ['required', 'string'], 'version' => ['nullable', 'string']]);
        if ($data['key'] === 'php' && ($data['version'] ?? null) === '8.4') {
            return $this->fail('PHP 8.4 runs GBX Panel itself and cannot be removed.');
        }

        return $this->task($this->software->uninstall($data['key'], $data['version'] ?? null), 'Removal started');
    }

    public function php(string $version, PhpManager $php)
    {
        abort_unless(PhpManager::validVersion($version), 404);

        return $this->ok('ok', [
            'version' => $version,
            'settings' => $php->settings($version),
            'definitions' => collect(PhpManager::SETTINGS)->map(fn ($d) => $d['label']),
            'extensions' => $php->extensions($version),
            'ini' => $php->iniPath($version),
        ]);
    }

    public function phpSave(string $version, Request $request, PhpManager $php)
    {
        abort_unless(PhpManager::validVersion($version), 404);

        return $this->result($php->saveSettings($version, $request->input('settings', [])), "PHP {$version} settings saved", 'software');
    }

    public function phpExtension(string $version, Request $request, PhpManager $php)
    {
        abort_unless(PhpManager::validVersion($version), 404);
        $ext = (string) $request->input('extension');

        return $this->task(TaskRunner::dispatch("Install php{$version}-{$ext}", $php->installExtensionScript($version, $ext), 'software'));
    }
}
