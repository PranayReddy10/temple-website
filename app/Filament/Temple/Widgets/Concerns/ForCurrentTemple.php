<?php

namespace App\Filament\Temple\Widgets\Concerns;

use App\Filament\Temple\TemplePortal;
use App\Models\Temple;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Facades\Auth;

/** A dashboard widget about the temple picked above it (always one of the team's own). */
trait ForCurrentTemple
{
    use InteractsWithPageFilters;

    protected function temple(): ?Temple
    {
        return TemplePortal::choose($this->pageFilters['temple'] ?? null);
    }

    public static function canView(): bool
    {
        return (Auth::user()?->isTempleAdmin() ?? false) && TemplePortal::current() !== null;
    }
}
