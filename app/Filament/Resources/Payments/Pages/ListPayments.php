<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    public function getSubheading(): ?string
    {
        return 'Every checkout started in the app. A plan switches on only when the gateway itself confirms the payment.';
    }
}
