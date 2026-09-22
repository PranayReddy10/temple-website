<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards against re-introducing the cast that took the live panel down.
 *
 * `SomeEnum::tryFrom((string) $get('field'))` throws a TypeError whenever the
 * form state holds an enum instance rather than its string value, which is
 * what happens for a field default or a cast model attribute.
 *
 * This is checked by reading the source rather than by rendering, and that is
 * deliberate. The failing paths are `visible()` and `required()` closures that
 * only run for particular field combinations, reached through Filament's lazy
 * loading — mounting the components in a test does not evaluate them, so a
 * render test passes while the panel is broken. That was confirmed by
 * restoring the bug and watching the render tests stay green.
 *
 * App\Support\FormState::enum() handles both shapes; use it instead.
 */
class NoUnsafeEnumCastTest extends TestCase
{
    public function test_no_filament_schema_casts_form_state_to_string_for_an_enum(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(dirname(__DIR__, 2).'/app/Filament') as $file) {
            $contents = file_get_contents($file);

            // Matches ::tryFrom((string) $get(...)) and ::from((string) $get(...))
            if (preg_match('/::(try)?from\(\s*\(string\)\s*\$get\(/i', $contents)) {
                $offenders[] = str_replace(dirname(__DIR__, 2).'/', '', $file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Unsafe enum cast from form state in:\n  ".implode("\n  ", $offenders)
                ."\nUse App\\Support\\FormState::enum() — casting form state to string throws"
                ." when it holds an enum instance.",
        );
    }

    /** @return array<int, string> */
    protected function phpFilesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
