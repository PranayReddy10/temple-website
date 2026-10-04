<?php

namespace App\Filament\Temple;

use App\Models\Temple;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The temple the portal is showing. A team usually runs one temple; one that
 * runs several picks which, and the choice follows them from the dashboard
 * to finance, bank details and the rest. Only approved temples are ever
 * chosen: anything else falls back to the first of them.
 */
final class TemplePortal
{
    private const SESSION_KEY = 'temple_portal.temple';

    /** @return Collection<int, Temple> the signed-in team's temples, by name */
    public static function temples(): Collection
    {
        $ids = Auth::user()?->approvedTempleIds() ?? [];

        return $ids === [] ? collect() : Temple::query()->whereIn('id', $ids)->orderBy('name')->get();
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return self::temples()->mapWithKeys(fn (Temple $t): array => [$t->id => $t->name.($t->city ? ' · '.$t->city : '')])->all();
    }

    public static function current(): ?Temple
    {
        $temples = self::temples();
        $chosen = (int) session(self::SESSION_KEY);

        return $temples->firstWhere('id', $chosen) ?? $temples->first();
    }

    public static function choose(int|string|null $id): ?Temple
    {
        $temple = self::temples()->firstWhere('id', (int) $id);
        if ($temple !== null) {
            session([self::SESSION_KEY => $temple->id]);
        }

        return self::current();
    }

    /** May this user decide where the temple's money goes? */
    public static function isOwner(?Temple $temple): bool
    {
        return $temple !== null && (Auth::user()?->ownsTemple($temple) ?? false);
    }
}
