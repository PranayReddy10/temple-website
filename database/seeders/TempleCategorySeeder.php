<?php

namespace Database\Seeders;

use App\Models\TempleCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Recognised pilgrimage groups from section 3 of the plan, plus a few
 * structural temple types. Circuits carry their canonical member count so the
 * admin can show how complete the database is for each one.
 */
class TempleCategorySeeder extends Seeder
{
    public function run(): void
    {
        $circuits = [
            ['Jyotirlinga', 12, 'The twelve radiant manifestations of Shiva.'],
            ['Shakti Peetha', 51, 'Shrines marking where parts of Sati fell; counts vary by tradition, 51 is the most widely cited.'],
            ['Char Dham', 4, 'Badrinath, Dwarka, Puri and Rameswaram.'],
            ['Chota Char Dham', 4, 'Yamunotri, Gangotri, Kedarnath and Badrinath in Uttarakhand.'],
            ['Divya Desam', 108, 'The 108 Vishnu temples praised by the Alvars.'],
            ['Ashta Vinayak', 8, 'Eight Ganesha temples around Pune, Maharashtra.'],
            ['Pancharama', 5, 'Five ancient Shiva temples in Andhra Pradesh.'],
            ['Pancha Bhoota Sthalam', 5, 'Five Shiva temples representing the five elements.'],
            ['Arupadai Veedu', 6, 'The six abodes of Murugan in Tamil Nadu.'],
            ['Sapta Puri', 7, 'The seven holy cities of Hinduism.'],
        ];

        $types = [
            ['Cave Temple', 'Temples carved into rock, such as Ellora and Badami.'],
            ['Rock-cut Temple', 'Temples hewn from a single rock face.'],
            ['Hill Temple', 'Temples requiring an ascent or hill climb.'],
            ['River Ghat Temple', 'Temples on a river bank with bathing ghats.'],
            ['Coastal Temple', 'Temples on or near the shoreline.'],
            ['Forest Temple', 'Temples within forest or wildlife areas.'],
            ['UNESCO World Heritage', 'Temples inscribed on the UNESCO World Heritage list.'],
        ];

        $order = 0;

        foreach ($circuits as [$name, $count, $description]) {
            TempleCategory::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'kind' => 'circuit',
                    'expected_count' => $count,
                    'description' => $description,
                    'sort_order' => ++$order,
                    'is_active' => true,
                ],
            );
        }

        foreach ($types as [$name, $description]) {
            TempleCategory::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'kind' => 'type',
                    'expected_count' => null,
                    'description' => $description,
                    'sort_order' => ++$order,
                    'is_active' => true,
                ],
            );
        }
    }
}
