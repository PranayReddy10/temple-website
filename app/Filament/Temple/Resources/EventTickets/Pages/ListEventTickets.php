<?php

namespace App\Filament\Temple\Resources\EventTickets\Pages;

use App\Filament\Temple\Resources\EventTickets\EventTicketResource;
use Filament\Resources\Pages\ListRecords;

class ListEventTickets extends ListRecords
{
    protected static string $resource = EventTicketResource::class;
}
