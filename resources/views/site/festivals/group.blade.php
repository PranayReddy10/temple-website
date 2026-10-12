@extends('site.layout')
@include('site.festivals._styles')

@section('content')
    @include('site._crumbs')
    <h1>{{ $title }}</h1>
    <p>{{ $meta['about'] }}</p>
    @if ($next)
        <p class="answer">{{ __('The next :name is', ['name' => $meta['title']]) }} <b>{{ $next->name }}</b> {{ __('on') }} <b>{{ $next->starts_on->format('l, j F Y') }}</b>@if ($next->tithi) <span class="muted">({{ $next->tithi }})</span>@endif.</p>
    @endif
    @foreach ($byYear as $y => $rows)
        <h2>{{ $meta['title'] }} {{ $y }} {{ __('dates') }}</h2>
        <table class="ftable">
            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Name') }}</th><th>{{ __('Tithi') }}</th></tr></thead>
            <tbody>
                @foreach ($rows as $f)
                    <tr @class(['next' => $next && $f->is($next), 'past' => $f->starts_on->lt($today)])><td>{{ $f->starts_on->format('j M Y, D') }}</td><td><a href="{{ \App\Http\Controllers\FestivalPageController::url($f) }}">{{ $f->name }}</a></td><td>{{ $f->tithi }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
    @include('site.festivals._temples')
    <p style="margin-top:20px"><a href="{{ \App\Support\Seo::url('festivals') }}">← {{ __('Full festival calendar') }}</a></p>
@endsection
