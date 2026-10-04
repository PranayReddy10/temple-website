<?php

namespace App\Filament\Temple\Concerns;

use App\Filament\Temple\TemplePortal;
use App\Models\Temple;

/**
 * A portal page about one temple, with a picker when the team runs several.
 * The picked temple is re-checked against the user's approved temples on
 * every request, so a crafted id never reaches another temple.
 */
trait PicksTemple
{
    public ?int $templeId = null;

    public function mountPicksTemple(): void
    {
        $this->templeId = TemplePortal::current()?->id;
        abort_if($this->templeId === null, 403, 'This account is not approved for any temple yet.');
    }

    public function updatedTempleId(mixed $value): void
    {
        $this->templeId = TemplePortal::choose($value)?->id;
        $this->templeChanged();
    }

    protected function templeChanged(): void {}

    public function temple(): Temple
    {
        $temple = TemplePortal::temples()->firstWhere('id', $this->templeId) ?? TemplePortal::current();
        abort_if($temple === null, 403);

        return $temple;
    }

    /** @return array<int, string> */
    public function templeOptions(): array
    {
        return TemplePortal::options();
    }
}
