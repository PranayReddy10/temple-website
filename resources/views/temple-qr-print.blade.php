<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Check-in QR · {{ $temple->name }}</title>
    <style>
        :root { --saffron: {{ config('brand.colors.saffron.hex') }}; --kumkum: {{ config('brand.colors.kumkum.hex') }}; --sandal: {{ config('brand.colors.sandal.hex') }}; --deep: {{ config('brand.colors.deep.hex') }}; }
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f4efe6; color: var(--deep); font-family: Georgia, 'Noto Serif', serif; }
        .toolbar { display: flex; gap: 8px; justify-content: center; padding: 12px; font-family: system-ui, sans-serif; flex-wrap: wrap; }
        .toolbar button, .toolbar a { border: 0; border-radius: 10px; padding: 10px 16px; font-size: 15px; cursor: pointer; text-decoration: none; background: var(--kumkum); color: #fff; }
        .toolbar a { background: #fff; color: var(--deep); border: 1px solid #d6c7b0; }
        .poster { width: 100%; max-width: 186mm; margin: 0 auto 24px; background: #fffdf9; border: 3px solid var(--saffron); border-radius: 18px; padding: 14mm 12mm; text-align: center; }
        .brand { letter-spacing: .3em; text-transform: uppercase; font-size: 13px; color: var(--kumkum); font-family: system-ui, sans-serif; font-weight: 700; }
        h1 { font-size: 30px; margin: 10px 0 2px; }
        .city { font-family: system-ui, sans-serif; color: #7a6a60; margin: 0; }
        .qr { width: 118mm; max-width: 100%; margin: 10mm auto 6mm; padding: 4mm; border: 2px solid var(--deep); border-radius: 14px; background: #fff; }
        .qr svg { width: 100%; height: auto; display: block; }
        .cta { font-size: 22px; margin: 0; }
        ol { text-align: left; max-width: 130mm; margin: 6mm auto 0; font-family: system-ui, sans-serif; font-size: 14px; line-height: 1.6; }
        .url { font-family: ui-monospace, monospace; font-size: 9px; color: #9a8b80; word-break: break-all; margin-top: 8mm; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .poster { margin: 0 auto; max-width: none; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print</button>
        <a href="{{ route('temples.qr.download', $temple) }}">Download SVG</a>
    </div>
    <main class="poster">
        <div class="brand">{{ config('brand.name') }} · Temple Passport</div>
        <h1>{{ $temple->name }}</h1>
        <p class="city">{{ collect([$temple->city, $temple->deity?->name])->filter()->implode(' · ') }}</p>
        <div class="qr">{!! $svg !!}</div>
        <p class="cta">Scan to collect your verified stamp</p>
        <ol>
            <li>Open {{ config('brand.name') }} and go to <strong>Passport</strong> or this temple's page.</li>
            <li>Tap <strong>Scan temple QR</strong> and point the camera at this code.</li>
            <li>Your visit is recorded with a verified stamp in your passport.</li>
        </ol>
        <p class="url">{{ $url }}</p>
    </main>
</body>
</html>
