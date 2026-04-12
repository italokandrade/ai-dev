<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            return Cache::remember("setting:{$key}", 60, function () use ($key, $default) {
                return static::where('key', $key)->value('value') ?? $default;
            });
        } catch (\Throwable) {
            // Table may not exist yet (before migrations run)
            return $default;
        }
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => (string) $value]);
        Cache::forget("setting:{$key}");
    }

    public static function isDevelopmentEnabled(): bool
    {
        return static::get('development_enabled', '0') === '1';
    }

    public static function setDevelopmentEnabled(bool $enabled): void
    {
        static::set('development_enabled', $enabled ? '1' : '0');
    }
}
