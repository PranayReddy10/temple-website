<?php

namespace App\Filament\Resources\TempleSuggestions\Pages;

use App\Filament\Resources\TempleSuggestions\TempleSuggestionResource;
use Filament\Resources\Pages\ListRecords;

class ListTempleSuggestions extends ListRecords
{
    protected static string $resource = TempleSuggestionResource::class;

    public function getSubheading(): ?string
    {
        return 'Temples devotees and temple members added from the app. Create a draft temple from one, match it to a temple already listed, or turn it down — the sender sees the answer in the app.';
    }
}
