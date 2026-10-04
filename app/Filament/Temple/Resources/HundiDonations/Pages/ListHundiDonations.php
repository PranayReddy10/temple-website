<?php

namespace App\Filament\Temple\Resources\HundiDonations\Pages;

use App\Filament\Temple\Resources\HundiDonations\HundiDonationResource;
use App\Filament\Temple\TemplePortal;
use App\Models\TempleDonation;
use App\Models\TemplePayoutAccount;
use App\Models\TempleSettlement;
use App\Support\DevotionalClock;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ListHundiDonations extends ListRecords
{
    protected static string $resource = HundiDonationResource::class;

    protected ?string $heading = 'Online hundi';

    public function getSubheading(): string|Htmlable|null
    {
        $temple = TemplePortal::current();
        if ($temple === null) {
            return null;
        }
        $now = DevotionalClock::now();
        $paid = fn () => TempleDonation::query()->where('temple_id', $temple->id)->paid();
        $sum = fn ($q): string => TempleSettlement::rupees((int) $q->sum('amount_paise')).' ('.(clone $q)->count().')';

        return new HtmlString(e($temple->name).': online hundi is <strong>'.($temple->accepts_donations ? 'on' : 'off').'</strong> · today '
            .e($sum($paid()->whereDate('paid_on', $now->toDateString())))
            .' · this month '.e($sum($paid()->whereDate('paid_on', '>=', $now->copy()->startOfMonth()->toDateString())))
            .' · all time '.e($sum($paid())));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggleHundi')
                ->label(fn (): string => TemplePortal::current()?->accepts_donations ? 'Turn online hundi off' : 'Turn online hundi on')
                ->icon('heroicon-m-power')
                ->color(fn (): string => TemplePortal::current()?->accepts_donations ? 'gray' : 'primary')
                // Only the temple's owner decides whether it takes gifts.
                ->visible(fn (): bool => TemplePortal::isOwner(TemplePortal::current()))
                ->requiresConfirmation()
                ->modalDescription(fn (): string => TemplePortal::current()?->accepts_donations
                    ? 'Devotees will no longer see the online hundi for this temple. Gifts already made are kept and paid out.'
                    : 'Devotees can give to this temple in the app and on the website. Gifts are paid out with the temple\'s settlements.')
                ->action(function (): void {
                    $temple = TemplePortal::current();
                    abort_unless(TemplePortal::isOwner($temple), 403);
                    $on = ! $temple->accepts_donations;
                    if ($on && ! $temple->canCollectPayments()) {
                        Notification::make()->title('Not yet')->body(TemplePayoutAccount::NOT_APPROVED_MESSAGE)->warning()->persistent()->send();

                        return;
                    }
                    $temple->forceFill(['accepts_donations' => $on])->save();
                    Notification::make()->title($on ? 'Online hundi is on' : 'Online hundi is off')->success()->send();
                }),
        ];
    }
}
