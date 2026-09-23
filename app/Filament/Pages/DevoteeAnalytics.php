<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Devotees\DevoteeActivityChart;
use App\Filament\Widgets\Devotees\DevoteeAudienceWidget;
use App\Filament\Widgets\Devotees\DevoteeEngagementWidget;
use App\Filament\Widgets\Devotees\LanguageBreakdownWidget;
use App\Filament\Widgets\Devotees\PopularTemplesWidget;
use App\Support\DevoteeStats;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Everything about the devotee side of the product, on one screen.
 *
 * Its own page rather than more tiles on the dashboard. The dashboard is a
 * work queue — what needs a person today — and product analytics is a
 * different job done at a different time; mixing them means the number of
 * monthly active users sits next to the two temples awaiting review, and
 * neither gets the attention it needs.
 */
class DevoteeAnalytics extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Devotees';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Analytics';

    protected static ?string $title = 'Devotee Analytics';

    protected static ?string $slug = 'devotee-analytics';

    protected string $view = 'filament.pages.devotee-analytics';

    public function getSubheading(): ?string
    {
        $monthly = DevoteeStats::activeUsers(30);
        $total = DevoteeStats::totalAccounts();

        if ($total === 0) {
            return 'No devotee accounts yet. Figures appear as people sign up in the app.';
        }

        return number_format($total).' accounts · '
            .number_format($monthly).' active in the last 30 days · '
            .number_format(DevoteeStats::tripsBeingPlanned()).' trips being planned';
    }

    public function getWidgets(): array
    {
        return [
            DevoteeAudienceWidget::class,
            DevoteeEngagementWidget::class,
            DevoteeActivityChart::class,
            LanguageBreakdownWidget::class,
            PopularTemplesWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }

    /** Product figures are for staff; a temple admin never reaches this panel. */
    public static function canAccess(): bool
    {
        return Auth::user()?->role?->isStaff() ?? false;
    }
}
