@php
    $days = $this->getDays();
    $lead = $days->first();
    $accent = $lead?->accentColor() ?? config('brand.colors.saffron.hex');
@endphp

<x-filament-widgets::widget>
    <div
        class="temple-today"
        style="--day-accent: {{ $accent }};"
    >
        <div class="temple-today__rule" aria-hidden="true"></div>

        <div class="temple-today__body">
            <div class="temple-today__meta">
                <p class="temple-today__eyebrow">{{ $this->getDayName() }} · {{ $this->getFormattedDate() }}</p>

                @if ($lead)
                    <h2 class="temple-today__title">{{ $lead->title }}</h2>
                    @if ($lead->subtitle)
                        <p class="temple-today__subtitle">{{ $lead->subtitle }}</p>
                    @endif
                    @if ($lead->mantra)
                        <p class="temple-today__mantra">{{ $lead->mantra }}</p>
                        <p class="temple-today__translit">{{ $lead->mantra_transliteration }}</p>
                    @endif
                @else
                    <h2 class="temple-today__title">No deity set for today</h2>
                    <p class="temple-today__subtitle">
                        Add one under Daily Devotion so devotees see something on the app home screen.
                    </p>
                @endif
            </div>

            @if ($days->isNotEmpty())
                <div class="temple-today__deities">
                    @foreach ($days as $day)
                        <div class="temple-today__deity">
                            <span class="temple-today__deity-name">{{ $day->deity?->name }}</span>
                            <span class="temple-today__deity-count">
                                {{ $day->media->count() }} {{ Str::plural('item', $day->media->count()) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-filament-widgets::widget>
