@extends('site.layout')

@php
    use App\Support\Seo;
    use Illuminate\Support\Str;

    $signedIn = auth('devotee_web')->check();
    // For search engines: the temple's sevas as services with their price.
    $ld = [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Temples', 'item' => Seo::url('temples')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $temple->name, 'item' => Seo::url('temples/'.$temple->slug)],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => 'Sevas', 'item' => $canonical],
                ],
            ],
            [
                '@type' => 'ItemList',
                'name' => 'Sevas and pujas at '.$temple->name,
                'itemListElement' => $pujas->values()->map(fn ($p, $i) => array_filter([
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'item' => array_filter([
                        '@type' => 'Service',
                        'name' => $p->name,
                        'description' => $p->description ? Str::limit(strip_tags($p->description), 200) : null,
                        'provider' => ['@type' => 'HinduTemple', 'name' => $temple->name, 'url' => Seo::url('temples/'.$temple->slug)],
                        'offers' => $p->is_free || $p->fee_amount !== null ? [
                            '@type' => 'Offer',
                            'price' => $p->is_free ? '0' : number_format((float) $p->fee_amount, 2, '.', ''),
                            'priceCurrency' => $p->fee_currency ?: 'INR',
                            'availability' => $p->isBookableInApp() ? 'https://schema.org/InStock' : 'https://schema.org/InStoreOnly',
                            'url' => $canonical,
                        ] : null,
                    ]),
                ]))->all(),
            ],
        ],
    ];
@endphp

@push('head')
    <script type="application/ld+json">{!! json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    <style>
        .sevas { display: grid; gap: 14px; margin-top: 16px; }
        .seva { display: grid; grid-template-columns: 1fr auto; gap: 12px 20px; align-items: center; }
        .seva h2 { margin: 0 0 4px; font-size: 1.15rem; }
        .seva .meta { display: flex; flex-wrap: wrap; gap: 6px 14px; color: var(--muted); font-size: .88rem; }
        .seva p { margin: 6px 0 0; font-size: .93rem; }
        .seva .price { font-family: Georgia, 'Noto Serif', serif; font-size: 1.25rem; font-weight: 700; color: var(--kumkum); text-align: right; }
        .seva .side { display: grid; gap: 8px; justify-items: end; }
        .seva .counter { font-size: .82rem; color: var(--muted); }
        @media (max-width: 600px) { .seva { grid-template-columns: 1fr; } .seva .side { justify-items: start; } .seva .price { text-align: left; } }
        .event form { display: flex; flex-wrap: wrap; gap: 8px; align-items: end; margin-top: 10px; }
        .event select, .event input { font: inherit; padding: 8px 10px; border: 1px solid var(--line); border-radius: 10px; background: #fff; }
    </style>
@endpush

@section('content')
    <p class="crumbs"><a href="{{ Seo::url('/') }}">Home</a> › <a href="{{ Seo::url('temples') }}">Temples</a> › <a href="{{ Seo::url('temples/'.$temple->slug) }}">{{ $temple->name }}</a> › Sevas</p>
    <h1>{{ $title }}</h1>
    <p class="muted">{{ $place }}@if ($pujas->contains(fn ($p) => $p->isBookableInApp())) · {{ __('Book online and show the code at the temple counter.') }}@endif</p>

    @include('site.auth._errors')
    <div class="sevas">
        @foreach ($pujas as $p)
            <div class="card seva" id="seva-{{ $p->id }}">
                <div>
                    <h2>{{ $p->name }}</h2>
                    <div class="meta">
                        @if ($p->kind)<span>{{ $p->kind->getLabel() }}</span>@endif
                        @if ($p->starts_at)<span>🕰️ {{ \Carbon\Carbon::parse($p->starts_at)->format('g:i A') }}</span>@endif
                        @if ($p->durationLabel())<span>⏳ {{ $p->durationLabel() }}</span>@endif
                        @if ($p->schedule_note)<span>{{ $p->schedule_note }}</span>@endif
                    </div>
                    @if ($p->description)<p>{{ Str::limit(strip_tags($p->description), 260) }}</p>@endif
                    @if ($p->includes)<p class="muted"><b>{{ __('Includes') }}:</b> {{ strip_tags($p->includes) }}</p>@endif
                    @if ($p->eligibility)<p class="muted"><b>{{ __('Who can take part') }}:</b> {{ strip_tags($p->eligibility) }}</p>@endif
                </div>
                <div class="side">
                    <div class="price">{{ $p->feeLabel() }}@if ($p->fee_per_person && $p->requiresPayment())<small style="font-size:.75rem;font-weight:400;color:var(--muted)"> {{ __('per person') }}</small>@endif</div>
                    @if ($p->isBookableInApp())
                        <a class="btn primary" href="{{ Seo::url('temples/'.$temple->slug.'/sevas/'.$p->id.'/book') }}" rel="nofollow">{{ $signedIn ? __('Book now') : __('Sign in to book') }}</a>
                    @elseif ($p->booking_url)
                        <a class="btn" href="{{ $p->booking_url }}" rel="nofollow noopener" target="_blank">{{ $p->hasOfficialBooking() ? __('Official booking') : __('Booking link') }}</a>
                    @else
                        <span class="counter">{{ __('Book at the temple counter') }}</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    @if ($events->isNotEmpty())
        <h2 id="events">{{ __('Events you can join') }}</h2>
        <div class="sevas">
            @foreach ($events as $e)
                <div class="card event">
                    <b>{{ $e->title }}</b>
                    <div class="muted" style="font-size:.88rem">{{ $e->dateLabel() }}@if ($e->priceLabel()) · {{ $e->priceLabel() }}@endif</div>
                    @if ($e->description)<p style="margin:6px 0 0">{{ Str::limit(strip_tags($e->description), 200) }}</p>@endif
                    @if ($signedIn)
                        <form method="post" action="{{ Seo::url('events/'.$e->id.'/join') }}">
                            @csrf
                            <label>{{ __('Day') }}<br>
                                <select name="occurs_on">
                                    @foreach ($e->nextDates() as $d)<option value="{{ $d->toDateString() }}">{{ $d->format('D j M Y') }}</option>@endforeach
                                </select>
                            </label>
                            <label>{{ __('People') }}<br><input type="number" name="people" value="1" min="1" max="{{ $e->max_people_per_registration ?: 10 }}" style="width:80px"></label>
                            <button class="btn primary" type="submit">{{ $e->isTicketed() ? __('Buy tickets') : __('Register') }}</button>
                        </form>
                    @else
                        <p style="margin:10px 0 0"><a class="btn primary" href="{{ Seo::url('login').'?'.http_build_query(['next' => $canonical.'#events']) }}" rel="nofollow">{{ __('Sign in to join') }}</a></p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <p style="margin-top:24px"><a href="{{ Seo::url('temples/'.$temple->slug) }}">← {{ __('Timings, rules and directions for :name', ['name' => $temple->name]) }}</a></p>
@endsection
