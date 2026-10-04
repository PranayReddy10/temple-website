<?php

namespace Tests\Feature\Finance;

use App\Enums\TempleStatus;
use App\Enums\UserRole;
use App\Filament\Resources\TempleBalances\Pages\ListTempleBalances;
use App\Models\Temple;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** A temple is found in Temple balances by name or town, even with nothing owed. */
class TempleBalanceSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_searching_finds_a_temple_with_nothing_owed(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin, 'is_active' => true]));
        $rama = Temple::create(['name' => 'Sri Rama Temple', 'slug' => 'sri-rama', 'city' => 'Bhadrachalam', 'status' => TempleStatus::Published, 'published_at' => now()]);
        $shiva = Temple::create(['name' => 'Someshwara Temple', 'slug' => 'someshwara', 'city' => 'Kolanupaka', 'status' => TempleStatus::Published, 'published_at' => now()]);

        // By default only temples with money waiting, so neither shows.
        Livewire::test(ListTempleBalances::class)->assertCanNotSeeTableRecords([$rama, $shiva]);

        Livewire::test(ListTempleBalances::class)->searchTable('Rama')->assertCanSeeTableRecords([$rama])->assertCanNotSeeTableRecords([$shiva]);
        Livewire::test(ListTempleBalances::class)->searchTable('kolanu')->assertCanSeeTableRecords([$shiva])->assertCanNotSeeTableRecords([$rama]);
    }
}
