<?php

namespace App\Filament\Resources\Temples;

use App\Enums\PujaKind;
use App\Enums\TimingKind;
use App\Filament\Resources\Temples\RelationManagers\TimingsRelationManager;
use App\Models\District;
use App\Models\State;
use App\Models\Temple;
use App\Support\OfficialSite\OfficialSiteImport;
use App\Support\OfficialSite\OfficialSiteReader;
use App\Support\TempleImport\MapsLink;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;

/**
 * "Read official website" and "Review official details" on a temple:
 * facts from the temple's own site, side by side with what is on file,
 * taken into the listing only where a staff member ticks them.
 */
final class OfficialSiteActions
{
    public static function read(): Action
    {
        return Action::make('readOfficialSite')
            ->label('Import from Maps & website')
            ->icon('heroicon-o-globe-alt')
            ->color('gray')
            ->modalHeading('Import details and photos')
            ->modalDescription('From the Google Maps link: the exact pin, and the address, PIN code, district and state the map has there. From the website: phone, email, timings and sevas. Photos: free-licence photos taken at the temple, and the website\'s own (only with the temple\'s permission). Nothing on the listing changes until you review it.')
            ->fillForm(fn (Temple $record): array => [
                'maps' => $record->google_maps_url,
                'url' => $record->official_website,
                'pages' => implode("\n", $record->official_site_pages ?? []),
            ])
            ->schema([
                TextInput::make('maps')->label('Google Maps link')->placeholder('https://maps.app.goo.gl/…')
                    ->helperText('In Google Maps, open the temple, tap Share and copy the link.')
                    ->requiredWithout('url')
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                        if (filled($value) && ! MapsLink::isMapsLink((string) $value)) {
                            $fail('Paste a Google Maps link (maps.app.goo.gl or google.com/maps).');
                        }
                    }),
                TextInput::make('url')->label('Official website (if it has one)')->placeholder('https://www.example-temple.org')
                    ->requiredWithout('maps')
                    ->helperText('Only the temple\'s own website, not a directory or a blog about it.'),
                Textarea::make('pages')->label('Also read these pages (optional)')->rows(4)
                    ->placeholder("https://www.example-temple.org/sevas\nhttps://www.example-temple.org/darshan-timings")
                    ->helperText('One link per line, up to '.OfficialSiteReader::MAX_EXTRA_PAGES.'. Paste the pages where the site lists its sevas, prices or timings if they were missed. They are kept and read again next time.'),
            ])
            ->modalSubmitActionLabel('Read')
            ->action(function (Temple $record, array $data, $livewire): void {
                $pages = preg_split('/[\s,]+/', (string) ($data['pages'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
                $found = OfficialSiteImport::read($record, $data['url'] ?? null, $pages, $data['maps'] ?? null);
                if (isset($found['error'])) {
                    Notification::make()->title('Could not read the links')->body($found['error'])->danger()->send();

                    return;
                }
                Notification::make()->title('Links read')->body('Found: '.OfficialSiteImport::summary($found).'.'.(isset($found['warning']) ? ' '.$found['warning'] : ''))->success()->send();
                $livewire->mountAction('reviewOfficialSite');
            });
    }

    public static function review(): Action
    {
        return Action::make('reviewOfficialSite')
            ->label(fn (Temple $record): string => 'Review imported details'.($record->official_import && ! $record->official_import_reviewed_at ? ' (new)' : ''))
            ->icon('heroicon-o-clipboard-document-check')
            ->color(fn (Temple $record): string => $record->official_import && ! $record->official_import_reviewed_at ? 'warning' : 'gray')
            ->visible(fn (Temple $record): bool => ! empty($record->official_import))
            ->modalWidth('5xl')
            ->modalHeading(fn (Temple $record): string => 'Imported details for '.$record->name)
            ->modalDescription(fn (Temple $record): HtmlString => new HtmlString(
                'Read from <a href="'.e($record->official_import['source_url'] ?? '#').'" target="_blank" rel="noopener" class="underline">'.e($record->official_import['source_url'] ?? '').'</a> '
                .e(optional($record->official_import_at)->diffForHumans() ?? '').'. Tick what is right; correct anything before taking it. Facts are kept with the website as their source.'))
            ->fillForm(fn (Temple $record): array => self::prefill($record))
            ->schema(fn (Temple $record): array => self::form($record))
            ->modalSubmitActionLabel('Take the ticked details')
            ->action(function (Temple $record, array $data): void {
                $done = OfficialSiteImport::apply($record, $data);
                Notification::make()
                    ->title($done === [] ? 'Nothing taken' : 'Updated: '.implode(', ', $done))
                    ->body($done === [] ? 'Marked as reviewed.' : 'New sevas are added as drafts unless you chose to publish them; app booking stays off.')
                    ->success()->send();
            });
    }

    /** @return array<string, mixed> */
    private static function prefill(Temple $t): array
    {
        $f = $t->official_import ?? [];
        $empty = fn ($v) => blank($v);
        $twelve = fn (?string $v): ?string => OfficialSiteReader::twelveHour($v);
        $hasTimings = $t->timings()->exists();
        $existing = $t->pujas()->get(['name', 'fee_amount'])->keyBy(fn ($p) => mb_strtolower($p->name));

        return [
            'contact_phone' => $f['phones'][0] ?? null, 'use_contact_phone' => isset($f['phones'][0]) && $empty($t->contact_phone),
            'contact_email' => $f['emails'][0] ?? null, 'use_contact_email' => isset($f['emails'][0]) && $empty($t->contact_email),
            'address' => $f['address'] ?? $f['place']['address'] ?? null, 'use_address' => isset($f['address']) || isset($f['place']['address']) ? $empty($t->address) : false,
            'pincode' => $f['pincode'] ?? $f['place']['pincode'] ?? null, 'use_pincode' => isset($f['pincode']) || isset($f['place']['pincode']) ? $empty($t->pincode) : false,
            'city' => $f['place']['city'] ?? null, 'use_city' => isset($f['place']['city']) && $empty($t->city),
            'state_id' => $f['place']['state_id'] ?? null, 'district_id' => $f['place']['district_id'] ?? null,
            'use_region' => isset($f['place']['state_id']) && $empty($t->state_id),
            // Photos: a few free ones ticked for a temple with none yet.
            'commons_photos' => $t->photos()->exists() ? [] : array_slice(array_keys($f['commons_photos'] ?? []), 0, 3),
            'website_photos' => [],
            'website_permission' => false,
            'publish_photos' => true,
            'latitude' => $f['latitude'] ?? null, 'longitude' => $f['longitude'] ?? null, 'use_location' => isset($f['latitude']) && ! $t->hasCoordinates(),
            'short_description' => $f['description'] ?? null, 'use_short_description' => false,
            // Timings and sevas: all ticked when the listing has none.
            'timings' => array_map(fn (array $x): array => [
                'take' => ! $hasTimings,
                'label' => $x['label'] ?? '',
                'kind' => $x['kind'] ?? 'general',
                'days' => array_map('strval', $x['days'] ?? []),
                'opens_at' => $twelve($x['opens_at'] ?? null),
                'closes_at' => $twelve($x['closes_at'] ?? null),
                'notes' => $x['notes'] ?? null,
            ], $f['timings'] ?? []),
            'replace_timings' => false,
            'sevas' => array_map(function (array $x) use ($existing, $twelve): array {
                $on = $existing->get(mb_strtolower((string) ($x['name'] ?? '')));

                return [
                    'take' => $existing->isEmpty() || ($on !== null && (int) $on->fee_amount !== (int) ($x['fee'] ?? 0)),
                    'name' => $x['name'] ?? '',
                    'kind' => $x['kind'] ?? 'seva',
                    'fee' => $x['fee'] ?? null,
                    'starts_at' => $twelve($x['starts_at'] ?? null),
                    'note' => $x['note'] ?? null,
                    'duration_minutes' => $x['duration_minutes'] ?? null,
                    'on_file' => $on ? 'Listed, ₹'.number_format((int) $on->fee_amount) : 'New',
                ];
            }, $f['sevas'] ?? []),
            'publish_sevas' => false,
        ];
    }

    /** @return array<int, mixed> */
    private static function form(Temple $t): array
    {
        $f = $t->official_import ?? [];
        $now = fn (?string $v): string => 'On file now: '.(filled($v) ? $v : '— (empty)');
        $row = fn (string $key, string $label, ?string $current, bool $long = false) => Grid::make(['default' => 1, 'md' => 4])->schema([
            Toggle::make('use_'.$key)->label('Take')->inline(false),
            ($long ? Textarea::make($key)->rows(3) : TextInput::make($key))->label($label)->helperText($now($current))->columnSpan(['md' => 3]),
        ]);
        $time = fn (string $name, string $label) => TextInput::make($name)->label($label)->placeholder('5:30 AM')
            ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                if (filled($value) && OfficialSiteReader::fromTwelveHour((string) $value) === null) {
                    $fail('Write the time like 5:30 AM or 7:00 PM.');
                }
            });

        $sections = [];

        if (! empty($f['warning'])) {
            $sections[] = Placeholder::make('warning')->hiddenLabel()->content(new HtmlString('<strong>Note:</strong> '.e($f['warning'])));
        }

        $contact = array_filter([
            isset($f['phones']) ? $row('contact_phone', 'Phone'.(count($f['phones']) > 1 ? ' (also found: '.implode(', ', array_slice($f['phones'], 1, 3)).')' : ''), $t->contact_phone) : null,
            isset($f['emails']) ? $row('contact_email', 'Email', $t->contact_email) : null,
            isset($f['address']) || isset($f['place']['address']) ? $row('address', 'Address', $t->address, true) : null,
            isset($f['pincode']) || isset($f['place']['pincode']) ? $row('pincode', 'PIN code', $t->pincode) : null,
            isset($f['place']['city']) ? $row('city', 'City / town / village', $t->city) : null,
            isset($f['place']['state_id']) ? Grid::make(['default' => 1, 'md' => 4])->schema([
                Toggle::make('use_region')->label('Take')->inline(false),
                Select::make('state_id')->label('State')->options(fn () => State::orderBy('name')->pluck('name', 'id')->all())->live()
                    ->afterStateUpdated(fn (Set $set) => $set('district_id', null)),
                Select::make('district_id')->label('District')->searchable()
                    ->options(fn (Get $get): array => $get('state_id') ? District::where('state_id', $get('state_id'))->orderBy('name')->pluck('name', 'id')->all() : [])
                    ->helperText(isset($f['place']['district']) && ! isset($f['place']['district_id']) ? 'The map says "'.$f['place']['district'].'"; pick it.' : null),
                Placeholder::make('region_now')->label('On file now')->content(collect([$t->district?->name, $t->state?->name])->filter()->implode(', ') ?: '— (empty)'),
            ]) : null,
            isset($f['latitude']) ? Grid::make(['default' => 1, 'md' => 4])->schema([
                Toggle::make('use_location')->label('Take')->inline(false),
                TextInput::make('latitude')->numeric(),
                TextInput::make('longitude')->numeric(),
                Placeholder::make('loc_now')->label('On file now')->content($t->hasCoordinates() ? $t->latitude.', '.$t->longitude : '— (empty)'),
            ]) : null,
        ]);
        if ($contact !== []) {
            $sections[] = Section::make('Contact and location')
                ->description(isset($f['maps']) ? 'The pin is from the Google Maps link; the address, PIN code, district and state are what OpenStreetMap has at that pin.' : null)
                ->schema($contact);
        }

        if (! empty($f['timings'])) {
            $sections[] = Section::make('Timings')
                ->description(($t->timings()->exists() ? 'The listing has '.$t->timings()->count().' timings already; ticked rows are added, or replace them all.' : 'The listing has no timings yet.')
                    .' Correct a name or time before taking it, or remove a row that is wrong.')
                ->schema([
                    Repeater::make('timings')->hiddenLabel()
                        ->table([
                            TableColumn::make('Take')->width('60px'),
                            TableColumn::make('Name'),
                            TableColumn::make('Kind')->width('150px'),
                            TableColumn::make('Days')->width('190px'),
                            TableColumn::make('Opens')->width('120px'),
                            TableColumn::make('Closes')->width('120px'),
                            TableColumn::make('Days / note'),
                        ])
                        ->schema([
                            Toggle::make('take')->hiddenLabel(),
                            TextInput::make('label')->hiddenLabel()->maxLength(120),
                            Select::make('kind')->hiddenLabel()->options(TimingKind::class)->selectablePlaceholder(false),
                            Select::make('days')->hiddenLabel()->multiple()->placeholder('Every day')
                                ->options(TimingsRelationManager::dayOptions()),
                            $time('opens_at', 'Opens'),
                            $time('closes_at', 'Closes'),
                            TextInput::make('notes')->hiddenLabel()->maxLength(120),
                        ])
                        ->addActionLabel('Add a timing')->reorderable(false)->defaultItems(0),
                    Toggle::make('replace_timings')->label('Replace the listing\'s timings with the ticked ones'),
                ]);
        }

        if (! empty($f['sevas'])) {
            $sections[] = Section::make('Sevas and fees')
                ->description('Ticked sevas are added, or their fee updated where the name is already listed. In-app booking stays off.')
                ->schema([
                    Repeater::make('sevas')->hiddenLabel()
                        ->table([
                            TableColumn::make('Take')->width('60px'),
                            TableColumn::make('Name'),
                            TableColumn::make('Kind')->width('130px'),
                            TableColumn::make('Fee ₹')->width('110px'),
                            TableColumn::make('Time')->width('120px'),
                            TableColumn::make('Days / note'),
                            TableColumn::make('On file')->width('130px'),
                        ])
                        ->schema([
                            Toggle::make('take')->hiddenLabel(),
                            TextInput::make('name')->hiddenLabel()->maxLength(150),
                            Select::make('kind')->hiddenLabel()->options(PujaKind::class)->selectablePlaceholder(false),
                            TextInput::make('fee')->hiddenLabel()->numeric()->minValue(0),
                            $time('starts_at', 'Time'),
                            TextInput::make('note')->hiddenLabel()->maxLength(250),
                            TextInput::make('on_file')->hiddenLabel()->disabled()->dehydrated(false),
                        ])
                        ->addActionLabel('Add a seva')->reorderable(false)->defaultItems(0),
                    Toggle::make('publish_sevas')->label('Show the new sevas to devotees now (otherwise they are drafts)'),
                ]);
        }

        if (! empty($f['description'])) {
            $sections[] = Section::make('The site\'s own description')
                ->description('These are the temple\'s own words. Take them only with the temple\'s permission, or rewrite them in your own words first.')
                ->collapsed()
                ->schema([$row('short_description', 'Short description', $t->short_description, true)]);
        }

        $photo = fn (string $thumb, string $text): string => '<span style="display:inline-flex;flex-direction:column;gap:4px;max-width:220px">'
            .'<img src="'.e($thumb).'" alt="" loading="lazy" style="width:220px;height:150px;object-fit:cover;border-radius:10px">'
            .'<span style="font-size:12px;line-height:1.3">'.$text.'</span></span>';
        $photoSections = [];
        if (! empty($f['commons_photos'])) {
            $photoSections[] = CheckboxList::make('commons_photos')->label('Free-licence photos taken here (Wikimedia Commons)')
                ->helperText('Free to use with the credit, which is saved with the photo and shown with it.')
                ->allowHtml()->columns(['default' => 1, 'md' => 3])->bulkToggleable()
                ->options(collect($f['commons_photos'])->mapWithKeys(fn ($p, $i) => [$i => $photo($p['thumb'], e($p['title']).'<br><em>'.e($p['credit']).' · '.e($p['license']).'</em>'.(isset($p['distance']) ? ' · '.e($p['distance']).' m away' : ''))])->all());
        }
        if (! empty($f['images'])) {
            $photoSections[] = CheckboxList::make('website_photos')->label('Photos on the temple\'s website')
                ->helperText('These belong to the temple. Copy them only with their permission.')
                ->allowHtml()->columns(['default' => 1, 'md' => 3])->bulkToggleable()
                ->options(collect($f['images'])->mapWithKeys(fn ($p, $i) => [$i => $photo($p['url'], e($p['alt'] ?: basename(parse_url($p['url'], PHP_URL_PATH) ?: '')))])->all());
            $photoSections[] = Toggle::make('website_permission')->label('The temple has given permission to use these photos')
                ->accepted(fn (Get $get): bool => ! empty($get('website_photos')))
                ->validationMessages(['accepted' => 'Tick this only when the temple has said yes, or untick their photos.']);
        }
        if ($photoSections !== []) {
            $photoSections[] = Toggle::make('publish_photos')->label('Show the copied photos to devotees now');
            $sections[] = Section::make('Photos')
                ->description(($t->photos()->count() ? 'The temple has '.$t->photos()->count().' photos already. ' : 'The temple has no photos yet; the first one copied becomes the cover. ').'Photos from Google Maps cannot be copied: they belong to the people who took them.')
                ->schema($photoSections);
        }

        $sections[] = Section::make('Pages read')
            ->visible(! empty($f['pages']))
            ->description('Something missing? Use "Read official website" again and paste the page that has it under "Also read these pages".')
            ->collapsed(empty($f['failed_pages']) && empty($f['documents']))
            ->schema([Placeholder::make('pages')->hiddenLabel()->content(new HtmlString(self::pagesHtml($f)))]);

        if (empty($f['timings']) && empty($f['sevas']) && $contact === [] && $photoSections === []) {
            array_unshift($sections, Placeholder::make('none')->hiddenLabel()->content('Nothing useful was found on these pages. Many temple sites put timings and seva lists in images or PDFs, which cannot be read; enter them by hand, or add the right page links and read again.'));
        }

        return $sections;
    }

    private static function pagesHtml(array $f): string
    {
        $link = fn (string $url, string $text): string => '<a href="'.e($url).'" target="_blank" rel="noopener" class="underline">'.e($text).'</a>';
        $items = array_map(function ($p) use ($link): string {
            // Readings from before page counts were kept are plain links.
            $url = is_array($p) ? $p['url'] : (string) $p;
            $what = is_array($p) ? collect([$p['timings'] ? $p['timings'].' timings' : null, $p['sevas'] ? $p['sevas'].' sevas' : null])->filter()->implode(', ') : '';

            return '<li>'.$link($url, $url).' — '.e($what !== '' ? $what : 'nothing found').'</li>';
        }, $f['pages'] ?? []);
        $html = '<ul style="list-style:disc;padding-left:1.2rem">'.implode('', $items).'</ul>';
        if (! empty($f['failed_pages'])) {
            $html .= '<p style="margin-top:.5rem"><strong>Could not open:</strong> '.e(implode(', ', $f['failed_pages'])).'</p>';
        }
        if (! empty($f['documents'])) {
            $html .= '<p style="margin-top:.5rem"><strong>Lists in PDFs or images (open and enter by hand):</strong></p><ul style="list-style:disc;padding-left:1.2rem">'
                .implode('', array_map(fn ($d) => '<li>'.$link($d['url'], $d['text']).'</li>', $f['documents'])).'</ul>';
        }

        return $html;
    }
}
