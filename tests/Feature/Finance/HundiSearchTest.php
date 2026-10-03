<?php

namespace Tests\Feature\Finance;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Models\Devotee;
use App\Models\Temple;
use App\Models\TempleDonation;
use App\Models\TempleUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The temple searches its hundi gifts, and an anonymous gift stays anonymous. */
class HundiSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_gifts_are_found_by_name_phone_or_reference_but_anonymous_ones_only_by_reference(): void
    {
        $temple = Temple::create(['name' => 'Sri Rama Temple', 'slug' => 'sri-rama', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $owner = User::factory()->create(['role' => UserRole::TempleAdmin, 'is_active' => true]);
        TempleUser::create(['temple_id' => $temple->id, 'user_id' => $owner->id, 'role' => 'owner', 'requested_at' => now(), 'approved_at' => now()]);

        $gift = function (Devotee $d, array $attrs) use ($temple): TempleDonation {
            $g = new TempleDonation(['temple_id' => $temple->id, 'devotee_id' => $d->id, 'amount_paise' => 50100, 'purpose' => 'general', 'status' => TempleDonation::PAID] + $attrs);
            $g->forceFill(['paid_at' => now(), 'paid_on' => now()->toDateString()])->save();

            return $g;
        };
        $named = $gift(Devotee::factory()->create(['name' => 'Lakshmi Devi', 'phone' => '+91 98480 22338']), ['donor_name' => 'Lakshmi Devi', 'is_anonymous' => false]);
        $hidden = $gift(Devotee::factory()->create(['name' => 'Ravi Kumar', 'phone' => '90000 11111']), ['donor_name' => 'Ravi Kumar', 'is_anonymous' => true]);

        $this->withToken($owner->createToken('t')->plainTextToken);
        $url = '/api/v1/trust/temples/'.$temple->id.'/donations';
        $refs = fn (string $q) => collect($this->getJson($url.'?q='.urlencode($q))->assertOk()->json('data.items'))->pluck('reference')->all();

        $this->assertSame([$named->reference], $refs('laksh'));
        $this->assertSame([$named->reference], $refs('22338'));
        $this->assertSame([$named->reference], $refs(strtolower($named->reference)));

        // Anonymous: not by name, not by phone; by its receipt reference only.
        $this->assertSame([], $refs('ravi'));
        $this->assertSame([], $refs('90000 11111'));
        $this->assertSame([$hidden->reference], $refs($hidden->reference));
        $this->assertSame('A devotee', $this->getJson($url.'?q='.$hidden->reference)->json('data.items.0.donor'));

        // No search: everything, and the totals are unaffected by a search.
        $this->assertCount(2, $refs(''));
        $this->getJson($url.'?q=laksh')->assertJsonPath('data.total.count', 2);
    }
}
