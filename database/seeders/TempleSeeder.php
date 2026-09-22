<?php

namespace Database\Seeders;

use App\Enums\TempleStatus;
use App\Enums\VerificationStatus;
use App\Models\Deity;
use App\Models\District;
use App\Models\State;
use App\Models\Temple;
use App\Models\TempleCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Starter temple records so the admin panel has something real to work with.
 *
 * Deliberate choice: every record here is seeded as COMMUNITY level with no
 * source and no verification date. These entries were not checked against an
 * official temple or government source, and section 20 of the plan is explicit
 * that unverified information must never be dressed up as official. The
 * coordinates are approximate, good enough to place a marker and no more.
 *
 * Editors are expected to work through these, confirm each field against a
 * primary source, attach that source and only then raise the trust level. The
 * "Needs re-verification" filter in the temples table lists exactly this queue.
 *
 * Telangana temples, including Yadadri and Bhadrachalam, live in
 * TelanganaTempleSeeder, which can also be imported into a live database.
 */
class TempleSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->temples() as $row) {
            $state = State::where('code', $row['state'])->first();

            if ($state === null) {
                continue;
            }

            $district = District::firstOrCreate(
                ['state_id' => $state->id, 'slug' => Str::slug($row['district'])],
                ['name' => $row['district']],
            );

            $temple = Temple::updateOrCreate(
                ['slug' => Str::slug($row['name'])],
                [
                    'name' => $row['name'],
                    'deity_id' => Deity::where('slug', $row['deity'])->value('id'),
                    'state_id' => $state->id,
                    'district_id' => $district->id,
                    'city' => $row['city'],
                    'latitude' => $row['lat'],
                    'longitude' => $row['lng'],
                    'short_description' => $row['summary'],
                    'status' => $row['status'],
                    // Seed data is not source-checked. It says so.
                    'verification_status' => VerificationStatus::Community,
                    'source_name' => null,
                    'source_url' => null,
                    'last_verified_at' => null,
                ],
            );

            if ($row['categories'] !== []) {
                $temple->categories()->sync(
                    TempleCategory::whereIn('slug', $row['categories'])->pluck('id')
                );
            }

            if ($row['aliases'] !== []) {
                $temple->aliases()->delete();
                foreach ($row['aliases'] as $alias => $locale) {
                    $temple->aliases()->create(['name' => $alias, 'locale' => $locale]);
                }
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    protected function temples(): array
    {
        $p = TempleStatus::Published;
        $d = TempleStatus::Draft;
        $r = TempleStatus::InReview;

        return [
            [
                'name' => 'Sri Venkateswara Swamy Temple, Tirumala', 'deity' => 'venkateswara',
                'state' => 'AP', 'district' => 'Tirupati', 'city' => 'Tirumala',
                'lat' => 13.6833, 'lng' => 79.3474, 'status' => $p,
                'categories' => ['divya-desam'],
                'aliases' => ['Tirupati Balaji' => 'en', 'తిరుమల వెంకటేశ్వర ఆలయం' => 'te'],
                'summary' => 'Hill shrine of Venkateswara at Tirumala, among the most visited pilgrimage sites in India.',
            ],
            [
                'name' => 'Kashi Vishwanath Temple', 'deity' => 'shiva',
                'state' => 'UP', 'district' => 'Varanasi', 'city' => 'Varanasi',
                'lat' => 25.3109, 'lng' => 83.0107, 'status' => $p,
                'categories' => ['jyotirlinga', 'sapta-puri', 'river-ghat-temple'],
                'aliases' => ['Vishwanath Temple' => 'en', 'काशी विश्वनाथ मंदिर' => 'hi'],
                'summary' => 'Jyotirlinga shrine on the western bank of the Ganga in Varanasi.',
            ],
            [
                'name' => 'Somnath Temple', 'deity' => 'shiva',
                'state' => 'GJ', 'district' => 'Gir Somnath', 'city' => 'Prabhas Patan',
                'lat' => 20.8880, 'lng' => 70.4012, 'status' => $p,
                'categories' => ['jyotirlinga', 'coastal-temple'],
                'aliases' => ['Somanath' => 'en'],
                'summary' => 'First among the twelve Jyotirlingas, on the Arabian Sea coast of Saurashtra.',
            ],
            [
                'name' => 'Mahakaleshwar Temple, Ujjain', 'deity' => 'shiva',
                'state' => 'MP', 'district' => 'Ujjain', 'city' => 'Ujjain',
                'lat' => 23.1828, 'lng' => 75.7682, 'status' => $p,
                'categories' => ['jyotirlinga', 'sapta-puri'],
                'aliases' => ['Mahakal Temple' => 'en', 'महाकालेश्वर मंदिर' => 'hi'],
                'summary' => 'Jyotirlinga known for the Bhasma Aarti performed at dawn.',
            ],
            [
                'name' => 'Meenakshi Amman Temple', 'deity' => 'devi',
                'state' => 'TN', 'district' => 'Madurai', 'city' => 'Madurai',
                'lat' => 9.9195, 'lng' => 78.1193, 'status' => $p,
                'categories' => [],
                'aliases' => ['Meenakshi Sundareswarar Temple' => 'en', 'மீனாட்சி அம்மன் கோயில்' => 'ta'],
                'summary' => 'Twin shrines to Meenakshi and Sundareswarar, known for towering painted gopurams.',
            ],
            [
                'name' => 'Jagannath Temple, Puri', 'deity' => 'krishna',
                'state' => 'OD', 'district' => 'Puri', 'city' => 'Puri',
                'lat' => 19.8048, 'lng' => 85.8180, 'status' => $p,
                'categories' => ['char-dham', 'coastal-temple'],
                'aliases' => ['Shree Jagannatha Temple' => 'en'],
                'summary' => 'Char Dham shrine to Jagannath, Balabhadra and Subhadra, home of the Rath Yatra.',
            ],
            [
                'name' => 'Badrinath Temple', 'deity' => 'vishnu',
                'state' => 'UK', 'district' => 'Chamoli', 'city' => 'Badrinath',
                'lat' => 30.7433, 'lng' => 79.4938, 'status' => $p,
                'categories' => ['char-dham', 'chota-char-dham', 'divya-desam', 'hill-temple'],
                'aliases' => ['Badrinarayan Temple' => 'en'],
                'summary' => 'Himalayan Char Dham shrine on the bank of the Alaknanda, open roughly May to November.',
            ],
            [
                'name' => 'Kedarnath Temple', 'deity' => 'shiva',
                'state' => 'UK', 'district' => 'Rudraprayag', 'city' => 'Kedarnath',
                'lat' => 30.7346, 'lng' => 79.0669, 'status' => $p,
                'categories' => ['jyotirlinga', 'chota-char-dham', 'hill-temple'],
                'aliases' => ['केदारनाथ मंदिर' => 'hi'],
                'summary' => 'Highest of the twelve Jyotirlingas, reached on foot from Gaurikund.',
            ],
            [
                'name' => 'Ramanathaswamy Temple, Rameswaram', 'deity' => 'shiva',
                'state' => 'TN', 'district' => 'Ramanathapuram', 'city' => 'Rameswaram',
                'lat' => 9.2881, 'lng' => 79.3174, 'status' => $p,
                'categories' => ['jyotirlinga', 'char-dham', 'coastal-temple'],
                'aliases' => ['Rameswaram Temple' => 'en'],
                'summary' => 'Jyotirlinga and Char Dham site, known for the longest temple corridor in India.',
            ],
            [
                'name' => 'Dwarkadhish Temple', 'deity' => 'krishna',
                'state' => 'GJ', 'district' => 'Devbhumi Dwarka', 'city' => 'Dwarka',
                'lat' => 22.2376, 'lng' => 68.9678, 'status' => $p,
                'categories' => ['char-dham', 'divya-desam', 'coastal-temple'],
                'aliases' => ['Jagat Mandir' => 'en'],
                'summary' => 'Char Dham shrine to Krishna as king of Dwarka, on the Gomti creek.',
            ],
            [
                'name' => 'Vaishno Devi Temple, Katra', 'deity' => 'devi',
                'state' => 'JK', 'district' => 'Reasi', 'city' => 'Katra',
                'lat' => 33.0308, 'lng' => 74.9497, 'status' => $p,
                'categories' => ['shakti-peetha', 'hill-temple', 'cave-temple'],
                'aliases' => ['Mata Vaishno Devi' => 'en', 'वैष्णो देवी' => 'hi'],
                'summary' => 'Cave shrine in the Trikuta hills, reached by a marked trek from Katra.',
            ],
            [
                'name' => 'Sabarimala Sree Dharmasastha Temple', 'deity' => 'ayyappa',
                'state' => 'KL', 'district' => 'Pathanamthitta', 'city' => 'Sabarimala',
                'lat' => 9.4360, 'lng' => 77.0811, 'status' => $p,
                'categories' => ['hill-temple', 'forest-temple'],
                'aliases' => ['Sabarimala Ayyappa Temple' => 'en'],
                'summary' => 'Forest hill shrine to Ayyappa, opened for the Mandala and Makaravilakku seasons.',
            ],
            [
                'name' => 'Brihadeeswarar Temple, Thanjavur', 'deity' => 'shiva',
                'state' => 'TN', 'district' => 'Thanjavur', 'city' => 'Thanjavur',
                'lat' => 10.7828, 'lng' => 79.1318, 'status' => $p,
                'categories' => ['unesco-world-heritage'],
                'aliases' => ['Peruvudaiyar Kovil' => 'en', 'Big Temple' => 'en'],
                'summary' => 'Chola temple completed around 1010 CE, part of the Great Living Chola Temples.',
            ],
            [
                'name' => 'Konark Sun Temple', 'deity' => 'surya',
                'state' => 'OD', 'district' => 'Puri', 'city' => 'Konark',
                'lat' => 19.8876, 'lng' => 86.0945, 'status' => $p,
                'categories' => ['unesco-world-heritage', 'coastal-temple'],
                'aliases' => ['Black Pagoda' => 'en'],
                'summary' => 'Thirteenth-century temple built as the sun god\'s chariot, a UNESCO World Heritage Site.',
            ],
            [
                'name' => 'Siddhivinayak Temple, Mumbai', 'deity' => 'ganesha',
                'state' => 'MH', 'district' => 'Mumbai City', 'city' => 'Mumbai',
                'lat' => 19.0170, 'lng' => 72.8302, 'status' => $p,
                'categories' => [],
                'aliases' => ['Shree Siddhivinayak Ganapati Mandir' => 'en'],
                'summary' => 'Ganesha temple at Prabhadevi, among the busiest shrines in Mumbai.',
            ],
            [
                'name' => 'Mallikarjuna Jyotirlinga Temple, Srisailam', 'deity' => 'shiva',
                'state' => 'AP', 'district' => 'Nandyal', 'city' => 'Srisailam',
                'lat' => 16.0733, 'lng' => 78.8683, 'status' => $p,
                'categories' => ['jyotirlinga', 'shakti-peetha', 'hill-temple', 'forest-temple'],
                'aliases' => ['Srisailam Temple' => 'en', 'శ్రీశైలం' => 'te'],
                'summary' => 'Rare site counted as both a Jyotirlinga and a Shakti Peetha, above the Krishna river.',
            ],
            [
                'name' => 'Kamakhya Temple, Guwahati', 'deity' => 'devi',
                'state' => 'AS', 'district' => 'Kamrup Metropolitan', 'city' => 'Guwahati',
                'lat' => 26.1665, 'lng' => 91.7060, 'status' => $p,
                'categories' => ['shakti-peetha', 'hill-temple'],
                'aliases' => ['Kamakhya Devalaya' => 'en'],
                'summary' => 'Shakti Peetha on Nilachal Hill, known for the Ambubachi Mela.',
            ],
            [
                'name' => 'Lingaraj Temple, Bhubaneswar', 'deity' => 'shiva',
                'state' => 'OD', 'district' => 'Khordha', 'city' => 'Bhubaneswar',
                'lat' => 20.2385, 'lng' => 85.8338, 'status' => $p,
                'categories' => [],
                'aliases' => ['Lingaraja Temple' => 'en'],
                'summary' => 'Eleventh-century Kalinga-style temple, the largest in Bhubaneswar.',
            ],
            [
                'name' => 'Guruvayur Sri Krishna Temple', 'deity' => 'krishna',
                'state' => 'KL', 'district' => 'Thrissur', 'city' => 'Guruvayur',
                'lat' => 10.5949, 'lng' => 76.0411, 'status' => $r,
                'categories' => [],
                'aliases' => ['Guruvayoor Temple' => 'en', 'ഗുരുവായൂർ ക്ഷേത്രം' => 'ml'],
                'summary' => 'Krishna temple in Thrissur district, often called the Dwarka of the south.',
            ],
            [
                'name' => 'Kailasa Temple, Ellora', 'deity' => 'shiva',
                'state' => 'MH', 'district' => 'Chhatrapati Sambhajinagar', 'city' => 'Ellora',
                'lat' => 20.0268, 'lng' => 75.1779, 'status' => $d,
                'categories' => ['unesco-world-heritage', 'rock-cut-temple', 'cave-temple'],
                'aliases' => ['Kailasanatha Temple' => 'en', 'Cave 16' => 'en'],
                'summary' => 'Monolithic temple carved downward from a single basalt cliff, Ellora Cave 16.',
            ],
        ];
    }
}
