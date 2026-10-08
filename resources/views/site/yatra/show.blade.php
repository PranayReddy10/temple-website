@extends('site.layout')

@php
    $u = \App\Support\Seo::class;
    $base = $u::url('account/yatras/'.$yatra->id);
    $today = (int) \App\Support\DevotionalClock::now()->dayOfWeek;
    $total = array_sum($legs);
    // The plan as text, for WhatsApp.
    $plan = '🛕 '.$yatra->title.($yatra->starts_on ? ' ('.$yatra->dateLabel().')' : '')."\n";
    foreach ($days as $d => $stops) {
        $plan .= "\n".__('Day').' '.$d.":\n";
        foreach ($stops as $s) { $plan .= '• '.$s->temple?->name.($s->temple?->city ? ', '.$s->temple->city : '')."\n"; }
    }
    if ($mapsUrl) { $plan .= "\n".__('Route').': '.$mapsUrl; }
@endphp

@push('head')
    <style>
        .yhead { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; align-items: flex-start; }
        .day { margin-top: 22px; }
        .day h2 { display: flex; align-items: center; gap: 10px; margin: 0 0 10px; }
        .day h2 .n { background: var(--kumkum); color: #fff; border-radius: 999px; padding: 2px 12px; font-size: .9rem; font-family: system-ui, sans-serif; }
        .stop { display: grid; grid-template-columns: 64px 1fr auto; gap: 12px; align-items: center; }
        .stop .ph { width: 64px; height: 64px; border-radius: 12px; overflow: hidden; background: #efe3cf; display: grid; place-items: center; font-size: 1.4rem; }
        .stop .ph img { width: 100%; height: 100%; object-fit: cover; }
        .stop b a { color: var(--deep); text-decoration: none; }
        .stop small { display: block; color: var(--muted); }
        .stop .tools { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; justify-content: flex-end; }
        .stop .tools button, .stop .tools select { font: inherit; font-size: .85rem; padding: 6px 9px; border-radius: 8px; border: 1px solid var(--line); background: #fff; cursor: pointer; color: var(--deep); }
        .leg { margin: 4px 0 4px 30px; padding-left: 18px; border-left: 2px dashed var(--line); color: var(--muted); font-size: .85rem; }
        .find-temple { display: flex; gap: 8px; }
        .find-temple input { flex: 1; min-width: 0; font: inherit; padding: 10px 14px; border: 1px solid var(--line); border-radius: 999px; }
        .found { display: grid; gap: 8px; margin-top: 10px; }
        .found .item form { margin: 0; }
        @media (max-width: 640px) { .stop { grid-template-columns: 48px 1fr; } .stop .ph { width: 48px; height: 48px; } .stop .tools { grid-column: 1 / -1; justify-content: flex-start; } }
    </style>
@endpush

@section('content')
    <div class="acct">
        @include('site.account._nav')
        <div>
            <p class="crumbs"><a href="{{ $u::url('account/yatras') }}">{{ __('Yatra planner') }}</a> › {{ $yatra->title }}</p>
            <div class="yhead">
                <div>
                    <h1 style="margin-bottom:4px">{{ $yatra->title }}</h1>
                    <p class="muted" style="margin:0">{{ $yatra->dateLabel() }} · {{ $yatra->stops->count() }} {{ \Illuminate\Support\Str::plural('temple', $yatra->stops->count()) }}@if ($total > 0) · ~{{ number_format($total) }} km {{ __('between temples') }}@endif @if ($yatra->party_size) · {{ $yatra->party_size }} {{ __('people') }}@endif</p>
                </div>
                <span class="pill {{ $yatra->status->value }}">{{ $yatra->status->getLabel() }}</span>
            </div>

            @if ($yatra->stops->isNotEmpty())
                <div class="ticket-share">
                    @if ($mapsUrl)<a class="btn primary" href="{{ $mapsUrl }}" target="_blank" rel="noopener">🗺️ {{ __('Route in Google Maps') }}</a>@endif
                    <form method="post" action="{{ $base.'/optimise' }}">@csrf<button class="btn" type="submit">✨ {{ __('Shortest order') }}</button></form>
                    <a class="btn wa" href="https://wa.me/?text={{ rawurlencode($plan) }}" target="_blank" rel="noopener">{{ __('Share on WhatsApp') }}</a>
                    <button class="btn" type="button" onclick="window.print()">🖨️ {{ __('Print') }}</button>
                </div>
            @endif

            @forelse ($days as $d => $stops)
                <section class="day">
                    <h2><span class="n">{{ __('Day') }} {{ $d }}</span>@if ($yatra->starts_on)<small class="muted" style="font-family:system-ui;font-size:.9rem;font-weight:400">{{ $yatra->starts_on->copy()->addDays($d - 1)->format('D, j M') }}</small>@endif</h2>
                    @foreach ($stops as $s)
                        @if (isset($legs[$s->id]))<div class="leg">↓ {{ number_format($legs[$s->id], 1) }} km</div>@endif
                        @php($todayHours = $s->temple ? \App\Models\TempleTiming::forDay($s->temple->timings, $today) : collect())
                        <div class="card stop" id="stop-{{ $s->id }}">
                            <div class="ph">@if ($s->temple?->primaryPhoto)<img src="{{ $u::absolute($s->temple->primaryPhoto->thumbnailUrl()) }}" alt="" loading="lazy">@else🛕@endif</div>
                            <div>
                                <b><a href="{{ $u::url('temples/'.$s->temple?->slug) }}">{{ $s->temple?->name }}</a></b>
                                <small>{{ collect([$s->temple?->city, $s->temple?->state?->name])->filter()->implode(', ') }}</small>
                                @if ($todayHours->isNotEmpty())<small>🕰️ {{ __('Today') }}: {{ $todayHours->map(fn ($t) => $t->window())->implode(', ') }}</small>@endif
                            </div>
                            <div class="tools">
                                <form method="post" action="{{ $base.'/stops/'.$s->id.'/move' }}">@csrf<input type="hidden" name="to" value="up"><button type="submit" title="{{ __('Earlier') }}" aria-label="{{ __('Earlier') }}">↑</button></form>
                                <form method="post" action="{{ $base.'/stops/'.$s->id.'/move' }}">@csrf<input type="hidden" name="to" value="down"><button type="submit" title="{{ __('Later') }}" aria-label="{{ __('Later') }}">↓</button></form>
                                <form method="post" action="{{ $base.'/stops/'.$s->id.'/move' }}">@csrf
                                    <select name="day_number" onchange="this.form.submit()" aria-label="{{ __('Day') }}">
                                        @for ($n = 1; $n <= $nextDay + 1; $n++)<option value="{{ $n }}" @selected($n === $s->day_number)>{{ __('Day') }} {{ $n }}</option>@endfor
                                    </select>
                                </form>
                                <form method="post" action="{{ $base.'/stops/'.$s->id.'/remove' }}">@csrf<button type="submit" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}">✕</button></form>
                            </div>
                        </div>
                    @endforeach
                </section>
            @empty
                <p class="card" style="margin-top:18px">{{ __('No temples yet. Search below, or use “Add to yatra” on any temple’s page.') }}</p>
            @endforelse

            <h2 id="add">{{ __('Add a temple') }}</h2>
            <form class="find-temple" method="get" action="{{ $base }}#add">
                <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('Temple name or town') }}" aria-label="{{ __('Search temples') }}">
                <button class="btn primary" type="submit">{{ __('Search') }}</button>
            </form>
            @if ($q !== '')
                <div class="found">
                    @forelse ($found as $t)
                        <div class="card item">
                            <span><b>{{ $t->name }}</b><small>{{ collect([$t->city, $t->state?->name])->filter()->implode(', ') }}</small></span>
                            <form method="post" action="{{ $base.'/stops' }}" style="display:flex;gap:6px">@csrf
                                <input type="hidden" name="temple" value="{{ $t->slug }}">
                                <select name="day_number" style="font:inherit;padding:6px;border-radius:8px;border:1px solid var(--line)">@for ($n = 1; $n <= $nextDay + 1; $n++)<option value="{{ $n }}" @selected($n === $nextDay)>{{ __('Day') }} {{ $n }}</option>@endfor</select>
                                <button class="btn primary" type="submit">＋ {{ __('Add') }}</button>
                            </form>
                        </div>
                    @empty
                        <p class="muted">{{ __('No temples match. Try a shorter name or the town.') }}</p>
                    @endforelse
                </div>
            @endif

            <details style="margin-top:28px">
                <summary style="cursor:pointer;font-weight:600">✏️ {{ __('Edit details or delete') }}</summary>
                <form class="card form" method="post" action="{{ $base }}" style="margin-top:12px">
                    @csrf
                    <label>{{ __('Name') }}<input name="title" value="{{ old('title', $yatra->title) }}" required maxlength="160"></label>
                    <div class="row">
                        <label>{{ __('From') }}<input type="date" name="starts_on" value="{{ old('starts_on', $yatra->starts_on?->toDateString()) }}"></label>
                        <label>{{ __('To') }}<input type="date" name="ends_on" value="{{ old('ends_on', $yatra->ends_on?->toDateString()) }}"></label>
                    </div>
                    <div class="row">
                        <label>{{ __('People travelling') }}<input type="number" name="party_size" min="1" max="500" value="{{ old('party_size', $yatra->party_size) }}"></label>
                        <label>{{ __('Status') }}
                            <select name="status">@foreach ($statuses as $st)<option value="{{ $st->value }}" @selected($yatra->status === $st)>{{ $st->getLabel() }}</option>@endforeach</select>
                        </label>
                    </div>
                    <label>{{ __('Notes') }}<textarea name="description" rows="3" maxlength="5000">{{ old('description', $yatra->description) }}</textarea></label>
                    <button class="btn primary" type="submit">{{ __('Save') }}</button>
                </form>
                <form method="post" action="{{ $base.'/delete' }}" onsubmit="return confirm('{{ __('Delete this yatra?') }}')" style="margin-top:10px">@csrf<button class="btn" type="submit" style="border-color:#a3162c;color:#a3162c">{{ __('Delete yatra') }}</button></form>
            </details>
            @if ($yatra->description)<p class="card" style="margin-top:16px;white-space:pre-line">{{ $yatra->description }}</p>@endif
        </div>
    </div>
@endsection
