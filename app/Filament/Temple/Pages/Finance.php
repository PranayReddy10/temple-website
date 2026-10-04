<?php

namespace App\Filament\Temple\Pages;

use App\Filament\Temple\Concerns\PicksTemple;
use App\Filament\Temple\Resources\Settlements\SettlementResource;
use App\Filament\Temple\TemplePortal;
use App\Models\TempleSettlement;
use App\Support\DevotionalClock;
use App\Support\Finance\Settlements;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The temple's money, as the trust app's Finance screen shows it: a day at
 * the temple, the month, the year and all time, what the platform holds for
 * the temple, what has been paid out, and where it is paid.
 */
class Finance extends Page
{
    use PicksTemple;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Finance';

    protected static ?string $slug = 'finance';

    protected string $view = 'filament.temple.pages.finance';

    #[Url]
    public ?string $date = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->isTempleAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->date = $this->validDate($this->date);
    }

    public function updatedDate(mixed $value): void
    {
        $this->date = $this->validDate($value);
    }

    private function validDate(mixed $value): string
    {
        $today = DevotionalClock::now()->toDateString();

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : $today;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $temple = $this->temple()->loadMissing('payoutAccount');
        $settlements = app(Settlements::class);
        $now = DevotionalClock::now();

        return [
            'temple' => $temple,
            'day' => $settlements->day($temple, $this->date),
            'isToday' => $this->date === $now->toDateString(),
            'periods' => [
                'This month' => $settlements->period($temple, $now->copy()->startOfMonth(), $now->copy()->endOfMonth()),
                'This year' => $settlements->period($temple, $now->copy()->startOfYear(), $now->copy()->endOfYear()),
                'All time' => $settlements->period($temple, $now->copy()->subYears(20)->startOfYear(), $now->copy()->addYears(5)->endOfYear()),
            ],
            'balance' => $settlements->balance($temple),
            'recent' => $temple->settlements()->where('status', '!=', TempleSettlement::CANCELLED)->latest('id')->limit(5)->get(),
            'account' => $temple->payoutAccount,
            'owner' => TemplePortal::isOwner($temple),
            'settlementsUrl' => SettlementResource::getUrl(),
            'bankUrl' => BankDetails::getUrl(),
            'rupees' => fn (int $paise): string => TempleSettlement::rupees($paise),
            'percent' => fn (float $p): string => rtrim(rtrim(number_format($p, 2), '0'), '.').'%',
        ];
    }
}
