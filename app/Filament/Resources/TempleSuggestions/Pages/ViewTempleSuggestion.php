<?php

namespace App\Filament\Resources\TempleSuggestions\Pages;

use App\Enums\TempleSuggestionStatus;
use App\Filament\Resources\Devotees\DevoteeResource;
use App\Filament\Resources\TempleSuggestions\TempleSuggestionResource;
use App\Filament\Resources\Temples\TempleResource;
use App\Models\Temple;
use App\Models\TempleSuggestion;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ViewTempleSuggestion extends ViewRecord
{
    protected static string $resource = TempleSuggestionResource::class;

    public function getHeading(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): ?string
    {
        $r = $this->record;

        return $r->status?->getLabel().' · sent by '.($r->submitter_name ?? $r->devotee?->name ?? 'a devotee')
            .' ('.$r->roleLabel().') · '.$r->created_at?->diffForHumans();
    }

    protected function getHeaderActions(): array
    {
        $pending = fn (): bool => $this->record->status === TempleSuggestionStatus::Pending;

        return [
            Action::make('create_temple')
                ->label('Create temple')
                ->icon('heroicon-o-plus')
                ->color('success')
                ->visible($pending)
                ->requiresConfirmation()
                ->modalDescription('Creates a draft temple with these details and photographs (unpublished), and opens it so you can check and publish it.')
                ->action(function (): void {
                    $temple = $this->record->createTemple(Auth::id());
                    $this->redirect(TempleResource::getUrl('edit', ['record' => $temple]));
                }),

            Action::make('duplicate')
                ->label('Already listed')
                ->icon('heroicon-o-document-duplicate')
                ->color('info')
                ->visible($pending)
                ->form([
                    Select::make('temple_id')
                        ->label('The temple it is')
                        ->options(fn (): array => Temple::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all())
                        ->getSearchResultsUsing(fn (string $search): array => Temple::query()->where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id')->all())
                        ->searchable()
                        ->required(),
                    Textarea::make('review_note')->label('Note to the sender')->rows(2)->default('This temple is already listed — thank you.'),
                ])
                ->action(fn (array $data) => $this->record->review(TempleSuggestionStatus::Duplicate, Auth::id(), $data['review_note'] ?? null, (int) $data['temple_id'])),

            Action::make('reject')
                ->label('Not add')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible($pending)
                ->form([
                    Textarea::make('review_note')
                        ->label('Why')
                        ->required()
                        ->rows(3)
                        ->helperText('The sender sees this in the app.'),
                ])
                ->action(fn (array $data) => $this->record->review(TempleSuggestionStatus::Rejected, Auth::id(), $data['review_note'])),

            Action::make('open_temple')
                ->label('Open temple')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->visible(fn (): bool => $this->record->temple_id !== null)
                ->url(fn (): ?string => $this->record->temple_id ? TempleResource::getUrl('edit', ['record' => $this->record->temple_id]) : null),

            DeleteAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Photographs')
                ->schema([
                    ImageEntry::make('photo_paths')
                        ->hiddenLabel()
                        ->state(fn (TempleSuggestion $record): array => $record->photos->pluck('path')->all())
                        ->disk(fn (TempleSuggestion $record): string => $record->photos->first()?->disk ?? config('filesystems.media'))
                        ->imageHeight(180)
                        ->checkFileExistence(false),
                ]),

            Section::make('The temple')
                ->columns(3)
                ->schema([
                    TextEntry::make('name')->weight('bold'),
                    TextEntry::make('alternate_names')->label('Also known as')->placeholder('—'),
                    TextEntry::make('deity')->label('Deity')->state(fn (TempleSuggestion $r): string => $r->deity?->name ?? $r->deity_name ?? '—'),
                    TextEntry::make('description')->label('About')->prose()->columnSpanFull(),
                    TextEntry::make('history')->prose()->placeholder('—')->columnSpanFull(),
                    TextEntry::make('built_period')->label('Built')->placeholder('—'),
                    TextEntry::make('festivals')->placeholder('—')->columnSpan(2),
                    TextEntry::make('timings')
                        ->label('Timings')
                        ->state(fn (TempleSuggestion $r): string => collect([
                            $r->opens_at && $r->closes_at ? substr($r->opens_at, 0, 5).' – '.substr($r->closes_at, 0, 5) : null,
                            $r->timings_note,
                        ])->filter()->implode(' · ') ?: '—'),
                    TextEntry::make('contact_phone')->label('Temple phone')->copyable()->placeholder('—'),
                    TextEntry::make('official_website')->label('Website')->url(fn (TempleSuggestion $r): ?string => $r->official_website)->placeholder('—'),
                ]),

            Section::make('Where')
                ->columns(3)
                ->schema([
                    TextEntry::make('address')->placeholder('—')->columnSpan(2),
                    TextEntry::make('pincode')->label('PIN code')->placeholder('—'),
                    TextEntry::make('city')->label('Village / town'),
                    TextEntry::make('district')->placeholder('—'),
                    TextEntry::make('state.name')->label('State')->placeholder('—'),
                    TextEntry::make('map')
                        ->label('Map')
                        ->state(fn (TempleSuggestion $r): string => $r->latitude !== null ? 'Open in Google Maps' : 'No pin dropped')
                        ->url(fn (TempleSuggestion $r): ?string => $r->latitude !== null ? 'https://www.google.com/maps?q='.$r->latitude.','.$r->longitude : null, shouldOpenInNewTab: true)
                        ->color(fn (TempleSuggestion $r): ?string => $r->latitude !== null ? 'primary' : null),
                ]),

            Section::make('Who sent it')
                ->description(fn (TempleSuggestion $r): ?string => $r->isFromTempleMember()
                    ? 'From the temple itself. Once it is listed, this is the person to give temple access to (Administration → Temple Trust Access) when the temple portal opens to them.'
                    : null)
                ->columns(3)
                ->schema([
                    TextEntry::make('role')->label('Role')->state(fn (TempleSuggestion $r): string => $r->roleLabel())->badge()
                        ->color(fn (TempleSuggestion $r): string => $r->isFromTempleMember() ? 'success' : 'gray'),
                    TextEntry::make('submitter_name')->label('Name')->placeholder('—'),
                    TextEntry::make('submitter_phone')->label('Phone')->copyable()->placeholder('—'),
                    TextEntry::make('devotee.name')
                        ->label('App account')
                        ->url(fn (TempleSuggestion $r): ?string => $r->devotee ? DevoteeResource::getUrl('view', ['record' => $r->devotee]) : null)
                        ->color('primary')
                        ->placeholder('—'),
                    TextEntry::make('devotee.email')->label('Email')->copyable()->placeholder('—'),
                    TextEntry::make('submitter_note')->label('Their note')->placeholder('—'),
                ]),

            Section::make('Decision')
                ->visible(fn (TempleSuggestion $r): bool => $r->status !== TempleSuggestionStatus::Pending)
                ->columns(3)
                ->schema([
                    TextEntry::make('status')->badge(),
                    TextEntry::make('reviewer.name')->label('By')->placeholder('—'),
                    TextEntry::make('reviewed_at')->label('When')->dateTime('d M Y, H:i'),
                    TextEntry::make('review_note')->label('Note to the sender')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }
}
