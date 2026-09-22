<?php

namespace App\Filament\Support;

use Closure;
use Filament\Tables\Columns\ImageColumn;

/**
 * An image column that resolves its own URL from the row's disk.
 *
 * Every one of these used to be handed a finished URL through ->state(). That
 * worked only because the URL happened to be absolute: Filament treats state
 * as a storage path unless it validates as a URL, and the local disk's URL
 * was absolute only because it was built from APP_URL — which is
 * http://localhost until someone changes it, so in production these were
 * absolute and pointing at the visitor's own machine.
 *
 * Making the local disk root-relative fixed that everywhere else and broke it
 * here, which is the tell: a column should be given the path and the disk and
 * let the framework build the URL, rather than each caller building one and
 * the column guessing what it was handed.
 *
 * The disk is per row because each media row records its own — that is what
 * lets photos survive a move between storage backends.
 */
class MediaColumn
{
    /**
     * @param  string  $name  the column name
     * @param  Closure  $path  given the record, returns the storage path or null
     * @param  Closure|null  $disk  given the record, returns its disk
     */
    public static function make(string $name, Closure $path, ?Closure $disk = null): ImageColumn
    {
        return ImageColumn::make($name)
            ->state($path)
            ->disk($disk ?? fn ($record): string => $record->disk ?? config('filesystems.media'))
            // A HEAD request per row against object storage, to decide
            // whether to render an img tag. A missing file shows as a broken
            // thumbnail; a page of round trips shows as a slow admin.
            ->checkFileExistence(false);
    }
}
