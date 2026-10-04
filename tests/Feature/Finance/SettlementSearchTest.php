<?php

namespace Tests\Feature\Finance;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Resources\TempleSettlements\Pages\ListTempleSettlements;
use App\Models\Temple;
use App\Models\TempleSettlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Settlements are found by temple name or town, reference, or the bank's UTR. */
class SettlementSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function settlement(Temple $temple, string $status, ?string $utr = null): TempleSettlement
    {
        $s = new TempleSettlement;
        $s->forceFill([
            'temple_id' => $temple->id, 'period_from' => now()->subDays(7)->toDateString(), 'period_to' => now()->subDay()->toDateString(),
            'bookings_count' => 2, 'bookings_paise' => 100000, 'gross_paise' => 100000, 'fee_percent' => 5, 'fee_paise' => 5000, 'net_paise' => 95000,
            'status' => $status, 'transaction_ref' => $utr, 'paid_at' => $status === TempleSettlement::PAID ? now() : null,
        ])->save();

        return $s;
    }

    public function test_settlements_are_searched_by_temple_town_reference_or_utr(): void
    {
        $rama = Temple::create(['name' => 'Sri Rama Temple', 'slug' => 'sri-rama', 'city' => 'Bhadrachalam', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $shiva = Temple::create(['name' => 'Someshwara Temple', 'slug' => 'someshwara', 'city' => 'Kolanupaka', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $a = $this->settlement($rama, TempleSettlement::PAID, 'UTR123456789');
        $b = $this->settlement($shiva, TempleSettlement::PAID, 'UTR987654321');

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]);

        // The Trust app's admin screen.
        $this->withToken($admin->createToken('t')->plainTextToken);
        $refs = fn (string $q) => collect($this->getJson('/api/v1/trust/admin/settlements?status=paid&q='.urlencode($q))->assertOk()->json('data'))->pluck('reference')->all();
        $this->assertSame([$a->reference], $refs('rama'));
        $this->assertSame([$b->reference], $refs('kolanu'));
        $this->assertSame([$b->reference], $refs('987654'));
        $this->assertSame([$a->reference], $refs($a->reference));
        $this->assertCount(2, $refs(''));

        // The admin panel's list.
        $this->actingAs($admin);
        Livewire::test(ListTempleSettlements::class)->searchTable('Someshwara')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListTempleSettlements::class)->searchTable('Bhadrachalam')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
        Livewire::test(ListTempleSettlements::class)->searchTable('UTR9876')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
    }
}
