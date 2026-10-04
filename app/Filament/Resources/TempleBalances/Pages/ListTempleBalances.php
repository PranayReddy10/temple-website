<?php

namespace App\Filament\Resources\TempleBalances\Pages;

use App\Filament\Resources\TempleBalances\TempleBalanceResource;
use App\Filament\Widgets\Finance\FinanceOverviewWidget;
use Filament\Resources\Pages\ListRecords;

class ListTempleBalances extends ListRecords
{
    protected static string $resource = TempleBalanceResource::class;

    public function getHeading(): string
    {
        return 'Temple balances';
    }

    public function getSubheading(): ?string
    {
        return 'Devotees pay the platform for sevas; the platform settles with each temple. Settle to prepare a payout (advance bookings included unless you choose an earlier day), transfer it from the bank, then mark it paid with the UTR under Settlements.';
    }

    protected function getHeaderWidgets(): array
    {
        return [FinanceOverviewWidget::class];
    }
}
