<?php

namespace App\Filament\Resources\PaymentVerifications\Pages;

use App\Filament\Resources\PaymentVerifications\PaymentVerificationResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentVerifications extends ListRecords
{
    protected static string $resource = PaymentVerificationResource::class;

    public function getHeading(): string
    {
        return 'Payment verifications';
    }

    public function getSubheading(): ?string
    {
        return 'Temple owners who want to take money in the app (paid sevas, tickets, hundi). Open one to see the Aadhaar, the temple proof and the photo, then approve or reject.';
    }
}
