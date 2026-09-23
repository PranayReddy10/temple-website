<?php

namespace App\Filament\Resources\TemplePujas\Pages;

use App\Filament\Resources\TemplePujas\TemplePujaResource;
use Filament\Resources\Pages\ListRecords;

class ListTemplePujas extends ListRecords
{
    protected static string $resource = TemplePujaResource::class;

    public function getHeading(): string
    {
        return 'Puja & Sevas';
    }

    public function getSubheading(): ?string
    {
        return 'Every temple\'s sevas in one place. Add them inside a temple; check prices and booking links here.';
    }
}
