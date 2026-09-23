<?php

namespace App\Filament\Resources\SupportTickets\Pages;

use App\Enums\TicketKind;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Filament\Resources\SupportTickets\SupportTicketResource;
use App\Models\SupportTicket;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListSupportTickets extends ListRecords
{
    protected static string $resource = SupportTicketResource::class;

    public function getHeading(): string
    {
        return 'Support & Reports';
    }

    public function getSubheading(): ?string
    {
        $open = SupportTicket::query()->open()->count();

        if ($open === 0) {
            return 'Nothing is waiting. Requests and reports from the app arrive here.';
        }

        $oldest = SupportTicket::query()->open()->oldest()->value('created_at');

        return $open.' open · oldest has been waiting '
            .($oldest?->diffForHumans(syntax: true) ?? 'no time at all');
    }

    public function getTabs(): array
    {
        return [
            'mine' => Tab::make('Mine')
                ->icon('heroicon-m-user')
                ->modifyQueryUsing(fn (Builder $query) => $query->open()->where('assigned_to', Auth::id()))
                ->badge(fn (): int => SupportTicket::query()->open()->where('assigned_to', Auth::id())->count()),

            /*
             * Unclaimed first among the rest.
             *
             * A ticket nobody has picked up is the one most likely to sit
             * unanswered — everything assigned has at least one person who
             * would notice.
             */
            'unassigned' => Tab::make('Nobody has it')
                ->icon('heroicon-m-inbox')
                ->modifyQueryUsing(fn (Builder $query) => $query->open()->unassigned())
                ->badge(fn (): int => SupportTicket::query()->open()->unassigned()->count())
                ->badgeColor('warning'),

            'reports' => Tab::make('Reports')
                ->icon('heroicon-m-flag')
                ->modifyQueryUsing(fn (Builder $query) => $query->open()->where('kind', TicketKind::Report))
                ->badge(fn (): int => SupportTicket::query()->open()->where('kind', TicketKind::Report)->count())
                ->badgeColor('danger'),

            'urgent' => Tab::make('Urgent')
                ->icon('heroicon-m-exclamation-triangle')
                ->modifyQueryUsing(fn (Builder $query) => $query->open()->where('priority', TicketPriority::Urgent))
                ->badge(fn (): int => SupportTicket::query()->open()->where('priority', TicketPriority::Urgent)->count())
                ->badgeColor('danger'),

            'open' => Tab::make('All open')
                ->modifyQueryUsing(fn (Builder $query) => $query->open())
                ->badge(fn (): int => SupportTicket::query()->open()->count()),

            'resolved' => Tab::make('Resolved')
                ->icon('heroicon-m-check-badge')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    TicketStatus::Resolved, TicketStatus::Closed,
                ])),

            'all' => Tab::make('Everything'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        // Land on what nobody has picked up when there is any, otherwise on
        // your own. Opening to "everything" buries the point of the screen.
        return SupportTicket::query()->open()->unassigned()->exists() ? 'unassigned' : 'mine';
    }
}
