<?php

namespace Database\Seeders;

use App\Models\Deity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The deities the app lets devotees explore by, per section 3 of the plan.
 * Alternate names matter: the same deity is known differently across regions.
 */
class DeitySeeder extends Seeder
{
    public function run(): void
    {
        $deities = [
            ['Shiva', 'Mahadeva, Shankara, Rudra, Nataraja, Bholenath', 'The destroyer and transformer, worshipped widely as the lingam.'],
            ['Vishnu', 'Narayana, Hari, Padmanabha', 'The preserver, worshipped in many forms and avatars.'],
            ['Devi', 'Parvati, Durga, Shakti, Amman, Ambika', 'The mother goddess in her many forms.'],
            ['Ganesha', 'Ganapati, Vinayaka, Vighneshwara, Pillaiyar', 'The remover of obstacles, invoked before any beginning.'],
            ['Hanuman', 'Anjaneya, Maruti, Bajrangbali', 'The devoted servant of Rama, symbol of strength and devotion.'],
            ['Murugan', 'Kartikeya, Subrahmanya, Skanda, Shanmukha', 'The commander of the divine armies, especially revered in Tamil Nadu.'],
            ['Krishna', 'Govinda, Gopala, Madhava, Vasudeva', 'The eighth avatar of Vishnu, teacher of the Bhagavad Gita.'],
            ['Rama', 'Ramachandra, Raghava, Maryada Purushottama', 'The seventh avatar of Vishnu, the ideal king.'],
            ['Lakshmi', 'Sri, Mahalakshmi, Kamala', 'The goddess of prosperity and well-being.'],
            ['Saraswati', 'Sharada, Vagdevi', 'The goddess of learning, music and speech.'],
            ['Venkateswara', 'Balaji, Srinivasa, Govinda', 'A form of Vishnu, presiding deity of Tirumala.'],
            ['Ayyappa', 'Dharma Shasta, Manikandan', 'Presiding deity of Sabarimala.'],
            ['Surya', 'Aditya, Bhaskara', 'The sun god.'],
            ['Kali', 'Mahakali, Bhadrakali', 'The fierce form of the mother goddess.'],
            ['Narasimha', 'Nrisimha, Lakshmi Narasimha', 'The man-lion avatar of Vishnu.'],
        ];

        foreach ($deities as $index => [$name, $alternates, $description]) {
            Deity::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'alternate_names' => $alternates,
                    'description' => $description,
                    'sort_order' => $index + 1,
                    'is_active' => true,
                ],
            );
        }
    }
}
