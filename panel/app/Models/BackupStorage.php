<?php

namespace App\Models;

use App\Services\Backup\StorageManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackupStorage extends Model
{
    protected $fillable = ['type', 'name', 'credentials', 'folder', 'is_active', 'bwlimit', 'account', 'used_bytes', 'total_bytes', 'checked_at', 'last_error'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'used_bytes' => 'integer',
            'total_bytes' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(BackupTransfer::class, 'storage_id');
    }

    public function definition(): array
    {
        return StorageManager::TYPES[$this->type] ?? ['name' => $this->type, 'group' => 'server', 'fields' => []];
    }

    public function typeName(): string
    {
        return $this->definition()['name'];
    }

    public function label(): string
    {
        return $this->name.' ('.$this->typeName().')';
    }

    public function credential(string $key, mixed $default = ''): mixed
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    /** Credentials for edit forms: secrets and tokens are never sent back to the browser. */
    public function maskedCredentials(): array
    {
        $fields = $this->definition()['fields'] ?? [];
        $out = [];
        foreach ($this->credentials ?? [] as $key => $value) {
            $type = $fields[$key]['type'] ?? 'text';
            $out[$key] = in_array($type, ['password', 'token', 'secret_text'], true) ? '' : $value;
        }

        return $out;
    }

    /** Whether a secret field already has a stored value (shown as "stored" in forms). */
    public function storedSecrets(): array
    {
        $fields = $this->definition()['fields'] ?? [];

        return array_values(array_filter(array_keys($this->credentials ?? []), fn ($key) => in_array($fields[$key]['type'] ?? 'text', ['password', 'token', 'secret_text'], true) && ($this->credentials[$key] ?? '') !== ''));
    }
}
