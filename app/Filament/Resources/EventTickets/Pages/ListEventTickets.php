<?php

namespace App\Filament\Resources\EventTickets\Pages;

use App\Filament\Resources\EventTickets\EventTicketResource;
use Filament\Resources\Pages\ListRecords;

class ListEventTickets extends ListRecords
{
    protected static string $resource = EventTicketResource::class;

    public function getSubheading(): ?string
    {
        return '"I\'ll join" places and paid tickets for temple events and bhajan gatherings. Paid ones are settled with the temple under Temple balances.';
    }
}
