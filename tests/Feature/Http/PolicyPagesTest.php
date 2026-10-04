<?php

namespace Tests\Feature\Http;

use App\Enums\TicketCategory;
use App\Enums\UserRole;
use App\Filament\Pages\Settings\ManageBusinessDetails;
use App\Filament\Resources\Devotees\Pages\ViewDevotee;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Models\Devotee;
use App\Models\Page;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\BrandName;
use App\Support\DefaultPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Privacy policy, terms, refunds, account deletion: edited in the admin panel, shown on the website. */
class PolicyPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['brand.website' => 'https://darshansaathi.com', 'brand.support_email' => 'support@darshansaathi.com']);
    }

    public function test_every_policy_page_exists_from_the_start_with_the_details_filled_in(): void
    {
        foreach (Page::REQUIRED as $slug) {
            $this->assertNotNull(Page::query()->where('slug', $slug)->first(), $slug);
        }

        $this->get('https://darshansaathi.com/privacy-policy')->assertOk()
            ->assertSee('<title>Privacy policy · Darshan Saathi</title>', false)
            ->assertSee('<link rel="canonical" href="https://darshansaathi.com/privacy-policy">', false)
            ->assertSee('href="mailto:support@darshansaathi.com"', false)
            ->assertSee('support@darshansaathi.com')
            ->assertDontSee('{email}')->assertDontSee('%7Bemail%7D')->assertDontSee('{app}');

        Setting::set('legal_business_name', 'Saathi Technologies LLP');
        Setting::set('legal_jurisdiction_city', 'Hyderabad');

        $this->get('https://darshansaathi.com/terms-and-conditions')->assertOk()
            ->assertSee('Saathi Technologies LLP')
            ->assertSee('the courts at Hyderabad, India');
    }

    /** The app's name comes from Settings, not an old .env value, on every page and in the editor. */
    public function test_pages_use_the_brand_name_from_settings(): void
    {
        config(['brand.name' => 'Temple Passport']);
        Setting::set('brand_name', 'Darshan Saathi');
        BrandName::apply();

        $this->get('https://darshansaathi.com/about-us')->assertOk()
            ->assertSee('Darshan Saathi is a companion for visiting Hindu temples across India.')
            ->assertSee('<meta name="description" content="What Darshan Saathi is: a companion for temple visits across India.">', false)
            ->assertDontSee('Temple Passport')
            ->assertDontSee('{app}');

        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));
        Livewire::test(EditPage::class, ['record' => Page::query()->where('slug', 'about-us')->firstOrFail()->getRouteKey()])
            ->assertSee('{app} → Darshan Saathi')
            ->assertSee('Shown as: “What Darshan Saathi is: a companion for temple visits across India.”', false);
    }

    public function test_the_footer_links_the_pages_and_the_sitemap_lists_them(): void
    {
        $this->get('https://darshansaathi.com/refund-and-cancellation')->assertOk()
            ->assertSee('href="https://darshansaathi.com/privacy-policy"', false)
            ->assertSee('href="https://darshansaathi.com/account-deletion"', false);

        $this->get('/sitemap-pages.xml')->assertSee('<loc>https://darshansaathi.com/terms-and-conditions</loc>', false);
    }

    public function test_unknown_and_unpublished_pages_are_not_found_and_other_addresses_are_untouched(): void
    {
        Page::query()->where('slug', 'disclaimer')->update(['is_published' => false]);

        $this->get('https://darshansaathi.com/disclaimer')->assertNotFound();
        $this->get('https://darshansaathi.com/no-such-page')->assertNotFound();
        $this->get('https://darshansaathi.com/privacy-policy/extra')->assertNotFound();
        $this->get('/admin/login')->assertOk();
    }

    public function test_a_deletion_request_from_the_website_reaches_support(): void
    {
        $devotee = Devotee::create(['name' => 'Asha', 'email' => 'asha@example.com', 'password' => 'secret-pass']);

        $this->get('https://darshansaathi.com/account-deletion')->assertOk()->assertSee('Request account deletion');

        $this->post('https://darshansaathi.com/account-deletion', ['contact' => 'asha@example.com', 'reason' => 'Not using it'])
            ->assertRedirect();

        $ticket = SupportTicket::query()->latest('id')->first();
        $this->assertSame(TicketCategory::Account, $ticket->category);
        $this->assertSame($devotee->id, $ticket->devotee_id);
        $this->assertStringContainsString('asha@example.com', $ticket->body);
        // The account itself waits for support to confirm it is the owner.
        $this->assertNotNull($devotee->fresh());

        // Bots fill the hidden field.
        $this->post('https://darshansaathi.com/account-deletion', ['contact' => 'x@example.com', 'website' => 'spam'])
            ->assertSessionHasErrors('website');
    }

    public function test_the_app_deletes_the_account_and_signs_it_out_everywhere(): void
    {
        $devotee = Devotee::create(['name' => 'Ravi', 'email' => 'ravi@example.com', 'phone' => '9876543210', 'password' => 'secret-pass']);
        $token = $devotee->createToken('app')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/devices', ['token' => 'fcm-1', 'platform' => 'android'])->assertSuccessful();
        $this->assertSame(1, $devotee->devices()->count());

        $this->withToken($token)->deleteJson('/api/v1/me')->assertUnprocessable();
        $this->withToken($token)->deleteJson('/api/v1/me', ['confirm' => 'DELETE'])->assertOk();

        $gone = Devotee::withTrashed()->find($devotee->id);
        $this->assertTrue($gone->trashed());
        $this->assertNull($gone->email);
        $this->assertNull($gone->phone);
        $this->assertSame('Deleted account', $gone->name);
        $this->assertSame(0, $gone->devices()->count());
        $this->assertSame(0, $gone->tokens()->count());

        // Signed out everywhere, and the email is free to sign up again.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/register', ['name' => 'Ravi', 'email' => 'ravi@example.com', 'password' => 'secret-pass-2', 'password_confirmation' => 'secret-pass-2'])
            ->assertSuccessful();
    }

    public function test_admins_edit_pages_restore_the_default_text_and_cannot_delete_required_ones(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));
        $page = Page::query()->where('slug', 'privacy-policy')->first();

        Livewire::test(ListPages::class)->assertOk()->assertSee('Privacy policy');
        Livewire::test(ManageBusinessDetails::class)->assertOk();

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['title' => 'Privacy notice', 'body' => '<p>Short and clear.</p>'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Privacy notice', $page->fresh()->title);
        // The address the stores link to does not move.
        $this->assertSame('privacy-policy', $page->fresh()->slug);
        $this->get('https://darshansaathi.com/privacy-policy')->assertSee('Short and clear.');

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->assertActionHidden('delete')
            ->callAction('restore');
        $this->assertSame(DefaultPages::html('privacy-policy'), $page->fresh()->body);

        // A request from the website form, confirmed by support.
        $devotee = Devotee::create(['name' => 'Meena', 'email' => 'meena@example.com']);
        Livewire::test(ViewDevotee::class, ['record' => $devotee->getRouteKey()])->callAction('delete_account');
        $this->assertTrue(Devotee::withTrashed()->find($devotee->id)->trashed());

        $this->actingAs(User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]));
        $this->get('/admin/website/pages')->assertForbidden();
    }
}
