@extends('site.layout')

@php
    // The list itself, for search engines: which temples this page is about.
    $itemList = [
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => $heading,
        'numberOfItems' => $temples->total(),
        'itemListElement' => $temples->values()->map(fn ($t, $i) => [
            '@type' => 'ListItem',
            'position' => ($temples->firstItem() ?? 1) + $i,
            'url' => \App\Support\Seo::url('temples/'.$t->slug),
            'name' => $t->name,
        ])->all(),
    ];
@endphp

@push('head')
    @if (empty($noindex) && $temples->isNotEmpty())<script type="application/ld+json">{!! json_encode($itemList, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>@endif
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
        .tile p { margin: 6px 0 0; font-size: .85rem; color: var(--deep); opacity: .85; }
        .badges { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .badge { font-size: .72rem; font-weight: 600; padding: 2px 8px; border-radius: 999px; background: #f5ead6; color: var(--deep); }
        .badge.book { background: var(--kumkum); color: #fff; }
    </style>
@endpush

@section('content')
    @php($district = $district ?? null)
    @php($districts = $districts ?? collect())
    <p class="crumbs"><a href="{{ \App\Support\Seo::url('/') }}">Home</a> › @if ($state || $deity)<a href="{{ \App\Support\Seo::url('temples') }}">Temples</a> › @if ($district)<a href="{{ \App\Support\Seo::url('states/'.$state->slug) }}">{{ $state->name }}</a> › {{ $district->name }}@else{{ $state?->name ?? $deity->name }}@endif @else Temples @endif</p>
    <h1>{{ $heading }}</h1>
    <p class="muted">{{ number_format($temples->total()) }} {{ \Illuminate\Support\Str::plural('temple', $temples->total()) }} with darshan timings, pujas and sevas, visiting rules and directions.</p>
    @if (! empty($intro))<p>{{ $intro }}</p>@endif

    {{-- On a state or deity page the search stays within it. --}}
    <form class="find" action="{{ \App\Support\Seo::url($district ? 'states/'.$state->slug.'/'.$district->slug : ($state ? 'states/'.$state->slug : ($deity ? 'deities/'.$deity->slug : 'temples'))) }}" method="get" role="search">
        <input type="search" name="q" value="{{ $q ?? '' }}" placeholder="{{ $district ? 'Search temples in '.$district->name : ($state ? 'Search temples in '.$state->name : ($deity ? 'Search '.\App\Http\Controllers\PublicTempleController::deityPhrase($deity->name).' temples' : 'Search by temple name or town')) }}" aria-label="Search temples">
        <button type="submit">Search</button>
    </form>
    @if (($state || $deity) && filled($q ?? null))
        <p class="muted">@if ($temples->total() === 0)No temples here match "{{ $q }}". @endif<a href="{{ \App\Support\Seo::url('temples?q='.urlencode($q)) }}">Search all temples</a></p>
    @endif

    @if ($districts->isNotEmpty())
        {{-- A state's districts: "temples in <district>" is how people look. --}}
        <nav class="states" aria-label="Districts">
            @foreach ($districts as $d)
                <a href="{{ \App\Support\Seo::url('states/'.$state->slug.'/'.$d->slug) }}" @class(['on' => $district?->id === $d->id])>{{ $d->name }} ({{ $d->temples_count }})</a>
            @endforeach
        </nav>
    @endif

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

    @if ($temples->isEmpty())
        <p class="card">No temples match that yet. Try a shorter name, the town, or <a href="{{ \App\Support\Seo::url('temples') }}">browse all temples</a>.</p>
    @endif

    <div class="grid">
        @foreach ($temples as $t)
            <a class="tile" href="{{ \App\Support\Seo::url('temples/'.$t->slug) }}">
                <div class="ph">@if ($t->primaryPhoto)<img src="{{ \App\Support\Seo::absolute($t->primaryPhoto->thumbnailUrl()) }}" alt="{{ $t->name }}" loading="lazy">@endif</div>
                <div class="tx">
                    <b>{{ $t->name }}</b>
                    <span>{{ collect([$t->deity?->name, $t->city, $t->state?->name])->filter()->implode(' · ') }}</span>
                    @if ($t->short_description)<p>{{ \Illuminate\Support\Str::limit(strip_tags($t->short_description), 110) }}</p>@endif
                    <div class="badges">
                        @if ($t->books_in_app)<span class="badge book">Book sevas online</span>@endif
                        @if ($t->pujas_count)<span class="badge">{{ $t->pujas_count }} {{ \Illuminate\Support\Str::plural('seva', $t->pujas_count) }}</span>@endif
                        @if ($t->is_featured)<span class="badge">Popular</span>@endif
                    </div>
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
