<?php

namespace App\Support;

/**
 * Which build of the site is running: the release number in the VERSION
 * file, and the git commit the server's copy is on.
 *
 * Shown in both panels and by `app:deploy`, so "I merged it but nothing
 * changed" can be answered by comparing the commit here with the one on
 * GitHub. The commit is read from .git directly rather than by running git,
 * which shared hosts often forbid from PHP.
 */
final class AppVersion
{
    private static ?array $cached = null;

    public static function number(): string
    {
        return self::read()['number'];
    }

    /** Short commit hash, or null when the site was not deployed from git. */
    public static function commit(): ?string
    {
        return self::read()['commit'];
    }

    /** "v0.8.0 · 627c9ac" */
    public static function label(): string
    {
        return 'v'.self::number().(self::commit() !== null ? ' · '.self::commit() : '');
    }

    /** For tests, and after a deploy changes the files mid-process. */
    public static function flush(): void
    {
        self::$cached = null;
    }

    /** @return array{number: string, commit: ?string} */
    private static function read(): array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $file = base_path('VERSION');
        $number = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return self::$cached = [
            'number' => $number !== '' ? $number : '0.0.0',
            'commit' => self::gitCommit(base_path('.git')),
        ];
    }

    private static function gitCommit(string $git): ?string
    {
        try {
            if (! is_dir($git) || ! is_file($git.'/HEAD')) {
                return null;
            }

            $head = trim((string) file_get_contents($git.'/HEAD'));

            if (! str_starts_with($head, 'ref: ')) {
                // Detached: HEAD is the hash itself.
                return self::short($head);
            }

            $ref = substr($head, 5);

            if (is_file($git.'/'.$ref)) {
                return self::short(trim((string) file_get_contents($git.'/'.$ref)));
            }

            // After `git gc` refs live in packed-refs: "<hash> <ref>" per line.
            if (is_file($git.'/packed-refs')) {
                foreach (file($git.'/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    if (str_ends_with($line, ' '.$ref)) {
                        return self::short(strtok($line, ' '));
                    }
                }
            }
        } catch (\Throwable) {
            // An unreadable .git must never take a page down.
        }

        return null;
    }

    private static function short(string|false $hash): ?string
    {
        return is_string($hash) && preg_match('/^[0-9a-f]{40}$/', $hash) === 1 ? substr($hash, 0, 7) : null;
    }
}
