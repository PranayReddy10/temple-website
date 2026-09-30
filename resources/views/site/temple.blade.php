@extends('site.layout')

@php
    use App\Support\Seo;
    $schema = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'HinduTemple',
        'name' => $temple->name,
        'description' => $description,
        'url' => $canonical,
        'image' => $image,
        'telephone' => $temple->contact_phone,
        'sameAs' => $temple->official_website ? [$temple->official_website] : null,
        'address' => array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $temple->address,
            'addressLocality' => $temple->city,
            'addressRegion' => $temple->state?->name,
            'postalCode' => $temple->pincode,
            'addressCountry' => 'IN',
        ]),
        'geo' => $temple->hasCoordinates() ? ['@type' => 'GeoCoordinates', 'latitude' => (float) $temple->latitude, 'longitude' => (float) $temple->longitude] : null,
        // Opening hours, from the general and darshan timings that give both ends.
        'openingHoursSpecification' => $temple->timings
            ->filter(fn ($t) => in_array($t->kind?->value, ['general', 'darshan'], true) && filled($t->opens_at) && filled($t->closes_at))
            ->map(fn ($t) => [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => $t->day_of_week === null ? array_values(\App\Models\TempleTiming::dayNames()) : \App\Models\TempleTiming::dayNames()[$t->day_of_week] ?? null,
                'opens' => substr((string) $t->opens_at, 0, 5),
                'closes' => substr((string) $t->closes_at, 0, 5),
            ])->values()->all() ?: null,
    ]);
    $crumbs = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => array_values(array_filter([
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Temples', 'item' => Seo::url('temples')],
            $temple->state ? ['@type' => 'ListItem', 'position' => 2, 'name' => $temple->state->name, 'item' => Seo::url('states/'.$temple->state->slug)] : null,
            ['@type' => 'ListItem', 'position' => $temple->state ? 3 : 2, 'name' => $temple->name, 'item' => $canonical],
        ])),
    ];
@endphp

@push('head')
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    <script type="application/ld+json">{!! json_encode($crumbs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    <style>
        .hero { border-radius: 18px; overflow: hidden; aspect-ratio: 21/9; background: #efe3cf; margin: 12px 0; }
        .hero img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .cols { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 20px; }
        @media (max-width: 760px) { .cols { grid-template-columns: 1fr; } }
        table { width: 100%; border-collapse: collapse; font-size: .92rem; }
        td { padding: 8px 4px; border-top: 1px solid #efe6d8; vertical-align: top; }
        .gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; }
        .gallery img { aspect-ratio: 4/3; object-fit: cover; border-radius: 10px; width: 100%; }
        .puja { padding: 10px 0; border-top: 1px solid #efe6d8; }
        .puja b { display: block; }
        .facts dt { font-weight: 600; margin-top: 10px; }
        .facts dd { margin: 2px 0 0; }
        .more { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
        .more a { display: flex; gap: 10px; align-items: center; background: #fffdf9; border: 1px solid var(--line); border-radius: 14px; padding: 8px; text-decoration: none; color: var(--deep); }
        .more .ph { width: 64px; height: 48px; flex: none; border-radius: 10px; overflow: hidden; background: #efe3cf; }
        .more .ph img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .more b { display: block; font-size: .92rem; line-height: 1.25; }
        .more span { color: var(--muted); font-size: .8rem; }
    </style>
@endpush

@section('content')
    <p class="crumbs">
        <a href="{{ Seo::url('temples') }}">Temples</a>
        @if ($temple->state) › <a href="{{ Seo::url('states/'.$temple->state->slug) }}">{{ $temple->state->name }}</a>@endif
        › {{ $temple->name }}
    </p>
    <h1>{{ $temple->name }}</h1>
    <p class="muted">
        @if ($temple->deity?->slug && $sameDeity->isNotEmpty())<a href="{{ Seo::url('deities/'.$temple->deity->slug) }}">{{ $temple->deity->name }}</a>@else{{ $temple->deity?->name }}@endif
        @if ($temple->deity && $place !== '') · @endif{{ $place }}
    </p>

    @if ($image)
        <div class="hero"><img src="{{ $image }}" alt="{{ $temple->name }}"></div>
    @endif

    <div class="cols">
        <div>
            @if ($about)<p>{{ $about }}</p>@endif

            @if ($temple->timings->isNotEmpty())
                <h2>Darshan timings</h2>
                <table>
                    @foreach ($temple->timings as $t)
                        <tr><td>{{ $t->label ?: $t->kind?->getLabel() }}</td><td>{{ $t->dayLabel() }}</td><td>{{ $t->window() }}</td></tr>
                    @endforeach
                </table>
            @endif

            @if ($temple->pujas->isNotEmpty())
                <h2>Pujas and sevas</h2>
                @foreach ($temple->pujas as $p)
                    <div class="puja">
                        <b>{{ $p->name }}</b>
                        <span class="muted">{{ collect([$p->feeLabel(), $p->durationLabel()])->filter()->implode(' · ') }}</span>
                        @if ($p->description)<div>{{ \Illuminate\Support\Str::limit($p->description, 240) }}</div>@endif
                    </div>
                @endforeach
            @endif

            @if ($significance)<h2>Significance</h2><p>{{ $significance }}</p>@endif
            @if ($history)<h2>History</h2><p>{{ $history }}</p>@endif

            @if ($temple->photos->count() > 1)
                <h2>Photos</h2>
                <div class="gallery">
                    @foreach ($temple->photos as $ph)
                        <img src="{{ Seo::absolute($ph->thumbnailUrl()) }}" alt="{{ $ph->caption ?: $temple->name }}" loading="lazy">
                    @endforeach
                </div>
            @endif
        </div>

        <aside>
            <div class="card">
                <dl class="facts">
                    @if ($temple->address || $place)<dt>Address</dt><dd>{{ collect([$temple->address, $place, $temple->pincode])->filter()->implode(', ') }}</dd>@endif
                    @if ($temple->hasCoordinates())
                        <dt>Directions</dt>
                        <dd><a href="https://www.google.com/maps/dir/?api=1&destination={{ $temple->latitude }},{{ $temple->longitude }}" rel="nofollow noopener" target="_blank">Open in Google Maps</a></dd>
                    @endif
                    @if ($dressCode)<dt>Dress code</dt><dd>{{ $dressCode }}</dd>@endif
                    @if ($temple->footwear_policy)<dt>Footwear</dt><dd>{{ $temple->footwear_policy }}</dd>@endif
                    @if ($temple->mobile_policy)<dt>Mobile phones</dt><dd>{{ $temple->mobile_policy }}</dd>@endif
                    @if ($temple->contact_phone)<dt>Phone</dt><dd><a href="tel:{{ $temple->contact_phone }}">{{ $temple->contact_phone }}</a></dd>@endif
                    @if ($temple->official_website)<dt>Official website</dt><dd><a href="{{ $temple->official_website }}" rel="nofollow noopener" target="_blank">{{ parse_url($temple->official_website, PHP_URL_HOST) ?: $temple->official_website }}</a></dd>@endif
                </dl>
            </div>
            <p style="margin-top:16px"><a class="cta" style="display:inline-block;text-decoration:none" href="{{ Seo::url('/') }}">Book pujas and plan your visit in {{ config('brand.name') }}</a></p>
        </aside>
    </div>

    @foreach ([
        ['list' => $sameDeity, 'heading' => 'More '.\App\Http\Controllers\PublicTempleController::deityPhrase((string) $temple->deity?->name).' temples', 'all' => $temple->deity?->slug ? Seo::url('deities/'.$temple->deity->slug) : null],
        ['list' => $sameState->reject(fn ($t) => $sameDeity->contains('id', $t->id)), 'heading' => 'More temples in '.$temple->state?->name, 'all' => $temple->state ? Seo::url('states/'.$temple->state->slug) : null],
    ] as $group)
        @if ($group['list']->isNotEmpty())
            <h2>{{ $group['heading'] }}</h2>
            <div class="more">
                @foreach ($group['list'] as $t)
                    <a href="{{ Seo::url('temples/'.$t->slug) }}">
                        <div class="ph">@if ($t->primaryPhoto)<img src="{{ Seo::absolute($t->primaryPhoto->thumbnailUrl()) }}" alt="{{ $t->name }}" loading="lazy">@endif</div>
                        <div><b>{{ $t->name }}</b><span>{{ collect([$t->city, $t->state?->name])->filter()->unique()->implode(', ') }}</span></div>
                    </a>
                @endforeach
            </div>
            @if ($group['all'])<p><a href="{{ $group['all'] }}">See all →</a></p>@endif
        @endif
    @endforeach
@endsection
