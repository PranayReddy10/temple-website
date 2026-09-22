<?php

namespace Database\Seeders;

use App\Enums\CheckInMethod;
use App\Enums\PhotoModerationStatus;
use App\Enums\YatraStatus;
use App\Models\Devotee;
use App\Models\DevoteeMemory;
use App\Models\DevoteeVisit;
use App\Models\LoginEvent;
use App\Models\Temple;
use App\Models\VisitPhoto;
use App\Models\Yatra;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Plausible devotee activity, so the analytics screen can be looked at.
 *
 * Explicitly NOT reference data: it is never seeded by `app:deploy`, and it
 * must never run against production. An empty analytics screen is honest; one
 * full of invented pilgrims is a screen someone will eventually quote a
 * number from.
 *
 * Run deliberately:
 *   php artisan db:seed --class=DemoDevoteeSeeder
 */
class DemoDevoteeSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoDevoteeSeeder refuses to run in production.');

            return;
        }

        $temples = Temple::query()->published()->get();

        if ($temples->isEmpty()) {
            $this->command?->warn('No published temples; seed those first.');

            return;
        }

        $devotees = $this->makeDevotees();

        foreach ($devotees as $index => $devotee) {
            $this->recordSignIns($devotee, $index);
            $visits = $this->recordVisits($devotee, $temples, $index);
            $this->recordPhotos($devotee, $visits, $index);
            $this->recordMemories($devotee, $visits, $index);
            $this->saveTemples($devotee, $temples, $index);
            $this->planTrip($devotee, $temples, $index);
        }

        $this->command?->info(count($devotees).' demo devotees with visits, photos, memories and trips.');
    }

    /** @return array<int, Devotee> */
    protected function makeDevotees(): array
    {
        // A spread of languages, because the language breakdown is the chart
        // that decides what gets translated next.
        $people = [
            ['Anusha Reddy', 'anusha@example.test', 'te'],
            ['Ravi Kumar', 'ravi@example.test', 'te'],
            ['Meera Iyer', 'meera@example.test', 'ta'],
            ['Suresh Nair', 'suresh@example.test', 'ml'],
            ['Priya Sharma', 'priya@example.test', 'hi'],
            ['Arjun Patel', 'arjun@example.test', 'gu'],
            ['Lakshmi Rao', 'lakshmi@example.test', 'te'],
            ['Deepak Joshi', 'deepak@example.test', 'hi'],
            ['Kavya Menon', 'kavya@example.test', 'ml'],
            ['Vikram Singh', 'vikram@example.test', 'en'],
            // Registered and never came back: the figure that usually means a
            // broken confirmation step, so the dashboard should show one.
            ['Neha Gupta', 'neha@example.test', 'hi'],
            ['Karthik Subramanian', 'karthik@example.test', 'ta'],
        ];

        return collect($people)
            ->map(fn (array $person, int $index): Devotee => Devotee::firstOrCreate(
                ['email' => $person[1]],
                [
                    'name' => $person[0],
                    'password' => Hash::make('devotee-demo-password'),
                    'locale' => $person[2],
                    'is_active' => $index !== 11,
                    'email_verified_at' => $index % 3 === 0 ? now()->subMonths(2) : null,
                    'created_at' => now()->subDays(60 - $index * 4),
                    'last_seen_at' => $index >= 10 ? null : now()->subDays($index),
                ],
            ))
            ->all();
    }

    protected function recordSignIns(Devotee $devotee, int $index): void
    {
        // The last two never signed in at all.
        if ($index >= 10) {
            return;
        }

        // Heavier users first, so daily/weekly/monthly actives differ from
        // each other — three identical numbers would tell nobody anything.
        $sessions = max(1, 24 - $index * 2);

        // Not everyone's latest session is today: with all of them today,
        // daily, weekly and monthly actives print the same number and the
        // screen looks broken rather than quiet.
        $lastSeenDaysAgo = [0, 0, 0, 1, 2, 4, 6, 9, 14, 21][$index];

        for ($session = 0; $session < $sessions; $session++) {
            LoginEvent::create([
                'authenticatable_type' => $devotee->getMorphClass(),
                'authenticatable_id' => $devotee->getKey(),
                'guard' => 'devotee',
                'succeeded' => true,
                'ip_address' => '203.0.113.'.(10 + $index),
                'platform' => $index % 2 === 0 ? 'android' : 'ios',
                'app_version' => '1.0.0',
                'occurred_at' => now()
                    ->subDays($lastSeenDaysAgo + intdiv($session * 25, max(1, $sessions)))
                    ->subHours($session % 12),
            ]);
        }

        // One person having a bad time with their password.
        if ($index === 3) {
            foreach (range(1, 4) as $attempt) {
                LoginEvent::create([
                    'guard' => 'devotee',
                    'identifier' => $devotee->email,
                    'succeeded' => false,
                    'failure_reason' => 'bad_password',
                    'ip_address' => '203.0.113.13',
                    'occurred_at' => now()->subDays(2)->subMinutes($attempt * 3),
                ]);
            }
        }
    }

    /** @return \Illuminate\Support\Collection<int, DevoteeVisit> */
    protected function recordVisits(Devotee $devotee, $temples, int $index)
    {
        if ($index >= 10) {
            return collect();
        }

        return $temples
            ->shuffle()
            ->take(max(1, 8 - intdiv($index, 2)))
            ->values()
            ->map(function (Temple $temple, int $n) use ($devotee, $index): DevoteeVisit {
                // A mix, so the passport shows the difference between a claim
                // and evidence rather than all rows looking alike.
                $method = match (($index + $n) % 3) {
                    0 => CheckInMethod::Gps,
                    1 => CheckInMethod::Qr,
                    default => CheckInMethod::Manual,
                };

                return DevoteeVisit::create([
                    'devotee_id' => $devotee->getKey(),
                    'temple_id' => $temple->getKey(),
                    'method' => $method,
                    'visited_on' => now()->subDays(($index * 7) + ($n * 11))->toDateString(),
                    'latitude' => $method === CheckInMethod::Manual ? null : $temple->latitude,
                    'longitude' => $method === CheckInMethod::Manual ? null : $temple->longitude,
                    'distance_metres' => $method === CheckInMethod::Manual ? null : ($n * 20),
                    'is_verified' => $method->isSelfVerifying(),
                    'verified_at' => $method->isSelfVerifying() ? now()->subDays($index * 7) : null,
                    'note' => $n === 0 ? 'Went with family for the morning darshan.' : null,
                ]);
            });
    }

    protected function recordPhotos(Devotee $devotee, $visits, int $index): void
    {
        $visits->take(2)->each(function (DevoteeVisit $visit, int $n) use ($devotee, $index): void {
            // A queue with something in it, so the moderation screen is not
            // empty the first time anyone opens it.
            $status = match (($index + $n) % 4) {
                0, 1 => PhotoModerationStatus::Pending,
                2 => PhotoModerationStatus::Approved,
                default => PhotoModerationStatus::Rejected,
            };

            // Real files, not just paths. A moderation queue full of broken
            // images demonstrates nothing, and the first thing anyone does
            // with this screen is look at a photo.
            $original = $this->placeholder('demo/originals/'.$devotee->getKey().'-'.$n.'.jpg', 1200, 800);
            $stamp = $this->placeholder('demo/stamps/'.$devotee->getKey().'-'.$n.'.jpg', 800, 800);

            VisitPhoto::create([
                'devotee_id' => $devotee->getKey(),
                'temple_id' => $visit->temple_id,
                'devotee_visit_id' => $visit->getKey(),
                'disk' => config('filesystems.media'),
                'original_path' => $original,
                'stamp_path' => $stamp,
                'caption' => $n === 0 ? 'Morning darshan.' : null,
                'status' => $status,
                'moderation_note' => $status === PhotoModerationStatus::Rejected
                    ? 'Not a photo of this temple.'
                    : null,
                'is_public' => $n === 0,
                'created_at' => now()->subDays($index + $n),
            ]);
        });
    }

    protected function recordMemories(Devotee $devotee, $visits, int $index): void
    {
        $visit = $visits->first();

        if ($visit === null) {
            return;
        }

        DevoteeMemory::create([
            'devotee_id' => $devotee->getKey(),
            'temple_id' => $visit->temple_id,
            'devotee_visit_id' => $visit->getKey(),
            'title' => 'The morning we arrived',
            'body' => 'The queue started before dawn and we could hear the bells from the road.',
            'happened_on' => $visit->visited_on,
            // Mostly private, which is the default and the point.
            'is_private' => $index % 3 !== 0,
        ]);
    }

    /**
     * A plain saffron-to-kumkum placeholder on the media disk.
     *
     * Returns the path whether or not it could be written: the row is still
     * worth having with a broken image, and a seeder that dies because GD is
     * missing is a seeder nobody runs.
     */
    protected function placeholder(string $path, int $width, int $height): string
    {
        $disk = Storage::disk(config('filesystems.media'));

        if ($disk->exists($path) || ! function_exists('imagecreatetruecolor')) {
            return $path;
        }

        $image = imagecreatetruecolor($width, $height);

        [$fromR, $fromG, $fromB] = sscanf(config('brand.colors.saffron.hex'), '#%02x%02x%02x');
        [$toR, $toG, $toB] = sscanf(config('brand.colors.kumkum.hex'), '#%02x%02x%02x');

        for ($y = 0; $y < $height; $y++) {
            $ratio = $y / max(1, $height - 1);
            $colour = imagecolorallocate(
                $image,
                (int) round($fromR + ($toR - $fromR) * $ratio),
                (int) round($fromG + ($toG - $fromG) * $ratio),
                (int) round($fromB + ($toB - $fromB) * $ratio),
            );
            imageline($image, 0, $y, $width, $y, $colour);
        }

        ob_start();
        imagejpeg($image, null, 70);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $disk->put($path, $bytes);

        return $path;
    }

    /**
     * Favourites: saved temples.
     *
     * More of them than there are visits, which is the honest shape — people
     * save far more than they reach, and a temple with many saves and no
     * visits is the interesting row on the popular-temples table.
     */
    protected function saveTemples(Devotee $devotee, $temples, int $index): void
    {
        $saved = $temples->shuffle()->take(max(2, 10 - $index))->pluck('id');

        $devotee->savedTemples()->syncWithoutDetaching(
            $saved->mapWithKeys(fn (int $id): array => [$id => ['note' => null]])->all(),
        );
    }

    protected function planTrip(Devotee $devotee, $temples, int $index): void
    {
        if ($index >= 9) {
            return;
        }

        $status = match ($index % 5) {
            0, 1 => YatraStatus::Planning,
            2 => YatraStatus::Confirmed,
            3 => YatraStatus::Completed,
            default => YatraStatus::InProgress,
        };

        $starts = $status->isUpcoming()
            ? now()->addDays(15 + $index * 10)
            : now()->subDays(30 + $index * 5);

        $yatra = Yatra::create([
            'devotee_id' => $devotee->getKey(),
            'title' => ['Char Dham', 'Jyotirlinga run', 'Temple weekend', 'Family yatra'][$index % 4],
            'description' => 'Planned around the school holidays.',
            'status' => $status,
            'starts_on' => $starts->toDateString(),
            'ends_on' => $starts->copy()->addDays(4 + ($index % 6))->toDateString(),
            'party_size' => 2 + ($index % 5),
            'created_at' => now()->subDays(20 - $index),
        ]);

        $temples->shuffle()->take(3 + ($index % 3))->values()->each(
            fn (Temple $temple, int $n) => $yatra->stops()->create([
                'temple_id' => $temple->getKey(),
                'day_number' => intdiv($n, 2) + 1,
                'sort_order' => $n,
            ]),
        );
    }
}
