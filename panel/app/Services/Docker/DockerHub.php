<?php

namespace App\Services\Docker;

use App\Services\Shell;
use Illuminate\Support\Facades\Http;

/** Docker Hub search and tags (Docker > Cloud image). */
class DockerHub
{
    public const API = 'https://hub.docker.com/v2';

    public function search(string $query, int $page = 1, int $size = 25): array
    {
        $query = trim($query) ?: 'library';
        if (Shell::simulating() && ! app()->runningUnitTests()) {
            return $this->fakeSearch($query);
        }

        $response = Http::timeout(15)->acceptJson()->get(self::API.'/search/repositories/', ['query' => $query, 'page' => max(1, $page), 'page_size' => max(1, min($size, 100))]);
        if ($response->failed()) {
            throw new \RuntimeException('Docker Hub did not respond (HTTP '.$response->status().'). Check the internet connection of the server.');
        }

        return [
            'total' => (int) $response->json('count', 0),
            'results' => array_map(fn ($r) => [
                'name' => (string) ($r['repo_name'] ?? ''),
                'description' => (string) ($r['short_description'] ?? ''),
                'stars' => (int) ($r['star_count'] ?? 0),
                'pulls' => (int) ($r['pull_count'] ?? 0),
                'official' => (bool) ($r['is_official'] ?? false),
            ], (array) $response->json('results', [])),
        ];
    }

    /** Most recent tags of a repository. */
    public function tags(string $repository): array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]*(\/[a-z0-9][a-z0-9._-]*)?$/', $repository)) {
            throw new \InvalidArgumentException('Invalid repository');
        }
        $path = str_contains($repository, '/') ? $repository : 'library/'.$repository;
        if (Shell::simulating() && ! app()->runningUnitTests()) {
            return ['latest', 'alpine', '1', '1.27', '1.27-alpine'];
        }

        $response = Http::timeout(15)->acceptJson()->get(self::API.'/repositories/'.$path.'/tags', ['page_size' => 50, 'ordering' => 'last_updated']);
        if ($response->failed()) {
            throw new \RuntimeException('Could not load the tags of '.$repository.' (HTTP '.$response->status().').');
        }

        return array_values(array_filter(array_map(fn ($t) => (string) ($t['name'] ?? ''), (array) $response->json('results', []))));
    }

    protected function fakeSearch(string $query): array
    {
        $rows = [
            ['nginx', 'Official build of Nginx.', 20500, 1000000000, true],
            ['redis', 'Redis is the fastest data platform for caching, vector search, and NoSQL databases.', 13100, 1000000000, true],
            ['postgres', 'The PostgreSQL object-relational database system provides reliability and data integrity.', 14000, 1000000000, true],
            ['mysql', 'MySQL is a widely used, open-source relational database management system.', 15600, 1000000000, true],
            ['node', 'Node.js is a JavaScript-based platform for server-side and networking applications.', 13900, 1000000000, true],
            ['qdrant/qdrant', 'Qdrant - vector similarity search engine and vector database', 190, 50000000, false],
            ['n8nio/n8n', 'Free and open fair-code licensed node based Workflow Automation Tool.', 1500, 100000000, false],
        ];
        $q = strtolower($query);
        $rows = array_values(array_filter($rows, fn ($r) => $q === 'library' || str_contains($r[0], $q) || str_contains(strtolower($r[1]), $q)));

        return ['total' => count($rows), 'results' => array_map(fn ($r) => ['name' => $r[0], 'description' => $r[1], 'stars' => $r[2], 'pulls' => $r[3], 'official' => $r[4]], $rows)];
    }
}
