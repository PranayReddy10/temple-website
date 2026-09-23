<?php

namespace App\Filament\Resources\DevoteeSubscriptions\Pages;

use App\Filament\Resources\DevoteeSubscriptions\DevoteeSubscriptionResource;
use App\Models\Devotee;
use App\Models\SubscriptionPlan;
use App\Support\Payments\Payments;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ManageDevoteeSubscriptions extends ManageRecords
{
    protected static string $resource = DevoteeSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Grant a plan')->modalHeading('Grant a plan without payment')
                ->using(fn (array $data): Model => app(Payments::class)->startSubscription(
                    Devotee::query()->findOrFail($data['devotee_id']),
                    SubscriptionPlan::query()->findOrFail($data['subscription_plan_id']),
                    days: filled($data['days'] ?? null) ? (int) $data['days'] : null,
                    grantedBy: Auth::id(),
                    note: $data['note'] ?? null,
                )),
        ];
    }
}
