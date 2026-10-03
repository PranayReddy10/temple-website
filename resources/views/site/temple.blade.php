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

@php
    $faqSchema = $faq === [] ? null : [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => collect($faq)->map(fn ($f) => ['@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]])->all(),
    ];
    $eventSchemas = $temple->events->map(fn ($e) => array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Event',
        'name' => $e->title,
        'description' => $e->description ? \Illuminate\Support\Str::limit(strip_tags($e->description), 300) : null,
        'startDate' => optional($e->nextDate() ?? $e->starts_on)->toDateString(),
        'endDate' => optional($e->ends_on)->toDateString(),
        'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        'eventStatus' => 'https://schema.org/EventScheduled',
        'image' => $e->imageUrl() ? Seo::absolute($e->imageUrl()) : $image,
        'location' => ['@type' => 'Place', 'name' => $temple->name, 'address' => collect([$temple->address, $place])->filter()->implode(', ') ?: $temple->name],
        'organizer' => ['@type' => 'Organization', 'name' => $temple->name, 'url' => $canonical],
    ]))->values();
    if ($aliases->isNotEmpty()) {
        $schema['alternateName'] = $aliases->all();
    }
@endphp

@push('head')
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    @if ($faqSchema)<script type="application/ld+json">{!! json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>@endif
    @foreach ($eventSchemas as $ev)<script type="application/ld+json">{!! json_encode($ev, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>@endforeach
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
        .actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 14px 0 6px; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 16px; border-radius: 999px; font-weight: 600; font-size: .95rem; text-decoration: none; border: 1.5px solid var(--line); color: var(--deep); background: #fffdf9; cursor: pointer; font-family: inherit; }
        .btn.primary { background: var(--kumkum, #9B1B30); border-color: var(--kumkum, #9B1B30); color: #fff; }
        .today { display: flex; flex-wrap: wrap; gap: 6px 18px; background: #fff7e8; border: 1px solid #f0dcb4; border-radius: 14px; padding: 12px 14px; margin: 10px 0; font-size: .93rem; }
        .today b { color: var(--deep); }
        .event { padding: 10px 0; border-top: 1px solid #efe6d8; }
        .event b { display: block; }
        details.q { border-top: 1px solid #efe6d8; padding: 10px 0; }
        details.q summary { font-weight: 600; cursor: pointer; }
        details.q p { margin: 6px 0 0; }
        .aka { font-size: .9rem; }
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

    @if ($aliases->isNotEmpty())<p class="muted aka">Also known as {{ $aliases->implode(', ') }}</p>@endif

    {{-- Every button opens this same temple: in the app, on the map, or to share. --}}
    <div class="actions">
        @if ($bookable)<a class="btn primary" href="{{ $bookLink }}">Book a seva</a>@endif
        <a class="btn {{ $bookable ? '' : 'primary' }}" href="{{ $appLink }}">Open in {{ config('brand.name') }}</a>
        @if ($temple->hasCoordinates())
            <a class="btn" href="https://www.google.com/maps/dir/?api=1&destination={{ $temple->latitude }},{{ $temple->longitude }}" rel="nofollow noopener" target="_blank">Directions</a>
        @endif
        <button type="button" class="btn" id="share" data-url="{{ $canonical }}" data-title="{{ $temple->name }}">Share</button>
    </div>

    @if ($todays->isNotEmpty())
        <div class="today">
            <b>Today ({{ \App\Support\DevotionalClock::now()->format('l') }})</b>
            @foreach ($todays as $t)<span>{{ $t->label ?: $t->kind?->getLabel() }}: {{ $t->window() }}</span>@endforeach
        </div>
    @endif
    @foreach ($temple->closures as $c)
        <div class="today" style="background:#fdeceb;border-color:#f3c3be"><b>Closure</b><span>{{ $c->reason ?? 'Closed' }} · {{ $c->starts_on?->format('d M Y') }}@if ($c->ends_on && ! $c->ends_on->equalTo($c->starts_on)) – {{ $c->ends_on->format('d M Y') }}@endif</span></div>
    @endforeach

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

            @if ($temple->events->isNotEmpty())
                <h2>Festivals and events</h2>
                @foreach ($temple->events as $e)
                    <div class="event">
                        <b>{{ $e->title }}</b>
                        <span class="muted">{{ optional($e->nextDate() ?? $e->starts_on)->format('l, d M Y') }}@if ($e->group_name) · {{ $e->group_name }}@endif</span>
                        @if ($e->description)<div>{{ \Illuminate\Support\Str::limit(strip_tags($e->description), 220) }}</div>@endif
                    </div>
                @endforeach
            @endif

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
            <div class="card" style="margin-top:16px">
                <b>{{ $bookable ? 'Book a seva at '.$temple->name : 'Plan your visit to '.$temple->name }}</b>
                <p class="muted" style="margin:6px 0 10px">Timings, sevas, festivals and directions in the {{ config('brand.name') }} app{{ $bookable ? ', with booking and payment' : '' }}.</p>
                <a class="btn primary" href="{{ $bookable ? $bookLink : $appLink }}">{{ $bookable ? 'Book a seva' : 'Open in the app' }}</a>
                @if ($storeUrl)<a class="btn" href="{{ $storeUrl }}" rel="noopener" style="margin-top:8px">Get the Android app</a>@endif
            </div>
        </aside>
    </div>

    @if ($faq !== [])
        <h2>Questions devotees ask about {{ $temple->name }}</h2>
        @foreach ($faq as $f)
            <details class="q" @if ($loop->first) open @endif><summary>{{ $f['q'] }}</summary><p>{{ $f['a'] }}</p></details>
        @endforeach
    @endif

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

    <script>
        // Share this temple's page: the phone's share sheet, else WhatsApp.
        document.getElementById('share')?.addEventListener('click', function () {
            var url = this.dataset.url, title = this.dataset.title;
            if (navigator.share) { navigator.share({ title: title, text: title, url: url }).catch(function () {}); return; }
            window.open('https://wa.me/?text=' + encodeURIComponent(title + ' ' + url), '_blank', 'noopener');
        });
    </script>
@endsection
