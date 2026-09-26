<?php

namespace App\Filament\Resources\SevaDrives\Pages;

use App\Filament\Resources\SevaDrives\SevaDriveResource;
use App\Filament\Support\SevaDriveDecisions;
use App\Models\SevaDrive;
use App\Models\SevaDriveMedia;
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
            .' · '.$record->starts_at?->format('d M Y, H:i')
            .' · raised by '.($record->organiser?->name ?? 'a deleted account');
    }

    protected function getHeaderActions(): array
    {
        return SevaDriveDecisions::actions();
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
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
