<?php

namespace App\Filament\Resources\SubscriptionPlans\Pages;

use App\Filament\Resources\SubscriptionPlans\SubscriptionPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSubscriptionPlans extends ManageRecords
{
    protected static string $resource = SubscriptionPlanResource::class;

    public function getSubheading(): ?string
    {
        return 'What devotees can buy in the app. Payment gateways are set up under Monetisation → Payment gateways.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New plan')
                ->mutateDataUsing(fn (array $data): array => SubscriptionPlanResource::withPrice($data)),
        ];
    }
}
