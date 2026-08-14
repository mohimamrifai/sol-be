<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\SystemConfig;
use App\Support\SystemSettingsSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class SystemSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'group',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();
        $value = $setting?->value ?? $default;

        if ($value !== null && SystemSettingsSchema::isSecret($key)) {
            try {
                return Crypt::decryptString((string) $value);
            } catch (\Throwable) {
                return $value;
            }
        }

        return $value;
    }

    public static function setValue(string $key, mixed $value, string $group = 'general'): self
    {
        Cache::forget('system_settings');
        SystemConfig::flush();

        if (SystemSettingsSchema::isSecret($key) && $value !== null && $value !== '') {
            $value = Crypt::encryptString((string) $value);
        }

        return static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group]
        );
    }

    public static function maskedValue(string $key, mixed $value): mixed
    {
        if (! SystemSettingsSchema::isSecret($key) || $value === null || $value === '') {
            return $value;
        }

        return '********';
    }
}
