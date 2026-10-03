<?php

namespace App\Models\Concerns;

use App\Models\Temple;
use Illuminate\Database\Eloquent\Model;

/**
 * A change to something shown on a temple's page (its timings, sevas,
 * photos, events, closures, other names) marks the temple changed, so the
 * sitemap's date moves and search engines are told about the page.
 *
 * Only the timestamp is written, straight to the table: no temple events
 * run, so a temple team editing its timings is not stopped by the rules
 * for who may change a published listing.
 */
trait MarksTempleChanged
{
    public static function bootMarksTempleChanged(): void
    {
        $mark = function (Model $model): void {
            $ids = array_filter([$model->getAttribute('temple_id'), $model->getOriginal('temple_id')]);
            if ($ids !== []) {
                Temple::withoutGlobalScopes()->whereKey(array_unique($ids))->toBase()->update(['updated_at' => now()]);
            }
        };

        static::saved($mark);
        static::deleted($mark);
    }
}
