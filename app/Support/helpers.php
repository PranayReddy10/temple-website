<?php

use App\Models\Setting;

if (! function_exists('setting')) {
    /**
     * Reads a setting, falling back to config and then to the given default.
     *
     * The config fallback matters: settings are edited in the admin panel but
     * seeded from .env, so a value that has never been touched in the panel
     * still resolves to whatever the environment configured.
     */
    function setting(string $key, ?string $configKey = null, mixed $default = null): mixed
    {
        $value = Setting::get($key);

        if ($value !== null && $value !== '') {
            return $value;
        }

        if ($configKey !== null) {
            return config($configKey, $default);
        }

        return $default;
    }
}
