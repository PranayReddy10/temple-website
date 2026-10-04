<?php

namespace App\Filament\Temple\Widgets;

use App\Filament\Temple\Widgets\Concerns\ForCurrentTemple;
use App\Models\PujaBooking;
use App\Support\Clock;
use App\Support\DevotionalClock;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Who is coming for a seva today, in the order they are expected. */
class TodaysBookingsWidget extends TableWidget
{
    use ForCurrentTemple;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $temple = $this->temple();

        return $table
            ->heading('Seva bookings for today')
            ->query(fn () => PujaBooking::query()
                ->where('temple_id', $temple?->id ?? 0)
                ->live()
                ->forDay(DevotionalClock::now()->toDateString())
                ->with(['puja:id,name,starts_at'])
                ->orderBy('slot_starts_at'))
            ->columns([
                TextColumn::make('slot_starts_at')->label('Time')
                    ->state(fn (PujaBooking $b): string => $b->slotLabel() ?? ($b->puja?->starts_at ? Clock::twelve((string) $b->puja->starts_at) : 'Any time')),
                TextColumn::make('puja.name')->label('Seva')->weight('medium'),
                TextColumn::make('devotee_name')->label('For')->description(fn (PujaBooking $b): ?string => $b->devotee_phone),
                TextColumn::make('people')->alignCenter(),
                TextColumn::make('reference')->fontFamily('mono')->copyable(),
                TextColumn::make('status')->badge(),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('No seva bookings for today')
            ->emptyStateDescription('Bookings devotees make in the app for today appear here; receive them with Scan booking.')
            ->emptyStateIcon('heroicon-o-ticket');
    }
}
