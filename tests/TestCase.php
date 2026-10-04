<?php

namespace Tests;

use App\Models\Temple;
use App\Models\TemplePayoutAccount;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A temple whose owner and bank are approved, so devotees may pay it:
     * bank details, Aadhaar, temple proof and photo, verified by staff.
     */
    protected function approvePayments(Temple $temple): TemplePayoutAccount
    {
        $account = new TemplePayoutAccount([
            'account_name' => 'Temple Trust',
            'account_number' => '123456789012',
            'ifsc' => 'SBIN0001234',
            'kyc_name' => 'Trust Secretary',
            'aadhaar_number' => '234567890123',
            'temple_proof_kind' => 'trust_registration',
        ]);
        $account->forceFill([
            'temple_id' => $temple->getKey(),
            'kyc_disk' => 'local',
            'aadhaar_front_path' => 'kyc/test/front.jpg',
            'aadhaar_back_path' => 'kyc/test/back.jpg',
            'temple_proof_path' => 'kyc/test/proof.pdf',
            'person_photo_path' => 'kyc/test/photo.jpg',
            'kyc_submitted_at' => now(),
        ])->save();
        $account->forceFill(['verified_at' => now()])->saveQuietly();

        $temple->unsetRelation('payoutAccount');

        return $account;
    }
}
