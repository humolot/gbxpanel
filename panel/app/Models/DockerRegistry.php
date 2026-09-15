<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DockerRegistry extends Model
{
    protected $fillable = ['name', 'url', 'username', 'password', 'namespace', 'remark'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted'];
    }

    /** Registry host used by docker login/tag; Docker Hub needs no prefix. */
    public function host(): string
    {
        $host = preg_replace('#^https?://#', '', rtrim($this->url, '/'));

        return in_array($host, ['docker.io', 'index.docker.io', 'registry-1.docker.io', 'hub.docker.com'], true) ? 'docker.io' : $host;
    }

    public function isDockerHub(): bool
    {
        return $this->host() === 'docker.io';
    }

    /** Full reference of an image in this registry: host/namespace/name:tag. */
    public function reference(string $image): string
    {
        $image = ltrim($image, '/');
        if ($this->namespace && ! str_contains($image, '/')) {
            $image = trim($this->namespace, '/').'/'.$image;
        }

        return $this->isDockerHub() ? $image : $this->host().'/'.$image;
    }
}
