<?php

namespace Tests\Feature\Http;

use App\Enums\TempleStatus;
use App\Models\Devotee;
use App\Models\Payment;
use App\Models\State;
use App\Models\Temple;
use App\Models\TemplePayoutAccount;
use App\Support\TempleQr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a visitor to darshansaathi.com meets: a Donate button where the temple
 * takes offerings, a search within a state, a temple QR code scanned with a
 * phone camera, and the way back after paying on the website.
 */
class WebsiteVisitorFlowsTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://darshansaathi.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['brand.website' => self::SITE, 'brand.url' => 'https://temple.darshansaathi.com']);
    }

    private function temple(string $name, string $slug, ?State $state = null, array $extra = []): Temple
    {
        $state ??= State::firstOrCreate(['slug' => 'telangana'], ['name' => 'Telangana', 'code' => 'TG', 'type' => 'state']);

        return Temple::create($extra + ['name' => $name, 'slug' => $slug, 'city' => 'Hyderabad', 'state_id' => $state->id, 'status' => TempleStatus::Published, 'published_at' => now()]);
    }

    private function verifiedPayout(Temple $temple): void
    {
        (new TemplePayoutAccount)->forceFill([
            'temple_id' => $temple->id, 'upi_id' => 'temple@sbi', 'kyc_name' => 'Trust Secretary', 'aadhaar_number' => '1234 5678 1234',
            'aadhaar_front_path' => 'k/f.jpg', 'aadhaar_back_path' => 'k/b.jpg', 'temple_proof_path' => 'k/p.jpg', 'person_photo_path' => 'k/s.jpg',
        ])->save();
        // Approved by staff after the details are in (a change of details
        // asks for approval again).
        TemplePayoutAccount::where('temple_id', $temple->id)->firstOrFail()->forceFill(['verified_at' => now()])->save();
        $this->assertTrue($temple->refresh()->canCollectPayments());
    }

    public function test_donate_shows_only_where_the_temple_takes_offerings(): void
    {
        $open = $this->temple('Chilkur Balaji Temple', 'chilkur', extra: ['accepts_donations' => true]);
        $this->verifiedPayout($open);
        $notVerified = $this->temple('Birla Mandir', 'birla-mandir', extra: ['accepts_donations' => true]);
        $this->temple('Sanghi Temple', 'sanghi');

        $this->get(self::SITE.'/temples/chilkur')->assertOk()
            ->assertSee('🪔 Donate')
            ->assertSee('intent://darshansaathi.com/temples/chilkur?action=donate#Intent', false);
        $this->get(self::SITE.'/temples/birla-mandir')->assertOk()->assertDontSee('🪔 Donate');
        $this->get(self::SITE.'/temples/sanghi')->assertOk()->assertDontSee('🪔 Donate');
    }

    public function test_a_state_page_searches_within_its_state(): void
    {
        $telangana = State::create(['name' => 'Telangana', 'slug' => 'telangana', 'code' => 'TG', 'type' => 'state']);
        $andhra = State::create(['name' => 'Andhra Pradesh', 'slug' => 'andhra-pradesh', 'code' => 'AP', 'type' => 'state']);
        $this->temple('Chilkur Balaji Temple', 'chilkur', $telangana);
        $this->temple('Yadadri Narasimha Temple', 'yadadri', $telangana);
        $this->temple('Tirumala Balaji Temple', 'tirumala', $andhra);

        $this->get(self::SITE.'/states/telangana')->assertOk()
            ->assertSee('placeholder="Search temples in Telangana"', false);

        $this->get(self::SITE.'/states/telangana?q=Balaji')->assertOk()
            ->assertSee('Chilkur Balaji Temple')
            ->assertDontSee('Yadadri Narasimha Temple')
            ->assertDontSee('Tirumala Balaji Temple')
            ->assertSee('noindex', false);

        $this->get(self::SITE.'/states/telangana?q=Nothing')->assertOk()->assertSee('No temples here match');
    }

    public function test_a_temple_qr_scanned_with_a_camera_opens_the_temple_page(): void
    {
        $temple = $this->temple('Keesaragutta Temple', 'keesaragutta');
        $url = TempleQr::url($temple);

        // On the website's own address, which the app claims.
        $this->assertStringStartsWith(self::SITE.'/temples/keesaragutta/checkin?s=', $url);

        $this->get($url)->assertOk()
            ->assertSee('Genuine Darshan Saathi code of Keesaragutta Temple', false)
            ->assertSee('<h1>Keesaragutta Temple</h1>', false)
            ->assertSee('intent://darshansaathi.com/temples/keesaragutta/checkin?s=', false)
            ->assertSee('noindex', false);

        // A code printed before, on the old address, leads to the same page.
        $old = str_replace(self::SITE, 'https://temple.darshansaathi.com', $url);
        $this->get($old)->assertRedirect($url)->assertStatus(301);

        // A forged code says so.
        $this->get(self::SITE.'/temples/keesaragutta/checkin?s=forged')->assertOk()->assertSee('Not a genuine code');
    }

    public function test_a_payment_made_on_the_website_returns_there_with_its_result(): void
    {
        $devotee = Devotee::factory()->create();
        $web = Payment::create(['devotee_id' => $devotee->id, 'purpose' => Payment::DONATION, 'gateway' => 'razorpay', 'amount_paise' => 50100, 'status' => Payment::PAID, 'paid_at' => now(), 'meta' => ['client' => 'web']]);
        $app = Payment::create(['devotee_id' => $devotee->id, 'purpose' => Payment::DONATION, 'gateway' => 'razorpay', 'amount_paise' => 50100, 'status' => Payment::FAILED]);

        $this->get(route('pay.done', $web))->assertOk()
            ->assertSee(self::SITE.'/?payment='.$web->uuid, false)
            ->assertSee('location.replace', false);

        // From the phone app's browser tab: the app shows the result itself.
        $this->get(route('pay.done', $app))->assertOk()->assertDontSee('location.replace', false);
    }
}
