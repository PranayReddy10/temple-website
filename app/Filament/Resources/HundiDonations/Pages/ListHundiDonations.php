<?php

namespace App\Filament\Resources\HundiDonations\Pages;

use App\Filament\Resources\HundiDonations\HundiDonationResource;
use Filament\Resources\Pages\ListRecords;

class ListHundiDonations extends ListRecords
{
    protected static string $resource = HundiDonationResource::class;

    public function getSubheading(): ?string
    {
        return 'Online hundi gifts, settled with each temple under Temple balances. The temple sees "A devotee" for an anonymous gift.';
    }
}
