<?php

namespace Database\Seeders;

use App\Enums\TempleStatus;
use App\Enums\TimingKind;
use App\Enums\VerificationStatus;
use App\Models\Deity;
use App\Models\District;
use App\Models\State;
use App\Models\Temple;
use App\Models\TempleCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Temples of Telangana: the major pilgrimage sites of every region of the
 * state, with the famous ones marked as featured.
 *
 * The same honesty rules as TempleSeeder apply, and matter more here because
 * this set is larger and carries timings:
 *
 * - Every record is COMMUNITY level. Where a Wikipedia or UNESCO page exists
 *   it is recorded as the source, which is exactly what community level
 *   means — a public reference, not a check against the temple itself.
 * - Coordinates are approximate, good enough to place a marker and no more.
 * - Timings are typical hours as commonly reported and every general row
 *   says so. Temples change hours for festivals, eclipses and renovations.
 * - Every puja leaves fee_amount null ("No published price", never "Free"),
 *   and no booking link is marked official. Chilkur Balaji's pradakshina is
 *   the one free entry, because it is a devotee's own vow, not a paid seva.
 * - No photographs. A photograph needs a credit and a licence, and none were
 *   collected; editors upload them through the admin with attribution.
 *
 * Safe to re-run, including in production through `temples:import-telangana`:
 *
 * - A temple an editor has verified (verified or official level, or any
 *   last-verified date) is never touched.
 * - An existing community record only has its EMPTY fields filled; nothing an
 *   editor typed is overwritten, and its publishing status is left alone.
 * - Timings and pujas are only added to a temple that has none.
 * - Featured is only ever switched on, never off. Re-running re-applies the
 *   famous mark to a community record an editor un-featured; verify the
 *   record (or leave the import alone) to make that choice stick.
 */
class TelanganaTempleSeeder extends Seeder
{
    /** @var array{created: int, updated: int, unchanged: int, skipped: int} */
    public array $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

    protected const TIMING_NOTE = 'Typical hours as commonly reported, not confirmed with the temple. Hours change on festival days, during eclipses and for renovation — check with the devasthanam before travelling.';

    public function run(): void
    {
        $state = State::where('code', 'TG')->first();

        if ($state === null) {
            return;
        }

        foreach ($this->temples() as $row) {
            $this->import($state, $row);
        }
    }

    /** @param array<string, mixed> $row */
    protected function import(State $state, array $row): void
    {
        $district = District::firstOrCreate(
            ['state_id' => $state->id, 'slug' => Str::slug($row['district'])],
            ['name' => $row['district']],
        );

        $slug = Str::slug($row['name']);
        $temple = Temple::withTrashed()->where('slug', $slug)->first();

        if ($temple !== null && $this->editorOwns($temple)) {
            $this->counts['skipped']++;

            return;
        }

        $attributes = [
            'name' => $row['name'],
            'deity_id' => $row['deity'] ? Deity::where('slug', $row['deity'])->value('id') : null,
            'state_id' => $state->id,
            'district_id' => $district->id,
            'city' => $row['city'],
            'latitude' => $row['lat'],
            'longitude' => $row['lng'],
            'short_description' => $row['summary'],
            'significance' => $row['significance'] ?? null,
            'built_period' => $row['built'] ?? null,
            'architecture_style' => $row['style'] ?? null,
            'official_website' => $row['website'] ?? null,
            'source_name' => isset($row['source']) ? $row['source'][0] : null,
            'source_url' => isset($row['source']) ? $row['source'][1] : null,
            'dress_code' => $row['rules']['dress_code'] ?? null,
            'photography_policy' => $row['rules']['photography'] ?? null,
            'mobile_policy' => $row['rules']['mobile'] ?? null,
            'footwear_policy' => $row['rules']['footwear'] ?? null,
            'entry_rules' => $row['rules']['entry'] ?? null,
            'queue_information' => $row['rules']['queue'] ?? null,
        ];

        if ($temple === null) {
            $temple = Temple::create($attributes + [
                'slug' => $slug,
                'status' => TempleStatus::Published,
                'is_featured' => $row['famous'],
                'verification_status' => VerificationStatus::Community,
                'last_verified_at' => null,
            ]);
            $this->counts['created']++;
        } else {
            // Fill only what is empty. An editor's wording always wins.
            $fill = array_filter(
                $attributes,
                fn ($value, string $key): bool => $value !== null && blank($temple->getAttribute($key)),
                ARRAY_FILTER_USE_BOTH,
            );

            if ($row['famous']) {
                $fill['is_featured'] = true;
            }

            $temple->forceFill($fill);
            $this->counts[$temple->isDirty() ? 'updated' : 'unchanged']++;
            $temple->save();
        }

        if ($row['categories'] !== []) {
            $temple->categories()->syncWithoutDetaching(
                TempleCategory::whereIn('slug', $row['categories'])->pluck('id')
            );
        }

        foreach ($row['aliases'] as $alias => $locale) {
            $temple->aliases()->firstOrCreate(['name' => $alias], ['locale' => $locale]);
        }

        if (($row['timings'] ?? []) !== [] && ! $temple->timings()->exists()) {
            foreach ($row['timings'] as $index => [$kind, $label, $opens, $closes]) {
                $temple->timings()->create([
                    'kind' => $kind,
                    'label' => $label,
                    'opens_at' => $opens,
                    'closes_at' => $closes,
                    'notes' => $kind === TimingKind::General ? self::TIMING_NOTE : null,
                    'sort_order' => $index + 1,
                ]);
            }
        }

        if (($row['pujas'] ?? []) !== [] && ! $temple->pujas()->exists()) {
            foreach ($row['pujas'] as $index => $puja) {
                $temple->pujas()->create([
                    'name' => $puja[0],
                    'description' => $puja[1],
                    'starts_at' => $puja[2] ?? null,
                    'duration_minutes' => $puja[3] ?? null,
                    'schedule_note' => $puja[4] ?? null,
                    'is_free' => $puja[5] ?? false,
                    // Left null on purpose: "No published price", never "Free".
                    'fee_amount' => null,
                    'sort_order' => $index + 1,
                ]);
            }
        }
    }

    /**
     * A record someone has checked against a source belongs to the editors,
     * and so does one they deleted: re-importing must not resurrect it.
     */
    protected function editorOwns(Temple $temple): bool
    {
        return $temple->trashed()
            || $temple->last_verified_at !== null
            || in_array($temple->verification_status, [VerificationStatus::Verified, VerificationStatus::Official], true);
    }

    // --- Shared puja sets ---------------------------------------------------

    /** @return array<int, array<int, mixed>> */
    protected static function vaishnavaSevas(): array
    {
        return [
            ['Suprabhatam', 'Pre-dawn hymn waking the deity; the first seva of the day.', '04:00', 30, 'Daily, before the doors open for darshan'],
            ['Archana', 'Recitation of the deity\'s names with offerings on behalf of the devotee.', null, 15, 'Through the day during opening hours'],
            ['Abhishekam', 'Ceremonial bathing of the deity with milk, curd, honey and water.', null, 45, 'Morning; on some days only — ask at the seva counter'],
            ['Nitya Kalyanam', 'Daily celestial wedding ceremony of the deity and consort, performed on behalf of devotees.', null, 60, 'Daily, late morning'],
            ['Sahasranamarchana', 'Worship with the thousand names of the deity.', null, 30, 'Daily'],
        ];
    }

    /** @return array<int, array<int, mixed>> */
    protected static function shaivaSevas(): array
    {
        return [
            ['Rudrabhishekam', 'Bathing of the lingam while the Sri Rudram is chanted.', null, 45, 'Daily, mornings; busiest on Mondays'],
            ['Archana', 'Recitation of the deity\'s names with offerings on behalf of the devotee.', null, 15, 'Through the day during opening hours'],
            ['Bilva Archana', 'Worship with bilva leaves, sacred to Shiva.', null, 20, 'Daily; Mondays and Maha Shivaratri especially'],
        ];
    }

    /** @return array<int, array<int, mixed>> */
    protected static function deviSevas(): array
    {
        return [
            ['Kumkuma Archana', 'Worship of the goddess with kumkum while her names are recited.', null, 20, 'Daily; Tuesdays and Fridays especially'],
            ['Abhishekam', 'Ceremonial bathing of the goddess.', null, 45, 'Mornings'],
            ['Bonam offering', 'Cooked rice with jaggery carried in a decorated pot and offered to the goddess.', null, null, 'Ashada month (June–July), during Bonalu'],
        ];
    }

    /** @return array<int, array<int, mixed>> */
    protected static function hanumanSevas(): array
    {
        return [
            ['Archana', 'Recitation of Hanuman\'s names with offerings on behalf of the devotee.', null, 15, 'Through the day during opening hours'],
            ['Sindoor (Chandanam) offering', 'Offering of sindoor and sandal paste to Hanuman.', null, null, 'Tuesdays and Saturdays especially'],
            ['Vada mala', 'Garland of vadas offered to Hanuman.', null, null, 'Tuesdays and Saturdays'],
        ];
    }

    // --- Data ---------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    protected function temples(): array
    {
        $g = TimingKind::General;
        $a = TimingKind::Aarti;
        $d = TimingKind::Darshan;

        $wiki = fn (string $title): array => ['Wikipedia', 'https://en.wikipedia.org/wiki/'.$title];

        return [
            // ===== Famous temples ==========================================

            [
                'name' => 'Yadadri Lakshmi Narasimha Temple', 'deity' => 'narasimha', 'famous' => true,
                'district' => 'Yadadri Bhuvanagiri', 'city' => 'Yadagirigutta',
                'lat' => 17.5886, 'lng' => 78.9449,
                'categories' => ['hill-temple'],
                'aliases' => ['Yadagirigutta Temple' => 'en', 'Sri Lakshmi Narasimha Swamy Devasthanam, Yadadri' => 'en', 'యాదాద్రి' => 'te', 'యాదగిరిగుట్ట' => 'te'],
                'summary' => 'Hill temple to Lakshmi Narasimha, rebuilt in black Krishna Shila stone and reopened in 2022. The most visited temple in Telangana.',
                'significance' => 'The deity is worshipped in a cave sanctum in five forms (Pancha Narasimha). Devotees commonly perform the Satyanarayana Swamy Vratam here. The annual Brahmotsavam falls in Phalguna (February–March).',
                'style' => 'Dravidian, in Krishna Shila stone',
                'website' => 'https://yadadritemple.telangana.gov.in',
                'rules' => [
                    'dress_code' => 'Traditional dress expected, and required for sevas. Men: dhoti or kurta-pyjama. Women: saree, half-saree or churidar.',
                    'mobile' => 'Not permitted inside the main temple; deposit at the counters.',
                    'footwear' => 'To be left at the footwear counters before the queue complex.',
                    'queue' => 'Free (dharma) darshan and paid special-entry queues run separately. Expect long waits at weekends and during Brahmotsavam.',
                ],
                'timings' => [
                    [$g, null, '04:00', '21:45'],
                    [$a, 'Suprabhatam', '04:00', '04:30'],
                    [$d, 'Sarva darshan', '06:30', '21:00'],
                    [$a, 'Ekanta seva (closing)', '21:30', '21:45'],
                ],
                'pujas' => [
                    ...self::vaishnavaSevas(),
                    ['Satyanarayana Swamy Vratam', 'Vow worship of Satyanarayana, performed by families in the vratam halls.', null, 90, 'Several batches daily'],
                    ['Swarna Pushparchana', 'Worship with golden flowers.', null, 30, 'Daily'],
                ],
            ],
            [
                'name' => 'Bhadrachalam Sita Ramachandraswamy Temple', 'deity' => 'rama', 'famous' => true,
                'district' => 'Bhadradri Kothagudem', 'city' => 'Bhadrachalam',
                'lat' => 17.6688, 'lng' => 80.8897,
                'categories' => ['river-ghat-temple'],
                'aliases' => ['Bhadrachalam Temple' => 'en', 'Bhadradri Rama Temple' => 'en', 'భద్రాచలం' => 'te', 'భద్రాద్రి' => 'te'],
                'summary' => 'Rama temple on the Godavari, built in the 17th century by Kancherla Gopanna (Bhakta Ramadasu). Known for the Sri Rama Navami Kalyanam.',
                'significance' => 'Sri Rama Navami Kalyanam (March–April) draws devotees from across the Telugu states, and the state government traditionally sends pearl talambralu. Vaikunta Ekadasi (Mukkoti) and the Teppotsavam on the Godavari are the other major festivals.',
                'built' => '17th century',
                'website' => 'https://bhadrachalarama.org',
                'rules' => [
                    'dress_code' => 'Traditional dress expected, and required for sevas.',
                    'footwear' => 'To be left at the footwear counters.',
                ],
                'timings' => [
                    [$g, null, '04:00', '21:00'],
                    [$a, 'Suprabhatam', '04:00', '04:30'],
                    [$a, 'Pavalimpu seva (closing)', '20:30', '21:00'],
                ],
                'pujas' => self::vaishnavaSevas(),
            ],
            [
                'name' => 'Sri Raja Rajeshwara Swamy Temple, Vemulawada', 'deity' => 'shiva', 'famous' => true,
                'district' => 'Rajanna Sircilla', 'city' => 'Vemulawada',
                'lat' => 18.4663, 'lng' => 78.8687,
                'categories' => [],
                'aliases' => ['Vemulawada Temple' => 'en', 'Rajanna Temple' => 'en', 'Dakshina Kashi' => 'en', 'వేములవాడ రాజరాజేశ్వర స్వామి' => 'te'],
                'summary' => 'Shiva temple known as Dakshina Kashi, where devotees keep the Kode Mokku vow by walking a bull calf around the shrine.',
                'significance' => 'Devotees bathe in the Dharma Gundam tank before darshan. Maha Shivaratri and Sri Rama Navami are the largest festivals. A dargah stands within the temple complex.',
                'timings' => [
                    [$g, null, '04:00', '22:00'],
                ],
                'pujas' => [
                    ...self::shaivaSevas(),
                    ['Kode Mokku', 'Vow in which the devotee leads a bull calf (kode) around the temple.', null, null, 'Daily; Mondays especially'],
                ],
            ],
            [
                'name' => 'Gnana Saraswati Temple, Basara', 'deity' => 'saraswati', 'famous' => true,
                'district' => 'Nirmal', 'city' => 'Basara',
                'lat' => 18.8766, 'lng' => 77.9555,
                'categories' => ['river-ghat-temple'],
                'aliases' => ['Basara Saraswati Temple' => 'en', 'Basar Temple' => 'en', 'బాసర జ్ఞాన సరస్వతి' => 'te'],
                'summary' => 'Temple to Saraswati on the Godavari, one of the best known in India, where families bring children for Aksharabhyasam, their first letters.',
                'significance' => 'Vasant Panchami and Sharan Navaratri, especially the Moola Nakshatra day, are the busiest days for Aksharabhyasam.',
                'timings' => [
                    [$g, null, '04:00', '20:30'],
                ],
                'pujas' => [
                    ['Aksharabhyasam', 'A child\'s first writing of letters in the presence of the goddess.', null, 30, 'Daily; very busy on Vasant Panchami and Moola Nakshatra'],
                    ['Abhishekam', 'Ceremonial bathing of the goddess.', null, 45, 'Mornings'],
                    ['Kumkuma Archana', 'Worship of the goddess with kumkum while her names are recited.', null, 20, 'Daily'],
                ],
            ],
            [
                'name' => 'Ramappa Temple (Kakatiya Rudreshwara Temple)', 'deity' => 'shiva', 'famous' => true,
                'district' => 'Mulugu', 'city' => 'Palampet',
                'lat' => 18.2593, 'lng' => 79.9434,
                'categories' => ['unesco-world-heritage'],
                'aliases' => ['Ramappa Temple' => 'en', 'Ramalingeswara Temple, Palampet' => 'en', 'రామప్ప దేవాలయం' => 'te'],
                'summary' => 'Kakatiya Shiva temple completed in 1213 CE and named after its sculptor, Ramappa. Inscribed as a UNESCO World Heritage Site in 2021.',
                'significance' => 'Known for its bracket figures (madanikas), carved basalt pillars and a superstructure built of light bricks that float on water.',
                'built' => '1213 CE',
                'style' => 'Kakatiya',
                'source' => ['UNESCO World Heritage List', 'https://whc.unesco.org/en/list/1570'],
                'pujas' => self::shaivaSevas(),
            ],
            [
                'name' => 'Thousand Pillar Temple, Hanumakonda', 'deity' => 'shiva', 'famous' => true,
                'district' => 'Hanumakonda', 'city' => 'Hanumakonda',
                'lat' => 18.0037, 'lng' => 79.5747,
                'categories' => [],
                'aliases' => ['Rudreshwara Swamy Temple' => 'en', 'Veyi Sthambala Gudi' => 'en', 'వేయి స్తంభాల గుడి' => 'te'],
                'summary' => 'Kakatiya temple of 1163 CE with three shrines, to Shiva, Vishnu and Surya, and a monolithic Nandi.',
                'built' => '1163 CE',
                'style' => 'Kakatiya',
                'source' => $wiki('Thousand_Pillar_Temple'),
                'pujas' => self::shaivaSevas(),
            ],
            [
                'name' => 'Bhadrakali Temple, Warangal', 'deity' => 'kali', 'famous' => true,
                'district' => 'Hanumakonda', 'city' => 'Warangal',
                'lat' => 17.9937, 'lng' => 79.5831,
                'categories' => ['hill-temple'],
                'aliases' => ['Sri Bhadrakali Ammavari Temple' => 'en', 'భద్రకాళి ఆలయం' => 'te'],
                'summary' => 'Hilltop shrine to Bhadrakali above Bhadrakali lake, between Hanumakonda and Warangal, patronised by the Kakatiyas.',
                'significance' => 'Sharan Navaratri is the principal festival.',
                'source' => $wiki('Bhadrakali_Temple'),
                'timings' => [
                    [$g, null, '05:00', '20:30'],
                ],
                'pujas' => self::deviSevas(),
            ],
            [
                'name' => 'Chilkur Balaji Temple', 'deity' => 'venkateswara', 'famous' => true,
                'district' => 'Rangareddy', 'city' => 'Chilkur',
                'lat' => 17.3563, 'lng' => 78.2994,
                'categories' => [],
                'aliases' => ['Visa Balaji Temple' => 'en', 'చిలుకూరు బాలాజీ' => 'te'],
                'summary' => 'Venkateswara temple near Osman Sagar, known as Visa Balaji. Devotees vow 11 pradakshinas and return for 108 when the wish is fulfilled.',
                'significance' => 'The temple accepts no hundi offerings and runs no paid or VIP darshan: every devotee joins the same queue.',
                'source' => $wiki('Chilkur_Balaji_Temple'),
                'rules' => [
                    'queue' => 'One queue for everyone; there is no special or paid darshan.',
                ],
                'timings' => [
                    [$g, null, '05:00', '20:00'],
                ],
                'pujas' => [
                    ['Pradakshina vow', 'Eleven circumambulations while making a wish; 108 when it is fulfilled.', null, null, 'Any time during opening hours', true],
                    ['Archana', 'Recitation of the deity\'s names on behalf of the devotee.', null, 15, 'Through the day'],
                ],
            ],
            [
                'name' => 'Birla Mandir, Hyderabad', 'deity' => 'venkateswara', 'famous' => true,
                'district' => 'Hyderabad', 'city' => 'Hyderabad',
                'lat' => 17.4062, 'lng' => 78.4691,
                'categories' => ['hill-temple'],
                'aliases' => ['Sri Venkateswara Temple, Naubat Pahad' => 'en', 'బిర్లా మందిర్' => 'te'],
                'summary' => 'White marble Venkateswara temple on Naubat Pahad hill overlooking Hussain Sagar, opened in 1976.',
                'built' => '1976',
                'style' => 'Dravidian, Rajasthani and Utkala blend in white marble',
                'source' => $wiki('Birla_Mandir,_Hyderabad'),
                'rules' => [
                    'mobile' => 'Mobile phones and cameras are not allowed; deposit them at the counter.',
                    'footwear' => 'To be left at the footwear counter at the foot of the steps.',
                ],
                'timings' => [
                    [$g, 'Morning', '07:00', '12:00'],
                    [$g, 'Evening', '15:00', '21:00'],
                ],
            ],
            [
                'name' => 'Jogulamba Temple, Alampur', 'deity' => 'devi', 'famous' => true,
                'district' => 'Jogulamba Gadwal', 'city' => 'Alampur',
                'lat' => 15.8784, 'lng' => 78.1339,
                'categories' => ['shakti-peetha', 'river-ghat-temple'],
                'aliases' => ['Jogulamba Devi' => 'en', 'జోగులాంబ' => 'te'],
                'summary' => 'Shrine to Jogulamba near the meeting of the Tungabhadra and Krishna, counted among the eighteen Maha Shakti Peethas.',
                'significance' => 'The present temple was rebuilt in 2005 on the site of the original, which was destroyed in the 14th century.',
                'source' => $wiki('Jogulamba_Temple'),
                'timings' => [
                    [$g, null, '06:00', '20:30'],
                ],
                'pujas' => self::deviSevas(),
            ],
            [
                'name' => 'Sammakka Saralamma Temple, Medaram', 'deity' => 'devi', 'famous' => true,
                'district' => 'Mulugu', 'city' => 'Medaram',
                'lat' => 18.2956, 'lng' => 80.2464,
                'categories' => ['forest-temple'],
                'aliases' => ['Medaram Jatara' => 'en', 'Sammakka Saralamma' => 'en', 'మేడారం సమ్మక్క సారలమ్మ' => 'te'],
                'summary' => 'Forest shrine of the Koya tribal goddesses Sammakka and Saralamma, home of the Medaram Jatara, one of the largest tribal gatherings in the world.',
                'significance' => 'The jatara is held every two years in Magha (January–February). Devotees offer jaggery, called bangaram (gold), equal to their own weight.',
                'pujas' => [
                    ['Bangaram offering', 'Jaggery offered to the goddesses, traditionally equal to the devotee\'s weight.', null, null, 'Year-round; above all during the biennial jatara'],
                ],
            ],
            [
                'name' => 'Komuravelli Mallikarjuna Swamy Temple', 'deity' => 'shiva', 'famous' => true,
                'district' => 'Siddipet', 'city' => 'Komuravelli',
                'lat' => 17.9667, 'lng' => 78.8833,
                'categories' => ['hill-temple'],
                'aliases' => ['Komuravelli Mallanna' => 'en', 'కొమురవెల్లి మల్లన్న' => 'te'],
                'summary' => 'Hill temple of Mallanna, a folk form of Shiva, with his consorts Golla Ketamma and Medalamma.',
                'significance' => 'The jatara runs from Sankranti to Ugadi. Devotees draw a patnam, a large coloured-powder pattern, as an offering.',
                'pujas' => [
                    ...self::shaivaSevas(),
                    ['Patnam', 'Offering of a large pattern drawn in coloured powders before the deity.', null, null, 'Sundays during the jatara season (Sankranti to Ugadi)'],
                ],
            ],
            [
                'name' => 'Kondagattu Anjaneya Swamy Temple', 'deity' => 'hanuman', 'famous' => true,
                'district' => 'Jagtial', 'city' => 'Kondagattu',
                'lat' => 18.7164, 'lng' => 78.9467,
                'categories' => ['hill-temple'],
                'aliases' => ['Kondagattu Temple' => 'en', 'కొండగట్టు ఆంజనేయ స్వామి' => 'te'],
                'summary' => 'Hill temple to Hanuman in Jagtial district, visited in large numbers on Hanuman Jayanti.',
                'timings' => [
                    [$g, null, '04:00', '21:00'],
                ],
                'pujas' => self::hanumanSevas(),
            ],
            [
                'name' => 'Kaleshwara Mukteswara Swamy Temple, Kaleshwaram', 'deity' => 'shiva', 'famous' => true,
                'district' => 'Jayashankar Bhupalpally', 'city' => 'Kaleshwaram',
                'lat' => 18.8120, 'lng' => 79.9070,
                'categories' => ['river-ghat-temple'],
                'aliases' => ['Kaleshwaram Temple' => 'en', 'కాళేశ్వరం' => 'te'],
                'summary' => 'Shiva temple at the confluence of the Godavari and Pranahita, where two lingams, Kaleshwara and Mukteswara, share one pedestal.',
                'significance' => 'Counted as a Triveni Sangamam with the hidden Saraswati. Godavari and Pranahita Pushkaralu draw very large crowds.',
                'pujas' => self::shaivaSevas(),
            ],
            [
                'name' => 'Sri Lakshmi Narasimha Swamy Temple, Dharmapuri', 'deity' => 'narasimha', 'famous' => true,
                'district' => 'Jagtial', 'city' => 'Dharmapuri',
                'lat' => 18.9480, 'lng' => 79.0930,
                'categories' => ['river-ghat-temple'],
                'aliases' => ['Dharmapuri Temple' => 'en', 'ధర్మపురి లక్ష్మీ నరసింహ స్వామి' => 'te'],
                'summary' => 'Narasimha temple on the Godavari, a major bathing site during Godavari Pushkaralu.',
                'pujas' => self::vaishnavaSevas(),
            ],
            [
                'name' => 'Keesaragutta Sri Ramalingeswara Swamy Temple', 'deity' => 'shiva', 'famous' => true,
                'district' => 'Medchal–Malkajgiri', 'city' => 'Keesara',
                'lat' => 17.5310, 'lng' => 78.6680,
                'categories' => ['hill-temple'],
                'aliases' => ['Keesaragutta Temple' => 'en', 'కీసరగుట్ట' => 'te'],
                'summary' => 'Hill shrine where, by tradition, Rama installed a Shiva lingam; hundreds of lingams lie scattered across the hill.',
                'significance' => 'Maha Shivaratri brings the year\'s largest crowds.',
                'source' => $wiki('Keesaragutta_Temple'),
                'pujas' => self::shaivaSevas(),
            ],
            [
                'name' => 'Ujjaini Mahankali Temple, Secunderabad', 'deity' => 'kali', 'famous' => true,
                'district' => 'Hyderabad', 'city' => 'Secunderabad',
                'lat' => 17.4390, 'lng' => 78.4960,
                'categories' => [],
                'aliases' => ['Lashkar Bonalu Temple' => 'en', 'ఉజ్జయిని మహంకాళి' => 'te'],
                'summary' => 'Mahankali temple in Secunderabad, dating to the early 19th century, and the centre of the Lashkar Bonalu festival.',
                'significance' => 'Lashkar Bonalu in Ashada (June–July) is one of the largest festivals in Hyderabad.',
                'source' => $wiki('Ujjaini_Mahankali_Temple'),
                'pujas' => self::deviSevas(),
            ],
            [
                'name' => 'Balkampet Yellamma Temple', 'deity' => 'devi', 'famous' => true,
                'district' => 'Hyderabad', 'city' => 'Hyderabad',
                'lat' => 17.4460, 'lng' => 78.4290,
                'categories' => [],
                'aliases' => ['Balkampet Yellamma Pochamma Temple' => 'en', 'బల్కంపేట ఎల్లమ్మ' => 'te'],
                'summary' => 'Shrine to Yellamma whose self-manifested idol lies below ground level, known for the Yellamma Kalyanam before Bonalu.',
                'significance' => 'The Yellamma Kalyanam in Ashada is one of the most attended festivals in the city.',
                'pujas' => self::deviSevas(),
            ],
            [
                'name' => 'Alampur Navabrahma Temples', 'deity' => 'shiva', 'famous' => true,
                'district' => 'Jogulamba Gadwal', 'city' => 'Alampur',
                'lat' => 15.8790, 'lng' => 78.1330,
                'categories' => ['river-ghat-temple'],
                'aliases' => ['Navabrahma Temples' => 'en', 'అలంపూర్ నవబ్రహ్మ ఆలయాలు' => 'te'],
                'summary' => 'Group of nine 7th–8th century Badami Chalukya Shiva temples on the Tungabhadra, beside the Jogulamba shrine.',
                'built' => '7th–8th century',
                'style' => 'Badami Chalukya (Nagara)',
                'source' => $wiki('Alampur_Navabrahma_Temples'),
                'pujas' => self::shaivaSevas(),
            ],

            // ===== Hyderabad and around ====================================

            [
                'name' => 'Peddamma Temple, Jubilee Hills', 'deity' => 'devi', 'famous' => false,
                'district' => 'Hyderabad', 'city' => 'Hyderabad',
                'lat' => 17.4310, 'lng' => 78.4050,
                'categories' => [],
                'aliases' => ['Peddamma Thalli Temple' => 'en', 'పెద్దమ్మ తల్లి' => 'te'],
                'summary' => 'Temple to Peddamma, the elder mother goddess, a major Bonalu venue in Jubilee Hills.',
                'source' => $wiki('Peddamma_Temple'),
                'pujas' => self::deviSevas(),
            ],
            [
                'name' => 'Karmanghat Hanuman Temple', 'deity' => 'hanuman', 'famous' => false,
                'district' => 'Rangareddy', 'city' => 'Karmanghat',
                'lat' => 17.3440, 'lng' => 78.5420,
                'categories' => [],
                'aliases' => ['Dhyananjaneya Swamy Temple, Karmanghat' => 'en', 'కర్మన్‌ఘాట్ హనుమాన్' => 'te'],
                'summary' => 'Hanuman temple of Kakatiya-era tradition near LB Nagar, among the most visited Hanuman shrines in Hyderabad.',
                'pujas' => self::hanumanSevas(),
            ],
            [
                'name' => 'Sanghi Temple', 'deity' => 'venkateswara', 'famous' => false,
                'district' => 'Rangareddy', 'city' => 'Sanghi Nagar',
                'lat' => 17.2890, 'lng' => 78.6700,
                'categories' => ['hill-temple'],
                'aliases' => ['Sri Venkateswara Temple, Sanghi Nagar' => 'en', 'సంఘీ టెంపుల్' => 'te'],
                'summary' => 'Hilltop Venkateswara temple on Paramanand Giri, with a tall Chola-style rajagopuram visible from the Vijayawada highway.',
                'pujas' => self::vaishnavaSevas(),
            ],
            [
                'name' => 'Jagadamba Mahankali Temple, Golconda', 'deity' => 'kali', 'famous' => false,
                'district' => 'Hyderabad', 'city' => 'Golconda',
                'lat' => 17.3840, 'lng' => 78.4010,
                'categories' => ['hill-temple'],
                'aliases' => ['Golconda Bonalu Temple' => 'en', 'గోల్కొండ జగదాంబ మహంకాళి' => 'te'],
                'summary' => 'Shrine to Jagadamba inside Golconda Fort, where the first Bonam of the Ashada Bonalu season is offered.',
                'pujas' => self::deviSevas(),
            ],
            [
                'name' => 'Akkanna Madanna Mahankali Temple, Haribowli', 'deity' => 'kali', 'famous' => false,
                'district' => 'Hyderabad', 'city' => 'Hyderabad',
                'lat' => 17.3560, 'lng' => 78.4800,
                'categories' => [],
                'aliases' => ['Akkanna Madanna Temple' => 'en', 'అక్కన్న మాదన్న మహంకాళి' => 'te'],
                'summary' => 'Old City Mahankali shrine associated with Akkanna and Madanna, ministers of the Qutb Shahi court; a principal Bonalu venue.',
                'pujas' => self::deviSevas(),
            ],
            [
                'name' => 'Hare Krishna Golden Temple, Banjara Hills', 'deity' => 'krishna', 'famous' => false,
                'district' => 'Hyderabad', 'city' => 'Hyderabad',
                'lat' => 17.4200, 'lng' => 78.4190,
                'categories' => ['hill-temple'],
                'aliases' => ['Sri Sri Radha Krishna Temple' => 'en', 'హరే కృష్ణ గోల్డెన్ టెంపుల్' => 'te'],
                'summary' => 'Radha Krishna temple on a rock outcrop in Banjara Hills, run by the Hare Krishna Movement.',
            ],
            [
                'name' => 'Jagannath Temple, Hyderabad', 'deity' => 'krishna', 'famous' => false,
                'district' => 'Hyderabad', 'city' => 'Hyderabad',
                'lat' => 17.4150, 'lng' => 78.4330,
                'categories' => [],
                'aliases' => ['Banjara Hills Jagannath Temple' => 'en', 'జగన్నాథ ఆలయం హైదరాబాద్' => 'te'],
                'summary' => 'Red sandstone temple to Jagannath, Balabhadra and Subhadra in Banjara Hills, built in the Kalinga style. Holds a Rath Yatra each year.',
                'style' => 'Kalinga',
            ],

            // ===== North Telangana =========================================

            [
                'name' => 'Nagoba Temple, Keslapur', 'deity' => null, 'famous' => false,
                'district' => 'Adilabad', 'city' => 'Keslapur',
                'lat' => 19.4630, 'lng' => 78.6810,
                'categories' => ['forest-temple'],
                'aliases' => ['Nagoba Jatara' => 'en', 'నాగోబా' => 'te'],
                'summary' => 'Serpent-deity shrine of the Mesram clan of the Gond people, home of the Nagoba Jatara held in Pushya (January–February).',
            ],
            [
                'name' => 'Jainath Lakshmi Narayana Temple', 'deity' => 'vishnu', 'famous' => false,
                'district' => 'Adilabad', 'city' => 'Jainath',
                'lat' => 19.7310, 'lng' => 78.5850,
                'categories' => [],
                'aliases' => ['Jainath Temple' => 'en', 'జైనథ్ ఆలయం' => 'te'],
                'summary' => 'Stone temple to Lakshmi Narayana in Adilabad district, showing Jain architectural influence.',
                'pujas' => self::vaishnavaSevas(),
            ],
            [
                'name' => 'Siddhulagutta Temple, Armoor', 'deity' => 'shiva', 'famous' => false,
                'district' => 'Nizamabad', 'city' => 'Armoor',
                'lat' => 18.7870, 'lng' => 78.2860,
                'categories' => ['hill-temple', 'cave-temple'],
                'aliases' => ['Navanatha Siddeshwara Temple' => 'en', 'సిద్ధులగుట్ట' => 'te'],
                'summary' => 'Rocky hill above Armoor with a Shiva shrine and natural caves associated with the Navanatha siddhas.',
                'pujas' => self::shaivaSevas(),
            ],
            [
                'name' => 'Edupayala Vana Durga Bhavani Temple', 'deity' => 'devi', 'famous' => false,
                'district' => 'Medak', 'city' => 'Nagsanpalli',
                'lat' => 17.9360, 'lng' => 78.1790,
                'categories' => ['forest-temple', 'river-ghat-temple'],
                'aliases' => ['Edupayala Temple' => 'en', 'ఏడుపాయల వనదుర్గ' => 'te'],
                'summary' => 'Forest shrine to Vana Durga where the Manjira splits into seven streams (edu payalu). A large jatara is held on Maha Shivaratri.',
                'pujas' => self::deviSevas(),
            ],
            [
                'name' => 'Vargal Saraswati Temple', 'deity' => 'saraswati', 'famous' => false,
                'district' => 'Siddipet', 'city' => 'Vargal',
                'lat' => 17.7610, 'lng' => 78.5970,
                'categories' => ['hill-temple'],
                'aliases' => ['Sri Vidya Saraswati Temple, Wargal' => 'en', 'వర్గల్ సరస్వతి' => 'te'],
                'summary' => 'Hill temple to Saraswati near Hyderabad, popular for Aksharabhyasam, especially on Vasant Panchami.',
                'pujas' => [
                    ['Aksharabhyasam', 'A child\'s first writing of letters in the presence of the goddess.', null, 30, 'Daily; busiest on Vasant Panchami'],
                    ['Kumkuma Archana', 'Worship of the goddess with kumkum while her names are recited.', null, 20, 'Daily'],
                ],
            ],
            [
                'name' => 'Inavolu Mallikarjuna Swamy Temple', 'deity' => 'shiva', 'famous' => false,
                'district' => 'Hanumakonda', 'city' => 'Inavolu',
                'lat' => 17.9200, 'lng' => 79.6500,
                'categories' => [],
                'aliases' => ['Inavolu Mallanna' => 'en', 'ఐనవోలు మల్లన్న' => 'te'],
                'summary' => 'Kakatiya-era temple of Mallanna near Warangal, with a large jatara at Sankranti.',
                'pujas' => self::shaivaSevas(),
            ],

            // ===== Godavari and the east ===================================

            [
                'name' => 'Parnasala, Dummugudem', 'deity' => 'rama', 'famous' => false,
                'district' => 'Bhadradri Kothagudem', 'city' => 'Parnasala',
                'lat' => 17.8410, 'lng' => 80.8480,
                'categories' => ['river-ghat-temple', 'forest-temple'],
                'aliases' => ['Parnashala' => 'en', 'పర్ణశాల' => 'te'],
                'summary' => 'Godavari-bank site where, by tradition, Rama, Sita and Lakshmana lived during their exile. Usually visited together with Bhadrachalam.',
            ],
            [
                'name' => 'Mallur Hemachala Lakshmi Narasimha Swamy Temple', 'deity' => 'narasimha', 'famous' => false,
                'district' => 'Mulugu', 'city' => 'Mallur',
                'lat' => 18.1270, 'lng' => 80.3690,
                'categories' => ['hill-temple', 'forest-temple'],
                'aliases' => ['Hemachala Narasimha Temple' => 'en', 'మల్లూరు హేమాచల నరసింహ' => 'te'],
                'summary' => 'Forest hill temple of Narasimha near Mangapet, with a perennial spring (Chintamani) beside the shrine.',
                'pujas' => self::vaishnavaSevas(),
            ],
            [
                'name' => 'Kuravi Veerabhadra Swamy Temple', 'deity' => 'shiva', 'famous' => false,
                'district' => 'Mahabubabad', 'city' => 'Kuravi',
                'lat' => 17.5553, 'lng' => 79.9992,
                'categories' => [],
                'aliases' => ['Kuravi Temple' => 'en', 'కురవి వీరభద్ర స్వామి' => 'te'],
                'summary' => 'Temple to Veerabhadra, the fierce form of Shiva, with a large jatara on Maha Shivaratri.',
                'pujas' => self::shaivaSevas(),
            ],
            [
                'name' => 'Jamalapuram Sri Venkateswara Swamy Temple', 'deity' => 'venkateswara', 'famous' => false,
                'district' => 'Khammam', 'city' => 'Jamalapuram',
                'lat' => 16.9667, 'lng' => 80.3833,
                'categories' => ['hill-temple'],
                'aliases' => ['Telangana Chinna Tirupati' => 'en', 'జమలాపురం వెంకటేశ్వర స్వామి' => 'te'],
                'summary' => 'Hill temple to Venkateswara in Khammam district, known locally as the Chinna Tirupati of Telangana.',
                'pujas' => self::vaishnavaSevas(),
            ],

            // ===== South Telangana =========================================

            [
                'name' => 'Kolanupaka Someswara Temple', 'deity' => 'shiva', 'famous' => false,
                'district' => 'Yadadri Bhuvanagiri', 'city' => 'Kolanupaka',
                'lat' => 17.6960, 'lng' => 79.0270,
                'categories' => [],
                'aliases' => ['Someswara Swamy Temple, Kolanupaka' => 'en', 'కొలనుపాక సోమేశ్వర' => 'te'],
                'summary' => 'Ancient Shiva temple revered by Veerashaivas as the place where Renukacharya emerged from the lingam. A celebrated Jain temple stands nearby.',
                'pujas' => self::shaivaSevas(),
            ],
            [
                'name' => 'Swarnagiri Sri Venkateswara Swamy Temple', 'deity' => 'venkateswara', 'famous' => false,
                'district' => 'Yadadri Bhuvanagiri', 'city' => 'Bhuvanagiri',
                'lat' => 17.5190, 'lng' => 78.8660,
                'categories' => ['hill-temple'],
                'aliases' => ['Swarnagiri Temple' => 'en', 'స్వర్ణగిరి' => 'te'],
                'summary' => 'Recently built hill temple to Venkateswara on the Manepally hills near Bhuvanagiri, on the way to Yadadri.',
                'pujas' => self::vaishnavaSevas(),
            ],
            [
                'name' => 'Pachala Someswara Temple, Panagal', 'deity' => 'shiva', 'famous' => false,
                'district' => 'Nalgonda', 'city' => 'Panagal',
                'lat' => 17.0730, 'lng' => 79.2830,
                'categories' => [],
                'aliases' => ['Panagal Someswara Temple' => 'en', 'పచ్చల సోమేశ్వర' => 'te'],
                'summary' => 'Kanduri Chola-era Shiva temple at Panagal, known for its intricately carved pillars.',
            ],
            [
                'name' => 'Chaya Someswara Temple, Panagal', 'deity' => 'shiva', 'famous' => false,
                'district' => 'Nalgonda', 'city' => 'Panagal',
                'lat' => 17.0780, 'lng' => 79.2860,
                'categories' => [],
                'aliases' => ['Chaya Someshwara' => 'en', 'ఛాయా సోమేశ్వర' => 'te'],
                'summary' => 'Three-shrined temple where a steady shadow falls on the lingam through the day, the phenomenon that gives it its name.',
            ],
            [
                'name' => 'Mattapalli Lakshmi Narasimha Swamy Temple', 'deity' => 'narasimha', 'famous' => false,
                'district' => 'Suryapet', 'city' => 'Mattapalli',
                'lat' => 16.8453, 'lng' => 79.8963,
                'categories' => ['river-ghat-temple', 'cave-temple'],
                'aliases' => ['Mattapalli Temple' => 'en', 'మట్టపల్లి నరసింహ' => 'te'],
                'summary' => 'Cave shrine of Narasimha on the bank of the Krishna river in Suryapet district.',
                'pujas' => self::vaishnavaSevas(),
            ],
            [
                'name' => 'Ananthagiri Padmanabha Swamy Temple', 'deity' => 'vishnu', 'famous' => false,
                'district' => 'Vikarabad', 'city' => 'Ananthagiri',
                'lat' => 17.3100, 'lng' => 77.8667,
                'categories' => ['hill-temple', 'forest-temple'],
                'aliases' => ['Ananthagiri Temple' => 'en', 'అనంతగిరి పద్మనాభ స్వామి' => 'te'],
                'summary' => 'Forest hill temple to Vishnu as Padmanabha in the Ananthagiri hills, near the source of the Musi river.',
                'pujas' => self::vaishnavaSevas(),
            ],
            [
                'name' => 'Umamaheswaram Temple', 'deity' => 'shiva', 'famous' => false,
                'district' => 'Nagarkurnool', 'city' => 'Rangapur',
                'lat' => 16.4370, 'lng' => 78.6960,
                'categories' => ['hill-temple', 'forest-temple'],
                'aliases' => ['Uma Maheshwara Temple' => 'en', 'ఉమామహేశ్వరం' => 'te'],
                'summary' => 'Shiva temple in the Nallamala forest near Achampet, traditionally called the northern gateway to Srisailam.',
                'pujas' => self::shaivaSevas(),
            ],
            [
                'name' => 'Srirangapur Ranganayaka Swamy Temple', 'deity' => 'vishnu', 'famous' => false,
                'district' => 'Wanaparthy', 'city' => 'Srirangapur',
                'lat' => 16.1900, 'lng' => 77.9800,
                'categories' => [],
                'aliases' => ['Srirangapuram Temple' => 'en', 'శ్రీరంగాపూర్ రంగనాయక స్వామి' => 'te'],
                'summary' => 'Lakeside temple to Ranganatha built by the rulers of the Wanaparthy samsthanam.',
                'pujas' => self::vaishnavaSevas(),
            ],
            [
                'name' => 'Beechupally Anjaneya Swamy Temple', 'deity' => 'hanuman', 'famous' => false,
                'district' => 'Jogulamba Gadwal', 'city' => 'Beechupally',
                'lat' => 16.0570, 'lng' => 77.9270,
                'categories' => ['river-ghat-temple'],
                'aliases' => ['Beechupalli Temple' => 'en', 'బీచుపల్లి ఆంజనేయ స్వామి' => 'te'],
                'summary' => 'Hanuman temple on the Krishna river beside the Hyderabad–Bengaluru highway, a major bathing ghat during Krishna Pushkaralu.',
                'pujas' => self::hanumanSevas(),
            ],
        ];
    }
}
