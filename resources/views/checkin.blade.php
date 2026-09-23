<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $temple?->name ?? 'Temple code' }} · {{ config('brand.name') }}</title>
    <style>
        :root { --saffron: {{ config('brand.colors.saffron.hex') }}; --kumkum: {{ config('brand.colors.kumkum.hex') }}; --sandal: {{ config('brand.colors.sandal.hex') }}; --deep: {{ config('brand.colors.deep.hex') }}; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--sandal); color: var(--deep); font-family: Georgia, 'Noto Serif', serif; }
        .card { max-width: 420px; width: 100%; background: #fffdf9; border-radius: 20px; padding: 28px 24px; text-align: center; border: 2px solid var(--saffron); box-shadow: 0 10px 30px rgba(62, 39, 35, .12); }
        .card.bad { border-color: var(--kumkum); }
        .mark { width: 72px; height: 72px; border-radius: 50%; margin: 0 auto 16px; display: grid; place-items: center; font-size: 38px; color: #fff; background: var(--saffron); }
        .bad .mark { background: var(--kumkum); }
        h1 { font-size: 1.4rem; margin: 0 0 8px; }
        p { font-family: system-ui, sans-serif; line-height: 1.5; margin: 8px 0; }
        small { color: #7a6a60; font-family: system-ui, sans-serif; }
    </style>
</head>
<body>
    <main @class(['card', 'bad' => ! $valid])>
        <div class="mark">{{ $valid ? '✓' : '!' }}</div>
        <h1>{{ $valid ? $temple->name : 'Not a genuine code' }}</h1>
        <p>{{ $reason }}</p>
        @if ($valid)
            <p>Open <strong>{{ config('brand.name') }}</strong> and use <em>Scan temple QR</em> on the temple's page to collect a verified stamp in your Passport.</p>
        @else
            <p>Please tell the temple office. Only codes printed from {{ config('brand.name') }} give a verified stamp.</p>
        @endif
        <small>{{ config('brand.name') }} · {{ config('brand.tagline') }}</small>
    </main>
</body>
</html>
