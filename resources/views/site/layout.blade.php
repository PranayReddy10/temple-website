<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('brand.name') }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}">
    @foreach ($alternates ?? [] as $hreflang => $href)
        <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}">
    @endforeach
    @if (! \App\Support\Seo::onWebsite(request()) || ! empty($noindex))
        {{-- The admin host's copy (the website's is the one to list), a search, or a missing page. --}}
        <meta name="robots" content="noindex, follow">
    @endif
    <meta property="og:site_name" content="{{ config('brand.name') }}">
    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:locale" content="{{ ($locale ?? 'en').'_IN' }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    {{-- The temple's own photo, else the brand's share card (public/og.png). --}}
    @php($shareImage = ! empty($image) ? $image : \App\Support\Seo::url('og.png'))
    <meta property="og:image" content="{{ $shareImage }}">
    <meta property="og:image:alt" content="{{ $title }}">
    @if (empty($image))
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
    @endif
    {{-- WhatsApp, X and others read these for the preview card. --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $shareImage }}">
    <link rel="icon" href="{{ \App\Support\Seo::url('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ \App\Support\Seo::url('icons/apple-touch-icon.png') }}">
    @php($ga = \App\Support\Seo::measurementId())
    @if ($ga && \App\Support\Seo::onWebsite(request()))
        {{-- The website's stream under Admin → Analytics & SEO. --}}
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $ga }}"></script>
        <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config',@json($ga));</script>
    @endif
    @stack('head')
    <style>
        :root { --saffron: {{ config('brand.colors.saffron.hex') }}; --kumkum: {{ config('brand.colors.kumkum.hex') }}; --sandal: {{ config('brand.colors.sandal.hex') }}; --deep: {{ config('brand.colors.deep.hex') }}; --gold: {{ config('brand.colors.gold.hex') }}; --muted: #7a6a60; --line: #e7dccb; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--sandal); color: var(--deep); font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; line-height: 1.55; }
        a { color: var(--kumkum); }
        header.top { background: var(--deep); color: #fff; border-bottom: 3px solid var(--gold); position: sticky; top: 0; z-index: 10; }
        header.top .wrap { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding-top: 10px; padding-bottom: 10px; }
        header.top a { color: #fff; text-decoration: none; }
        .brand { font-family: Georgia, 'Noto Serif', serif; font-size: 1.25rem; font-weight: 700; display: inline-flex; align-items: center; gap: 10px; white-space: nowrap; }
        .brand .mark { width: 34px; height: 34px; display: inline-block; }
        nav.menu { display: flex; align-items: center; gap: 4px 18px; font-size: .95rem; }
        nav.menu a:not(.cta) { opacity: .88; padding: 4px 0; border-bottom: 2px solid transparent; }
        nav.menu a:not(.cta):hover, nav.menu a.on { opacity: 1; border-bottom-color: var(--gold); }
        @media (max-width: 560px) { nav.menu a.wide { display: none; } .brand { font-size: 1.1rem; } }
        .cta { background: var(--saffron); color: #fff !important; border-radius: 999px; padding: 8px 16px; font-weight: 600; font-size: .9rem; white-space: nowrap; }
        .wrap { max-width: 1080px; margin: 0 auto; padding-left: 16px; padding-right: 16px; }
        main { padding-top: 20px; padding-bottom: 40px; }
        h1 { font-family: Georgia, 'Noto Serif', serif; font-size: clamp(1.6rem, 4vw, 2.3rem); line-height: 1.2; margin: 8px 0; }
        h2 { font-family: Georgia, 'Noto Serif', serif; font-size: 1.3rem; margin: 28px 0 10px; }
        .muted { color: var(--muted); }
        .crumbs { font-size: .85rem; color: var(--muted); }
        .crumbs a { color: var(--muted); }
        .card { background: #fffdf9; border: 1px solid var(--line); border-radius: 16px; padding: 16px; }
        footer.bottom { background: #2e1d1a; color: #d9c9b6; border-top: 3px solid var(--gold); padding: 32px 0 28px; font-size: .9rem; }
        footer.bottom a { color: #f3e6d3; text-decoration: none; }
        footer.bottom a:hover { text-decoration: underline; }
        footer.bottom .cols { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 24px; }
        footer.bottom .cols > div, footer.bottom .cols > nav { display: flex; flex-direction: column; gap: 6px; }
        footer.bottom b { color: #fff; font-family: Georgia, 'Noto Serif', serif; font-size: 1.05rem; margin-bottom: 4px; }
        footer.bottom p { margin: 0 0 6px; }
        footer.bottom .note { border-top: 1px solid rgba(255,255,255,.12); margin-top: 24px; padding-top: 16px; font-size: .8rem; opacity: .8; }
        @media (max-width: 720px) { footer.bottom .cols { grid-template-columns: 1fr 1fr; } footer.bottom .cols > div:first-child { grid-column: 1 / -1; } }
        img { max-width: 100%; }
        form.find { display: flex; gap: 8px; margin: 12px 0 16px; max-width: 560px; }
        form.find input { flex: 1; min-width: 0; font: inherit; padding: 10px 14px; border: 1px solid var(--line); border-radius: 999px; background: #fff; color: var(--deep); }
        form.find button { font: inherit; font-weight: 600; background: var(--kumkum); color: #fff; border: 0; border-radius: 999px; padding: 10px 18px; cursor: pointer; }
    </style>
    {{-- Verification tags and the code pasted under Analytics & SEO, on every page. --}}
    {!! \App\Support\Seo::headExtras() !!}
</head>
<body>
    {!! \App\Support\Seo::bodyExtras() !!}
@php($storeUrl = \App\Support\Seo::storeUrl())
@php($footerPages = rescue(fn () => \App\Models\Page::footer(), collect(), report: false))
<header class="top">
    <div class="wrap">
        <a class="brand" href="{{ \App\Support\Seo::url('/') }}">
            {{-- Inline, so the header never waits on another request. --}}
            <span class="mark">{!! file_get_contents(public_path('brand/logo-mark-light.svg')) !!}</span>
            {{ config('brand.name') }}
        </a>
        <nav class="menu" aria-label="Main">
            <a href="{{ \App\Support\Seo::url('temples') }}" @class(['on' => request()->is('temples', 'temples/*', '*/temples/*')])>{{ __('Temples') }}</a>
            <a href="{{ \App\Support\Seo::url('/') }}#states" class="wide">{{ __('States') }}</a>
            <a href="{{ \App\Support\Seo::url('/') }}#deities" class="wide">{{ __('Deities') }}</a>
            <a class="cta" href="{{ $appLink ?? ($storeUrl ?? \App\Support\Seo::url('/').'#app') }}" @isset($appIntent) data-intent="{{ $appIntent }}" @endisset>{{ __('Get the app') }}</a>
        </nav>
    </div>
</header>
<main class="wrap">
    @yield('content')
</main>
<footer class="bottom">
    <div class="wrap">
        <div class="cols">
            <div>
                <b class="brand-sm">{{ config('brand.name') }}</b>
                <p>{{ config('brand.tagline') }}. {{ __('Darshan timings, pujas and sevas, dress code and directions for temples across India.') }}</p>
                <p><a href="mailto:{{ setting('support_email', 'brand.support_email') }}">{{ setting('support_email', 'brand.support_email') }}</a></p>
            </div>
            <div>
                <b>{{ __('Explore') }}</b>
                <a href="{{ \App\Support\Seo::url('temples') }}">{{ __('All temples') }}</a>
                <a href="{{ \App\Support\Seo::url('/') }}#states">{{ __('Temples by state') }}</a>
                <a href="{{ \App\Support\Seo::url('/') }}#deities">{{ __('Temples by deity') }}</a>
                <a href="{{ $storeUrl ?? \App\Support\Seo::url('/').'#app' }}">{{ __('Get the app') }}</a>
            </div>
            @if ($footerPages->isNotEmpty())
                <nav class="legal" aria-label="Policies">
                    <b>{{ __('About') }}</b>
                    @foreach ($footerPages as $fp)
                        <a href="{{ \App\Support\Seo::url($fp->slug) }}">{{ $fp->title }}</a>
                    @endforeach
                </nav>
            @endif
        </div>
        <p class="note">{{ __('Timings and rules change; confirm with the temple before travelling.') }} · © {{ now()->year }} {{ config('brand.name') }}</p>
    </div>
</footer>
<script>
    // On Android, app buttons open the installed app on this temple (the
    // store, or the Get the app section, when it is not installed).
    if (/Android/i.test(navigator.userAgent)) document.querySelectorAll('a[data-intent]').forEach(function (a) { a.href = a.dataset.intent; });
</script>
</body>
</html>
