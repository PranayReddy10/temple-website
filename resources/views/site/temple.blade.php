@extends('site.layout')

@php
    use App\Support\Seo;
    use App\Support\TempleSeo;
    $districtUrl = $temple->state && $temple->district?->slug ? Seo::url('states/'.$temple->state->slug.'/'.$temple->district->slug) : null;
    // Every photo on the page, for image search and the rich result.
    $photoUrls = $temple->photos->map(fn ($ph) => Seo::absolute($ph->mediumUrl() ?? $ph->url()))->filter()->unique()->take(10)->values();
    $schema = array_filter([
        '@context' => 'https://schema.org',
        // A temple is a place of worship and, for most who search it, a place to visit.
        '@type' => ['HinduTemple', 'TouristAttraction'],
        '@id' => Seo::url('temples/'.$temple->slug).'#temple',
        'name' => $name,
        'alternateName' => collect([$name !== $temple->name ? $temple->name : null])->merge($aliases)->filter()->unique()->values()->all() ?: null,
        'description' => $description,
        'url' => $canonical,
        'image' => $photoUrls->isNotEmpty() ? $photoUrls->all() : $image,
        'telephone' => $temple->contact_phone,
        'sameAs' => $temple->official_website ? [$temple->official_website] : null,
        'isAccessibleForFree' => true,
        'publicAccess' => true,
        'address' => array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $temple->address,
            'addressLocality' => $temple->city,
            'addressRegion' => $temple->state?->name,
            'postalCode' => $temple->pincode,
            'addressCountry' => 'IN',
        ]),
        'geo' => $temple->hasCoordinates() ? ['@type' => 'GeoCoordinates', 'latitude' => (float) $temple->latitude, 'longitude' => (float) $temple->longitude] : null,
        'hasMap' => $temple->hasCoordinates() ? 'https://www.google.com/maps/search/?api=1&query='.$temple->latitude.','.$temple->longitude : null,
        'containedInPlace' => $temple->district ? array_filter([
            '@type' => 'AdministrativeArea',
            'name' => $temple->district->name.($temple->state ? ', '.$temple->state->name : ''),
            'url' => $districtUrl,
        ]) : null,
        // Opening hours, from the general and darshan timings that give both ends.
        'openingHoursSpecification' => $temple->timings
            ->filter(fn ($t) => in_array($t->kind?->value, ['general', 'darshan'], true) && filled($t->opens_at) && filled($t->closes_at))
            ->map(fn ($t) => [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => array_map(fn ($d) => \App\Models\TempleTiming::dayNames()[$d], $t->dayList()),
                'opens' => substr((string) $t->opens_at, 0, 5),
                'closes' => substr((string) $t->closes_at, 0, 5),
            ])->values()->all() ?: null,
        'inLanguage' => $locale,
    ]);
    $crumbList = array_values(array_filter([
        ['name' => __('Temples'), 'item' => Seo::url('temples')],
        $temple->state ? ['name' => $temple->state->name, 'item' => Seo::url('states/'.$temple->state->slug)] : null,
        $districtUrl ? ['name' => $temple->district->name, 'item' => $districtUrl] : null,
        ['name' => $name, 'item' => $canonical],
    ]));
    $crumbs = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => collect($crumbList)->values()->map(fn ($c, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'item' => $c['item']])->all(),
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
    // "Sri Rama Temple, Bhadrachalam": how photos are described to image search.
    $photoName = $name.(TempleSeo::town($temple) && stripos($name, (string) TempleSeo::town($temple)) === false ? ', '.TempleSeo::town($temple) : '');
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
        .scan { display: flex; gap: 14px; align-items: flex-start; background: #eef7ef; border: 1.5px solid #9cc9a3; border-radius: 16px; padding: 14px 16px; margin: 12px 0; }
        .scan .tick { flex: none; width: 40px; height: 40px; border-radius: 999px; background: #2e7d55; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 700; }
        .scan p { margin: 4px 0 0; color: var(--muted); font-size: .92rem; }
        .gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; }
        .gallery button { padding: 0; border: 0; background: #efe3cf; border-radius: 12px; overflow: hidden; cursor: zoom-in; aspect-ratio: 4/3; }
        .gallery img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .2s; }
        .gallery button:hover img { transform: scale(1.04); }
        dialog.lightbox { border: 0; padding: 0; background: transparent; max-width: 100vw; max-height: 100vh; width: 100vw; height: 100vh; }
        dialog.lightbox::backdrop { background: rgba(20, 12, 8, .92); }
        .lightbox .frame { position: relative; width: 100%; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 48px 56px 16px; box-sizing: border-box; }
        .lightbox img { max-width: 100%; max-height: calc(100vh - 120px); object-fit: contain; border-radius: 8px; }
        .lightbox .cap { color: #f5ead8; font-size: .9rem; text-align: center; margin-top: 10px; max-width: 900px; }
        .lightbox .cap a { color: #f5c97a; }
        .lightbox .nav, .lightbox .close { position: absolute; background: rgba(255,255,255,.12); color: #fff; border: 0; border-radius: 999px; width: 44px; height: 44px; font-size: 22px; cursor: pointer; }
        .lightbox .nav:hover, .lightbox .close:hover { background: rgba(255,255,255,.25); }
        .lightbox .prev { left: 8px; top: 50%; transform: translateY(-50%); }
        .lightbox .next { right: 8px; top: 50%; transform: translateY(-50%); }
        .lightbox .close { right: 8px; top: 8px; }
        .lightbox .count { position: absolute; left: 16px; top: 18px; color: #d9c7ad; font-size: .85rem; }
        .videos { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
        .video { background: #fffdf9; border: 1px solid var(--line); border-radius: 14px; overflow: hidden; }
        .video .player { aspect-ratio: 16/9; background: #1d130e; position: relative; }
        .video .player iframe, .video .player video { width: 100%; height: 100%; border: 0; display: block; }
        .video .player button { position: absolute; inset: 0; width: 100%; border: 0; padding: 0; cursor: pointer; background: #1d130e center/cover no-repeat; }
        .video .player button::after { content: '▶'; position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); width: 60px; height: 60px; line-height: 60px; border-radius: 999px; background: rgba(155, 27, 48, .92); color: #fff; font-size: 24px; text-align: center; }
        .video .meta { padding: 10px 12px; font-size: .9rem; }
        .video .meta b { display: block; }
        .puja { padding: 10px 0; border-top: 1px solid #efe6d8; }
        .puja b { display: block; }
        .facts dt { font-weight: 600; margin-top: 10px; }
        .facts dd { margin: 2px 0 0; }
        .more { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 14px; }
        .more a { display: flex; flex-direction: column; background: #fffdf9; border: 1px solid var(--line); border-radius: 16px; overflow: hidden; text-decoration: none; color: var(--deep); transition: box-shadow .2s, transform .2s; }
        .more a:hover { box-shadow: 0 8px 22px rgba(60, 30, 10, .12); transform: translateY(-2px); }
        .more .ph { aspect-ratio: 4/3; background: #efe3cf; overflow: hidden; }
        .more .ph img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .more .ph .none { height: 100%; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: #c9a77a; }
        .more .txt { padding: 10px 12px 12px; display: flex; flex-direction: column; gap: 2px; }
        .more b { font-size: .95rem; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .more span { color: var(--muted); font-size: .8rem; line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        @media (max-width: 520px) { .more { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; } }
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
        .tags { display: flex; flex-wrap: wrap; gap: 6px; margin: 6px 0; }
        .tags a { background: #fffdf9; border: 1px solid var(--line); border-radius: 999px; padding: 2px 10px; font-size: .82rem; text-decoration: none; color: var(--deep); }
        .langs { display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: .9rem; margin: 4px 0; }
        @media (max-width: 560px) {
            .actions { display: grid !important; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
            .actions > .btn, .actions > form, .actions > details, .actions form .btn, .actions details summary { width: 100%; justify-content: center; text-align: center; }
            .actions details.add-yatra .pop { min-width: 0; width: calc(200% + 8px); }
        }
        details.add-yatra { position: relative; display: inline-block; }
        details.add-yatra summary { list-style: none; }
        details.add-yatra summary::-webkit-details-marker { display: none; }
        details.add-yatra .pop { position: absolute; z-index: 5; top: calc(100% + 6px); left: 0; min-width: 240px; background: #fffdf9; border: 1px solid var(--line); border-radius: 14px; box-shadow: 0 10px 30px rgba(62,39,35,.15); padding: 6px; }
        details.add-yatra .pop button { display: block; width: 100%; text-align: left; font: inherit; background: none; border: 0; padding: 10px 12px; border-radius: 10px; cursor: pointer; color: var(--deep); }
        details.add-yatra .pop button:hover { background: #f5ead6; }
        .reviews .rsum { display: grid; grid-template-columns: 140px 1fr; gap: 18px; align-items: center; }
        .reviews .rcount b { font-family: Georgia, serif; font-size: 2rem; color: var(--kumkum); }
        .reviews .bars { display: grid; gap: 6px; }
        .reviews .bar { display: grid; grid-template-columns: minmax(0, 170px) 1fr 32px; gap: 10px; align-items: center; font-size: .88rem; }
        .reviews .bar i { height: 8px; background: #efe3cf; border-radius: 99px; overflow: hidden; }
        .reviews .bar em { display: block; height: 100%; background: linear-gradient(90deg, var(--saffron), var(--kumkum)); }
        .reviews .rlist { display: grid; gap: 10px; margin-top: 12px; }
        .reviews .review header { font-size: .92rem; }
        .reviews .chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0; }
        .reviews .chip { font-size: .78rem; background: #f5ead6; border-radius: 999px; padding: 3px 10px; }
        .reviews .chip b { color: #c98a10; letter-spacing: 1px; } .reviews .chip .off { color: #dccfb8; }
        .reviews .review p { margin: 6px 0 0; }
        .reviews .reply { background: #f8efe0; border-left: 3px solid var(--saffron); padding: 8px 12px; border-radius: 8px; font-size: .9rem; }
        .reviews .write { margin-top: 14px; }
        .reviews .write summary { cursor: pointer; display: flex; gap: 8px; align-items: center; }
        .stars-in { border: 0; padding: 0; margin: 0; display: flex; flex-direction: row-reverse; justify-content: flex-end; align-items: center; gap: 2px; flex-wrap: wrap; }
        .stars-in legend { width: 100%; font-weight: 600; font-size: .92rem; margin-bottom: 2px; padding: 0; }
        .stars-in input { position: absolute; opacity: 0; width: 1px; height: 1px; }
        .stars-in label { font-size: 1.8rem; line-height: 1; color: #dccfb8; cursor: pointer; display: inline; font-weight: 400; }
        .stars-in input:checked ~ label, .stars-in label:hover, .stars-in label:hover ~ label { color: #e0a313; }
        .stars-in input:focus-visible + label { outline: 2px solid var(--saffron); border-radius: 4px; }
        @media (max-width: 560px) { .reviews .rsum { grid-template-columns: 1fr; } .reviews .bar { grid-template-columns: minmax(0, 1fr) 90px 28px; } }
    </style>
@endpush

@section('content')
    @isset($scan)
        {{-- Opened by scanning the temple's QR code with a phone camera. --}}
        <div class="scan" role="status">
            <div class="tick" aria-hidden="true">✓</div>
            <div>
                <b>Genuine {{ config('brand.name') }} code of {{ $temple->name }}</b>
                <p>Check in with the app to collect a verified stamp in your Passport.</p>
                <div class="actions" style="margin:8px 0 0">
                    {{-- Android: opens the app when installed, the Play Store when not. --}}
                    <a class="btn primary" href="{{ $scan['storeUrl'] ?? $scan['appLink'] }}" data-intent="{{ $scan['intent'] }}">Open the app to check in</a>
                </div>
            </div>
        </div>
    @endisset
    <p class="crumbs">
        @foreach ($crumbList as $c)
            @if (! $loop->last)<a href="{{ $c['item'] }}">{{ $c['name'] }}</a> › @else{{ $c['name'] }}@endif
        @endforeach
    </p>
    <h1>{{ $name }}</h1>
    <p class="muted">
        @if ($temple->deity?->slug && $sameDeity->isNotEmpty())<a href="{{ Seo::url('deities/'.$temple->deity->slug) }}">{{ $deityName }}</a>@else{{ $deityName }}@endif
        @if ($temple->deity && $place !== '') · @endif{{ $place }}
    </p>

    @if ($aliases->isNotEmpty() || $name !== $temple->name)<p class="muted aka">{{ __('Also known as') }} {{ collect([$name !== $temple->name ? $temple->name : null])->merge($aliases)->filter()->implode(', ') }}</p>@endif
    @if ($keywords !== [])<p class="muted aka">{{ __('Also searched as') }} {{ implode(', ', $keywords) }}</p>@endif
    @if ($temple->categories->isNotEmpty())
        {{-- Tags: each has its own page of temples. --}}
        <p class="tags">
            @foreach ($temple->categories as $cat)
                <a href="{{ Seo::url('tags/'.$cat->slug) }}">{{ $cat->name }}</a>
            @endforeach
        </p>
    @endif

    @if ($alternates !== [])
        {{-- The page in the other languages it is published in. --}}
        <p class="langs">
            @foreach ($alternates as $code => $href)
                @continue($code === 'x-default')
                @if ($code === $locale)<b>{{ \App\Support\SiteLocale::native($code) }}</b>@else<a href="{{ $href }}" hreflang="{{ $code }}" lang="{{ $code }}">{{ \App\Support\SiteLocale::native($code) }}</a>@endif
            @endforeach
        </p>
    @endif

    {{-- Every button opens this same temple: in the app, on the map, or to share. --}}
    <div class="actions">
        @if ($bookable)<a class="btn primary" href="{{ $bookLink }}">{{ __('Book a seva') }}</a>@endif
        @if ($donateLink)<a class="btn {{ $bookable ? '' : 'primary' }}" href="{{ $donateLink }}">🪔 {{ __('Donate') }}</a>@endif
        @if (! $temple->pujas->isEmpty() && ! $bookable)<a class="btn" href="{{ Seo::url('temples/'.$temple->slug.'/sevas') }}">{{ __('Sevas & fees') }}</a>@endif
        @if (auth('devotee_web')->check())
            <form method="post" action="{{ Seo::url('temples/'.$temple->slug.'/save') }}" style="display:inline">@csrf<button class="btn" type="submit">{{ ($saved ?? false) ? '♥ '.__('Saved') : '♡ '.__('Save') }}</button></form>
        @else
            <a class="btn" href="{{ Seo::url('login').'?'.http_build_query(['next' => $canonical]) }}" rel="nofollow">♡ {{ __('Save') }}</a>
        @endif
        @if (auth('devotee_web')->check())
            <details class="add-yatra">
                <summary class="btn">🧭 {{ __('Add to yatra') }}</summary>
                <div class="pop">
                    @foreach ($yatras ?? [] as $y)
                        <form method="post" action="{{ Seo::url('temples/'.$temple->slug.'/yatra') }}">@csrf<input type="hidden" name="yatra" value="{{ $y->id }}"><button type="submit">{{ $y->title }}</button></form>
                    @endforeach
                    <form method="post" action="{{ Seo::url('account/yatras') }}">@csrf<input type="hidden" name="temple" value="{{ $temple->slug }}"><input type="hidden" name="title" value="{{ __('Yatra to :place', ['place' => $temple->city ?: $temple->name]) }}"><button type="submit"><b>＋ {{ __('New yatra') }}</b></button></form>
                </div>
            </details>
        @else
            <a class="btn" href="{{ Seo::url('login').'?'.http_build_query(['next' => $canonical]) }}" rel="nofollow">🧭 {{ __('Add to yatra') }}</a>
        @endif
        @if ($temple->hasCoordinates())
            <a class="btn" href="https://www.google.com/maps/dir/?api=1&destination={{ $temple->latitude }},{{ $temple->longitude }}" rel="nofollow noopener" target="_blank">{{ __('Directions') }}</a>
        @endif
        <button type="button" class="btn" id="share" data-url="{{ $canonical }}" data-title="{{ $name }}">{{ __('Share') }}</button>
    </div>

    @if ($todays->isNotEmpty())
        <div class="today">
            <b>{{ __('Today') }} ({{ __(\App\Support\DevotionalClock::now()->format('l')) }})</b>
            @foreach ($todays as $t)<span>{{ $t->label ?: $t->kind?->getLabel() }}: {{ $t->window() }}</span>@endforeach
        </div>
    @endif
    @foreach ($temple->closures as $c)
        <div class="today" style="background:#fdeceb;border-color:#f3c3be"><b>{{ __('Closure') }}</b><span>{{ $c->reason ?? __('Closed') }} · {{ $c->starts_on?->format('d M Y') }}@if ($c->ends_on && ! $c->ends_on->equalTo($c->starts_on)) – {{ $c->ends_on->format('d M Y') }}@endif</span></div>
    @endforeach

    @if ($image)
        @php
            $lead = $temple->primaryPhoto ?? $temple->photos->first();
        @endphp
        <div class="hero"><img src="{{ $image }}" alt="{{ $photoName }}" fetchpriority="high"></div>
        @if ($lead?->credit || $lead?->license)
            {{-- Commons photos may be used only with their photographer and licence beside them. --}}
            <p style="font-size:.8rem;opacity:.7;margin:4px 0 0">Photo: @if ($lead->source_url)<a href="{{ $lead->source_url }}" rel="noopener">{{ $lead->credit ?? 'source' }}</a>@else{{ $lead->credit }}@endif{{ $lead->license ? ', '.$lead->license : '' }}</p>
        @endif
    @endif

    <div class="cols">
        <div>
            @if ($intro !== [] && $locale === 'en')
                <h2>{{ __('About :name', ['name' => $name]) }}</h2>
                <p>{{ implode(' ', $intro) }}</p>
            @endif
            @if ($about)
                <p>{{ $about }}</p>
            @endif

            @if ($temple->timings->isNotEmpty())
                <h2>{{ __(':name timings', ['name' => $name]) }}</h2>
                <table>
                    @foreach (\App\Models\TempleTiming::inReadingOrder($temple->timings) as $t)
                        <tr><td>{{ $t->label ?: __($t->kind?->getLabel() ?? '') }}</td><td>{{ __($t->dayLabel()) }}</td><td>{{ $t->window() }}</td></tr>
                    @endforeach
                </table>
            @endif

            @if ($temple->pujas->isNotEmpty())
                <h2>{{ __('Pujas and sevas at :name', ['name' => $name]) }}</h2>
                @foreach ($temple->pujas as $p)
                    <div class="puja">
                        <b>{{ $p->name }}</b>
                        <span class="muted">{{ collect([$p->feeLabel(), $p->durationLabel()])->filter()->implode(' · ') }}</span>
                        @if ($p->description)<div>{{ \Illuminate\Support\Str::limit($p->description, 240) }}</div>@endif
                    </div>
                @endforeach
            @endif

            @foreach (['significance' => [$significance, __('Significance')], 'history' => [$history, __('History of :name', ['name' => $name])], 'entry_rules' => [$entryRules, __('Entry rules')], 'queue_information' => [$queueInfo, __('Queue and darshan tips')]] as $field => [$text, $heading])
                @if ($text)
                    <h2>{{ $heading }}</h2>
                    <p style="white-space:pre-line">{{ $text }}</p>
                @endif
            @endforeach

            @if ($temple->events->isNotEmpty())
                <h2>{{ __('Festivals and events') }}</h2>
                @foreach ($temple->events as $e)
                    <div class="event">
                        <b>{{ $e->title }}</b>
                        <span class="muted">{{ optional($e->nextDate() ?? $e->starts_on)->format('l, d M Y') }}@if ($e->group_name) · {{ $e->group_name }}@endif</span>
                        @if ($e->description)<div>{{ \Illuminate\Support\Str::limit(strip_tags($e->description), 220) }}</div>@endif
                    </div>
                @endforeach
            @endif

            @php
                // The temple's photos, then photos added under Songs, photos & videos.
                $media = $temple->allMedia();
                $pictures = $temple->photos->map(fn ($ph) => [
                    'thumb' => $ph->thumbnailUrl(), 'full' => $ph->mediumUrl() ?? $ph->url(),
                    'caption' => $ph->caption, 'credit' => $ph->credit, 'license' => $ph->license, 'source' => $ph->source_url,
                ])->concat($media->filter(fn ($m) => $m->type?->value === 'photo' && $m->url())->map(fn ($m) => [
                    'thumb' => $m->thumbnailUrl() ?? $m->url(), 'full' => $m->url(),
                    'caption' => $m->title, 'credit' => $m->credit, 'license' => $m->license, 'source' => null,
                ]))->filter(fn ($p) => filled($p['full']))->values();
                $videos = $media->filter(fn ($m) => $m->type?->value === 'video' && $m->url())->values();
            @endphp

            @if ($pictures->isNotEmpty())
                <h2>{{ __('Photos of :name', ['name' => $name]) }}</h2>
                <div class="gallery">
                    @foreach ($pictures as $i => $p)
                        <button type="button" data-photo="{{ $i }}" aria-label="{{ __('Open photo :n of :total', ['n' => $i + 1, 'total' => $pictures->count()]) }}">
                            <img src="{{ Seo::absolute($p['thumb'] ?? $p['full']) }}" alt="{{ $p['caption'] ? $p['caption'].' – '.$photoName : $photoName.' – '.__('photo :n', ['n' => $i + 1]) }}" loading="lazy" width="300" height="225">
                        </button>
                    @endforeach
                </div>
                <dialog class="lightbox" id="lightbox" aria-label="{{ __('Photos of :name', ['name' => $name]) }}">
                    <div class="frame">
                        <span class="count"></span>
                        <button type="button" class="close" aria-label="Close">✕</button>
                        @if ($pictures->count() > 1)
                            <button type="button" class="nav prev" aria-label="Previous photo">‹</button>
                            <button type="button" class="nav next" aria-label="Next photo">›</button>
                        @endif
                        <img alt="">
                        <div class="cap"></div>
                    </div>
                </dialog>
                <script type="application/json" id="photos-data">{!! json_encode($pictures->map(fn ($p) => [
                    'src' => Seo::absolute($p['full']),
                    'caption' => $p['caption'] ?: $photoName,
                    'credit' => collect([$p['credit'], $p['license']])->filter()->implode(', '),
                    'source' => $p['source'],
                ])->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
            @endif

            @if ($videos->isNotEmpty())
                <h2>{{ __('Videos') }}</h2>
                <div class="videos">
                    @foreach ($videos as $v)
                        <div class="video">
                            <div class="player">
                                @if ($v->needsEmbed() && $v->embedUrl())
                                    {{-- The player loads only when tapped: a page of embeds is slow. --}}
                                    <button type="button" data-embed="{{ $v->embedUrl() }}" aria-label="Play {{ $v->title }}" style="background-image:url('{{ $v->thumbnailUrl() ?? ($v->youTubeId() ? 'https://i.ytimg.com/vi/'.$v->youTubeId().'/hqdefault.jpg' : '') }}')"></button>
                                @elseif ($v->isDirectlyPlayable())
                                    <video controls preload="none" @if ($v->thumbnailUrl()) poster="{{ Seo::absolute($v->thumbnailUrl()) }}" @endif src="{{ Seo::absolute($v->url()) }}"></video>
                                @else
                                    <a href="{{ $v->url() }}" target="_blank" rel="noopener" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#fff">Watch ↗</a>
                                @endif
                            </div>
                            <div class="meta"><b>{{ $v->title }}</b>@if ($v->credit || $v->license)<span class="muted">{{ collect([$v->credit, $v->license])->filter()->implode(', ') }}</span>@endif</div>
                        </div>
                    @endforeach
                </div>
            @endif

            @include('site._reviews')
        </div>

        <aside>
            <div class="card">
                <h2 style="margin-top:0;font-size:1.1rem">{{ __('How to reach :name', ['name' => $name]) }}</h2>
                <dl class="facts">
                    @if ($temple->address || $place)<dt>{{ __('Address') }}</dt><dd>{{ collect([$temple->address, $place, $temple->pincode])->filter()->implode(', ') }}</dd>@endif
                    @if ($temple->hasCoordinates())
                        <dt>{{ __('Directions') }}</dt>
                        <dd><a href="https://www.google.com/maps/dir/?api=1&destination={{ $temple->latitude }},{{ $temple->longitude }}" rel="nofollow noopener" target="_blank">{{ __('Open in Google Maps') }}</a></dd>
                        <dt>{{ __('GPS') }}</dt>
                        <dd>{{ number_format((float) $temple->latitude, 5) }}, {{ number_format((float) $temple->longitude, 5) }}</dd>
                    @endif
                    @if ($nearby->isNotEmpty())
                        <dt>{{ __('Nearest temples') }}</dt>
                        <dd>@foreach ($nearby->take(3) as $n)<a href="{{ \App\Support\SiteLocale::templeUrl($n, $locale !== 'en' && in_array($locale, \App\Support\SiteLocale::languagesOf($n), true) ? $locale : null) }}">{{ $n->localName($locale) }}</a> ({{ TempleSeo::km((float) $n->distance_km) }})@if (! $loop->last), @endif @endforeach</dd>
                    @endif
                    @if ($dressCode)<dt>{{ __('Dress code') }}</dt><dd>{{ $dressCode }}</dd>@endif
                    @if ($temple->footwear_policy)<dt>{{ __('Footwear') }}</dt><dd>{{ $temple->footwear_policy }}</dd>@endif
                    @if ($temple->mobile_policy)<dt>{{ __('Mobile phones') }}</dt><dd>{{ $temple->mobile_policy }}</dd>@endif
                    @if ($temple->contact_phone)<dt>{{ __('Phone') }}</dt><dd><a href="tel:{{ $temple->contact_phone }}">{{ $temple->contact_phone }}</a></dd>@endif
                    @if ($temple->source_name)
                        {{-- OpenStreetMap's licence (ODbL) asks for this credit wherever its data is shown. --}}
                        <dt>{{ __('Source') }}</dt><dd>@if ($temple->source_url)<a href="{{ $temple->source_url }}" rel="nofollow noopener" target="_blank">{{ $temple->source_name === 'OpenStreetMap contributors' ? '© OpenStreetMap contributors' : $temple->source_name }}</a>@else{{ $temple->source_name }}@endif</dd>
                    @endif
                    @if ($temple->official_website)<dt>{{ __('Official website') }}</dt><dd><a href="{{ $temple->official_website }}" rel="nofollow noopener" target="_blank">{{ parse_url($temple->official_website, PHP_URL_HOST) ?: $temple->official_website }}</a></dd>@endif
                </dl>
                @if ($updatedAt)<p class="muted" style="font-size:.8rem;margin:10px 0 0">{{ __('Updated') }} <time datetime="{{ $updatedAt->toDateString() }}">{{ $updatedAt->format('d M Y') }}</time></p>@endif
            </div>
            <div class="card" style="margin-top:16px">
                <b>{{ $bookable ? __('Book a seva at :name', ['name' => $name]) : __('Plan your visit to :name', ['name' => $name]) }}</b>
                <p class="muted" style="margin:6px 0 10px">{{ $bookable ? __('Timings, sevas, festivals and directions in the :app app, with booking and payment.', ['app' => config('brand.name')]) : __('Timings, sevas, festivals and directions in the :app app.', ['app' => config('brand.name')]) }}</p>
                @if ($bookable)<a class="btn primary" href="{{ $bookLink }}">{{ __('Book a seva') }}</a>@endif
                @if ($donateLink)<a class="btn" href="{{ $donateLink }}" style="margin-top:8px">🪔 {{ __('Donate to the hundi') }}</a>@endif
                {{-- One app button: on Android it opens this temple in the installed app (the Play Store when it is not installed); elsewhere the Play Store. Hidden on iPhone, where the website is the app for now. --}}
                <a class="btn android-app {{ $bookable || $donateLink ? '' : 'primary' }}" href="{{ $appLink }}" data-intent="{{ Seo::appIntent($temple->slug) }}" rel="noopener" style="margin-top:8px">📱 {{ __('Open in the Android app') }}</a>
            </div>
        </aside>
    </div>

    @if ($nearby->isNotEmpty())
        <h2>{{ __('Temples near :name', ['name' => $name]) }}</h2>
        <div class="more">
            @foreach ($nearby as $t)
                @php($tLocale = $locale !== 'en' && in_array($locale, \App\Support\SiteLocale::languagesOf($t), true) ? $locale : null)
                <a href="{{ \App\Support\SiteLocale::templeUrl($t, $tLocale) }}">
                    <div class="ph">@if ($t->primaryPhoto)<img src="{{ Seo::absolute($t->primaryPhoto->mediumUrl() ?? $t->primaryPhoto->thumbnailUrl()) }}" alt="{{ $t->localName($locale) }}" loading="lazy">@else<div class="none" aria-hidden="true">🛕</div>@endif</div>
                    <div class="txt"><b>{{ $t->localName($locale) }}</b><span>{{ TempleSeo::km((float) $t->distance_km) }}@if ($t->city) · {{ $t->city }}@endif</span></div>
                </a>
            @endforeach
        </div>
    @endif

    @if ($faq !== [])
        <h2>{{ __('Questions devotees ask about :name', ['name' => $name]) }}</h2>
        @foreach ($faq as $f)
            <details class="q" @if ($loop->first) open @endif><summary>{{ $f['q'] }}</summary><p>{{ $f['a'] }}</p></details>
        @endforeach
    @endif

    @foreach ([
        ['list' => $sameDeity->reject(fn ($t) => $nearby->contains('id', $t->id)), 'heading' => __('More :deity temples', ['deity' => \App\Http\Controllers\PublicTempleController::deityPhrase((string) $deityName)]), 'all' => $temple->deity?->slug ? Seo::url('deities/'.$temple->deity->slug) : null],
        ['list' => $sameState->reject(fn ($t) => $sameDeity->contains('id', $t->id) || $nearby->contains('id', $t->id)), 'heading' => __('More temples in :place', ['place' => (string) $temple->state?->name]), 'all' => $temple->state ? Seo::url('states/'.$temple->state->slug) : null],
    ] as $group)
        @if ($group['list']->isNotEmpty())
            <h2>{{ $group['heading'] }}</h2>
            <div class="more">
                @foreach ($group['list'] as $t)
                    <a href="{{ Seo::url('temples/'.$t->slug) }}">
                        <div class="ph">@if ($t->primaryPhoto)<img src="{{ Seo::absolute($t->primaryPhoto->mediumUrl() ?? $t->primaryPhoto->thumbnailUrl()) }}" alt="{{ $t->localName($locale) }}" loading="lazy">@else<div class="none" aria-hidden="true">🛕</div>@endif</div>
                        <div class="txt"><b>{{ $t->localName($locale) }}</b><span>{{ collect([$t->city, $t->state?->name])->filter()->unique()->implode(', ') }}</span></div>
                    </a>
                @endforeach
            </div>
            @if ($group['all'])<p><a href="{{ $group['all'] }}">{{ __('See all') }} →</a></p>@endif
        @endif
    @endforeach

    <script>
        // Photos open full size: arrows, swipe and the keyboard move between them.
        (function () {
            var data = document.getElementById('photos-data'), box = document.getElementById('lightbox');
            if (!data || !box || !box.showModal) return;
            var photos = JSON.parse(data.textContent), at = 0, img = box.querySelector('img'), cap = box.querySelector('.cap'), count = box.querySelector('.count');
            function show(i) {
                at = (i + photos.length) % photos.length;
                var p = photos[at];
                img.src = p.src; img.alt = p.caption;
                cap.textContent = p.caption;
                if (p.credit) {
                    cap.appendChild(document.createElement('br'));
                    var c = p.source ? document.createElement('a') : document.createElement('span');
                    if (p.source) { c.href = p.source; c.target = '_blank'; c.rel = 'noopener'; }
                    c.textContent = 'Photo: ' + p.credit;
                    cap.appendChild(c);
                }
                count.textContent = photos.length > 1 ? (at + 1) + ' / ' + photos.length : '';
            }
            document.querySelectorAll('[data-photo]').forEach(function (b) {
                b.addEventListener('click', function () { show(+b.dataset.photo); box.showModal(); });
            });
            box.querySelector('.close').addEventListener('click', function () { box.close(); });
            box.querySelector('.prev')?.addEventListener('click', function () { show(at - 1); });
            box.querySelector('.next')?.addEventListener('click', function () { show(at + 1); });
            box.addEventListener('click', function (e) { if (e.target === box || e.target.classList.contains('frame')) box.close(); });
            box.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowLeft') show(at - 1);
                if (e.key === 'ArrowRight') show(at + 1);
            });
            var startX = null;
            box.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, { passive: true });
            box.addEventListener('touchend', function (e) {
                if (startX === null) return;
                var dx = e.changedTouches[0].clientX - startX;
                if (Math.abs(dx) > 50) show(at + (dx < 0 ? 1 : -1));
                startX = null;
            });
        })();

        // A video's player loads when it is tapped.
        document.querySelectorAll('[data-embed]').forEach(function (b) {
            b.addEventListener('click', function () {
                var f = document.createElement('iframe');
                f.src = b.dataset.embed + (b.dataset.embed.indexOf('?') < 0 ? '?' : '&') + 'autoplay=1';
                f.allow = 'autoplay; encrypted-media; picture-in-picture';
                f.allowFullscreen = true;
                f.title = b.getAttribute('aria-label');
                b.replaceWith(f);
            });
        });

        // Share this temple's page: the phone's share sheet, else WhatsApp.
        document.getElementById('share')?.addEventListener('click', function () {
            var url = this.dataset.url, title = this.dataset.title;
            if (navigator.share) { navigator.share({ title: title, text: title, url: url }).catch(function () {}); return; }
            window.open('https://wa.me/?text=' + encodeURIComponent(title + ' ' + url), '_blank', 'noopener');
        });
    </script>
@endsection
