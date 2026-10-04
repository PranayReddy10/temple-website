<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('brand.name') }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}">
    @if (! \App\Support\Seo::onWebsite(request()) || ! empty($noindex))
        {{-- The admin host's copy (the website's is the one to list), a search, or a missing page. --}}
        <meta name="robots" content="noindex, follow">
    @endif
    <meta property="og:site_name" content="{{ config('brand.name') }}">
    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:locale" content="en_IN">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    {{-- The temple's own photo, else the brand's share card (web/og.png in the app's web build). --}}
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
    <link rel="apple-touch-icon" href="{{ \App\Support\Seo::url('icons/Icon-192.png') }}">
    @php($ga = \App\Support\Seo::measurementId())
    @if ($ga && \App\Support\Seo::onWebsite(request()))
        {{-- The same stream as the web app, so a visit that starts here and goes on in the app is one visit. --}}
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $ga }}"></script>
        <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config',@json($ga));</script>
    @endif
    @stack('head')
    <style>
        :root { --saffron: {{ config('brand.colors.saffron.hex') }}; --kumkum: {{ config('brand.colors.kumkum.hex') }}; --sandal: {{ config('brand.colors.sandal.hex') }}; --deep: {{ config('brand.colors.deep.hex') }}; --muted: #7a6a60; --line: #e7dccb; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--sandal); color: var(--deep); font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; line-height: 1.55; }
        a { color: var(--kumkum); }
        header.top { background: var(--deep); color: #fff; }
        header.top .wrap { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding-top: 12px; padding-bottom: 12px; }
        header.top a { color: #fff; text-decoration: none; }
        .brand { font-family: Georgia, 'Noto Serif', serif; font-size: 1.25rem; font-weight: 700; }
        .cta { background: var(--saffron); color: #fff !important; border-radius: 999px; padding: 8px 16px; font-weight: 600; font-size: .9rem; white-space: nowrap; }
        .wrap { max-width: 1080px; margin: 0 auto; padding-left: 16px; padding-right: 16px; }
        main { padding-top: 20px; padding-bottom: 40px; }
        h1 { font-family: Georgia, 'Noto Serif', serif; font-size: clamp(1.6rem, 4vw, 2.3rem); line-height: 1.2; margin: 8px 0; }
        h2 { font-family: Georgia, 'Noto Serif', serif; font-size: 1.3rem; margin: 28px 0 10px; }
        .muted { color: var(--muted); }
        .crumbs { font-size: .85rem; color: var(--muted); }
        .crumbs a { color: var(--muted); }
        .card { background: #fffdf9; border: 1px solid var(--line); border-radius: 16px; padding: 16px; }
        footer.bottom { border-top: 1px solid var(--line); padding: 24px 0 40px; font-size: .85rem; color: var(--muted); }
        img { max-width: 100%; }
        nav.legal { display: flex; flex-wrap: wrap; gap: 6px 16px; margin: 8px 0; }
        nav.legal a { color: var(--muted); }
        form.find { display: flex; gap: 8px; margin: 12px 0 16px; max-width: 560px; }
        form.find input { flex: 1; min-width: 0; font: inherit; padding: 10px 14px; border: 1px solid var(--line); border-radius: 999px; background: #fff; color: var(--deep); }
        form.find button { font: inherit; font-weight: 600; background: var(--kumkum); color: #fff; border: 0; border-radius: 999px; padding: 10px 18px; cursor: pointer; }
    </style>
    {{-- Verification tags and the code pasted under Analytics & SEO, on every page. --}}
    {!! \App\Support\Seo::headExtras() !!}
</head>
<body>
    {!! \App\Support\Seo::bodyExtras() !!}
<header class="top">
    <div class="wrap">
        <a class="brand" href="{{ \App\Support\Seo::url('/') }}" style="display:inline-flex;align-items:center;gap:10px">
            {{-- Inline: these pages are also served on darshansaathi.com, where /brand is not this app's. --}}
            <span style="width:34px;height:34px;display:inline-block">{!! file_get_contents(public_path('brand/logo-mark-light.svg')) !!}</span>
            {{ config('brand.name') }}
        </a>
        <a class="cta" href="{{ $appLink ?? \App\Support\Seo::url('/') }}">Open the app</a>
    </div>
</header>
<main class="wrap">
    @yield('content')
</main>
<footer class="bottom">
    <div class="wrap">
        <p><a href="{{ \App\Support\Seo::url('temples') }}">All temples</a> · {{ config('brand.tagline') }} · <a href="mailto:{{ setting('support_email', 'brand.support_email') }}">{{ setting('support_email', 'brand.support_email') }}</a></p>
        @php($footerPages = rescue(fn () => \App\Models\Page::footer(), collect(), report: false))
        @if ($footerPages->isNotEmpty())
            <nav class="legal" aria-label="Policies">
                @foreach ($footerPages as $fp)
                    <a href="{{ \App\Support\Seo::url($fp->slug) }}">{{ $fp->title }}</a>
                @endforeach
            </nav>
        @endif
        <p>Timings and rules change; confirm with the temple before travelling.</p>
    </div>
</footer>
</body>
</html>
