{{-- A temple at a glance: its deity, today's hours, where it is and what kind it is. Shown first on a phone, beside the page on a wide screen. --}}
@php use App\Support\Seo; @endphp
<div class="card glance {{ $where }}">
    <h2><span class="pi" aria-hidden="true">🛕</span>{{ __('At a glance') }}</h2>
    @if ($deityName)
        <div class="g-tile"><span class="lbl">{{ __('Deity') }}</span>@if ($temple->deity?->slug)<a href="{{ Seo::url('deities/'.$temple->deity->slug) }}">{{ $deityName }}</a>@else{{ $deityName }}@endif</div>
    @endif
    <div class="g-tile">
        <span class="lbl">{{ __('Today') }} ({{ __(\App\Support\DevotionalClock::now()->format('l')) }})</span>
        @if ($todays->isNotEmpty())
            @foreach ($todays->take(3) as $t)<div>{{ $t->label ?: __($t->kind?->getLabel() ?? '') }}: <b>{{ $t->window() }}</b></div>@endforeach
        @elseif ($temple->timings->isNotEmpty())
            {{-- Open on other days only: say so, and when. --}}
            @php $next = \App\Models\TempleTiming::inReadingOrder($temple->timings)->first(); @endphp
            <div><b>{{ __('Closed today') }}</b> · {{ __($next->dayLabel()) }} {{ $next->window() }}</div>
        @elseif ($entryRules)
            <div>{{ \Illuminate\Support\Str::limit($entryRules, 90) }}</div>
        @else
            <div class="muted">{{ __('Timings not published yet') }}</div>
        @endif
    </div>
    @if ($place)
        <div class="g-tile"><span class="lbl">{{ __('Location') }}</span>{{ $place }}@if ($temple->district && $temple->state) · <a href="{{ Seo::url('states/'.$temple->state->slug.'/'.$temple->district->slug) }}">{{ __('Temples in :place', ['place' => $temple->district->name]) }}</a>@endif</div>
    @endif
    @if ($temple->categories->isNotEmpty())
        <div class="g-tile"><span class="lbl">{{ __('Type') }}</span>{{ $temple->categories->pluck('name')->implode(' · ') }}</div>
    @endif
</div>
