<?php

namespace App\Filament\Resources\SupportTickets\Pages;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Filament\Resources\SupportTickets\SupportTicketResource;
use App\Models\SupportTicket;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * One ticket, and the conversation on it.
 *
 * The reply box is a header action rather than a field on this page so it
 * cannot be filled in and then lost by navigating away, and so that writing
 * a reply and writing an internal note are visibly two different acts.
 */
class ViewSupportTicket extends ViewRecord
{
    protected static string $resource = SupportTicketResource::class;

    public function getHeading(): string
    {
        return $this->record->subject;
    }

    public function getSubheading(): ?string
    {
        $record = $this->record;

        return $record->reference.' · '.$record->kind?->getLabel()
            .' · from '.$record->reporterName()
            .($record->isOpen() ? ' · waiting '.$record->created_at?->diffForHumans(syntax: true) : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')
                ->label('Reply')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->form([
                    Textarea::make('body')
                        ->label('Reply')
                        ->required()
                        ->rows(6)
                        ->helperText('Sent to the person who wrote in. Internal notes go under "Add a note".'),
                ])
                ->action(function (array $data): void {
                    $this->record->messages()->create([
                        'author_type' => User::class,
                        'author_id' => Auth::id(),
                        'body' => $data['body'],
                        'is_internal' => false,
                    ]);

                    // Replying means the ball is in their court, which is
                    // what stops an answered ticket looking untouched.
                    $this->record->update([
                        'status' => TicketStatus::WaitingOnReporter,
                        'assigned_to' => $this->record->assigned_to ?? Auth::id(),
                    ]);
                }),

            Action::make('note')
                ->label('Add a note')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->form([
                    Textarea::make('body')
                        ->label('Internal note')
                        ->required()
                        ->rows(4)
                        ->helperText('Never shown to the person who wrote in.'),
                ])
                ->action(fn (array $data) => $this->record->messages()->create([
                    'author_type' => User::class,
                    'author_id' => Auth::id(),
                    'body' => $data['body'],
                    'is_internal' => true,
                ])),

            Action::make('assign')
                ->label('Assign')
                ->icon('heroicon-o-user-plus')
                ->color('gray')
                ->form([
                    Select::make('assigned_to')
                        ->label('Assign to')
                        ->options(fn (): array => User::query()
                            ->whereIn('role', ['super_admin', 'editor'])
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn () => $this->record->assigned_to)
                        ->native(false)
                        ->placeholder('Nobody'),

                    Select::make('priority')
                        ->options(TicketPriority::class)
                        ->default(fn () => $this->record->priority?->value)
                        ->native(false)
                        ->required(),
                ])
                ->action(fn (array $data) => $this->record->update($data)),

            Action::make('resolve')
                ->label('Resolve')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (): bool => $this->record->isOpen())
                ->form([
                    Textarea::make('resolution_note')
                        ->label('What was done')
                        ->required()
                        ->rows(3),
                ])
                ->action(fn (array $data) => $this->record->update([
                    'status' => TicketStatus::Resolved,
                    'resolved_at' => now(),
                    'resolved_by' => Auth::id(),
                    'resolution_note' => $data['resolution_note'],
                ])),

            Action::make('reopen')
                ->label('Reopen')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => ! $this->record->isOpen())
                ->requiresConfirmation()
                ->action(fn () => $this->record->update([
                    'status' => TicketStatus::Open,
                    'resolved_at' => null,
                    'resolved_by' => null,
                ])),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What they said')
                ->schema([
                    TextEntry::make('body')->hiddenLabel()->prose()->columnSpanFull(),
                ])
                ->columns(1),

            // Three columns, not four: "Inappropriate content" is a long
            // label and a truncated category is worse than a taller card.
            Section::make('Details')
                ->columns(3)
                ->schema([
                    TextEntry::make('kind')->label('Type')->badge(),
                    TextEntry::make('category')->badge()->color('gray'),
                    TextEntry::make('status')->badge(),

                    TextEntry::make('priority')->badge(),

                    TextEntry::make('reporter')
                        ->label('From')
                        ->state(fn (SupportTicket $record): string => $record->reporterName()),

                    TextEntry::make('assignee.name')->label('Assigned to')->placeholder('Nobody'),

                    TextEntry::make('reporter_contact')
                        ->label('Reply to')
                        ->state(fn (SupportTicket $record): string => $record->reporterEmail()
                            ?? 'No address — they cannot be written back to')
                        ->copyable()
                        // An address broken across a line cannot be read back
                        // over the phone, which is what it is usually for.
                        ->extraAttributes(['class' => 'break-all'])
                        ->columnSpan(2),

                    TextEntry::make('created_at')->label('Received')->dateTime('d M Y, H:i'),

                    /*
                     * What it is about, and a way to open it.
                     *
                     * A report you cannot act on from the ticket is a report
                     * that turns into a hunt through the admin.
                     */
                    TextEntry::make('about')
                        ->label('About')
                        ->state(fn (SupportTicket $record): string => $record->aboutLabel() ?? 'Nothing in particular')
                        ->url(fn (SupportTicket $record): ?string => self::subjectUrl($record))
                        ->color(fn (SupportTicket $record): ?string => self::subjectUrl($record) ? 'primary' : null)
                        ->columnSpan(2),



                    TextEntry::make('source')
                        ->label('Came from')
                        ->state(fn (SupportTicket $record): string => collect([
                            $record->source, $record->platform, $record->app_version,
                        ])->filter()->implode(' · ') ?: 'Not recorded'),
                ]),

            Section::make('Resolution')
                ->visible(fn (SupportTicket $record): bool => filled($record->resolution_note))
                ->schema([
                    TextEntry::make('resolution_note')->hiddenLabel()->prose(),
                    TextEntry::make('resolver.name')->label('Resolved by')->placeholder('—'),
                    TextEntry::make('resolved_at')->label('When')->dateTime('d M Y, H:i'),
                ])
                ->columns(2),
        ]);
    }

    /** A link to the reported record, where the admin has a screen for it. */
    protected static function subjectUrl(SupportTicket $ticket): ?string
    {
        $subject = $ticket->about;

        if ($subject === null) {
            return null;
        }

        return match ($subject::class) {
            \App\Models\Temple::class => \App\Filament\Resources\Temples\TempleResource::getUrl('edit', ['record' => $subject]),
            \App\Models\TempleEvent::class => \App\Filament\Resources\TempleEvents\TempleEventResource::getUrl('edit', ['record' => $subject]),
            \App\Models\Devotee::class => \App\Filament\Resources\Devotees\DevoteeResource::getUrl('view', ['record' => $subject]),
            \App\Models\TemplePuja::class => \App\Filament\Resources\TemplePujas\TemplePujaResource::getUrl('edit', ['record' => $subject]),
            default => null,
        };
    }
}
