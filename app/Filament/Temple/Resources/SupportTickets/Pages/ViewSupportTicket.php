<?php

namespace App\Filament\Temple\Resources\SupportTickets\Pages;

use App\Enums\TicketStatus;
use App\Filament\Temple\Resources\SupportTickets\SupportTicketResource;
use App\Models\SupportTicket;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

/** One request and its conversation with our team (never the internal notes). */
class ViewSupportTicket extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SupportTicketResource::class;

    protected string $view = 'filament.temple.pages.support-ticket';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->subject;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')
                ->label('Reply')
                ->icon('heroicon-m-chat-bubble-left')
                ->schema([Textarea::make('body')->label('Your reply')->required()->maxLength(5000)->rows(5)])
                ->action(function (array $data): void {
                    /** @var SupportTicket $ticket */
                    $ticket = $this->getRecord();
                    $ticket->messages()->create([
                        'author_type' => User::class,
                        'author_id' => Auth::id(),
                        'body' => $data['body'],
                        'is_internal' => false,
                    ]);
                    // Their reply puts it back with our team, even if resolved.
                    $ticket->update(['status' => TicketStatus::Open]);
                    Notification::make()->title('Reply sent')->success()->send();
                }),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var SupportTicket $ticket */
        $ticket = $this->getRecord();

        return ['ticket' => $ticket, 'replies' => $ticket->replies()->with('author')->get()];
    }
}
