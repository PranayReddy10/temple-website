<?php

namespace App\Filament\Temple\Resources\SupportTickets\Pages;

use App\Enums\TicketCategory;
use App\Filament\Temple\Resources\SupportTickets\SupportTicketResource;
use App\Filament\Temple\TemplePortal;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListSupportTickets extends ListRecords
{
    protected static string $resource = SupportTicketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new')
                ->label('Ask our team')
                ->icon('heroicon-m-plus')
                ->modalHeading('New support request')
                ->schema([
                    Select::make('temple_id')->label('About')->options(fn (): array => TemplePortal::options())
                        ->default(fn (): ?int => TemplePortal::current()?->id)->placeholder('Not about one temple'),
                    Select::make('category')->options(collect(TicketCategory::cases())->mapWithKeys(fn (TicketCategory $c): array => [$c->value => $c->getLabel()])->all())
                        ->default(TicketCategory::Other->value)->required(),
                    TextInput::make('subject')->required()->maxLength(200),
                    Textarea::make('body')->label('Message')->required()->maxLength(5000)->rows(6),
                ])
                ->action(function (array $data): void {
                    $ticket = SupportTicketResource::open(Auth::user(), $data);
                    Notification::make()->title('Sent to our team')->body('Reference '.$ticket->reference.'. Replies appear here.')->success()->send();
                    $this->redirect(SupportTicketResource::getUrl('view', ['record' => $ticket]));
                }),
        ];
    }
}
