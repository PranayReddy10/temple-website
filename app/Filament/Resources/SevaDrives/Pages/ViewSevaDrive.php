<?php

namespace App\Filament\Resources\SevaDrives\Pages;

use App\Enums\SevaDriveStatus;
use App\Filament\Resources\Devotees\DevoteeResource;
use App\Filament\Resources\SevaDrives\SevaDriveResource;
use App\Filament\Support\SevaDriveDecisions;
use App\Models\SevaDrive;
use App\Models\SevaDriveMedia;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One drive: the place, the plan, and the before and after side by side,
 * which is the comparison verifying one comes down to.
 */
class ViewSevaDrive extends ViewRecord
{
    protected static string $resource = SevaDriveResource::class;

    public function getHeading(): string
    {
        return $this->record->title;
    }

    public function getSubheading(): ?string
    {
        $record = $this->record;

        return $record->status?->getLabel().' · '.$record->place_name
            .' · '.$record->dateLabel()
            .' · organised by '.$record->organiserName();
    }

    protected function getHeaderActions(): array
    {
        $decisions = SevaDriveDecisions::actions();

        // The two that come up most stay out in the open; the rest in a menu.
        return [
            EditAction::make(),
            ...array_slice($decisions, 0, 3),
            ActionGroup::make([...array_slice($decisions, 3), DeleteAction::make()])
                ->label('More')
                ->icon('heroicon-m-ellipsis-vertical')
                ->button()
                ->color('gray'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Blocked')
                ->icon('heroicon-o-shield-exclamation')
                ->iconColor('danger')
                ->visible(fn (SevaDrive $record): bool => $record->status === SevaDriveStatus::Blocked)
                ->columns(3)
                ->schema([
                    TextEntry::make('block_reason')->label('Reason (the organiser sees this)')->columnSpan(2),
                    TextEntry::make('blocker.name')->label('Blocked by')
                        ->state(fn (SevaDrive $record): string => ($record->blocker?->name ?? 'Staff').' · '.$record->blocked_at?->format('d M Y, H:i')),
                ]),

            Section::make('Marked misleading')
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor('warning')
                ->visible(fn (SevaDrive $record): bool => $record->is_misleading)
                ->schema([
                    TextEntry::make('misleading_note')->hiddenLabel()->helperText('Shown on the drive in the app. Joining and donations are closed.'),
                ]),

            Section::make('At a glance')
                ->columns(6)
                ->schema([
                    TextEntry::make('coming')->label('Coming')
                        ->state(fn (SevaDrive $record): string => $record->headcount().($record->volunteers_needed ? ' of '.$record->volunteers_needed : '')),
                    TextEntry::make('signups')->label('Sign-ups')->state(fn (SevaDrive $record): int => $record->volunteers()->count()),
                    TextEntry::make('raised_total')->label('Raised (confirmed)')->state(fn (SevaDrive $record): int => $record->confirmedDonationTotal())->money('INR'),
                    TextEntry::make('donors')->label('Donors')->state(fn (SevaDrive $record): int => $record->donations()->whereNotNull('confirmed_at')->count()),
                    TextEntry::make('reports_open')->label('Open reports')
                        ->state(fn (SevaDrive $record): int => $record->reports()->open()->count())
                        ->badge()
                        ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                    TextEntry::make('days')->label('Length')
                        ->state(fn (SevaDrive $record): string => $record->isMultiDay() ? $record->dayCount().' days' : 'One day'),
                ]),

            Section::make('Organiser')
                ->columns(4)
                ->schema([
                    TextEntry::make('organiser_shown')->label('Shown as')->state(fn (SevaDrive $record): string => $record->organiserName())->weight('bold'),
                    TextEntry::make('organiser.name')
                        ->label('Devotee account')
                        ->placeholder('None — a team drive')
                        ->url(fn (SevaDrive $record): ?string => $record->organiser ? DevoteeResource::getUrl('view', ['record' => $record->organiser]) : null)
                        ->color('primary'),
                    TextEntry::make('organiser.email')->label('Email')->copyable()->placeholder('—'),
                    TextEntry::make('organiser.phone')->label('Account phone')->copyable()->placeholder('—'),
                    TextEntry::make('organiser_history')
                        ->label('Drives they organised')
                        ->state(fn (SevaDrive $record): string => $record->devotee_id === null ? '—' : (string) SevaDrive::query()->where('devotee_id', $record->devotee_id)->count()),
                    TextEntry::make('organiser_blocked')
                        ->label('Of those, blocked')
                        ->state(fn (SevaDrive $record): string => $record->devotee_id === null ? '—' : (string) SevaDrive::query()->where('devotee_id', $record->devotee_id)->where('status', SevaDriveStatus::Blocked)->count()),
                    TextEntry::make('creator.name')->label('Created in the admin by')->placeholder('Raised from the app'),
                    TextEntry::make('created_at')->label('Raised')->dateTime('d M Y, H:i'),
                ]),

            Section::make('What is wrong, and the plan')
                ->columns(2)
                ->schema([
                    TextEntry::make('problem')->label('The place now')->prose(),
                    TextEntry::make('plan')->label('What they will do')->prose(),
                    TextEntry::make('what_to_bring')->label('Volunteers bring')->placeholder('Not said'),
                    TextEntry::make('moderation_note')->label('Last note to the organiser')->placeholder('None')->color('warning'),
                ]),

            Section::make('Where and when')
                ->columns(3)
                ->schema([
                    TextEntry::make('place_name')->label('Place'),
                    TextEntry::make('address')->placeholder('—'),
                    TextEntry::make('city')
                        ->state(fn (SevaDrive $record): string => collect([$record->city, $record->state?->name])->filter()->implode(', ') ?: '—'),
                    TextEntry::make('temple.name')->label('Listed temple')->placeholder('Not a listed temple'),
                    TextEntry::make('meeting_point')->placeholder('—'),
                    TextEntry::make('map')
                        ->label('Map')
                        ->state(fn (SevaDrive $record): string => $record->latitude !== null ? 'Open in Google Maps' : 'No pin dropped')
                        ->url(fn (SevaDrive $record): ?string => $record->latitude !== null
                            ? 'https://www.google.com/maps?q='.$record->latitude.','.$record->longitude
                            : null, shouldOpenInNewTab: true)
                        ->color(fn (SevaDrive $record): ?string => $record->latitude !== null ? 'primary' : null),
                    TextEntry::make('date_label')->label('Dates')->state(fn (SevaDrive $record): string => $record->dateLabel())->columnSpan(2),
                    TextEntry::make('starts_at')->label('Starts')->dateTime('d M Y, H:i'),
                    TextEntry::make('ends_at')->label('Ends')->dateTime('d M Y, H:i')->placeholder('—'),
                    TextEntry::make('volunteers_needed')->label('Volunteers wanted')->placeholder('Any number'),
                    TextEntry::make('contact_phone')->label('Organiser phone')->copyable()->placeholder('—'),
                    TextEntry::make('cause')->badge()->color('gray'),
                    TextEntry::make('status')->badge(),
                ]),

            Section::make('Before')
                ->schema([
                    self::gallery(SevaDriveMedia::STAGE_BEFORE),
                    self::videoLinks(SevaDriveMedia::STAGE_BEFORE),
                ]),

            Section::make('After')
                ->description('Compare with the photographs above before verifying.')
                ->visible(fn (SevaDrive $record): bool => $record->media->contains('stage', SevaDriveMedia::STAGE_AFTER) || filled($record->completion_note))
                ->schema([
                    TextEntry::make('completion_note')->label('What was done')->prose()->placeholder('Not written yet'),
                    self::gallery(SevaDriveMedia::STAGE_AFTER),
                    self::videoLinks(SevaDriveMedia::STAGE_AFTER),
                ]),

            Section::make('Donations')
                ->columns(3)
                ->schema([
                    TextEntry::make('upi_id')->label('UPI ID')->copyable()->placeholder('None given'),
                    TextEntry::make('upi_name')->label('Name on UPI')->placeholder('—'),
                    TextEntry::make('donations_open')
                        ->label('Shown in the app')
                        ->state(fn (SevaDrive $record): string => $record->acceptsDonations()
                            ? 'Yes'
                            : ($record->donations_enabled ? 'Not until verified' : 'Paused by staff')),
                    TextEntry::make('donation_goal')->label('Goal')->money('INR')->placeholder('—'),
                    TextEntry::make('donation_purpose')->label('For')->placeholder('—'),
                    TextEntry::make('raised')
                        ->label('Confirmed by the organiser')
                        ->state(fn (SevaDrive $record): int => $record->confirmedDonationTotal())
                        ->money('INR'),
                ]),
        ]);
    }

    protected static function gallery(string $stage): ImageEntry
    {
        return ImageEntry::make($stage.'_photos')
            ->hiddenLabel()
            ->state(fn (SevaDrive $record): array => $record->media
                ->where('stage', $stage)
                ->where('type', SevaDriveMedia::TYPE_PHOTO)
                ->pluck('path')
                ->filter()
                ->values()
                ->all())
            // All of a drive's media is written to the one disk it was
            // uploaded to, so the first row's disk speaks for the rest.
            ->disk(fn (SevaDrive $record): string => $record->media->first()?->disk ?? config('filesystems.media'))
            ->imageHeight(180)
            ->checkFileExistence(false)
            ->placeholder('None');
    }

    protected static function videoLinks(string $stage): TextEntry
    {
        return TextEntry::make($stage.'_videos')
            ->label('Videos')
            ->state(fn (SevaDrive $record): array => $record->media
                ->where('stage', $stage)
                ->where('type', SevaDriveMedia::TYPE_VIDEO)
                ->map(fn (SevaDriveMedia $m): string => $m->url() ?? '')
                ->filter()
                ->values()
                ->all())
            ->listWithLineBreaks()
            ->url(fn ($state): ?string => is_string($state) ? $state : null, shouldOpenInNewTab: true)
            ->color('primary')
            ->placeholder('None');
    }
}
