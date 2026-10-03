<?php

namespace Tests\Feature\Finance;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Resources\TempleBalances\Pages\ListTempleBalances;
use App\Http\Controllers\KycDocumentController;
use App\Models\Devotee;
use App\Models\Setting;
use App\Models\Temple;
use App\Models\TempleUser;
use App\Models\User;
use App\Support\Donations\Donations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * No money before the person collecting it is known: a temple takes paid
 * bookings, paid tickets or hundi gifts only after its owner has sent bank
 * details, Aadhaar, a temple proof and a photo, and staff have approved them.
 */
class PaymentsVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected Temple $temple;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->temple = Temple::create(['name' => 'Sri Rama Temple', 'slug' => 'sri-rama', 'status' => TempleStatus::Published, 'published_at' => now()]);
    }

    protected function owner(): User
    {
        $user = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);
        TempleUser::create(['temple_id' => $this->temple->id, 'user_id' => $user->id, 'role' => 'owner', 'requested_at' => now(), 'approved_at' => now()]);

        return $user;
    }

    protected function trust(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('trust')->plainTextToken);
    }

    protected function documents(): array
    {
        return [
            'kyc_name' => 'Rama Rao',
            'aadhaar_number' => '2345 6789 0123',
            'temple_proof_kind' => 'trust_registration',
            'aadhaar_front' => UploadedFile::fake()->image('front.jpg'),
            'aadhaar_back' => UploadedFile::fake()->image('back.jpg'),
            'temple_proof' => UploadedFile::fake()->create('trust-deed.pdf', 200, 'application/pdf'),
            'person_photo' => UploadedFile::fake()->image('me.jpg'),
        ];
    }

    public function test_nothing_paid_switches_on_until_the_owner_and_bank_are_approved(): void
    {
        $owner = $this->owner();
        $base = '/api/v1/trust/temples/'.$this->temple->id;

        // Hundi, paid seva and paid tickets: all refused.
        $this->trust($owner)->putJson($base.'/donation-settings', ['accepts_donations' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('accepts_donations');
        $this->trust($owner)->post($base.'/sevas', ['kind' => 'seva', 'name' => 'Abhishekam', 'is_free' => '0', 'fee_amount' => '500', 'app_booking_enabled' => '1'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('app_booking_enabled');
        $this->trust($owner)->post($base.'/events', [
            'type' => 'bhajan', 'title' => 'Saturday bhajan', 'starts_on' => now()->addDays(3)->toDateString(), 'is_all_day' => '1',
            'registration_enabled' => '1', 'ticket_price' => '50', 'status' => 'draft',
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('ticket_price');

        // Free things need no approval.
        $this->trust($owner)->post($base.'/sevas', ['kind' => 'seva', 'name' => 'Archana', 'is_free' => '1', 'app_booking_enabled' => '1'], ['Accept' => 'application/json'])
            ->assertCreated();

        // Bank details, then the documents: waiting for staff.
        $this->trust($owner)->putJson($base.'/payout-account', ['account_name' => 'Sri Rama Trust', 'account_number' => '001234567890', 'ifsc' => 'SBIN0001234'])->assertOk();
        $this->trust($owner)->post($base.'/payout-account/kyc', $this->documents(), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.kyc.status', 'pending')
            ->assertJsonPath('data.kyc.aadhaar_masked', 'XXXX XXXX 0123')
            ->assertJsonPath('data.can_receive_money', false)
            ->assertJsonMissingPath('data.kyc.aadhaar_number');

        $account = $this->temple->payoutAccount()->firstOrFail();
        Storage::disk('local')->assertExists($account->temple_proof_path);
        $this->assertSame('234567890123', $account->aadhaar_number);
        $this->assertNotSame('234567890123', \DB::table('temple_payout_accounts')->value('aadhaar_number'));

        $this->trust($owner)->putJson($base.'/donation-settings', ['accepts_donations' => true])->assertUnprocessable();

        // Staff approve: now the hundi opens.
        $account->forceFill(['verified_at' => now()])->saveQuietly();
        $this->trust($owner)->putJson($base.'/donation-settings', ['accepts_donations' => true])->assertOk();

        // New documents send it back for checking.
        $this->trust($owner)->post($base.'/payout-account/kyc', ['person_photo' => UploadedFile::fake()->image('new.jpg')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.kyc.status', 'pending');
        $this->assertFalse($this->temple->fresh()->canCollectPayments());
    }

    public function test_a_manager_cannot_send_documents_and_a_first_submission_needs_everything(): void
    {
        $manager = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);
        TempleUser::create(['temple_id' => $this->temple->id, 'user_id' => $manager->id, 'role' => 'manager', 'requested_at' => now(), 'approved_at' => now()]);
        $url = '/api/v1/trust/temples/'.$this->temple->id.'/payout-account/kyc';

        $this->trust($manager)->post($url, $this->documents(), ['Accept' => 'application/json'])->assertForbidden();

        $this->trust($this->owner())->post($url, ['kyc_name' => 'Rama Rao', 'aadhaar_number' => '1234'], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['aadhaar_number', 'aadhaar_front', 'aadhaar_back', 'temple_proof', 'person_photo', 'temple_proof_kind']);
    }

    public function test_devotees_cannot_pay_an_unapproved_temple(): void
    {
        Setting::set('payments_enabled', true);
        $this->temple->forceFill(['accepts_donations' => true])->saveQuietly();

        $this->getJson('/api/v1/temples/'.$this->temple->slug)->assertOk()->assertJsonPath('data.donations.enabled', false);

        $this->expectException(ValidationException::class);
        app(Donations::class)->give(Devotee::factory()->create(), $this->temple, ['amount_paise' => 10100]);
    }

    public function test_staff_review_the_documents_then_approve_or_reject(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);
        $owner = $this->owner();
        $base = '/api/v1/trust/temples/'.$this->temple->id;
        $this->trust($owner)->putJson($base.'/payout-account', ['upi_id' => 'srirama@sbi'])->assertOk();
        $this->trust($owner)->post($base.'/payout-account/kyc', $this->documents(), ['Accept' => 'application/json'])->assertOk();
        $account = $this->temple->payoutAccount()->firstOrFail();

        // The documents open only for signed-in staff, through a signed link.
        $link = KycDocumentController::url($account, 'aadhaar_front');
        $this->app['auth']->forgetGuards();
        $this->get('/admin-kyc/'.$account->id.'/aadhaar_front')->assertForbidden();
        $this->actingAs($owner)->get($link)->assertForbidden();
        $this->actingAs($admin)->get($link)->assertOk();

        Livewire::test(ListTempleBalances::class)
            ->set('tableFilters.owed.isActive', false)
            ->callTableAction('rejectPayments', $this->temple, data: ['reason' => 'The Aadhaar photo is not readable.']);
        $this->assertSame('rejected', $account->fresh()->kycStatus());
        $this->trust($owner)->getJson($base.'/finance')->assertJsonPath('data.payout_account.kyc.rejection_reason', 'The Aadhaar photo is not readable.');

        $this->actingAs($admin);
        Livewire::test(ListTempleBalances::class)
            ->set('tableFilters.owed.isActive', false)
            ->callTableAction('reviewPayments', $this->temple);
        $this->assertTrue($this->temple->fresh()->canCollectPayments());
    }
}
