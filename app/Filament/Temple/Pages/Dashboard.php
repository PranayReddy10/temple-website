<?php

namespace App\Filament\Temple\Pages;

use App\Filament\Temple\TemplePortal;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The temple team's home: today at the temple, money and its status, what
 * waits for an answer, and a way into everything the trust app offers.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'Dashboard';

    public function mount(): void
    {
        $this->filters = ['temple' => TemplePortal::choose($this->filters['temple'] ?? null)?->id];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    Select::make('temple')
                        ->label('Temple')
                        ->options(fn (): array => TemplePortal::options())
                        ->selectablePlaceholder(false)
                        ->live()
                        ->afterStateUpdated(fn ($state) => TemplePortal::choose($state)),
                ])
                // One temple needs no choosing.
                ->visible(fn (): bool => count(TemplePortal::options()) > 1),
        ]);
    }

    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 3];
    }
}
