<?php

namespace Database\Seeders;

use App\Models\Facility;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Facilities from section 4 of the project plan. Accessibility items are a
 * separate group because they decide whether a visit is possible at all, not
 * merely how comfortable it is.
 */
class FacilitySeeder extends Seeder
{
    public function run(): void
    {
        $amenities = [
            ['Parking', 'heroicon-o-truck'],
            ['Toilets', 'heroicon-o-home-modern'],
            ['Drinking water', 'heroicon-o-beaker'],
            ['Cloakroom', 'heroicon-o-lock-closed'],
            ['Footwear stand', 'heroicon-o-shopping-bag'],
            ['Prasadam counter', 'heroicon-o-gift'],
            ['Annadanam / free meals', 'heroicon-o-cake'],
            ['Temple accommodation', 'heroicon-o-building-office-2'],
            ['Queue shelter', 'heroicon-o-home'],
            ['First aid', 'heroicon-o-heart'],
            ['Cloak counter for mobiles', 'heroicon-o-device-phone-mobile'],
            ['Online booking counter', 'heroicon-o-ticket'],
        ];

        $accessibility = [
            ['Wheelchair access', 'heroicon-o-user'],
            ['Wheelchair available on request', 'heroicon-o-user-plus'],
            ['Ramp access', 'heroicon-o-arrow-trending-up'],
            ['Lift access', 'heroicon-o-arrows-up-down'],
            ['Senior citizen queue', 'heroicon-o-user-group'],
            ['Assistance for differently abled', 'heroicon-o-hand-raised'],
        ];

        $order = 0;

        foreach ($amenities as [$name, $icon]) {
            $this->store($name, 'amenity', $icon, ++$order);
        }

        foreach ($accessibility as [$name, $icon]) {
            $this->store($name, 'accessibility', $icon, ++$order);
        }
    }

    protected function store(string $name, string $group, string $icon, int $order): void
    {
        Facility::updateOrCreate(
            ['slug' => Str::slug($name)],
            [
                'name' => $name,
                'group' => $group,
                'icon' => $icon,
                'sort_order' => $order,
                'is_active' => true,
            ],
        );
    }
}
