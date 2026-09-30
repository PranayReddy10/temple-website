<?php

namespace App\Filament\Temple\Resources\Settlements\Pages;

use App\Filament\Temple\Resources\Settlements\SettlementResource;
use Filament\Resources\Pages\ListRecords;

class ListSettlements extends ListRecords
{
    protected static string $resource = SettlementResource::class;

    public function getSubheading(): ?string
    {
        return 'Devotees pay for sevas through the platform; it pays your temple once the seva day has passed, less the platform fee. Payout details are set in the Temple Trust app by the temple\'s owner.';
    }
}
