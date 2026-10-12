@php($past = $f->starts_on->lt($today ?? now()->startOfDay()))
<a class="card fcard" href="{{ \App\Http\Controllers\FestivalPageController::url($f) }}">
    <div @class(['fdate', 'vrat' => $f->kind === 'vrat', 'past' => $past])><b>{{ $f->starts_on->format('j') }}</b><small>{{ $f->starts_on->format('M') }}</small></div>
    <div>
        <b>{{ $f->name }}</b>@if ($f->is_major)<span class="tag major">{{ __('Major') }}</span>@endif
        <p>{{ $f->starts_on->format('l') }}@if ($f->ends_on && ! $f->ends_on->isSameDay($f->starts_on)) – {{ $f->ends_on->format('j M') }}@endif @if ($f->tithi) · {{ $f->tithi }}@endif</p>
    </div>
</a>
