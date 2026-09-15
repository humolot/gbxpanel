<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $all = Cache::rememberForever('gbx.settings', fn () => static::query()->pluck('value', 'key')->all());
        } catch (\Throwable) {
            return $default;
        }

        return array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '' ? $all[$key] : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => is_bool($value) ? (int) $value : $value]);
        Cache::forget('gbx.settings');
    }

    public static function secret(string $key, mixed $default = null): mixed
    {
        $value = static::get($key);
        if (! $value) {
            return $default;
        }
        try {
            return decrypt($value);
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function putSecret(string $key, ?string $value): void
    {
        static::put($key, $value ? encrypt($value) : null);
    }
}
