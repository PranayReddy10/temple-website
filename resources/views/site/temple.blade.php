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
        .hero.deity { aspect-ratio: auto; max-height: 420px; display: flex; justify-content: center; background: linear-gradient(135deg, #3E2723, #6b2a22); }
        .hero.deity img { width: auto; max-width: 100%; max-height: 420px; object-fit: contain; }
        .cols { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 20px; }
        @media (max-width: 760px) { .cols { grid-template-columns: 1fr; } }
        /* The temple's song: one small button, top right, below the header. */
        .temple-song { position: fixed; top: 72px; right: 12px; z-index: 30; width: 38px; height: 38px; padding: 0; border: 0; border-radius: 50%; background: rgba(59,29,14,.82); color: #fff; font-size: 1.1rem; line-height: 38px; text-align: center; cursor: pointer; box-shadow: 0 3px 10px rgba(0,0,0,.2); }
        .temple-song[hidden] { display: none; }
        .temple-song:hover, .temple-song:focus-visible { background: #3b1d0e; }
        .temple-song[aria-pressed="false"] { opacity: .7; text-decoration: line-through; }
        .temple-song[aria-pressed="true"] { animation: song-pulse 1.6s ease-in-out infinite; }
        @keyframes song-pulse { 50% { transform: scale(1.08); } }
        @media (prefers-reduced-motion: reduce) { .temple-song[aria-pressed="true"] { animation: none; } }
        /* Shown while the browser waits for the visitor's first tap. */
        .temple-song-hint { position: fixed; top: 76px; right: 58px; z-index: 30; background: #3b1d0e; color: #fff; font-size: .8rem; padding: 6px 12px; border-radius: 999px; box-shadow: 0 3px 10px rgba(0,0,0,.2); pointer-events: none; white-space: nowrap; }
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
        /* Each section a card of its own, tinted by what it holds. */
        .panels { display: grid; gap: 18px; align-content: start; }
        .panel { background: #fffdf9; border: 1px solid var(--line); border-radius: 18px; padding: 18px 20px; box-shadow: 0 2px 10px rgba(62,39,35,.05); min-width: 0; }
        .panel > h2, .reviews.panel > h2, .glance > h2, .map-card > h2 { display: flex; align-items: center; gap: 10px; margin: 0 0 12px; padding-bottom: 10px; border-bottom: 1px solid rgba(0,0,0,.06); font-size: 1.15rem; line-height: 1.3; }
        .panel > p:last-child { margin-bottom: 0; }
        .panel h3 { font-size: .95rem; margin: 6px 0 8px; }
        .pi { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 10px; background: rgba(0,0,0,.05); font-size: 1rem; }
        .tile { background: rgba(255,255,255,.75); border: 1px solid rgba(0,0,0,.05); border-radius: 12px; padding: 10px 12px; margin-top: 8px; display: grid; gap: 2px; }
        .tone-blue { background: #eef5ff; border-color: #cfe0fb; } .tone-blue > h2 { color: #1e4f9c; } .tone-blue .pi { background: #d9e8ff; }
        .tone-amber { background: #fff7e6; border-color: #f6dfae; } .tone-amber > h2 { color: #8a5300; } .tone-amber .pi { background: #fde8bd; }
        .tone-saffron { background: #fff2e8; border-color: #f7d3b8; } .tone-saffron > h2 { color: #a23e0e; } .tone-saffron .pi { background: #fddcc5; }
        .tone-green { background: #eefaf1; border-color: #c8ebd2; } .tone-green > h2 { color: #1d6b38; } .tone-green .pi { background: #d3f1dc; }
        .tone-purple { background: #f6f0ff; border-color: #e1d3fa; } .tone-purple > h2 { color: #5b2b9c; } .tone-purple .pi { background: #e6d9fb; }
        .timings { width: 100%; border-collapse: collapse; font-size: .95rem; }
        .timings th, .timings td { text-align: left; padding: 8px 6px; border-top: 1px solid #d6e4fa; vertical-align: top; }
        .timings tr:first-child th, .timings tr:first-child td { border-top: 0; }
        .timings th { font-weight: 600; } .timings .win { white-space: nowrap; color: #1e4f9c; font-weight: 600; }
        .chips { display: flex; flex-wrap: wrap; gap: 8px; }
        .chip { background: #ebe0fd; color: #5b2b9c; border-radius: 999px; padding: 5px 12px; text-decoration: none; font-size: .9rem; font-weight: 600; }
        .chip small { font-weight: 400; opacity: .8; margin-left: 4px; }
        .faq { margin-top: 24px; }
        .glance { margin-bottom: 16px; }
        /* At a glance: first thing on a phone, in the side column on a wide screen. */
        .glance.top { display: none; margin: 0; }
        @media (max-width: 760px) { .glance.top { display: block; } .glance.side { display: none; } }
        .reach > h2 { display: flex; align-items: center; gap: 10px; padding-bottom: 10px; border-bottom: 1px solid rgba(0,0,0,.06); }
        .glance > h2, .map-card > h2 { font-size: 1.05rem; }
        .g-tile { background: #f8f2e9; border-radius: 12px; padding: 9px 12px; margin-top: 8px; font-size: .93rem; overflow-wrap: anywhere; }
        .g-tile .lbl { display: block; font-size: .78rem; font-weight: 700; color: var(--muted); margin-bottom: 2px; }
        .map-card { margin-top: 16px; }
        .map-card iframe { width: 100%; height: 220px; border: 0; border-radius: 12px; display: block; background: #e9e2d6; }
        .reach h2 { margin: 0 0 12px; font-size: 1.1rem; line-height: 1.3; }
        .reach h3 { margin: 16px 0 4px; font-size: .78rem; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; }
        .reach-actions { display: grid; gap: 8px; margin-bottom: 6px; }
        .reach-actions .go { justify-content: center; width: 100%; }
        .reach-actions .pair { display: grid; grid-template-columns: repeat(auto-fit, minmax(0, 1fr)); gap: 8px; }
        .reach-actions .pair .btn { justify-content: center; padding: 8px 10px; font-size: .9rem; }
        .reach-row { display: grid; grid-template-columns: 26px minmax(0, 1fr) auto; gap: 4px 8px; align-items: start; padding: 9px 0; border-top: 1px solid var(--line); font-size: .93rem; overflow-wrap: anywhere; }
        .reach h3 + .reach-row, .reach-actions + .reach-row { border-top: 0; }
        .reach-row .ic { font-size: 1.05rem; line-height: 1.4; text-align: center; }
        .reach-row .lbl { font-size: .78rem; color: var(--muted); font-weight: 600; }
        .reach-row .gps { font-size: .8rem; color: var(--muted); margin-top: 2px; font-variant-numeric: tabular-nums; }
        .reach-row .copy { border: 1px solid var(--line); background: #fff; color: var(--deep); border-radius: 999px; padding: 3px 10px; font: inherit; font-size: .78rem; cursor: pointer; }
        .reach-row .copy:hover { border-color: var(--kumkum); }
        .near { list-style: none; margin: 0; padding: 0; }
        .near li { display: flex; justify-content: space-between; gap: 10px; padding: 8px 0; border-top: 1px solid var(--line); font-size: .93rem; }
        .near li:first-child { border-top: 0; }
        .near .km { flex: none; color: var(--muted); font-size: .82rem; background: var(--sandal); border-radius: 999px; padding: 1px 8px; height: fit-content; white-space: nowrap; }
        .reach-foot { margin: 14px 0 0; padding-top: 10px; border-top: 1px solid var(--line); font-size: .78rem; color: var(--muted); }
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
        /* Five to a row on a wide screen: a sixth card would sit alone. */
        @media (min-width: 1000px) { .more > a:nth-child(6) { display: none; } }
        /* A temple without a photo yet: a short tinted band, not an empty box. */
        .more .ph:has(.none) { aspect-ratio: 16/7; background: linear-gradient(135deg, #f3e6d0, #ead6b8); }
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
            /* An odd one out takes the whole row rather than sitting alone. */
            .actions > :last-child:nth-child(odd) { grid-column: 1 / -1; }
            .timings th, .timings td { padding: 7px 4px; font-size: .88rem; }
            .timings .win { white-space: normal; }
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
        <a class="btn" href="{{ TempleSeo::directionsUrl($temple, $place) }}" rel="nofollow noopener" target="_blank">🧭 {{ __('Directions') }}</a>
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

    @if ($heroIsDeity ?? false)
        {{-- No photo of the temple yet: its deity's painting, credited. --}}
        <div class="hero deity"><img src="{{ $heroImage }}" alt="{{ $temple->deity->name }} – {{ $photoName }}" fetchpriority="high"></div>
        <p style="font-size:.8rem;opacity:.7;margin:4px 0 0">{{ __('Painting of :deity', ['deity' => $temple->deity->name]) }}@if ($temple->deity->image_credit): {{ $temple->deity->image_credit }}@endif</p>
    @elseif ($heroImage ?? null)
        @php
            $lead = $temple->primaryPhoto ?? $temple->photos->first();
        @endphp
        <div class="hero"><img src="{{ $heroImage }}" alt="{{ $photoName }}" fetchpriority="high"></div>
        @if ($lead?->credit || $lead?->license)
            {{-- Commons photos may be used only with their photographer and licence beside them. --}}
            <p style="font-size:.8rem;opacity:.7;margin:4px 0 0">Photo: @if ($lead->source_url)<a href="{{ $lead->source_url }}" rel="noopener">{{ $lead->credit ?? 'source' }}</a>@else{{ $lead->credit }}@endif{{ $lead->license ? ', '.$lead->license : '' }}</p>
        @endif
    @endif

    <div class="cols">
        <div class="panels">
            @include('site._glance', ['where' => 'top'])
            @if (($intro !== [] && $locale === 'en') || $about)
                <section class="panel">
                    <h2><span class="pi" aria-hidden="true">📖</span>{{ __('About :name', ['name' => $name]) }}</h2>
                    @if ($intro !== [] && $locale === 'en')<p>{{ implode(' ', $intro) }}</p>@endif
                    @if ($about)<p>{{ $about }}</p>@endif
                </section>
            @endif

            @if ($temple->timings->isNotEmpty())
                <section class="panel tone-blue">
                    <h2><span class="pi" aria-hidden="true">🕘</span>{{ __(':name timings', ['name' => $name]) }}</h2>
                    <table class="timings">
                        @foreach (\App\Models\TempleTiming::inReadingOrder($temple->timings) as $t)
                            <tr><th>{{ $t->label ?: __($t->kind?->getLabel() ?? '') }}</th><td>{{ __($t->dayLabel()) }}</td><td class="win">{{ $t->window() }}</td></tr>
                        @endforeach
                    </table>
                </section>
            @endif

            @if ($temple->pujas->isNotEmpty())
                <section class="panel tone-amber">
                    <h2><span class="pi" aria-hidden="true">🪔</span>{{ __('Pujas and sevas at :name', ['name' => $name]) }}</h2>
                    @foreach ($temple->pujas as $p)
                        <div class="tile">
                            <b>{{ $p->name }}</b>
                            @if (filled($p->feeLabel()) || filled($p->durationLabel()))<span class="muted">{{ collect([$p->feeLabel(), $p->durationLabel()])->filter()->implode(' · ') }}</span>@endif
                            @if ($p->description)<div>{{ \Illuminate\Support\Str::limit($p->description, 240) }}</div>@endif
                        </div>
                    @endforeach
                </section>
            @endif

            @if ($significance)
                <section class="panel tone-saffron">
                    <h2><span class="pi" aria-hidden="true">🕉️</span>{{ __('Significance') }}</h2>
                    <p style="white-space:pre-line">{{ $significance }}</p>
                </section>
            @endif

            @if ($history)
                <section class="panel">
                    <h2><span class="pi" aria-hidden="true">📜</span>{{ __('History') }}</h2>
                    <p style="white-space:pre-line">{{ $history }}</p>
                </section>
            @endif

            @if ($entryRules || $queueInfo)
                <section class="panel tone-green">
                    <h2><span class="pi" aria-hidden="true">✅</span>{{ __('Visitor guidelines') }}</h2>
                    @if ($entryRules)<div class="tile"><b>{{ __('Entry rules') }}</b><div style="white-space:pre-line">{{ $entryRules }}</div></div>@endif
                    @if ($queueInfo)<div class="tile"><b>{{ __('Queue and darshan tips') }}</b><div style="white-space:pre-line">{{ $queueInfo }}</div></div>@endif
                </section>
            @endif

            @if (($deityFestivals ?? collect())->isNotEmpty() || $temple->events->isNotEmpty())
                <section class="panel tone-purple">
                    <h2><span class="pi" aria-hidden="true">🎉</span>{{ __('Festivals and events') }}</h2>
                    @if (($deityFestivals ?? collect())->isNotEmpty())
                        <h3>{{ __('Festivals of :deity', ['deity' => $deityName ?? $temple->deity?->name]) }}</h3>
                        <div class="chips">
                            @foreach ($deityFestivals as $f)<a class="chip" href="{{ \App\Http\Controllers\FestivalPageController::url($f) }}">{{ $f->name }} <small>{{ $f->starts_on->format('j M Y') }}</small></a>@endforeach
                        </div>
                    @endif
                    @foreach ($temple->events as $e)
                        <div class="tile">
                            <b>{{ $e->title }}</b>
                            <span class="muted">{{ optional($e->nextDate() ?? $e->starts_on)->format('l, d M Y') }}@if ($e->group_name) · {{ $e->group_name }}@endif</span>
                            @if ($e->description)<div>{{ \Illuminate\Support\Str::limit(strip_tags($e->description), 220) }}</div>@endif
                        </div>
                    @endforeach
                </section>
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
                // The song that plays on opening the page: the temple's own
                // (uploaded, or a link to an audio file), else its deity's.
                // allMedia() lists the temple's media first. A YouTube link
                // cannot play as sound alone, so it is not chosen.
                $templeSong = $media->first(fn ($m) => in_array($m->type?->value, ['song', 'chant'], true)
                    && $m->url() && $m->playbackKind() === 'audio');
            @endphp

            @if ($templeSong)
                <button type="button" class="temple-song" id="temple-song" data-src="{{ $templeSong->url() }}" hidden
                    title="{{ $templeSong->title ?: __('Temple song') }}" aria-pressed="false"
                    data-play="{{ __('Play the temple song') }}" data-stop="{{ __('Stop the temple song') }}"
                    aria-label="{{ __('Play the temple song') }}">♪</button>
                <span class="temple-song-hint" id="temple-song-hint" hidden>{{ __('Tap anywhere to hear the temple song') }}</span>
            @endif

            @if ($pictures->isNotEmpty())
                <section class="panel">
                <h2><span class="pi" aria-hidden="true">📷</span>{{ __('Photos of :name', ['name' => $name]) }}</h2>
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
                </section>
            @endif

            @if ($videos->isNotEmpty())
                <section class="panel">
                <h2><span class="pi" aria-hidden="true">🎬</span>{{ __('Videos') }}</h2>
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
                </section>
            @endif

            @include('site._reviews')
        </div>

        <aside>
            @php
                // "Nearest railway station: Srikakulam Road, 13 km" lines, as rows with an icon.
                $reachRows = collect(preg_split('/\R/', (string) ($howToReach ?? '')))->map('trim')->filter()->map(function (string $line) {
                    [$label, $value] = str_contains($line, ':') ? array_map('trim', explode(':', $line, 2)) : [null, $line];
                    $icon = match (true) {
                        $label !== null && (bool) preg_match('/rail|train|station/i', $label) => '🚆',
                        $label !== null && (bool) preg_match('/air|flight/i', $label) => '✈️',
                        $label !== null && (bool) preg_match('/bus|road/i', $label) => '🚌',
                        default => '📍',
                    };
                    $short = match ($icon) { '🚆' => __('By train'), '✈️' => __('By air'), '🚌' => __('By bus'), default => $label };

                    return ['icon' => $icon, 'label' => $short, 'value' => $value];
                })->values();
                // The address, then the town, district and state, each part once:
                // "Arasavalli, near Srikakulam" and "Arasavalli, Srikakulam, …"
                // read as "Arasavalli, near Srikakulam, Srikakulam, …" no more.
                $addressLine = TempleSeo::addressLine($temple, $place);
                $rules = collect([
                    ['👘', __('Dress code'), $dressCode],
                    ['👟', __('Footwear'), $temple->footwear_policy],
                    ['📵', __('Mobile phones'), $temple->mobile_policy],
                ])->filter(fn ($r) => filled($r[2]));
            @endphp
            @include('site._glance', ['where' => 'side'])
            <div class="card reach">
                <h2><span class="pi" aria-hidden="true">🧭</span>{{ __('How to reach :name', ['name' => $name]) }}</h2>

                @if ($temple->hasCoordinates() || $addressLine || $temple->contact_phone || $temple->official_website)
                    <div class="reach-actions">
                        @if ($temple->hasCoordinates() || $addressLine)
                            <a class="btn primary go" href="{{ TempleSeo::directionsUrl($temple, $place) }}" rel="nofollow noopener" target="_blank">🧭 {{ __('Get directions') }}</a>
                        @endif
                        @if ($temple->contact_phone || $temple->official_website)
                            <div class="pair">
                                @if ($temple->contact_phone)<a class="btn" href="tel:{{ preg_replace('/[^\d+]/', '', $temple->contact_phone) }}">📞 {{ __('Call') }}</a>@endif
                                @if ($temple->official_website)<a class="btn" href="{{ $temple->official_website }}" rel="nofollow noopener" target="_blank">🌐 {{ __('Website') }}</a>@endif
                            </div>
                        @endif
                    </div>
                @endif

                @if ($addressLine)
                    <div class="reach-row">
                        <span class="ic" aria-hidden="true">📍</span>
                        <div>
                            <div class="lbl">{{ __('Address') }}</div>
                            <div>{{ $addressLine }}</div>
                            @if ($temple->hasCoordinates())
                                <div class="gps">{{ __('GPS') }} {{ number_format((float) $temple->latitude, 5) }}, {{ number_format((float) $temple->longitude, 5) }}</div>
                            @endif
                        </div>
                        <button type="button" class="copy" data-copy="{{ $addressLine }}{{ $temple->hasCoordinates() ? ' ('.number_format((float) $temple->latitude, 5).', '.number_format((float) $temple->longitude, 5).')' : '' }}" data-done="{{ __('Copied') }}">{{ __('Copy') }}</button>
                    </div>
                @endif
                @if ($temple->contact_phone)
                    <div class="reach-row">
                        <span class="ic" aria-hidden="true">📞</span>
                        <div><div class="lbl">{{ __('Phone') }}</div><a href="tel:{{ preg_replace('/[^\d+]/', '', $temple->contact_phone) }}">{{ $temple->contact_phone }}</a></div>
                    </div>
                @endif

                @if ($reachRows->isNotEmpty())
                    <h3>{{ __('Getting there') }}</h3>
                    @foreach ($reachRows as $r)
                        <div class="reach-row">
                            <span class="ic" aria-hidden="true">{{ $r['icon'] }}</span>
                            <div>@if ($r['label'])<div class="lbl">{{ $r['label'] }}</div>@endif<div>{{ $r['value'] }}</div></div>
                        </div>
                    @endforeach
                @endif

                @if ($nearby->isNotEmpty())
                    <h3>{{ __('Nearest temples') }}</h3>
                    <ul class="near">
                        @foreach ($nearby->take(3) as $n)
                            <li><a href="{{ \App\Support\SiteLocale::templeUrl($n, $locale !== 'en' && in_array($locale, \App\Support\SiteLocale::languagesOf($n), true) ? $locale : null) }}">{{ $n->localName($locale) }}</a><span class="km">{{ TempleSeo::km((float) $n->distance_km) }}</span></li>
                        @endforeach
                    </ul>
                @endif

                @if ($rules->isNotEmpty())
                    <h3>{{ __('Before you go') }}</h3>
                    @foreach ($rules as [$icon, $label, $text])
                        <div class="reach-row">
                            <span class="ic" aria-hidden="true">{{ $icon }}</span>
                            <div><div class="lbl">{{ $label }}</div><div>{{ $text }}</div></div>
                        </div>
                    @endforeach
                @endif

                @if ($temple->source_name || $updatedAt)
                    <p class="reach-foot">
                        @if ($temple->source_name)
                            {{-- OpenStreetMap's licence (ODbL) asks for this credit wherever its data is shown. --}}
                            {{ __('Source') }}: @if ($temple->source_url)<a href="{{ $temple->source_url }}" rel="nofollow noopener" target="_blank">{{ $temple->source_name === 'OpenStreetMap contributors' ? '© OpenStreetMap contributors' : $temple->source_name }}</a>@else{{ $temple->source_name }}@endif
                        @endif
                        @if ($temple->source_name && $updatedAt) · @endif
                        @if ($updatedAt){{ __('Updated') }} <time datetime="{{ $updatedAt->toDateString() }}">{{ $updatedAt->format('d M Y') }}</time>@endif
                    </p>
                @endif
            </div>
            @if ($temple->hasCoordinates())
                @php
                    $lat = (float) $temple->latitude;
                    $lng = (float) $temple->longitude;
                @endphp
                <div class="card map-card">
                    <h2><span class="pi" aria-hidden="true">🗺️</span>{{ __('Temple location') }}</h2>
                    <iframe title="{{ __('Map of :name', ['name' => $name]) }}" loading="lazy" referrerpolicy="no-referrer"
                        src="https://www.openstreetmap.org/export/embed.html?bbox={{ $lng - 0.012 }}%2C{{ $lat - 0.008 }}%2C{{ $lng + 0.012 }}%2C{{ $lat + 0.008 }}&amp;layer=mapnik&amp;marker={{ $lat }}%2C{{ $lng }}"></iframe>
                    <p class="reach-foot" style="border:0;padding:0;margin-top:6px">© <a href="https://www.openstreetmap.org/copyright" rel="noopener" target="_blank">OpenStreetMap</a> · <a href="{{ TempleSeo::directionsUrl($temple, $place) }}" rel="nofollow noopener" target="_blank">{{ __('Get directions') }}</a></p>
                </div>
            @endif
            <div class="card" style="margin-top:16px">
                <b>{{ $bookable ? __('Book a seva') : __('Plan your visit') }}</b>
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
        <section class="panel faq">
            <h2><span class="pi" aria-hidden="true">❓</span>{{ __('Questions devotees ask') }}</h2>
            @foreach ($faq as $f)
                <details class="q" @if ($loop->first) open @endif><summary>{{ $f['q'] }}</summary><p>{{ $f['a'] }}</p></details>
            @endforeach
        </section>
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

        // The temple's song: starts when the page opens, stops when the
        // devotee leaves (or goes back), and can be paused or stopped.
        // Browsers refuse sound before the visitor has touched the page, so
        // when the first try is refused it starts on their first tap.
        (function () {
            var button = document.getElementById('temple-song');
            if (!button) return;
            var hint = document.getElementById('temple-song-hint');
            var audio = new Audio(button.dataset.src);
            audio.loop = true;
            audio.preload = 'auto';
            var stopped = false;
            button.hidden = false;
            // Browsers allow sound only after a real tap, click or key: the
            // finger lifting (touchend, pointerup), not touching down, and
            // never a scroll. These are the events that count.
            var TAPS = ['pointerup', 'touchend', 'click', 'keydown'];

            function show() {
                var on = !audio.paused;
                button.setAttribute('aria-pressed', on ? 'true' : 'false');
                button.setAttribute('aria-label', on ? button.dataset.stop : button.dataset.play);
                if (on && hint) hint.hidden = true;
            }
            function listen(on) {
                TAPS.forEach(function (ev) { (on ? window.addEventListener : window.removeEventListener).call(window, ev, firstTap, true); });
            }
            function start() {
                if (stopped) return;
                audio.play().then(show).catch(function () {
                    // Not allowed yet: say so, and start on the first tap.
                    if (hint) hint.hidden = false;
                    listen(true);
                    show();
                });
            }
            function firstTap(e) {
                if (e && e.target === button) return; // The button handles itself.
                listen(false);
                start();
            }
            audio.addEventListener('play', show);
            audio.addEventListener('pause', show);
            audio.addEventListener('error', function () { button.hidden = true; if (hint) hint.hidden = true; });

            // One tap stops it; another plays it again.
            button.addEventListener('click', function () {
                listen(false);
                if (hint) hint.hidden = true;
                if (audio.paused) {
                    stopped = false;
                    audio.play().catch(function () {});
                } else {
                    stopped = true;
                    audio.pause();
                    audio.currentTime = 0;
                }
            });
            // Leaving the page, or going back: the song stops.
            window.addEventListener('pagehide', function () { audio.pause(); });
            // Coming back to this page from the browser's history.
            window.addEventListener('pageshow', function (e) { if (e.persisted) start(); });

            start();
        })();

        // Copy the address (and GPS) for a cab or a friend.
        document.querySelectorAll('.reach .copy').forEach(function (b) {
            b.addEventListener('click', function () {
                if (!navigator.clipboard) return;
                navigator.clipboard.writeText(b.dataset.copy).then(function () {
                    var was = b.textContent;
                    b.textContent = b.dataset.done;
                    setTimeout(function () { b.textContent = was; }, 1600);
                }).catch(function () {});
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
