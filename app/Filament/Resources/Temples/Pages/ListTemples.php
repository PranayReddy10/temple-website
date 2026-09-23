<?php

namespace App\Filament\Resources\Temples\Pages;

use App\Enums\TempleStatus;
use App\Filament\Resources\Temples\TempleResource;
use App\Models\Temple;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTemples extends ListRecords
{
    protected static string $resource = TempleResource::class;

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
