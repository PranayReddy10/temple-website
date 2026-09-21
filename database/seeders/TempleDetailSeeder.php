<?php

namespace Database\Seeders;

use App\Enums\TimingKind;
use App\Models\Facility;
use App\Models\Temple;
use Illuminate\Database\Seeder;

/**
 * Adds timings, sevas and facilities to a few seeded temples so the admin
 * panel and API have realistic structure to work with.
 *
 * As with TempleSeeder, none of this is source-verified, and the temples it
 * attaches to are all at COMMUNITY trust level. Note in particular that every
 * seeded puja leaves fee_amount null, which the API reports as "No published
 * price" rather than free. Inventing a seva fee would be exactly the kind of
 * unofficial-presented-as-official error section 13 of the plan forbids —
 * a devotee could arrive expecting one price and find another.
 *
 * Editors replace all of it with published figures during verification.
 */
class TempleDetailSeeder extends Seeder
{
    public function run(): void
    {
        $facilitySlugs = [
            'parking', 'toilets', 'drinking-water', 'cloakroom',
            'footwear-stand', 'prasadam-counter', 'queue-shelter',
        ];
        $accessibilitySlugs = ['wheelchair-access', 'senior-citizen-queue'];

        $facilityIds = Facility::whereIn('slug', $facilitySlugs)->pluck('id');
        $accessibilityIds = Facility::whereIn('slug', $accessibilitySlugs)->pluck('id');

        $targets = [
            'kashi-vishwanath-temple' => [
                'dress_code' => 'Modest dress expected. Traditional attire preferred for entry to the sanctum.',
                'photography_policy' => 'Not permitted inside the temple complex.',
                'mobile_policy' => 'Mobile phones must be deposited before entry.',
                'footwear_policy' => 'To be left at the designated footwear counters.',
            ],
            'meenakshi-amman-temple' => [
                'dress_code' => 'Traditional dress required. Men: dhoti or trousers with upper cloth. Women: saree or salwar.',
                'photography_policy' => 'Permitted in outer corridors only; not in the sanctum.',
                'mobile_policy' => 'Permitted but must be switched to silent.',
                'footwear_policy' => 'To be left outside the entrance towers.',
            ],
            'mallikarjuna-jyotirlinga-temple-srisailam' => [
                'dress_code' => 'Traditional dress required for abhishekam participation.',
                'photography_policy' => 'Not permitted inside the sanctum.',
                'footwear_policy' => 'Footwear stands available at each entrance.',
            ],
        ];

        foreach ($targets as $slug => $rules) {
            $temple = Temple::where('slug', $slug)->first();

            if ($temple === null) {
                continue;
            }

            $temple->forceFill($rules)->saveQuietly();

            $temple->facilities()->syncWithoutDetaching(
                $facilityIds->mapWithKeys(fn ($id) => [$id => ['is_verified' => false]])->all()
                + $accessibilityIds->mapWithKeys(fn ($id) => [$id => ['is_verified' => false]])->all()
            );

            if ($temple->timings()->exists()) {
                continue;
            }

            $temple->timings()->createMany([
                [
                    'kind' => TimingKind::General,
                    'label' => null,
                    'opens_at' => '05:00',
                    'closes_at' => '21:00',
                    'notes' => 'Placeholder hours — confirm against the official source before publishing.',
                    'sort_order' => 1,
                ],
                [
                    'kind' => TimingKind::Aarti,
                    'label' => 'Morning aarti',
                    'opens_at' => '05:30',
                    'closes_at' => '06:30',
                    'sort_order' => 2,
                ],
                [
                    'kind' => TimingKind::Aarti,
                    'label' => 'Evening aarti',
                    'opens_at' => '18:30',
                    'closes_at' => '19:30',
                    'sort_order' => 3,
                ],
            ]);

            $temple->pujas()->createMany([
                [
                    'name' => 'Archana',
                    'description' => 'Recitation of the deity\'s names with offerings on behalf of the devotee.',
                    'duration_minutes' => 15,
                    'schedule_note' => 'Through the day during opening hours',
                    // Left null on purpose: "No published price", never "Free".
                    'fee_amount' => null,
                    'sort_order' => 1,
                ],
                [
                    'name' => 'Abhishekam',
                    'description' => 'Ceremonial bathing of the deity.',
                    'starts_at' => '06:00',
                    'duration_minutes' => 45,
                    'schedule_note' => 'Daily, early morning',
                    'fee_amount' => null,
                    'sort_order' => 2,
                ],
            ]);
        }
    }
}
