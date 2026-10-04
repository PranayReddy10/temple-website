<x-filament-panels::page>
    @include('filament.temple.partials.styles')
    <x-filament::section>
        <x-slot name="heading">
            <span style="display: inline-flex; gap: .5rem; align-items: center; flex-wrap: wrap">
                <x-filament::badge :color="$ticket->status?->getColor() ?? 'gray'">{{ $ticket->status?->getLabel() }}</x-filament::badge>
                <span class="ds-muted" style="font-family: ui-monospace, monospace">{{ $ticket->reference }}</span>
                @if ($ticket->aboutLabel())
                    <span class="ds-muted">· {{ $ticket->aboutLabel() }}</span>
                @endif
            </span>
        </x-slot>
        <div class="ds-thread">
            <div class="ds-msg ds-msg--mine">
                <div class="ds-msg__who">You · {{ $ticket->created_at?->timezone(\App\Support\DevotionalClock::timezone())->format('d M Y, g:i A') }}</div>
                <div class="ds-msg__body">{{ $ticket->body }}</div>
            </div>
            @foreach ($replies as $m)
                @php($mine = $m->author_type === \App\Models\User::class && (int) $m->author_id === (int) auth()->id())
                <div @class(['ds-msg', 'ds-msg--mine' => $mine])>
                    <div class="ds-msg__who">{{ $mine ? 'You' : ($m->isFromStaff() ? 'Darshan Saathi team' : $m->authorName()) }} · {{ $m->created_at?->timezone(\App\Support\DevotionalClock::timezone())->format('d M Y, g:i A') }}</div>
                    <div class="ds-msg__body">{{ $m->body }}</div>
                </div>
            @endforeach
            @if ($replies->isEmpty())
                <p class="ds-muted">No reply yet. Our team answers here, usually within a day.</p>
            @endif
        </div>
    </x-filament::section>
    <style>
        .ds-thread { display: grid; gap: .75rem; }
        .ds-msg { max-width: 46rem; border-radius: .75rem; padding: .75rem 1rem; border: 1px solid rgba(120, 113, 108, .22); }
        .ds-msg--mine { margin-left: auto; background: rgba(155, 27, 48, .05); border-color: rgba(155, 27, 48, .25); }
        .ds-msg__who { font-size: .75rem; opacity: .7; margin-bottom: .3rem; }
        .ds-msg__body { white-space: pre-line; font-size: .9375rem; }
    </style>
</x-filament-panels::page>
