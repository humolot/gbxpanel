<?php

namespace App\Http\Controllers;

use App\Services\Docker\AppStore;
use App\Services\Docker\ComposeManager;
use App\Services\Docker\DockerHub;
use Illuminate\Http\Request;

/** Docker > One-Click Install and Cloud image (Docker Hub). */
class DockerStoreController extends Controller
{
    public function apps(AppStore $store)
    {
        $installed = $store->installed();
        $apps = [];
        foreach ($store->apps() as $slug => $app) {
            $apps[] = [
                'slug' => $slug,
                'name' => $app['name'],
                'category' => $app['category'],
                'icon' => $app['icon'] ?? 'bi-box',
                'color' => $app['color'] ?? '#3a3f47',
                'description' => $app['description'] ?? '',
                'website' => $app['website'] ?? null,
                'fields' => collect($app['fields'] ?? [])->map(fn ($f, $key) => ['key' => $key] + $f + ['default' => null])->values(),
                'compose' => $app['compose'],
                'installed' => $installed[$slug] ?? [],
            ];
        }

        return $this->ok('ok', ['categories' => $store->categories(), 'apps' => $apps]);
    }

    public function install(Request $request, AppStore $store, string $slug)
    {
        $app = $store->app($slug) ?? abort(404, 'Unknown app');
        $data = $request->validate([
            'project' => ['required', 'string', 'max:63'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['nullable', 'string', 'max:255'],
            'external' => ['nullable', 'boolean'],
        ]);
        if (! ComposeManager::validName($data['project'])) {
            return $this->fail('Project name: lowercase letters, numbers, - and _ (max 63).');
        }

        $result = $store->install($slug, $data['project'], $data['fields'] ?? [], $request->boolean('external'));
        $this->audit('docker', "Installed {$app['name']} as {$result['project']}", 'Ports: '.implode(', ', $result['ports']));

        return $this->task($result['task'], "Installing {$app['name']}");
    }

    public function hubSearch(Request $request, DockerHub $hub)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        try {
            return $this->ok('ok', $hub->search((string) ($data['q'] ?? ''), (int) ($data['page'] ?? 1)));
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 502);
        }
    }

    public function hubTags(Request $request, DockerHub $hub)
    {
        try {
            return $this->ok('ok', ['tags' => $hub->tags((string) $request->query('repo'))]);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->fail($e->getMessage(), $e instanceof \RuntimeException ? 502 : 422);
        }
    }
}
