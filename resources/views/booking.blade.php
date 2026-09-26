<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $booking?->reference ?? 'Booking' }} · {{ config('brand.name') }}</title>
    <style>
        :root { --saffron: {{ config('brand.colors.saffron.hex') }}; --kumkum: {{ config('brand.colors.kumkum.hex') }}; --sandal: {{ config('brand.colors.sandal.hex') }}; --deep: {{ config('brand.colors.deep.hex') }}; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; padding: 16px; background: var(--sandal); color: var(--deep); font-family: Georgia, 'Noto Serif', serif; }
        .card { max-width: 480px; margin: 24px auto; background: #fffdf9; border-radius: 20px; padding: 24px; border: 2px solid var(--saffron); box-shadow: 0 10px 30px rgba(62, 39, 35, .12); }
        .bad { border-color: var(--kumkum); text-align: center; }
        h1 { font-size: 1.4rem; margin: 0 0 4px; }
        p, dt, dd, small { font-family: system-ui, sans-serif; }
        .muted { color: #7a6a60; font-size: .875rem; margin: 2px 0 0; }
        .ref { font-family: ui-monospace, monospace; letter-spacing: .12em; font-size: 1.3rem; font-weight: 700; margin: 14px 0 4px; }
        .status { display: inline-block; font-size: .75rem; border-radius: 999px; padding: 3px 10px; background: #efe6d8; margin-top: 10px; }
        .status.ok { background: #d9f2e1; color: #14532d; }
        .status.live { background: #dbeafe; color: #1e3a8a; }
        .status.off { background: #fde2e2; color: #7f1d1d; }
        dl { display: grid; grid-template-columns: auto 1fr; gap: 8px 14px; margin: 18px 0 0; font-size: .9rem; }
        dt { color: #7a6a60; } dd { margin: 0; }
        .note { margin-top: 18px; font-size: .8rem; color: #7a6a60; }
    </style>
</head>
<body>
@if ($booking === null)
    <main class="card bad">
        <h1>Booking not found</h1>
        <p>This code is not recognised. It may have been cancelled and booked again; ask the devotee to open the booking in the app.</p>
        <small>{{ config('brand.name') }} · {{ config('brand.tagline') }}</small>
    </main>
@else
    <main class="card">
        <p class="muted">Seva booking · {{ config('brand.name') }}</p>
        <h1>{{ $booking->puja?->name ?? 'Seva' }}</h1>
        <p class="muted">{{ $booking->temple?->name }}{{ $booking->temple?->city ? ', '.$booking->temple->city : '' }}</p>
        <div class="ref">{{ $booking->reference }}</div>
        <span @class(['status', 'ok' => $booking->isVerified(), 'live' => $booking->isConfirmed(), 'off' => ! $booking->isLive()])>{{ $booking->status->getLabel() }}</span>
        <dl>
            <dt>Day</dt><dd>{{ $booking->booked_for?->format('l, d M Y') }}</dd>
            <dt>People</dt><dd>{{ $booking->people }}</dd>
            <dt>In the name of</dt><dd>{{ $booking->devotee_name }}</dd>
            <dt>Amount</dt><dd>{{ $booking->amountLabel() }}</dd>
            @if ($booking->isVerified())
                <dt>Received</dt><dd>{{ $booking->verified_at?->timezone(\App\Support\DevotionalClock::timezone())->format('d M Y, H:i') }}</dd>
            @endif
        </dl>
        <p class="note">The temple's counter verifies this code with its own scanner in the temple portal. A verified code is not accepted a second time.</p>
    </main>
@endif
</body>
</html>
