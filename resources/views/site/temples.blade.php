@extends('site.layout')

@push('head')
    @if ($temples->previousPageUrl())<link rel="prev" href="{{ $temples->previousPageUrl() }}">@endif
    @if ($temples->nextPageUrl())<link rel="next" href="{{ $temples->nextPageUrl() }}">@endif
    <style>
        .states { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0 20px; }
        .states a { background: #fffdf9; border: 1px solid var(--line); border-radius: 999px; padding: 4px 12px; font-size: .85rem; text-decoration: none; color: var(--deep); }
        .states a.on { background: var(--saffron); border-color: var(--saffron); color: #fff; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
        .tile { display: block; background: #fffdf9; border: 1px solid var(--line); border-radius: 16px; overflow: hidden; text-decoration: none; color: var(--deep); }
        .tile .ph { aspect-ratio: 16/9; background: #efe3cf; }
        .tile .ph img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .tile .tx { padding: 12px; }
        .tile b { display: block; font-size: 1rem; line-height: 1.3; }
        .tile span { color: var(--muted); font-size: .85rem; }
        nav.pages { display: flex; justify-content: space-between; margin-top: 24px; }
    </style>
@endpush

@section('content')
    <p class="crumbs"><a href="{{ \App\Support\Seo::url('/') }}">Home</a> › @if ($state || $deity)<a href="{{ \App\Support\Seo::url('temples') }}">Temples</a> › {{ $state?->name ?? $deity->name }}@else Temples @endif</p>
    <h1>{{ $heading }}</h1>
    <p class="muted">{{ number_format($temples->total()) }} {{ \Illuminate\Support\Str::plural('temple', $temples->total()) }} with darshan timings, pujas and sevas, visiting rules and directions.</p>
    @if (! empty($intro))<p>{{ $intro }}</p>@endif

    @if ($states->isNotEmpty())
        <nav class="states" aria-label="States">
            @foreach ($states as $s)
                <a href="{{ \App\Support\Seo::url('states/'.$s->slug) }}" @class(['on' => $state?->id === $s->id])>{{ $s->name }} ({{ $s->temples_count }})</a>
            @endforeach
        </nav>
    @endif

    @if ($deities->isNotEmpty())
        <nav class="states" aria-label="Deities">
            @foreach ($deities->take(24) as $d)
                <a href="{{ \App\Support\Seo::url('deities/'.$d->slug) }}" @class(['on' => $deity?->id === $d->id])>{{ $d->name }} ({{ $d->temples_count }})</a>
            @endforeach
        </nav>
    @endif

    <div class="grid">
        @foreach ($temples as $t)
            <a class="tile" href="{{ \App\Support\Seo::url('temples/'.$t->slug) }}">
                <div class="ph">@if ($t->primaryPhoto)<img src="{{ \App\Support\Seo::absolute($t->primaryPhoto->thumbnailUrl()) }}" alt="{{ $t->name }}" loading="lazy">@endif</div>
                <div class="tx">
                    <b>{{ $t->name }}</b>
                    <span>{{ collect([$t->deity?->name, $t->city, $t->state?->name])->filter()->implode(' · ') }}</span>
                </div>
            </a>
        @endforeach
    </div>

    <nav class="pages">
        <span>@if ($temples->previousPageUrl())<a href="{{ $temples->previousPageUrl() }}">← Previous</a>@endif</span>
        <span class="muted">Page {{ $temples->currentPage() }} of {{ $temples->lastPage() }}</span>
        <span>@if ($temples->nextPageUrl())<a href="{{ $temples->nextPageUrl() }}">Next →</a>@endif</span>
    </nav>
@endsection
