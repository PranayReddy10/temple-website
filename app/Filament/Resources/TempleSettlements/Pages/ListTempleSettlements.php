<?php

namespace App\Filament\Resources\TempleSettlements\Pages;

use App\Filament\Resources\TempleBalances\TempleBalanceResource;
use App\Filament\Resources\TempleSettlements\TempleSettlementResource;
use App\Filament\Widgets\Finance\FinanceOverviewWidget;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListTempleSettlements extends ListRecords
{
    protected static string $resource = TempleSettlementResource::class;

    public function getHeading(): string
    {
        return 'Settlements';
    }

    public function getSubheading(): ?string
    {
        return 'Payouts to temples. Transfer the amount "To temple" from the bank, then mark it paid with the UTR: the temple sees it in its app and portal.';
    }

    protected function getHeaderWidgets(): array
    {
        return [FinanceOverviewWidget::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('balances')
                ->label('Temple balances')
                ->icon('heroicon-o-building-library')
                ->color('gray')
                ->url(TempleBalanceResource::getUrl('index')),
        ];
    }
}
