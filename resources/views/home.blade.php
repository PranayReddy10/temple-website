<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ config('brand.name') }}</title>
    <link rel="icon" href="{{ asset('icons/favicon-32.png') }}">
    <style>
        :root { --saffron: {{ config('brand.colors.saffron.hex') }}; --kumkum: {{ config('brand.colors.kumkum.hex') }}; --sandal: {{ config('brand.colors.sandal.hex') }}; --deep: {{ config('brand.colors.deep.hex') }}; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; padding: 16px; background: var(--sandal); color: var(--deep); font-family: system-ui, sans-serif; display: grid; place-items: center; }
        .card { width: 100%; max-width: 520px; background: #fffdf9; border-radius: 20px; padding: 32px 24px; border: 2px solid var(--saffron); box-shadow: 0 10px 30px rgba(62, 39, 35, .12); text-align: center; }
        .logo { width: 88px; height: 88px; border-radius: 22px; }
        h1 { font-family: Georgia, 'Noto Serif', serif; font-size: 2rem; margin: 12px 0 4px; }
        .tagline { color: #7a6a60; margin: 0 0 20px; }
        .count { display: inline-block; background: #fbeee0; color: var(--kumkum); border-radius: 999px; padding: 4px 14px; font-size: .9rem; margin-bottom: 24px; }
        .primary { display: block; background: var(--saffron); color: #fff; text-decoration: none; font-weight: 600; border-radius: 12px; padding: 14px; margin-bottom: 12px; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .secondary { display: block; border: 1px solid #e7dccb; color: var(--deep); text-decoration: none; border-radius: 12px; padding: 12px 8px; font-size: .9rem; }
        .secondary b { display: block; font-size: 1rem; margin-bottom: 2px; }
        .secondary span { color: #7a6a60; font-size: .8rem; }
        footer { margin-top: 24px; color: #7a6a60; font-size: .8rem; }
        footer a { color: inherit; }
        @media (max-width: 380px) { .row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <main class="card">
        <img class="logo" src="{{ asset('icons/icon-192.png') }}" alt="">
        <h1>{{ config('brand.name') }}</h1>
        <p class="tagline">{{ config('brand.tagline') }}</p>
        @if ($temples > 0)
            <div class="count">{{ number_format($temples) }} {{ \Illuminate\Support\Str::plural('temple', $temples) }} listed</div>
        @endif

        <a class="primary" href="{{ config('brand.website') }}">Open {{ config('brand.name') }} →</a>

        <div class="row">
            <a class="secondary" href="{{ url('/temple') }}"><b>Temple portal</b><span>For temple teams: bookings, sevas, scans</span></a>
            <a class="secondary" href="{{ url('/admin') }}"><b>Admin</b><span>For the {{ config('brand.name') }} team</span></a>
        </div>

        <footer>
            Help: <a href="mailto:{{ config('brand.support_email') }}">{{ config('brand.support_email') }}</a>
        </footer>
    </main>
</body>
</html>
