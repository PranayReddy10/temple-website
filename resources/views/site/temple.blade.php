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
                'dayOfWeek' => array_map(fn ($d) => \App\Models\TempleTiming::dayNames()[$d], $t->dayList()),
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
        @php
            $lead = $temple->primaryPhoto ?? $temple->photos->first();
        @endphp
        <div class="hero"><img src="{{ $image }}" alt="{{ $temple->name }}"></div>
        @if ($lead?->credit || $lead?->license)
            {{-- Commons photos may be used only with their photographer and licence beside them. --}}
            <p style="font-size:.8rem;opacity:.7;margin:4px 0 0">Photo: @if ($lead->source_url)<a href="{{ $lead->source_url }}" rel="noopener">{{ $lead->credit ?? 'source' }}</a>@else{{ $lead->credit }}@endif{{ $lead->license ? ', '.$lead->license : '' }}</p>
        @endif
    @endif

    <div class="cols">
        <div>
            @if ($about)
                <p>{{ $about }}</p>
                @if ($temple->description_source === 'wikipedia')
                    <p style="font-size:.85rem;opacity:.75">From <a href="{{ $temple->wikipedia_url }}" rel="noopener">Wikipedia</a>, under <a href="https://creativecommons.org/licenses/by-sa/4.0/" rel="license noopener">CC BY-SA 4.0</a>.</p>
                @endif
            @endif
            @if ($temple->wikipedia_url && $temple->description_source !== 'wikipedia')
                <p style="font-size:.9rem"><a href="{{ $temple->wikipedia_url }}" rel="noopener">Read about {{ $temple->name }} on Wikipedia</a></p>
            @endif

            @if ($temple->timings->isNotEmpty())
                <h2>Darshan timings</h2>
                <table>
                    @foreach (\App\Models\TempleTiming::inReadingOrder($temple->timings) as $t)
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

            @foreach (['significance' => [$significance, 'Significance'], 'history' => [$history, 'History']] as $field => [$text, $heading])
                @if ($text)
                    <h2>{{ $heading }}</h2>
                    <p style="white-space:pre-line">{{ $text }}</p>
                    @if (in_array($field, $temple->wikipedia_fields ?? [], true) && $temple->wikipedia_url)
                        <p style="font-size:.85rem;opacity:.75">From <a href="{{ $temple->wikipedia_url }}" rel="noopener">Wikipedia</a>, under <a href="https://creativecommons.org/licenses/by-sa/4.0/" rel="license noopener">CC BY-SA 4.0</a>.</p>
                    @endif
                @endif
            @endforeach

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
                <h2>Photos</h2>
                <div class="gallery">
                    @foreach ($pictures as $i => $p)
                        <button type="button" data-photo="{{ $i }}" aria-label="Open photo {{ $i + 1 }} of {{ $pictures->count() }}">
                            <img src="{{ Seo::absolute($p['thumb'] ?? $p['full']) }}" alt="{{ $p['caption'] ?: $temple->name }}" loading="lazy">
                        </button>
                    @endforeach
                </div>
                <dialog class="lightbox" id="lightbox" aria-label="Photos of {{ $temple->name }}">
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
                    'caption' => $p['caption'] ?: $temple->name,
                    'credit' => collect([$p['credit'], $p['license']])->filter()->implode(', '),
                    'source' => $p['source'],
                ])->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
            @endif

            @if ($videos->isNotEmpty())
                <h2>Videos</h2>
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
                    @if ($temple->source_name)
                        {{-- OpenStreetMap's licence (ODbL) asks for this credit wherever its data is shown. --}}
                        <dt>Source</dt><dd>@if ($temple->source_url)<a href="{{ $temple->source_url }}" rel="nofollow noopener" target="_blank">{{ $temple->source_name === 'OpenStreetMap contributors' ? '© OpenStreetMap contributors' : $temple->source_name }}</a>@else{{ $temple->source_name }}@endif</dd>
                    @endif
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
                        <div class="ph">@if ($t->primaryPhoto)<img src="{{ Seo::absolute($t->primaryPhoto->mediumUrl() ?? $t->primaryPhoto->thumbnailUrl()) }}" alt="{{ $t->name }}" loading="lazy">@else<div class="none" aria-hidden="true">🛕</div>@endif</div>
                        <div class="txt"><b>{{ $t->name }}</b><span>{{ collect([$t->city, $t->state?->name])->filter()->unique()->implode(', ') }}</span></div>
                    </a>
                @endforeach
            </div>
            @if ($group['all'])<p><a href="{{ $group['all'] }}">See all →</a></p>@endif
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
