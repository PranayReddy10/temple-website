<?php

namespace Database\Seeders;

use App\Models\Deity;
use App\Models\DevotionalDay;
use Illuminate\Database\Seeder;

/**
 * The traditional weekday-to-deity associations.
 *
 * Seeded as data, not constants, because regional traditions differ and more
 * than one deity per day is normal — Saturday is Shani in the north and
 * Venkateswara for many in the south. Editors add, reorder and disable these.
 *
 * Colours are devotional rather than decorative: the white-ash tone for
 * Shiva's vibhuti, sindoor orange for Hanuman, and so on. They drive the
 * day's accent in the app and tint the admin dashboard.
 */
class DevotionalDaySeeder extends Seeder
{
    public function run(): void
    {
        $days = [
            // [weekday, deity slug, title, subtitle, mantra, transliteration, colour, order]
            [1, 'shiva', 'Somavara — Shiva', 'Monday belongs to Mahadeva', 'ॐ नमः शिवाय', 'Om Namah Shivaya', '#6B7FA8', 1],
            [2, 'hanuman', 'Mangalavara — Hanuman', 'Tuesday for strength and protection', 'ॐ हं हनुमते नमः', 'Om Ham Hanumate Namaha', '#C1440E', 1],
            [2, 'ganesha', 'Mangalavara — Ganesha', 'Also kept for the remover of obstacles', 'ॐ गं गणपतये नमः', 'Om Gam Ganapataye Namaha', '#C1440E', 2],
            [3, 'krishna', 'Budhavara — Krishna', 'Wednesday for Govinda', 'ॐ नमो भगवते वासुदेवाय', 'Om Namo Bhagavate Vasudevaya', '#2E7D55', 1],
            [4, 'vishnu', 'Guruvara — Vishnu', 'Thursday for the preserver and the guru', 'ॐ नमो नारायणाय', 'Om Namo Narayanaya', '#C9A227', 1],
            [5, 'devi', 'Shukravara — Devi', 'Friday for the mother goddess', 'ॐ ऐं ह्रीं क्लीं चामुण्डायै विच्चे', 'Om Aim Hreem Kleem Chamundayai Vichche', '#9B1B30', 1],
            [5, 'lakshmi', 'Shukravara — Lakshmi', 'Also kept for prosperity and well-being', 'ॐ श्रीं महालक्ष्म्यै नमः', 'Om Shreem Mahalakshmyai Namaha', '#9B1B30', 2],
            [6, 'venkateswara', 'Shanivara — Venkateswara', 'Saturday at Tirumala', 'ॐ नमो वेंकटेशाय', 'Om Namo Venkatesaya', '#3E2723', 1],
            [0, 'surya', 'Ravivara — Surya', 'Sunday for the sun', 'ॐ सूर्याय नमः', 'Om Suryaya Namaha', '#E07A1F', 1],
        ];

        foreach ($days as [$weekday, $slug, $title, $subtitle, $mantra, $transliteration, $color, $order]) {
            $deity = Deity::where('slug', $slug)->first();

            if ($deity === null) {
                continue;
            }

            DevotionalDay::updateOrCreate(
                ['weekday' => $weekday, 'deity_id' => $deity->id],
                [
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'mantra' => $mantra,
                    'mantra_transliteration' => $transliteration,
                    'accent_color' => $color,
                    'sort_order' => $order,
                    'is_active' => true,
                ],
            );
        }
    }
}
