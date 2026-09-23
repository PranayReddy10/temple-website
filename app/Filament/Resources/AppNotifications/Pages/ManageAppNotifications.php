<?php

namespace App\Filament\Resources\AppNotifications\Pages;

use App\Filament\Resources\AppNotifications\AppNotificationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;

class ManageAppNotifications extends ManageRecords
{
    protected static string $resource = AppNotificationResource::class;

    public function getSubheading(): ?string
    {
        return 'Messages to devotees: in the app\'s inbox always, and pushed to phones once push is set up.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New notification')
                ->mutateDataUsing(fn (array $data): array => AppNotificationResource::prepare($data) + ['created_by' => Auth::id()]),
        ];
    }
}
