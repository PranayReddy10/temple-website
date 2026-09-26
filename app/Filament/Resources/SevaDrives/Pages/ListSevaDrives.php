<?php

namespace App\Filament\Resources\SevaDrives\Pages;

use App\Enums\SevaDriveStatus;
use App\Filament\Resources\SevaDrives\SevaDriveResource;
use App\Models\SevaDrive;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSevaDrives extends ListRecords
{
    protected static string $resource = SevaDriveResource::class;

    public function getHeading(): string
    {
        return 'Seva Drives';
    }

    public function getSubheading(): ?string
    {
        return 'Devotees organising the care of old temples and heritage places. New drives are listed at once as "not verified" (switch on approval in Settings to review them first). Verify a drive to give it a badge and open donations. Block or mark misleading anything reported.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Create a drive')];
    }

    public function getTabs(): array
    {
        $count = fn (SevaDriveStatus ...$statuses): int => SevaDrive::query()->whereIn('status', $statuses)->count();

        return [
            'review' => Tab::make('To approve')
                ->icon('heroicon-m-clock')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SevaDriveStatus::Pending))
                ->badge(fn (): int => $count(SevaDriveStatus::Pending))
                ->badgeColor('warning'),

            'verify' => Tab::make('Verification requested')
                ->icon('heroicon-m-check-badge')
                ->modifyQueryUsing(fn (Builder $query) => $query->verificationRequested())
                ->badge(fn (): int => SevaDrive::query()->verificationRequested()->count())
                ->badgeColor('warning'),

            'live' => Tab::make('Upcoming')
                ->icon('heroicon-m-user-group')
                ->modifyQueryUsing(fn (Builder $query) => $query->upcoming()),

            'completed' => Tab::make('Completed')
                ->icon('heroicon-m-flag')
                ->modifyQueryUsing(fn (Builder $query) => $query->finished()),

            'unverified' => Tab::make('Not verified')
                ->icon('heroicon-m-question-mark-circle')
                ->modifyQueryUsing(fn (Builder $query) => $query->publiclyVisible()->whereNull('verified_at')),

            'verified' => Tab::make('Verified')
                ->icon('heroicon-m-check-badge')
                ->modifyQueryUsing(fn (Builder $query) => $query->verified()),

            'reported' => Tab::make('Reported')
                ->icon('heroicon-m-flag')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas('reports', fn ($q) => $q->open()))
                ->badge(fn (): int => SevaDrive::query()->whereHas('reports', fn ($q) => $q->open())->count())
                ->badgeColor('danger'),

            'blocked' => Tab::make('Blocked')
                ->icon('heroicon-m-shield-exclamation')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SevaDriveStatus::Blocked)),

            'all' => Tab::make('Everything'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return match (true) {
            SevaDrive::query()->where('status', SevaDriveStatus::Pending)->exists() => 'review',
            SevaDrive::query()->verificationRequested()->exists() => 'verify',
            default => 'live',
        };
    }
}
