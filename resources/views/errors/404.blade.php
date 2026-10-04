@php
    use App\Support\Seo;
    // Words from the address that was asked for: "temples/mattapalli-narasimha" → "mattapalli narasimha".
    $asked = trim(str_replace(['-', '_', '/'], ' ', preg_replace('#^(temples|states|deities)/#', '', trim(request()->path(), '/'))));
    $suggestions = $asked === '' ? collect() : rescue(fn () => \App\Support\TempleFinder::suggestions($asked, 6), collect(), report: false);
    $title = 'Page not found';
    $description = 'This page could not be found on '.config('brand.name').'.';
    $canonical = Seo::url(request()->path());
    $noindex = true;
@endphp
@extends('site.layout')

@push('head')
    <style>
        .lost { text-align: center; padding: 24px 0 8px; }
        .lost svg { width: 150px; height: auto; display: block; margin: 0 auto 8px; }
        .lost .code { font-family: Georgia, 'Noto Serif', serif; color: var(--saffron); font-size: 1rem; letter-spacing: .3em; margin: 0 auto; }
        .lost h1 { margin: 6px 0 8px; }
        .lost p { max-width: 520px; margin: 0 auto; }
        .lost form.find { margin: 20px auto; }
        .ways { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin: 8px 0 24px; }
        .ways a { background: #fffdf9; border: 1px solid var(--line); border-radius: 999px; padding: 8px 16px; text-decoration: none; color: var(--deep); font-weight: 600; font-size: .92rem; }
        .ways a.primary { background: var(--saffron); border-color: var(--saffron); color: #fff; }
        .more { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; text-align: left; }
        .more a { display: flex; gap: 10px; align-items: center; background: #fffdf9; border: 1px solid var(--line); border-radius: 14px; padding: 8px; text-decoration: none; color: var(--deep); }
        .more .ph { width: 64px; height: 48px; flex: none; border-radius: 10px; overflow: hidden; background: #efe3cf; }
        .more .ph img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .more b { display: block; font-size: .92rem; line-height: 1.25; }
        .more span { color: var(--muted); font-size: .8rem; }
    </style>
@endpush

@section('content')
    <section class="lost">
        {{-- A gopuram with its doors closed and the lamp still lit. --}}
        <svg viewBox="0 0 120 120" aria-hidden="true">
            <path d="M60 6c5 6 7 10 7 13a7 7 0 0 1-14 0c0-3 2-7 7-13z" fill="#E07A1F"/>
            <ellipse cx="60" cy="28" rx="5" ry="3.2" fill="#C9A227"/>
            <path d="M51 33h18l2 8H49z" fill="#9B1B30"/>
            <path d="M46 42h28l2.5 9h-33z" fill="#9B1B30"/>
            <path d="M41 52h38l3 10H38z" fill="#9B1B30"/>
            <path d="M35 63h50l3 11H32z" fill="#9B1B30"/>
            <path d="M28 75h64v33H28z" fill="#9B1B30"/>
            <path d="M48 108V88a12 12 0 0 1 24 0v20z" fill="#3E2723"/>
            <path d="M60 78v30" stroke="#C9A227" stroke-width="1.5"/>
            <circle cx="56.5" cy="95" r="1.6" fill="#C9A227"/><circle cx="63.5" cy="95" r="1.6" fill="#C9A227"/>
            <path d="M20 108h80" stroke="#C9A227" stroke-width="3" stroke-linecap="round"/>
        </svg>
        <p class="code">404</p>
        <h1>The doors to this page are closed</h1>
        <p class="muted">The address may be mistyped, or the page has moved. The temple you are looking for may be under a slightly different name.</p>

        <form class="find" action="{{ Seo::url('temples') }}" method="get" role="search">
            <input type="search" name="q" value="{{ \Illuminate\Support\Str::limit($asked, 60, '') }}" placeholder="Search by temple name or town" aria-label="Search temples">
            <button type="submit">Search</button>
        </form>

        <div class="ways">
            <a class="primary" href="{{ Seo::url('temples') }}">Browse all temples</a>
            <a href="{{ Seo::url('/') }}">Open {{ config('brand.name') }}</a>
            <a href="{{ Seo::url('contact-us') }}">Contact us</a>
        </div>
    </section>

    @if ($suggestions->isNotEmpty())
        <h2>Were you looking for?</h2>
        <div class="more">
            @foreach ($suggestions as $t)
                <a href="{{ Seo::url('temples/'.$t->slug) }}">
                    <div class="ph">@if ($t->primaryPhoto)<img src="{{ Seo::absolute($t->primaryPhoto->thumbnailUrl()) }}" alt="{{ $t->name }}" loading="lazy">@endif</div>
                    <div><b>{{ $t->name }}</b><span>{{ collect([$t->city, $t->state?->name])->filter()->unique()->implode(', ') }}</span></div>
                </a>
            @endforeach
        </div>
    @endif
@endsection
