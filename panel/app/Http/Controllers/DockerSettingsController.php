<?php

namespace App\Http\Controllers;

use App\Models\DockerRegistry;
use App\Services\Docker\ComposeManager;
use App\Services\Docker\DaemonConfig;
use App\Services\DockerManager;
use App\Services\Shell;
use App\Services\ShellResult;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Docker > Repository (registries) and Settings (service and daemon.json). */
class DockerSettingsController extends Controller
{
    /* ==================================================================== registries */

    public function registries()
    {
        return $this->ok('ok', ['data' => DockerRegistry::query()->orderBy('name')->get()->map(fn (DockerRegistry $r) => $r->only(['id', 'name', 'url', 'username', 'namespace', 'remark', 'created_at']) + [
            'host' => $r->host(),
            'has_password' => (bool) $r->password,
        ])]);
    }

    protected function registryData(Request $request, ?DockerRegistry $registry = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'string', 'max:255', 'regex:#^(https?://)?[a-zA-Z0-9.-]+(:\d{1,5})?/?$#'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:4096'],
            'namespace' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9._\/-]*$/'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);
        if ($registry && ($data['password'] ?? '') === '') {
            unset($data['password']); // keep the stored password
        }
        if (empty($data['username'])) {
            $data['password'] = null;
        }

        return $data;
    }

    public function registryStore(Request $request)
    {
        $registry = DockerRegistry::query()->create($this->registryData($request));
        $this->audit('docker', 'Added registry '.$registry->name, $registry->host());

        return $this->ok('Repository added');
    }

    public function registryUpdate(Request $request, DockerRegistry $registry)
    {
        $registry->update($this->registryData($request, $registry));

        return $this->ok('Repository updated');
    }

    public function registryDestroy(DockerRegistry $registry)
    {
        $name = $registry->name;
        $registry->delete();
        $this->audit('docker', 'Removed registry '.$name);

        return $this->ok('Repository removed');
    }

    /** docker login with a throw-away configuration directory; the password goes through stdin. */
    public function registryTest(DockerRegistry $registry)
    {
        if (! $registry->username) {
            return $this->ok('Anonymous access: nothing to test.');
        }
        if (Shell::simulating()) {
            return $this->ok('Login Succeeded');
        }
        $result = Shell::run('DOCKER_CONFIG=$(mktemp -d /root/.gbx-docker-XXXXXX); export DOCKER_CONFIG; docker login '.Shell::arg($registry->host()).' -u '.Shell::arg((string) $registry->username).' --password-stdin 2>&1; rc=$?; rm -rf "$DOCKER_CONFIG"; exit $rc', 60, (string) $registry->password);

        return $result->ok() ? $this->ok('Login Succeeded') : $this->fail('Login failed: '.trim($result->output.$result->error));
    }

    /* ====================================================================== settings */

    public function settings(DockerManager $docker, DaemonConfig $daemon)
    {
        $installed = $docker->installed();
        $config = $installed ? $daemon->read() : [];

        return $this->ok('ok', [
            'installed' => $installed,
            'running' => $installed && $docker->running(),
            'enabled' => Shell::simulating() || Shell::test('systemctl is-enabled --quiet docker'),
            'info' => $installed && $docker->running() ? $docker->info() : null,
            'projects_root' => ComposeManager::root(),
            'export_dir' => $docker->exportDir(),
            'config' => $config,
            'raw' => DaemonConfig::encode($config),
            'form' => [
                'registry_mirrors' => implode("\n", (array) ($config['registry-mirrors'] ?? [])),
                'insecure_registries' => implode("\n", (array) ($config['insecure-registries'] ?? [])),
                'log_driver' => $config['log-driver'] ?? 'json-file',
                'log_max_size' => $config['log-opts']['max-size'] ?? '',
                'log_max_file' => $config['log-opts']['max-file'] ?? '',
                'live_restore' => (bool) ($config['live-restore'] ?? false),
                'ipv6' => (bool) ($config['ipv6'] ?? false),
                'fixed_cidr_v6' => $config['fixed-cidr-v6'] ?? '',
                'iptables' => ($config['iptables'] ?? true) !== false,
                'data_root' => $config['data-root'] ?? null,
            ],
        ]);
    }

    public function saveSettings(Request $request, DaemonConfig $daemon)
    {
        $request->validate([
            'mode' => ['nullable', Rule::in(['form', 'raw'])],
            'raw' => ['nullable', 'string', 'max:65000'],
            'log_driver' => ['nullable', Rule::in(DaemonConfig::LOG_DRIVERS)],
        ]);

        if ($request->input('mode') === 'raw') {
            $config = json_decode((string) $request->input('raw'), true);
            if (! is_array($config) || array_is_list($config) && $config !== []) {
                return $this->fail('daemon.json must be a JSON object: '.json_last_error_msg());
            }
        } else {
            try {
                $form = $request->only(['registry_mirrors', 'insecure_registries', 'log_driver', 'log_max_size', 'log_max_file', 'fixed_cidr_v6']);
                foreach (['live_restore', 'ipv6', 'iptables'] as $flag) {
                    $form[$flag] = $request->boolean($flag);
                }
                $config = DaemonConfig::merge($daemon->read(), $form);
            } catch (\InvalidArgumentException $e) {
                return $this->fail($e->getMessage());
            }
        }

        return $this->result($daemon->save($config), 'Docker settings saved and Docker restarted', 'docker', DaemonConfig::encode($config));
    }

    public function service(Request $request)
    {
        $data = $request->validate(['action' => ['required', Rule::in(['start', 'stop', 'restart', 'enable', 'disable'])]]);
        $cmd = in_array($data['action'], ['enable', 'disable'], true)
            ? 'systemctl '.$data['action'].' docker docker.socket 2>&1'
            : 'systemctl '.$data['action'].' docker 2>&1';

        return $this->result(Shell::run($cmd, 180), 'Docker service: '.$data['action'], 'docker');
    }
}
