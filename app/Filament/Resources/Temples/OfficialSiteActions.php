<?php

namespace App\Filament\Resources\Temples;

use App\Models\Temple;
use App\Support\OfficialSite\OfficialSiteImport;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
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
            ->label('Read official website')
            ->icon('heroicon-o-globe-alt')
            ->color('gray')
            ->modalHeading('Read the temple\'s official website')
            ->modalDescription('Looks at the home page and up to four of its pages about timings, sevas and contact. Nothing on the listing changes until you review it.')
            ->fillForm(fn (Temple $record): array => ['url' => $record->official_website])
            ->schema([
                TextInput::make('url')->label('Official website')->required()->placeholder('https://www.example-temple.org')
                    ->helperText('Only the temple\'s own website, not a directory or a blog about it.'),
            ])
            ->modalSubmitActionLabel('Read')
            ->action(function (Temple $record, array $data, $livewire): void {
                $found = OfficialSiteImport::read($record, $data['url']);
                if (isset($found['error'])) {
                    Notification::make()->title('Could not read the website')->body($found['error'])->danger()->send();

                    return;
                }
                Notification::make()->title('Website read')->body('Found: '.OfficialSiteImport::summary($found).'. Open "Review official details" to choose what to take.')->success()->send();
                $livewire->mountAction('reviewOfficialSite');
            });
    }

    public static function review(): Action
    {
        return Action::make('reviewOfficialSite')
            ->label(fn (Temple $record): string => 'Review official details'.($record->official_import && ! $record->official_import_reviewed_at ? ' (new)' : ''))
            ->icon('heroicon-o-clipboard-document-check')
            ->color(fn (Temple $record): string => $record->official_import && ! $record->official_import_reviewed_at ? 'warning' : 'gray')
            ->visible(fn (Temple $record): bool => ! empty($record->official_import))
            ->modalWidth('5xl')
            ->modalHeading(fn (Temple $record): string => 'Official details for '.$record->name)
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

        return [
            'contact_phone' => $f['phones'][0] ?? null, 'use_contact_phone' => isset($f['phones'][0]) && $empty($t->contact_phone),
            'contact_email' => $f['emails'][0] ?? null, 'use_contact_email' => isset($f['emails'][0]) && $empty($t->contact_email),
            'address' => $f['address'] ?? null, 'use_address' => isset($f['address']) && $empty($t->address),
            'pincode' => $f['pincode'] ?? null, 'use_pincode' => isset($f['pincode']) && $empty($t->pincode),
            'latitude' => $f['latitude'] ?? null, 'longitude' => $f['longitude'] ?? null, 'use_location' => isset($f['latitude']) && ! $t->hasCoordinates(),
            'short_description' => $f['description'] ?? null, 'use_short_description' => false,
            // Timings and sevas: all ticked when the listing has none.
            'timings' => $t->timings()->exists() ? [] : array_keys($f['timings'] ?? []),
            'replace_timings' => false,
            'sevas' => $t->pujas()->exists() ? [] : array_keys($f['sevas'] ?? []),
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

        $sections = [];

        $contact = array_filter([
            isset($f['phones']) ? $row('contact_phone', 'Phone'.(count($f['phones']) > 1 ? ' (also found: '.implode(', ', array_slice($f['phones'], 1, 3)).')' : ''), $t->contact_phone) : null,
            isset($f['emails']) ? $row('contact_email', 'Email', $t->contact_email) : null,
            isset($f['address']) ? $row('address', 'Address', $t->address, true) : null,
            isset($f['pincode']) ? $row('pincode', 'PIN code', $t->pincode) : null,
            isset($f['latitude']) ? Grid::make(['default' => 1, 'md' => 4])->schema([
                Toggle::make('use_location')->label('Take')->inline(false),
                TextInput::make('latitude')->numeric(),
                TextInput::make('longitude')->numeric(),
                Placeholder::make('loc_now')->label('On file now')->content($t->hasCoordinates() ? $t->latitude.', '.$t->longitude : '— (empty)'),
            ]) : null,
        ]);
        if ($contact !== []) {
            $sections[] = Section::make('Contact and location')->schema($contact);
        }

        if (! empty($f['timings'])) {
            $sections[] = Section::make('Timings')
                ->description($t->timings()->exists() ? 'The listing has '.$t->timings()->count().' timings already. Ticked ones are added; or replace them all.' : 'The listing has no timings yet.')
                ->schema([
                    CheckboxList::make('timings')->hiddenLabel()->columns(2)->bulkToggleable()
                        ->options(collect($f['timings'])->mapWithKeys(fn ($x, $i) => [$i => $x['label'].' · '.$x['opens_at'].'–'.$x['closes_at']])->all()),
                    Toggle::make('replace_timings')->label('Replace the listing\'s timings with the ticked ones'),
                ]);
        }

        if (! empty($f['sevas'])) {
            $sections[] = Section::make('Sevas and fees')
                ->description('Ticked sevas are added, or their fee updated where the name already exists. In-app booking stays off.')
                ->schema([
                    CheckboxList::make('sevas')->hiddenLabel()->columns(2)->bulkToggleable()
                        ->options(collect($f['sevas'])->mapWithKeys(fn ($x, $i) => [$i => $x['name'].' · ₹'.number_format($x['fee'])])->all()),
                    Toggle::make('publish_sevas')->label('Show the new sevas to devotees now (otherwise they are drafts)'),
                ]);
        }

        if (! empty($f['description'])) {
            $sections[] = Section::make('The site\'s own description')
                ->description('These are the temple\'s own words. Take them only with the temple\'s permission, or rewrite them in your own words first.')
                ->collapsed()
                ->schema([$row('short_description', 'Short description', $t->short_description, true)]);
        }

        if (! empty($f['image'])) {
            $sections[] = Section::make('Main image on the site')
                ->description('Photos belong to the temple. Upload one under Photos only with their permission.')
                ->collapsed()
                ->schema([Placeholder::make('image')->hiddenLabel()->content(new HtmlString('<a href="'.e($f['image']).'" target="_blank" rel="noopener"><img src="'.e($f['image']).'" style="max-height:180px;border-radius:10px" alt=""></a>'))]);
        }

        if ($sections === []) {
            $sections[] = Placeholder::make('none')->hiddenLabel()->content('Nothing useful was found on the site. Many temple sites put timings in images or PDFs, which cannot be read; enter them by hand.');
        }

        return $sections;
    }
}
