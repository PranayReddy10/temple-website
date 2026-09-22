<?php

namespace App\Filament\Resources\Devotees\Pages;

use App\Filament\Resources\Devotees\DevoteeResource;
use Filament\Resources\Pages\ListRecords;

class ListDevotees extends ListRecords
{
    protected static string $resource = DevoteeResource::class;

    public function getHeading(): string
    {
        return 'Devotee Accounts';
    }

    public function getSubheading(): ?string
    {
        return 'Read-only. Devotees manage their own details in the app; staff can look into an account and suspend one that is being abused.';
    }
}
