<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Payment') · {{ config('brand.name') }}</title>
    <style>
        :root { --saffron: {{ config('brand.colors.saffron.hex') }}; --kumkum: {{ config('brand.colors.kumkum.hex') }}; --sandal: {{ config('brand.colors.sandal.hex') }}; --deep: {{ config('brand.colors.deep.hex') }}; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: var(--sandal); color: var(--deep); font-family: system-ui, sans-serif; }
        .card { max-width: 420px; width: 100%; background: #fffdf9; border-radius: 20px; padding: 28px 24px; text-align: center; border: 2px solid var(--saffron); box-shadow: 0 10px 30px rgba(62, 39, 35, .12); }
        .card.bad { border-color: var(--kumkum); }
        h1 { font-family: Georgia, 'Noto Serif', serif; font-size: 1.35rem; margin: 8px 0; }
        .amount { font-size: 2rem; font-weight: 700; margin: 8px 0; }
        .muted { color: #7a6a60; font-size: .9rem; line-height: 1.5; }
        button, .button { display: inline-block; border: 0; border-radius: 12px; padding: 14px 22px; font-size: 1rem; font-weight: 600; background: var(--kumkum); color: #fff; cursor: pointer; margin-top: 16px; text-decoration: none; }
        .mark { width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 8px; display: grid; place-items: center; font-size: 34px; color: #fff; background: var(--saffron); }
        .bad .mark { background: var(--kumkum); }
        .spinner { width: 36px; height: 36px; border: 4px solid #eadfcf; border-top-color: var(--saffron); border-radius: 50%; margin: 12px auto; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    @yield('body')
</body>
</html>
