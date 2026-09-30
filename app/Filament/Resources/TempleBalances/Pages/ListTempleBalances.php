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
        return 'Devotees pay the platform for sevas; the platform settles with each temple once the seva day has passed. Settle to prepare a payout, transfer it from the bank, then mark it paid under Settlements.';
    }

    protected function getHeaderWidgets(): array
    {
        return [FinanceOverviewWidget::class];
    }
}
