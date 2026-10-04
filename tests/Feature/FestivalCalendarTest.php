<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Festival;
use App\Models\User;
use App\Support\FestivalImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * India's festival calendar: loaded from the computed file, served to the
 * app by date range, and correctable by editors without a later import
 * undoing the correction.
 */
class FestivalCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shipped_calendar_has_2026_and_2027_with_the_major_festivals(): void
    {
        $result = FestivalImporter::import();
        $this->assertGreaterThan(250, $result['added']);

        $date = fn (string $slug, string $year) => Festival::query()->where('slug', $slug)->whereYear('starts_on', $year)->value('starts_on')?->toDateString();

        // Dates as all-India almanacs give them.
        $this->assertSame('2026-02-15', $date('maha-shivaratri', '2026'));
        $this->assertSame('2026-03-04', $date('holi', '2026'));
        $this->assertSame('2026-03-19', $date('ugadi', '2026'));
        $this->assertSame('2026-09-14', $date('ganesh-chaturthi', '2026'));
        $this->assertSame('2026-10-20', $date('dussehra', '2026'));
        $this->assertSame('2026-11-08', $date('diwali', '2026'));
        $this->assertNotNull($date('diwali', '2027'));
        // Two Ekadashis a lunar month.
        $this->assertGreaterThanOrEqual(24, Festival::query()->where('name', 'like', '%Ekadashi')->whereYear('starts_on', '2027')->count());
    }

    public function test_an_editors_correction_survives_a_second_import(): void
    {
        FestivalImporter::import();
        $diwali = Festival::query()->where('slug', 'diwali')->whereYear('starts_on', '2026')->firstOrFail();
        $diwali->update(['starts_on' => '2026-11-09']);
        $count = Festival::query()->count();

        $again = FestivalImporter::import();

        $this->assertSame(0, $again['added']);
        $this->assertSame($count, Festival::query()->count());
        $this->assertSame('2026-11-09', $diwali->refresh()->starts_on->toDateString());
    }

    public function test_the_app_reads_a_month_of_festivals(): void
    {
        FestivalImporter::import();

        $this->getJson('/api/v1/festivals?from=2026-11-01&to=2026-11-30&kind=festival')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'diwali', 'starts_on' => '2026-11-08', 'is_major' => true])
            ->assertJsonMissing(['kind' => 'vrat']);

        $this->getJson('/api/v1/festivals?from=2026-11-01&to=2026-11-30')
            ->assertOk()
            ->assertJsonFragment(['kind' => 'vrat']);

        Festival::query()->where('slug', 'diwali')->update(['is_published' => false]);
        $this->getJson('/api/v1/festivals?from=2026-11-01&to=2026-11-30')->assertJsonMissing(['slug' => 'diwali']);
    }

    public function test_editors_manage_the_calendar_in_the_admin(): void
    {
        FestivalImporter::import();
        $this->actingAs(User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]));

        $this->get('/admin/festivals')->assertOk();
        $this->get('/admin/festivals/'.Festival::query()->where('slug', 'diwali')->value('id').'/edit')->assertOk()->assertSee('Diwali');
    }
}
