<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPages extends ListRecords
{
    protected static string $resource = PageResource::class;

    public function getSubheading(): ?string
    {
        return 'Privacy policy, terms, refunds, account deletion and the other pages linked in the website\'s footer. The default text is a starting point: have it checked before relying on it.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New page')];
    }
}
