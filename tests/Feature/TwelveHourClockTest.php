<?php

namespace Tests\Feature;

use App\Models\Temple;
use App\Models\TempleTiming;
use App\Support\Clock;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TimePicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Times are shown on the 12-hour clock everywhere people read them. */
class TwelveHourClockTest extends TestCase
{
    use RefreshDatabase;

    public function test_clock_reads_stored_times_as_twelve_hour(): void
    {
        $this->assertSame('5:30 AM', Clock::twelve('05:30'));
        $this->assertSame('5:30 PM', Clock::twelve('17:30:00'));
        $this->assertSame('12:00 PM', Clock::twelve('12:00'));
        $this->assertSame('12:15 AM', Clock::twelve('00:15'));
        $this->assertNull(Clock::twelve(null));
        $this->assertNull(Clock::twelve('soon'));
    }

    public function test_a_timing_window_is_twelve_hour(): void
    {
        $temple = Temple::create(['name' => 'Tirumala']);
        $timing = TempleTiming::create(['temple_id' => $temple->id, 'kind' => 'darshan', 'opens_at' => '04:30', 'closes_at' => '21:00']);

        $this->assertSame('4:30 AM – 9:00 PM', $timing->window());
    }

    public function test_admin_time_pickers_show_twelve_hour(): void
    {
        $time = TimePicker::make('opens_at');
        $this->assertFalse($time->isNative());
        $this->assertSame('h:i A', $time->getDisplayFormat());

        $this->assertSame('d M Y, h:i A', DateTimePicker::make('when')->getDisplayFormat());
        // Date-only pickers are left as they were.
        $this->assertTrue(DatePicker::make('day')->isNative());
    }
}
