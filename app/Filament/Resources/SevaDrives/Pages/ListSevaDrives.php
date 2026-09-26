<?php

namespace App\Filament\Resources\SevaDrives\Pages;

use App\Enums\SevaDriveStatus;
use App\Filament\Resources\SevaDrives\SevaDriveResource;
use App\Models\SevaDrive;
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
        return 'Devotees organising the care of old temples and heritage places. Approve a drive before it is listed; verify the before and after before it may ask for donations.';
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

            'verify' => Tab::make('To verify')
                ->icon('heroicon-m-camera')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SevaDriveStatus::Completed))
                ->badge(fn (): int => $count(SevaDriveStatus::Completed))
                ->badgeColor('warning'),

            'live' => Tab::make('Open for volunteers')
                ->icon('heroicon-m-user-group')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SevaDriveStatus::Approved)),

            'verified' => Tab::make('Verified')
                ->icon('heroicon-m-check-badge')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SevaDriveStatus::Verified)),

            'all' => Tab::make('Everything'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return SevaDrive::query()->where('status', SevaDriveStatus::Pending)->exists() ? 'review' : 'verify';
    }
}
