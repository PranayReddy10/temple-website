<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Key/value settings editable from the admin panel.
 *
 * Reads are served from a single cached array rather than a query per key,
 * because the brand name is resolved on every page render.
 *
 * Deliberately NOT named all(): that is an Eloquent method with different
 * semantics, and shadowing it would mislead every reader and every call site.
 */
class Setting extends Model
{
    public const CACHE_KEY = 'settings.all';

    protected $fillable = ['key', 'value', 'type'];

    protected static function booted(): void
    {
        static::saved(fn () => static::flush());
        static::deleted(fn () => static::flush());
    }

    public static function flush(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // A cache store that is not reachable must not break saving a
            // setting; the read path tolerates a stale or missing cache.
        }
    }

    /**
     * Every setting as a key => cast-value map.
     *
     * Returns an empty array rather than throwing when the database or cache
     * store is unavailable. Settings are read while the panel boots, and that
     * happens during `migrate` on a fresh database, before either table
     * exists — an exception there would make the app impossible to install.
     */
    public static function values(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn (): array => static::readFromDatabase());
        } catch (Throwable) {
            // Cache store unavailable: fall back to reading directly, which
            // has its own guard.
            return static::readFromDatabase();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        // A stored null means "cleared", which falls back to the default the
        // same way an absent key does.
        return static::values()[$key] ?? $default;
    }

    public static function set(string $key, mixed $value, string $type = 'string'): void
    {
        if ($type === 'secret' && filled($value)) {
            // Encrypted at rest with the app key. The cached settings array
            // holds the ciphertext too; secret() decrypts at the point of use.
            $value = Crypt::encryptString((string) $value);
        }

        static::updateOrCreate(
            ['key' => $key],
            [
                'value' => is_array($value) ? json_encode($value) : $value,
                'type' => $type,
            ],
        );
    }

    /**
     * A credential saved from the admin panel: a payment gateway's secret, a
     * push service account. Null when unset or when it cannot be decrypted
     * (an APP_KEY that changed since it was saved), never the ciphertext.
     */
    public static function secret(string $key): ?string
    {
        $stored = static::get($key);

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return null;
        }
    }

    protected static function readFromDatabase(): array
    {
        try {
            if (! Schema::hasTable('settings')) {
                return [];
            }

            return static::query()
                ->get(['key', 'value', 'type'])
                ->mapWithKeys(fn (Setting $setting): array => [
                    $setting->key => $setting->castValue($setting->value, $setting->type),
                ])
                ->all();
        } catch (Throwable) {
            // No database yet, or it is unreachable. Callers fall back to
            // config, which is always present.
            return [];
        }
    }

    protected function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL),
            'integer' => (int) $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }
}
