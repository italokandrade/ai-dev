<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    use Auditable;

    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['key', 'value'];

    // Chaves Fixas (Identidade)
    public const SYSTEM_NAME = 'system_name';
    public const SYSTEM_LOGO = 'system_logo';
    public const SYSTEM_FAVICON = 'system_favicon';
    
    // Chaves Fixas (IA)
    public const OPENROUTER_API_KEY = 'openrouter_api_key';
    public const DEFAULT_OPUS_MODEL = 'default_opus_model';
    public const DEFAULT_SONNET_MODEL = 'default_sonnet_model';
    
    // Chaves Fixas (Operacional)
    public const DEVELOPMENT_ENABLED = 'development_enabled';
    public const MAINTENANCE_MODE = 'maintenance_mode';

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            return Cache::remember("setting:{$key}", 60, function () use ($key, $default) {
                $setting = static::where('key', $key)->first();
                return $setting ? $setting->value : $default;
            });
        } catch (\Throwable) {
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
        return static::get(self::DEVELOPMENT_ENABLED, '0') === '1';
    }

    public static function setDevelopmentEnabled(bool $enabled): void
    {
        static::set(self::DEVELOPMENT_ENABLED, $enabled ? '1' : '0');
    }
}
