<?php

namespace Database\Seeders;

use App\Models\State;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** All 28 states and 8 union territories, with ISO 3166-2:IN codes. */
class StateSeeder extends Seeder
{
    public function run(): void
    {
        $states = [
            ['Andhra Pradesh', 'AP'], ['Arunachal Pradesh', 'AR'], ['Assam', 'AS'],
            ['Bihar', 'BR'], ['Chhattisgarh', 'CG'], ['Goa', 'GA'], ['Gujarat', 'GJ'],
            ['Haryana', 'HR'], ['Himachal Pradesh', 'HP'], ['Jharkhand', 'JH'],
            ['Karnataka', 'KA'], ['Kerala', 'KL'], ['Madhya Pradesh', 'MP'],
            ['Maharashtra', 'MH'], ['Manipur', 'MN'], ['Meghalaya', 'ML'],
            ['Mizoram', 'MZ'], ['Nagaland', 'NL'], ['Odisha', 'OD'], ['Punjab', 'PB'],
            ['Rajasthan', 'RJ'], ['Sikkim', 'SK'], ['Tamil Nadu', 'TN'],
            ['Telangana', 'TG'], ['Tripura', 'TR'], ['Uttar Pradesh', 'UP'],
            ['Uttarakhand', 'UK'], ['West Bengal', 'WB'],
        ];

        $unionTerritories = [
            ['Andaman and Nicobar Islands', 'AN'], ['Chandigarh', 'CH'],
            ['Dadra and Nagar Haveli and Daman and Diu', 'DH'], ['Delhi', 'DL'],
            ['Jammu and Kashmir', 'JK'], ['Ladakh', 'LA'], ['Lakshadweep', 'LD'],
            ['Puducherry', 'PY'],
        ];

        foreach ($states as [$name, $code]) {
            $this->store($name, $code, 'state');
        }

        foreach ($unionTerritories as [$name, $code]) {
            $this->store($name, $code, 'union_territory');
        }
    }

    protected function store(string $name, string $code, string $type): void
    {
        State::updateOrCreate(
            ['code' => $code],
            ['name' => $name, 'slug' => Str::slug($name), 'type' => $type],
        );
    }
}
