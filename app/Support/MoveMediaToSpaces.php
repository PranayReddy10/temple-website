<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Moves files already on this server to DigitalOcean Spaces.
 *
 * Switching uploads to Spaces only decides where the *next* file goes; every
 * row keeps the disk its file was written to. This carries the older ones
 * across: copy to the Space, confirm it is there, point the row at the
 * Space, and only then delete the copy on the server. A file that fails stays
 * where it is and is tried again next time, so nothing is ever lost midway.
 *
 * Rows are updated with the query builder on purpose: saving the models
 * would run their observers (a payout account, for one, takes any change to
 * its documents as a reason to need approval again).
 */
final class MoveMediaToSpaces
{
    /** table => [disk column, path columns, private?] */
    public const TARGETS = [
        'temple_photos' => ['disk', ['path', 'medium_path', 'thumbnail_path'], false],
        'temple_suggestion_photos' => ['disk', ['path'], false],
        'visit_photos' => ['disk', ['original_path', 'stamp_path'], false],
        'seva_drive_media' => ['disk', ['path'], false],
        'devotional_media' => ['disk', ['path', 'thumbnail_path'], false],
        'devotees' => ['avatar_disk', ['avatar_path'], false],
        'temple_events' => ['image_disk', ['image_path'], false],
        'temple_pujas' => ['image_disk', ['image_path'], false],
        'deities' => ['image_disk', ['image_path'], false],
        // Identity documents: private on the Space, opened only through
        // the staff link.
        'temple_payout_accounts' => ['kyc_disk', ['aadhaar_front_path', 'aadhaar_back_path', 'temple_proof_path', 'person_photo_path'], true],
    ];

    /** The server's own disks; anything here is "not on Spaces yet". */
    public const LOCAL_DISKS = ['public', 'local'];

    /** Rows whose files are still on this server, by table. */
    public static function remaining(): array
    {
        $out = [];
        foreach (self::TARGETS as $table => [$diskColumn, $paths]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $out[$table] = self::pending($table, $diskColumn, $paths)->count();
        }

        return $out;
    }

    public static function remainingTotal(): int
    {
        return array_sum(self::remaining());
    }

    /**
     * Moves up to $limit rows. Returns [moved rows, files copied, failures, remaining].
     *
     * @return array{moved: int, files: int, failed: array<int, string>, remaining: int}
     */
    public static function run(int $limit = 200, bool $dryRun = false): array
    {
        if (! MediaStorage::spacesIsFilledIn()) {
            return ['moved' => 0, 'files' => 0, 'failed' => ['Spaces is not set up: fill in the key, secret, bucket and endpoint first.'], 'remaining' => self::remainingTotal()];
        }

        $spaces = Storage::disk(MediaStorage::SPACES_DISK);
        $moved = 0;
        $files = 0;
        $failed = [];

        foreach (self::TARGETS as $table => [$diskColumn, $paths, $private]) {
            if ($moved >= $limit || ! Schema::hasTable($table)) {
                continue;
            }

            $rows = self::pending($table, $diskColumn, $paths)->limit($limit - $moved)->get();

            foreach ($rows as $row) {
                $from = $row->{$diskColumn} ?: MediaStorage::LOCAL_DISK;
                $source = Storage::disk($from);
                $copied = [];
                $ok = true;

                foreach ($paths as $column) {
                    $path = $row->{$column} ?? null;
                    if (blank($path)) {
                        continue;
                    }
                    if (! $source->exists($path)) {
                        // Already gone from the server: nothing to carry.
                        continue;
                    }
                    if ($dryRun) {
                        $copied[] = $path;

                        continue;
                    }
                    try {
                        $stream = $source->readStream($path);
                        $written = $spaces->writeStream($path, $stream, ['visibility' => $private ? 'private' : 'public']);
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                        if ($written === false || ! $spaces->exists($path)) {
                            throw new \RuntimeException('the Space did not keep it');
                        }
                        $copied[] = $path;
                    } catch (Throwable $e) {
                        $failed[] = $table.' #'.$row->id.' '.$path.': '.$e->getMessage();
                        $ok = false;
                        break;
                    }
                }

                if (! $ok) {
                    continue;
                }

                if (! $dryRun) {
                    DB::table($table)->where('id', $row->id)->update([$diskColumn => MediaStorage::SPACES_DISK]);
                    foreach ($copied as $path) {
                        $source->delete($path);
                    }
                }

                $moved++;
                $files += count($copied);
            }
        }

        return ['moved' => $moved, 'files' => $files, 'failed' => $failed, 'remaining' => self::remainingTotal()];
    }

    protected static function pending(string $table, string $diskColumn, array $paths): Builder
    {
        return DB::table($table)
            ->where(fn ($q) => $q->whereIn($diskColumn, self::LOCAL_DISKS)->orWhereNull($diskColumn))
            ->where(function ($q) use ($paths): void {
                foreach ($paths as $column) {
                    $q->orWhere(fn ($w) => $w->whereNotNull($column)->where($column, '!=', ''));
                }
            })
            ->orderBy('id');
    }
}
