<?php

namespace App\Filament\Resources\Temples\Pages;

use App\Enums\TempleStatus;
use App\Filament\Resources\Temples\TempleResource;
use App\Models\Temple;
use App\Support\TempleImport\TempleLinkImport;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ListTemples extends ListRecords
{
    protected static string $resource = TempleResource::class;

    /** Each line is read while you wait; more go through temples:import-links. */
    public const LINKS_PER_RUN = 15;

    public function getSubheading(): ?string
    {
        $published = Temple::query()->where('status', TempleStatus::Published)->count();
        $total = Temple::query()->count();

        if ($total === 0) {
            return 'The temple database is the core asset. Add the first record to get started.';
        }

        return number_format($published).' published of '.number_format($total).' recorded. '
            .'Use the grouping menu to see them state-wise or deity-wise.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add a temple'),
            Action::make('importLinks')
                ->label('Add from Google Maps links')
                ->icon('heroicon-o-map-pin')
                ->color('gray')
                ->modalHeading('Add temples from Google Maps links')
                ->modalDescription('One temple per line: its Google Maps link, then its website if it has one. Each becomes a draft with its pin, address, district and state, and its website is read for you to review. A temple already listed at that spot is skipped.')
                ->schema([
                    Textarea::make('links')->hiddenLabel()->rows(10)->required()
                        ->placeholder("https://maps.app.goo.gl/AbCdEf https://www.example-temple.org\nhttps://maps.app.goo.gl/GhIjKl\nSri Rama Temple https://www.google.com/maps/place/…")
                        ->helperText('Up to '.self::LINKS_PER_RUN.' lines at a time. Words on a line before the links are used as the name.'),
                ])
                ->modalSubmitActionLabel('Add temples')
                ->action(function (array $data): void {
                    $lines = array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $data['links'])))), 0, self::LINKS_PER_RUN);
                    $results = collect($lines)->map(function (string $line): array {
                        $l = TempleLinkImport::parseLine($line);

                        return TempleLinkImport::create($l['maps'], $l['website'], $l['name']);
                    });
                    $count = fn (string $status) => $results->where('status', $status)->count();
                    Notification::make()
                        ->title($count('created').' added, '.$count('duplicate').' already listed, '.$count('error').' not read')
                        ->body(new HtmlString(nl2br(e($results->pluck('message')->implode("\n")))))
                        ->status($count('error') > 0 ? 'warning' : 'success')
                        ->persistent()
                        ->send();
                    if ($count('created') > 0) {
                        $this->redirect(TempleResource::getUrl('index', ['filters' => ['official_to_review' => ['isActive' => true]]]));
                    }
                }),
        ];
    }

    /**
     * The cuts of this list people actually take.
     *
     * Tabs rather than more filters because these are the ones reached many
     * times a day, and a filter you have to open a panel to set is a filter
     * that gets set once and then left on by mistake. The counts are the
     * point as much as the filtering: "needs attention" showing zero is
     * worth seeing without clicking it.
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All')
                ->badge(fn (): int => Temple::query()->count()),

            'published' => Tab::make('Published')
                ->icon('heroicon-m-check-badge')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TempleStatus::Published))
                ->badge(fn (): int => Temple::query()->where('status', TempleStatus::Published)->count())
                ->badgeColor('success'),

            'in_review' => Tab::make('Waiting for review')
                ->icon('heroicon-m-clock')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TempleStatus::InReview))
                ->badge(fn (): int => Temple::query()->where('status', TempleStatus::InReview)->count())
                ->badgeColor('warning'),

            'drafts' => Tab::make('Drafts')
                ->icon('heroicon-m-pencil-square')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TempleStatus::Draft))
                ->badge(fn (): int => Temple::query()->where('status', TempleStatus::Draft)->count()),

            /*
             * Published but incomplete: the listings a devotee can already
             * reach and be let down by. A temple with no photo and no
             * coordinates is on the map screen as a blank card that cannot be
             * navigated to.
             */
            'needs_work' => Tab::make('Needs work')
                ->icon('heroicon-m-exclamation-triangle')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', TempleStatus::Published)
                    ->where(fn (Builder $q) => $q
                        ->whereNull('latitude')
                        ->orWhereNull('longitude')
                        ->orWhereDoesntHave('photos')
                        ->orWhereNull('short_description')))
                ->badge(fn (): int => Temple::query()
                    ->where('status', TempleStatus::Published)
                    ->where(fn (Builder $q) => $q
                        ->whereNull('latitude')
                        ->orWhereNull('longitude')
                        ->orWhereDoesntHave('photos')
                        ->orWhereNull('short_description'))
                    ->count())
                ->badgeColor('danger'),
        ];
    }
}
