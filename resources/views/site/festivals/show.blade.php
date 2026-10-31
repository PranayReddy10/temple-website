@extends('site.layout')
@section('ads', '1')
@include('site.festivals._styles')

@php
    $ld = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => collect($faq)->map(fn ($f) => ['@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]])->all()];
    $thisYear = $rows->filter(fn ($r) => $r->starts_on->year === $year);
@endphp
@push('head')<script type="application/ld+json">{!! json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>@endpush

@section('content')
    @include('site._crumbs')
    <h1>{{ $name }} {{ $year }}@if ($name !== $festival->name)<small class="muted" style="display:block;font-size:1rem;font-family:system-ui;font-weight:400;margin-top:4px">{{ $festival->name }}</small>@endif</h1>
    <p class="answer">
        {{ \App\Http\Controllers\FestivalPageController::sentence($festival->name, $next->starts_on->lt($today)) }}
        <b>{{ $next->starts_on->format('l, j F Y') }}</b>@if ($next->ends_on && ! $next->ends_on->isSameDay($next->starts_on)) {{ __('to') }} <b>{{ $next->ends_on->format('l, j F Y') }}</b>@endif.
        @if ($next->tithi)<br><span class="muted">{{ __('Tithi') }}: {{ $next->tithi }}</span>@endif
    </p>

    @if ($festival->description)
        <h2>{{ __('About :name', ['name' => $name]) }}</h2>
        <p>{{ strip_tags($festival->description) }}</p>
    @endif

    @if ($rows->count() > 1)
        <h2>{{ __(':name dates', ['name' => $name]) }}</h2>
        <table class="ftable">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Day') }}</th><th>{{ __('Tithi') }}</th></tr></thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr @class(['next' => $r->is($next), 'past' => $r->starts_on->lt($today) && ! $r->is($next)])><td>{{ $r->starts_on->format('j F Y') }}</td><td>{{ $r->starts_on->format('l') }}</td><td>{{ $r->tithi }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @include('site._ad', ['place' => 'festival'])

    @include('site.festivals._temples')

    @if ($related->isNotEmpty())
        <h2>{{ __('Festivals around the same time') }}</h2>
        <div class="fgrid">@foreach ($related as $f)@include('site.festivals._card')@endforeach</div>
    @endif

    <h2>{{ __('Questions') }}</h2>
    @foreach ($faq as $f)<details class="faq" @if ($loop->first) open @endif><summary>{{ $f['q'] }}</summary><p>{{ $f['a'] }}</p></details>@endforeach

    <p style="margin-top:20px"><a href="{{ \App\Support\Seo::url('festivals') }}">← {{ __('Full festival calendar') }}</a></p>
@endsection
