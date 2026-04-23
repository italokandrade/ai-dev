<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SystemSetting extends Model
{
    use LogsActivity;

    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['key', 'value'];

    private const SENSITIVE_SUBSTRINGS = ['key', 'secret', 'password', 'token'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['key', 'value'])
            ->logOnlyDirty()
            ->setDescriptionForEvent(fn (string $eventName) => "Config. Sistema {$eventName}: {$this->key}");
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        foreach (self::SENSITIVE_SUBSTRINGS as $substring) {
            if (str_contains($this->key ?? '', $substring)) {
                $props = $activity->properties->toArray();
                if (isset($props['attributes']['value'])) {
                    $props['attributes']['value'] = '••••••';
                }
                if (isset($props['old']['value'])) {
                    $props['old']['value'] = '••••••';
                }
                $activity->properties = collect($props);
                break;
            }
        }
    }

    // Chaves Fixas (Identidade)
    public const SYSTEM_NAME = 'system_name';
    public const SYSTEM_LOGO = 'system_logo';
    public const SYSTEM_FAVICON = 'system_favicon';
    
    // IA Nível PREMIUM (Ex: Opus 4.7)
    public const AI_PREMIUM_PROVIDER = 'ai_premium_provider';
    public const AI_PREMIUM_KEY = 'ai_premium_key';
    public const AI_PREMIUM_MODEL = 'ai_premium_model';

    // IA Nível HIGH (Ex: Sonnet 4.6)
    public const AI_HIGH_PROVIDER = 'ai_high_provider';
    public const AI_HIGH_KEY = 'ai_high_key';
    public const AI_HIGH_MODEL = 'ai_high_model';

    // IA Nível FAST (Ex: Haiku 4.5)
    public const AI_FAST_PROVIDER = 'ai_fast_provider';
    public const AI_FAST_KEY = 'ai_fast_key';
    public const AI_FAST_MODEL = 'ai_fast_model';

    // IA DO SISTEMA (Produção/Interação)
    public const AI_SYSTEM_PROVIDER = 'ai_system_provider';
    public const AI_SYSTEM_KEY = 'ai_system_key';
    public const AI_SYSTEM_MODEL = 'ai_system_model';
    
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
