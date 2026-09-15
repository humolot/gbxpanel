<?php

namespace App\Services\Ai;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Ai\Tools\AntivirusTools;
use App\Services\Ai\Tools\BackupTools;
use App\Services\Ai\Tools\CronTools;
use App\Services\Ai\Tools\DatabaseTools;
use App\Services\Ai\Tools\DockerTools;
use App\Services\Ai\Tools\FileTools;
use App\Services\Ai\Tools\FirewallTools;
use App\Services\Ai\Tools\ProcessManagerTools;
use App\Services\Ai\Tools\SystemTools;
use App\Services\Ai\Tools\ToolGroup;
use App\Services\Ai\Tools\WebsiteTools;
use Illuminate\Support\Str;

/**
 * Registry of every function the AI can call.
 * "read" tools run automatically, "write" tools need the user's approval
 * (unless auto-approve is enabled for administrators).
 */
class ServerTools
{
    public const MAX_RESULT = 24000;

    /** Tool groups, in the order they are presented to the model. */
    public const GROUPS = [
        'system' => SystemTools::class,
        'files' => FileTools::class,
        'backups' => BackupTools::class,
        'websites' => WebsiteTools::class,
        'databases' => DatabaseTools::class,
        'cron' => CronTools::class,
        'firewall' => FirewallTools::class,
        'antivirus' => AntivirusTools::class,
        'docker' => DockerTools::class,
        'processes' => ProcessManagerTools::class,
    ];

    protected ?array $catalog = null;

    /** name => spec + ['group' => key, 'handler' => ToolGroup] */
    public function catalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $this->catalog = [];
        foreach (self::GROUPS as $key => $class) {
            /** @var ToolGroup $group */
            $group = app($class);
            foreach ($group->tools() as $name => $spec) {
                $this->catalog[$name] = $spec + ['group' => $key, 'handler' => $group];
            }
        }

        return $this->catalog;
    }

    /** OpenAI-style tool definitions allowed for this user. */
    public function definitions(?User $user): array
    {
        $out = [];
        foreach ($this->catalog() as $name => $spec) {
            if (! $this->allowed($name, $user)) {
                continue;
            }
            $out[] = ['type' => 'function', 'function' => ['name' => $name, 'description' => $spec['description'], 'parameters' => $spec['parameters']]];
        }

        return $out;
    }

    public function exists(string $name): bool
    {
        return array_key_exists($name, $this->catalog());
    }

    public function isWrite(string $name): bool
    {
        return (bool) ($this->catalog()[$name]['write'] ?? true);
    }

    public function allowed(string $name, ?User $user): bool
    {
        $spec = $this->catalog()[$name] ?? null;
        if (! $spec || ! $user) {
            return false;
        }

        return (! $spec['write'] || $user->canWrite()) && (! $spec['admin'] || $user->isAdmin());
    }

    public function label(string $name, array $args): string
    {
        $spec = $this->catalog()[$name] ?? null;
        if (! $spec) {
            return $name;
        }
        try {
            return Str::limit(trim(($spec['label'])($args)), 160);
        } catch (\Throwable) {
            return $name;
        }
    }

    /** Summary used by tests and the settings page. */
    public function summary(): array
    {
        return collect($this->catalog())->groupBy('group', true)->map(fn ($tools) => [
            'read' => $tools->where('write', false)->keys()->all(),
            'write' => $tools->where('write', true)->keys()->all(),
        ])->all();
    }

    /**
     * Execute a tool and return a string result for the model.
     * Never throws: errors are returned as JSON so the model can react.
     */
    public function execute(string $name, array $args, ?User $user): string
    {
        if (! $this->allowed($name, $user)) {
            return $this->encode(['error' => "Tool {$name} is not available for this user."]);
        }

        try {
            $result = $this->catalog()[$name]['handler']->handle($name, $args);
            if ($this->isWrite($name)) {
                ActivityLog::record('ai', 'AI action: '.$this->label($name, $args), Str::limit(json_encode($args), 900));
            }
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }

        return $this->encode($result);
    }

    protected function encode(mixed $result): string
    {
        $json = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $json = mb_convert_encoding((string) $json, 'UTF-8', 'UTF-8');

        return mb_strlen($json) > self::MAX_RESULT ? mb_substr($json, 0, self::MAX_RESULT).'... [truncated]' : $json;
    }

    public function rejectReadonly(string $command): ?string
    {
        return SystemTools::rejectReadonly($command);
    }
}
