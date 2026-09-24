<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $passport['name'] ?? 'Passport' }} · {{ config('brand.name') }}</title>
    <style>
        :root { --saffron: {{ config('brand.colors.saffron.hex') }}; --kumkum: {{ config('brand.colors.kumkum.hex') }}; --sandal: {{ config('brand.colors.sandal.hex') }}; --deep: {{ config('brand.colors.deep.hex') }}; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; padding: 16px; background: var(--sandal); color: var(--deep); font-family: Georgia, 'Noto Serif', serif; }
        .card { max-width: 520px; margin: 24px auto; background: #fffdf9; border-radius: 20px; padding: 24px; border: 2px solid var(--saffron); box-shadow: 0 10px 30px rgba(62, 39, 35, .12); }
        .bad { border-color: var(--kumkum); text-align: center; }
        .head { display: flex; gap: 14px; align-items: center; }
        .avatar { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; background: var(--sandal); display: grid; place-items: center; font-size: 28px; flex-shrink: 0; }
        h1 { font-size: 1.4rem; margin: 0; }
        p, li, small { font-family: system-ui, sans-serif; }
        .muted { color: #7a6a60; font-size: .875rem; margin: 2px 0 0; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 18px 0; }
        .stat { text-align: center; border: 1px solid #e7dccb; border-radius: 12px; padding: 8px 4px; }
        .stat b { display: block; font-size: 1.3rem; color: var(--kumkum); }
        .stat span { font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; color: #7a6a60; font-family: system-ui, sans-serif; }
        ul { list-style: none; padding: 0; margin: 0; }
        li { display: flex; justify-content: space-between; gap: 8px; padding: 10px 0; border-top: 1px solid #efe6d8; font-size: .9rem; }
        .badge { font-size: .7rem; border-radius: 999px; padding: 2px 8px; background: #efe6d8; white-space: nowrap; align-self: center; }
        .badge.ok { background: #d9f2e1; color: #14532d; }
    </style>
</head>
<body>
@if ($passport === null)
    <main class="card bad">
        <h1>Passport not found</h1>
        <p>This code is not recognised. Its holder may have reset it — ask them to show it again.</p>
        <small>{{ config('brand.name') }} · {{ config('brand.tagline') }}</small>
    </main>
@else
    <main class="card">
        <div class="head">
            @if ($passport['avatar_url'])
                <img class="avatar" src="{{ $passport['avatar_url'] }}" alt="">
            @else
                <div class="avatar">{{ mb_strtoupper(mb_substr($passport['name'], 0, 1)) }}</div>
            @endif
            <div>
                <h1>{{ $passport['name'] }}</h1>
                <p class="muted">Temple Passport{{ $passport['home_state'] ? ' · '.$passport['home_state'] : '' }}</p>
            </div>
        </div>
        <div class="stats">
            <div class="stat"><b>{{ $passport['stamps'] }}</b><span>Stamps</span></div>
            <div class="stat"><b>{{ $passport['temples_visited'] }}</b><span>Temples</span></div>
            <div class="stat"><b>{{ $passport['visits_recorded'] }}</b><span>Visits</span></div>
            <div class="stat"><b>{{ $passport['states_covered'] }}</b><span>States</span></div>
        </div>
        <ul>
            @forelse ($passport['visits'] as $visit)
                <li>
                    <div>
                        <strong>{{ $visit['temple']['name'] ?? 'Temple' }}</strong><br>
                        <span class="muted">{{ $visit['temple']['city'] ?? '' }} · {{ \Illuminate\Support\Carbon::parse($visit['visited_on'])->format('d M Y') }}</span>
                    </div>
                    <span @class(['badge', 'ok' => $visit['is_verified']])>{{ $visit['is_verified'] ? 'Stamp' : 'Self-recorded' }}</span>
                </li>
            @empty
                <li><span class="muted">No visits shared yet.</span></li>
            @endforelse
        </ul>
        <p class="muted" style="margin-top:16px">Open <strong>{{ config('brand.name') }}</strong> and scan this code to see the passport book.</p>
    </main>
@endif
</body>
</html>
