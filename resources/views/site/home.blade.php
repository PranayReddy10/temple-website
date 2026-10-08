@extends('site.layout')

@php
    use App\Http\Controllers\PublicTempleController;
    use App\Support\Seo;
    use Illuminate\Support\Str;

    // The site itself, for search engines: its name and its search box.
    $website = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => config('brand.name'),
        'url' => Seo::url('/'),
        'description' => $description,
        'publisher' => [
            '@type' => 'Organization',
            'name' => config('brand.name'),
            'url' => Seo::url('/'),
            'logo' => Seo::url('icons/icon-512.png'),
            'email' => setting('support_email', 'brand.support_email'),
        ],
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => ['@type' => 'EntryPoint', 'urlTemplate' => Seo::url('temples').'?q={search_term_string}'],
            'query-input' => 'required name=search_term_string',
        ],
    ];
@endphp

@push('head')
    <script type="application/ld+json">{!! json_encode($website, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    <style>
        main.wrap { max-width: none; padding: 0 0 40px; }
        .sec { max-width: 1080px; margin: 0 auto; padding: 0 16px; }
        .hero { background: radial-gradient(ellipse at top, #6b2a22 0%, var(--deep) 70%); color: #fff; text-align: center; padding: 48px 16px 56px; position: relative; }
        .hero::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 10px; background: repeating-linear-gradient(90deg, var(--gold) 0 14px, transparent 14px 20px); opacity: .55; }
        .hero .om { font-size: 2rem; color: var(--gold); line-height: 1; }
        .hero h1 { color: #fff; font-size: clamp(1.8rem, 5vw, 2.8rem); margin: 10px auto 8px; max-width: 760px; }
        .hero p.lead { color: #f1dfc8; max-width: 620px; margin: 0 auto; font-size: 1.05rem; }
        .hero form.find { margin: 24px auto 12px; max-width: 600px; }
        .hero form.find input { padding: 14px 20px; border: 0; font-size: 1rem; }
        .hero form.find button { background: var(--saffron); padding: 12px 22px; }
        .hero .stats { display: flex; justify-content: center; flex-wrap: wrap; gap: 8px 28px; margin-top: 18px; color: #f1dfc8; font-size: .92rem; }
        .hero .stats b { color: var(--gold); font-size: 1.15rem; margin-right: 4px; }
        .title { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin: 40px 0 14px; }
        .title h2 { margin: 0; font-size: 1.5rem; }
        .title h2::after { content: ""; display: block; width: 56px; height: 3px; background: var(--saffron); margin-top: 6px; border-radius: 2px; }
        .today { display: grid; grid-template-columns: auto 1fr; gap: 16px; align-items: center; margin-top: 28px; border-left: 4px solid var(--saffron); }
        .today .day { font-family: Georgia, 'Noto Serif', serif; font-size: 1.05rem; color: var(--kumkum); font-weight: 700; }
        .today p { margin: 4px 0 0; }
        .today .mantra { font-style: italic; color: var(--deep); }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
        .tile { display: block; background: #fffdf9; border: 1px solid var(--line); border-radius: 16px; overflow: hidden; text-decoration: none; color: var(--deep); transition: box-shadow .15s, transform .15s; }
        .tile:hover { box-shadow: 0 8px 24px rgba(62, 39, 35, .12); transform: translateY(-2px); }
        .tile .ph { aspect-ratio: 16/9; background: linear-gradient(135deg, #efe3cf, #e4cfae); display: grid; place-items: center; color: #b8925a; font-size: 2rem; }
        .tile .ph img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .tile .tx { padding: 12px; }
        .tile b { display: block; font-size: 1rem; line-height: 1.3; }
        .tile span { color: var(--muted); font-size: .85rem; }
        .badges { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .badge { font-size: .72rem; font-weight: 600; padding: 2px 8px; border-radius: 999px; background: #f5ead6; color: var(--deep); }
        .badge.book { background: var(--kumkum); color: #fff; }
        .chips { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
        .chips a { display: flex; justify-content: space-between; align-items: center; gap: 8px; background: #fffdf9; border: 1px solid var(--line); border-radius: 12px; padding: 10px 14px; text-decoration: none; color: var(--deep); font-weight: 600; }
        .chips a:hover { border-color: var(--saffron); }
        .chips a small { color: var(--muted); font-weight: 400; }
        .fests { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
        .fest { display: flex; gap: 14px; align-items: flex-start; }
        .fest .date { flex: none; width: 58px; text-align: center; background: var(--kumkum); color: #fff; border-radius: 10px; padding: 6px 0; line-height: 1.1; }
        .fest .date b { display: block; font-size: 1.4rem; }
        .fest .date small { font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; }
        .fest p { margin: 2px 0 0; font-size: .88rem; color: var(--muted); }
        .features { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 16px; }
        .features .card h3 { font-family: Georgia, 'Noto Serif', serif; margin: 8px 0 6px; font-size: 1.1rem; }
        .features .card p { margin: 0; color: var(--muted); font-size: .92rem; }
        .features .ic { font-size: 1.6rem; }
        .app { margin-top: 44px; background: linear-gradient(120deg, var(--kumkum), #6b1322); color: #fff; border-radius: 20px; padding: 28px; display: grid; grid-template-columns: 1fr auto; gap: 20px; align-items: center; }
        .app h2 { color: #fff; margin: 0 0 6px; }
        .app p { margin: 0; color: #f6dde0; }
        .app .btn { display: inline-block; background: #fff; color: var(--kumkum); font-weight: 700; padding: 12px 22px; border-radius: 999px; text-decoration: none; white-space: nowrap; }
        .links { columns: 3 220px; column-gap: 24px; padding: 0; margin: 0; list-style: none; font-size: .92rem; }
        .links li { break-inside: avoid; padding: 3px 0; }
        .links a { color: var(--deep); text-decoration: none; }
        .links a:hover { color: var(--kumkum); text-decoration: underline; }
        .all { font-weight: 600; text-decoration: none; white-space: nowrap; }
        @media (max-width: 640px) { .app { grid-template-columns: 1fr; } .today { grid-template-columns: 1fr; } .grid { grid-template-columns: 1fr 1fr; gap: 10px; } .tile .tx { padding: 8px 10px; } .tile b { font-size: .9rem; } .tile span { font-size: .78rem; } .chips a { padding: 8px 12px; font-size: .9rem; } .features { grid-template-columns: 1fr 1fr; } .features .card { padding: 12px; } }
    </style>
@endpush

@section('content')
    <section class="hero">
        <div class="om" aria-hidden="true">ॐ</div>
        <h1>{{ __('Find temples, darshan timings and sevas across India') }}</h1>
        <p class="lead">{{ __('Timings, pujas and sevas, dress code, festivals and directions for every temple, in one place.') }}</p>
        <form class="find" action="{{ Seo::url('temples') }}" method="get" role="search">
            <input type="search" name="q" placeholder="{{ __('Search a temple, town or deity') }}" aria-label="{{ __('Search temples') }}">
            <button type="submit">{{ __('Search') }}</button>
        </form>
        <div class="stats">
            <span><b>{{ number_format($templeCount) }}</b>{{ Str::plural('temple', $templeCount) }}</span>
            @if ($states->isNotEmpty())<span><b>{{ $states->count() }}</b>{{ Str::plural('state', $states->count()) }}</span>@endif
            <span><b>5</b>{{ __('languages') }}</span>
        </div>
    </section>

    <div class="sec">
        @if ($day)
            <div class="card today">
                <div class="day">{{ $weekday }}</div>
                <div>
                    <b>{{ $day->title ?: ($day->deity?->name ? __('The day of :deity', ['deity' => $day->deity->name]) : $weekday) }}</b>
                    @if ($day->subtitle)<p class="muted">{{ $day->subtitle }}</p>@endif
                    @if ($day->mantra)<p class="mantra">{{ $day->mantra_transliteration ?: $day->mantra }}</p>@endif
                    @if ($day->deity?->slug)<p><a href="{{ Seo::url('deities/'.$day->deity->slug) }}">{{ __(':deity temples', ['deity' => PublicTempleController::deityPhrase($day->deity->name)]) }} →</a></p>@endif
                </div>
            </div>
        @endif

        @if ($popular->isNotEmpty())
            <div class="title">
                <h2>{{ __('Popular temples') }}</h2>
                <a class="all" href="{{ Seo::url('temples') }}">{{ __('All temples') }} →</a>
            </div>
            <div class="grid">
                @foreach ($popular as $t)
                    <a class="tile" href="{{ Seo::url('temples/'.$t->slug) }}">
                        <div class="ph">@if ($t->primaryPhoto)<img src="{{ Seo::absolute($t->primaryPhoto->thumbnailUrl()) }}" alt="{{ $t->name }}" loading="lazy">@else<span aria-hidden="true">🛕</span>@endif</div>
                        <div class="tx">
                            <b>{{ $t->name }}</b>
                            <span>{{ collect([$t->deity?->name, $t->city, $t->state?->name])->filter()->implode(' · ') }}</span>
                            <div class="badges">
                                @if ($t->books_in_app)<span class="badge book">{{ __('Book sevas online') }}</span>@endif
                                @if ($t->pujas_count)<span class="badge">{{ $t->pujas_count }} {{ Str::plural('seva', $t->pujas_count) }}</span>@endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif

        @if ($states->isNotEmpty())
            <div class="title" id="states"><h2>{{ __('Temples by state') }}</h2></div>
            <nav class="chips" aria-label="States">
                @foreach ($states as $s)
                    <a href="{{ Seo::url('states/'.$s->slug) }}">{{ $s->name }} <small>{{ $s->temples_count }}</small></a>
                @endforeach
            </nav>
        @endif

        @if ($deities->isNotEmpty())
            <div class="title" id="deities"><h2>{{ __('Temples by deity') }}</h2></div>
            <nav class="chips" aria-label="Deities">
                @foreach ($deities as $d)
                    <a href="{{ Seo::url('deities/'.$d->slug) }}">{{ PublicTempleController::deityPhrase($d->name) }} <small>{{ $d->temples_count }}</small></a>
                @endforeach
            </nav>
        @endif

        @if ($festivals->isNotEmpty())
            <div class="title"><h2>{{ __('Festivals ahead') }}</h2></div>
            <div class="fests">
                @foreach ($festivals as $f)
                    <div class="card fest">
                        <div class="date"><b>{{ $f->starts_on->format('j') }}</b><small>{{ $f->starts_on->format('M') }}</small></div>
                        <div>
                            <b>{{ $f->name }}</b>
                            <p>{{ $f->starts_on->format('l') }}@if ($f->ends_on && ! $f->ends_on->isSameDay($f->starts_on)) – {{ $f->ends_on->format('j M') }}@endif @if ($f->tithi) · {{ $f->tithi }}@endif</p>
                            @if ($f->description)<p>{{ Str::limit(strip_tags($f->description), 110) }}</p>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="title"><h2>{{ __('Everything for your darshan') }}</h2></div>
        <div class="features">
            <div class="card"><div class="ic" aria-hidden="true">🕰️</div><h3>{{ __('Darshan timings') }}</h3><p>{{ __("Today's darshan and aarti hours, weekend timings, festival days and closures.") }}</p></div>
            <div class="card"><div class="ic" aria-hidden="true">🪔</div><h3>{{ __('Pujas and sevas') }}</h3><p>{{ __('The sevas each temple offers and their fees. Many can be booked and paid for in the app.') }}</p></div>
            <div class="card"><div class="ic" aria-hidden="true">🧭</div><h3>{{ __('Visiting rules and directions') }}</h3><p>{{ __('Dress code, mobile and photography rules, and the way there on the map.') }}</p></div>
            <div class="card"><div class="ic" aria-hidden="true">📿</div><h3>{{ __('Temple passport and yatras') }}</h3><p>{{ __('Collect a stamp at every temple you visit and plan a yatra across several temples.') }}</p></div>
        </div>

        <section class="app" id="app">
            <div>
                <h2 class="android-app">{{ __('Get the :app app', ['app' => config('brand.name')]) }}</h2>
                <h2 class="ios-only">{{ __('On iPhone? Everything works right here') }}</h2>
                <p class="ios-only">{{ __('Sign in on this website to book sevas, give to the hundi, buy event tickets and keep your temple passport. The iPhone app is on its way.') }}</p>
                <p class="android-app">{{ __('Book sevas, give to the hundi, check in for passport stamps and get festival reminders, in English, Telugu, Hindi, Tamil and Kannada.') }}</p>
            </div>
            <div class="android-app">
                @if ($storeUrl)
                    <a class="btn" href="{{ $storeUrl }}" rel="noopener">{{ __('Get it on Google Play') }}</a>
                @else
                    <span class="btn">{{ __('Coming soon to Google Play') }}</span>
                @endif
            </div>
            <a class="btn ios-only" href="{{ Seo::url(auth('devotee_web')->check() ? 'account' : 'register') }}">{{ auth('devotee_web')->check() ? __('My account') : __('Create a free account') }}</a>
        </section>

        @if ($more->isNotEmpty())
            <div class="title"><h2>{{ __('More temples') }}</h2></div>
            <ul class="links">
                @foreach ($more as $t)
                    <li><a href="{{ Seo::url('temples/'.$t->slug) }}">{{ $t->name }}{{ $t->city && ! Str::contains($t->name, $t->city, true) ? ', '.$t->city : '' }}</a></li>
                @endforeach
            </ul>
            <p><a class="all" href="{{ Seo::url('temples') }}">{{ __('Browse all :count temples', ['count' => number_format($templeCount)]) }} →</a></p>
        @endif
    </div>
@endsection
