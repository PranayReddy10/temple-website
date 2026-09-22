<?php

namespace App\Support;

/**
 * Builds links into a resource list with its filters already applied.
 *
 * A dashboard number that cannot be clicked makes you find the same records
 * again by hand, and a hand-built query string gets the shape wrong quietly:
 * Filament binds the filter state to `filters` in the URL, not `tableFilters`
 * as the Livewire property is named, and it coerces only the literal strings
 * "true", "false" and "null" back to their types. Both details live here once.
 */
final class AdminLinks
{
    /**
     * @param  array<string, array<string, mixed>>  $filters  keyed by filter name
     */
    public static function filtered(string $url, array $filters): string
    {
        if ($filters === []) {
            return $url;
        }

        return $url
            .(str_contains($url, '?') ? '&' : '?')
            .http_build_query(['filters' => $filters]);
    }

    /**
     * State for a SelectFilter marked ->multiple().
     *
     * @param  array<int, string>|string  $values
     * @return array<string, array<int, string>>
     */
    public static function selected(array|string $values): array
    {
        return ['values' => array_values((array) $values)];
    }

    /**
     * State for a SelectFilter that takes one value.
     *
     * @return array<string, string>
     */
    public static function is(string $value): array
    {
        return ['value' => $value];
    }

    /**
     * State for a ->toggle() filter.
     *
     * The literal string is deliberate: Filament rewrites "true" to a real
     * boolean when reading the query string, whereas http_build_query would
     * otherwise send 1 and leave the toggle looking off while filtering on.
     *
     * @return array<string, string>
     */
    public static function on(): array
    {
        return ['isActive' => 'true'];
    }
}
