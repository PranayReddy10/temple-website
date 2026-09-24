<?php

namespace App\Filament\Temple\Pages;

use App\Filament\Concerns\ScansDevoteePassports;
use App\Models\Temple;
use App\Support\DevotionalClock;
use App\Support\StaffCheckIn;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/**
 * The temple counter: scan a devotee's passport, see their stamps, and mark
 * today's visit to this temple.
 */
class ScanPassport extends Page
{
    use ScansDevoteePassports;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $title = 'Scan a devotee passport';

    protected static ?string $navigationLabel = 'Scan passport';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'scan-passport';

    protected string $view = 'filament.passport.scan';

    public static function canAccess(): bool
    {
        return Auth::user()?->isTempleAdmin() ?? false;
    }

    public function markVisited(int $templeId): void
    {
        $devotee = $this->scannedDevotee();
        // Through the user's own approved temples, never Temple::find: an id
        // from the browser is only a suggestion.
        $temple = Auth::user()->temples()->whereKey($templeId)->first();

        if ($devotee === null || ! $temple instanceof Temple) {
            Notification::make()->title('Scan the passport again.')->danger()->send();

            return;
        }

        try {
            $result = StaffCheckIn::mark($devotee, $temple, Auth::user());
        } catch (AuthorizationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title(match ($result['outcome']) {
                StaffCheckIn::CREATED => $devotee->name.' is marked as visited today. The stamp is in their passport.',
                StaffCheckIn::VERIFIED => 'Their visit today is now verified.',
                default => $devotee->name.' already has today\'s stamp for '.$temple->name.'.',
            })
            ->success()
            ->send();
    }

    protected function getViewData(): array
    {
        $devotee = $this->scannedDevotee();
        $today = DevotionalClock::now()->toDateString();
        $temples = Auth::user()->temples()->orderBy('name')->get(['temples.id', 'temples.name', 'temples.city', 'temples.status']);

        return [
            'passport' => $this->passportData(),
            'canMark' => true,
            'recordUrl' => null,
            'temples' => $temples->map(fn (Temple $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'city' => $t->city,
                'published' => $t->status === \App\Enums\TempleStatus::Published,
                'today' => $devotee === null ? null : $devotee->visits()
                    ->where('temple_id', $t->id)
                    ->whereDate('visited_on', $today)
                    ->orderByDesc('is_verified')
                    ->value('is_verified'),
            ])->all(),
        ];
    }
}
