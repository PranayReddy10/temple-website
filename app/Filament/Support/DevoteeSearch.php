<?php

namespace App\Filament\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The admin lists' search box, for the person: their name, or their phone
 * number however it was saved ("+91 98480 22338" is found by 9848022338,
 * by 22338, or as typed), on the booking itself and on their account.
 */
final class DevoteeSearch
{
    /**
     * @param  string  $nameColumn  the name written on the booking or gift
     * @param  string|null  $phoneColumn  the number given with it, if the row keeps one
     */
    public static function query(string $nameColumn = 'devotee_name', ?string $phoneColumn = 'devotee_phone'): Closure
    {
        return function (Builder $query, string $search) use ($nameColumn, $phoneColumn): Builder {
            $search = trim($search);
            $digits = preg_replace('/\D/', '', $search);
            $phone = strlen($digits) >= 4 && preg_match('/[A-Za-z]/', $search) !== 1 ? substr($digits, -10) : null;

            return $query->where(function (Builder $w) use ($search, $phone, $nameColumn, $phoneColumn): void {
                $w->where($nameColumn, 'like', "%{$search}%")
                    ->orWhereHas('devotee', fn (Builder $d) => $d->where('name', 'like', "%{$search}%"));

                if ($phone !== null) {
                    if ($phoneColumn !== null) {
                        $w->orWhereRaw(self::digitsOf($phoneColumn).' like ?', ["%{$phone}%"]);
                    }
                    $w->orWhereHas('devotee', fn (Builder $d) => $d->whereRaw(self::digitsOf('phone').' like ?', ["%{$phone}%"]));
                }
            });
        };
    }

    /** A phone column with spaces, dashes, + and brackets taken out. */
    public static function digitsOf(string $column): string
    {
        return "replace(replace(replace(replace(replace({$column}, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";
    }
}
