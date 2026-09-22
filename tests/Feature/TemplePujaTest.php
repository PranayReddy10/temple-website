<?php

namespace Tests\Feature;

use App\Models\Temple;
use App\Models\TemplePuja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplePujaTest extends TestCase
{
    use RefreshDatabase;

    protected function puja(array $attributes = []): TemplePuja
    {
        $temple = Temple::create(['name' => 'Puja Temple '.uniqid()]);

        return TemplePuja::create(array_merge([
            'temple_id' => $temple->id,
            'name' => 'Archana',
        ], $attributes));
    }

    public function test_an_unknown_price_is_not_reported_as_free(): void
    {
        $puja = $this->puja(['fee_amount' => null, 'is_free' => false]);

        // A devotee told "Free" who then finds a charge was misled by us.
        $this->assertSame('No published price', $puja->feeLabel());
    }

    public function test_a_free_puja_reads_as_free(): void
    {
        $puja = $this->puja(['is_free' => true]);

        $this->assertSame('Free', $puja->feeLabel());
    }

    public function test_marking_a_puja_free_clears_any_stored_amount(): void
    {
        $puja = $this->puja(['fee_amount' => 500, 'is_free' => true]);

        // Otherwise it could render as both free and ₹500.
        $this->assertNull($puja->fresh()->fee_amount);
    }

    public function test_a_published_fee_is_formatted_in_rupees(): void
    {
        $puja = $this->puja(['fee_amount' => 1500.5]);

        $this->assertSame('₹1,500.50', $puja->feeLabel());
    }

    public function test_official_booking_requires_a_url(): void
    {
        $puja = $this->puja(['booking_is_official' => true, 'booking_url' => null]);

        // The flag is a claim about a link. With no link there is no claim.
        $this->assertFalse($puja->fresh()->booking_is_official);
        $this->assertFalse($puja->fresh()->hasOfficialBooking());
    }

    public function test_a_third_party_link_is_never_labelled_official(): void
    {
        $puja = $this->puja([
            'booking_url' => 'https://some-reseller.example/book',
            'booking_is_official' => false,
        ]);

        $this->assertFalse($puja->hasOfficialBooking());
        $this->assertStringContainsString('not the official booking route', (string) $puja->bookingLabel());
    }

    public function test_a_confirmed_official_route_is_labelled_official(): void
    {
        $puja = $this->puja([
            'booking_url' => 'https://temple.example/seva',
            'booking_is_official' => true,
        ]);

        $this->assertTrue($puja->hasOfficialBooking());
        $this->assertSame('Official booking', $puja->bookingLabel());
    }

    public function test_an_uploaded_image_resolves_to_a_url(): void
    {
        config(['filesystems.media' => 'puja-media']);
        $disk = \Illuminate\Support\Facades\Storage::fake('puja-media');

        $temple = Temple::create(['name' => 'Image Temple']);
        $path = \Illuminate\Http\UploadedFile::fake()->image('seva.jpg')->store('pujas/'.$temple->id, 'puja-media');

        $puja = TemplePuja::create([
            'temple_id' => $temple->id,
            'name' => 'Abhishekam',
            'image_path' => $path,
        ]);

        $this->assertNotNull($puja->imageUrl());
        $this->assertStringContainsString('pujas/'.$temple->id, (string) $puja->imageUrl());
    }

    public function test_a_puja_without_an_image_has_no_url(): void
    {
        $this->assertNull($this->puja()->imageUrl());
    }

    public function test_deleting_a_puja_deletes_its_image(): void
    {
        config(['filesystems.media' => 'puja-media']);
        \Illuminate\Support\Facades\Storage::fake('puja-media');

        $temple = Temple::create(['name' => 'Cleanup Puja Temple']);
        $path = \Illuminate\Http\UploadedFile::fake()->image('seva.jpg')->store('pujas/'.$temple->id, 'puja-media');

        $puja = TemplePuja::create([
            'temple_id' => $temple->id,
            'name' => 'Archana',
            'image_path' => $path,
        ]);

        $puja->delete();

        // Orphaned objects in Spaces cost money and cannot be traced back.
        \Illuminate\Support\Facades\Storage::disk('puja-media')->assertMissing($path);
    }

    public function test_the_image_disk_is_recorded_on_the_row(): void
    {
        config(['filesystems.media' => 'puja-media']);

        $puja = $this->puja(['image_path' => 'pujas/1/x.jpg']);

        // So an image uploaded before a move to Spaces keeps resolving after.
        $this->assertSame('puja-media', $puja->fresh()->image_disk);
    }

    public function test_duration_is_rendered_in_hours_and_minutes(): void
    {
        $this->assertSame('45 min', $this->puja(['duration_minutes' => 45])->durationLabel());
        $this->assertSame('1 hr', $this->puja(['duration_minutes' => 60])->durationLabel());
        $this->assertSame('1 hr 30 min', $this->puja(['duration_minutes' => 90])->durationLabel());
        $this->assertNull($this->puja()->durationLabel());
    }
}
