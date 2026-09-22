<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DevotionalDays\DevotionalDayResource;
use App\Models\DevotionalDay;
use Filament\Widgets\Widget;

/**
 * Today's deity, front and centre on the dashboard.
 *
 * The admin panel is used by people maintaining a devotional product, so the
 * day's deity is genuinely useful context — it is what devotees are seeing in
 * the app right now.
 */
class TodaysDeityWidget extends Widget
{
    protected string $view = 'filament.widgets.todays-deity';

    protected static ?int $sort = -2;

    protected int|string|array $columnSpan = 'full';

    public function getDays()
    {
        return DevotionalDay::query()
            ->active()
            ->forDate()
            ->with(['deity', 'media' => fn ($q) => $q->published()])
            ->orderBy('sort_order')
            ->get();
    }

    public function getDayName(): string
    {
        return now()->format('l');
    }

    public function getFormattedDate(): string
    {
        return now()->format('j F Y');
    }

    /**
     * Where to go to change what devotees are seeing right now.
     *
     * Editing a day is the only thing anyone wants to do from this panel, so
     * it links straight to that record — or to the list, when there is no day
     * set and the answer is to add one.
     */
    public function dayUrl(?DevotionalDay $day = null): ?string
    {
        if (! DevotionalDayResource::canAccess()) {
            return null;
        }

        return $day === null
            ? DevotionalDayResource::getUrl('index')
            : DevotionalDayResource::getUrl('edit', ['record' => $day]);
    }
}
