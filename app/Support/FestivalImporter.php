<?php

namespace App\Support;

use App\Models\Festival;
use RuntimeException;

/**
 * Loads computed festival dates into the festivals table. Adds what is not
 * there and never touches a row already loaded, so an editor's correction
 * survives every later import.
 */
final class FestivalImporter
{
    public const DEFAULT_FILE = 'data/festivals.json';

    /** @return array{added: int, kept: int} */
    public static function import(?string $path = null): array
    {
        $path ??= database_path(self::DEFAULT_FILE);

        if (! is_file($path)) {
            throw new RuntimeException("No festival data at {$path}.");
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows)) {
            throw new RuntimeException("{$path} is not a JSON list.");
        }

        $added = 0;
        foreach ($rows as $row) {
            // Compared as a date: stored dates may carry a time part.
            if (Festival::query()->where('slug', $row['slug'])->whereDate('computed_on', $row['date'])->exists()) {
                continue;
            }

            Festival::query()->create([
                'slug' => $row['slug'],
                'computed_on' => $row['date'],
                'name' => $row['name'],
                'starts_on' => $row['date'],
                'ends_on' => $row['ends_on'] ?? null,
                'kind' => $row['kind'] ?? 'festival',
                'is_major' => (bool) ($row['is_major'] ?? false),
                'deity' => $row['deity'] ?? null,
                'description' => $row['description'] ?? null,
                'tithi' => $row['tithi'] ?? null,
            ]);
            $added++;
        }

        return ['added' => $added, 'kept' => count($rows) - $added];
    }
}
