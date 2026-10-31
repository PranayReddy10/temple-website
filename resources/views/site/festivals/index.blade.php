@extends('site.layout')
@section('ads', '1')
@include('site.festivals._styles')

@section('content')
    @include('site._crumbs')
    <h1>{{ $title }}</h1>
    <p class="muted">{{ __('Festivals and vrats with their dates and tithi, as in the :app app’s calendar. Dates follow the panchang for India; local calendars can differ by a day.', ['app' => config('brand.name')]) }}</p>

    <div class="chips" style="margin:14px 0">
        @foreach ($groups as $key => $g)<a href="{{ \App\Support\Seo::url('festivals/'.$key) }}">{{ $g['title'] }} {{ __('dates') }}</a>@endforeach
    </div>

    @if ($major->isNotEmpty())
        <h2>{{ __('Major festivals ahead') }}</h2>
        <div class="fgrid">@foreach ($major as $f)@include('site.festivals._card')@endforeach</div>
    @endif

    @include('site._ad', ['place' => 'festival'])

    @foreach ($months as $ym => $rows)
        @php($m = \Carbon\Carbon::createFromFormat('Y-m-d', $ym.'-01'))
        <h2><a href="{{ \App\Support\Seo::url('festivals/'.$m->year.'/'.strtolower($m->format('F'))) }}" style="color:inherit;text-decoration:none">{{ __('Festivals in :month', ['month' => $m->format('F Y')]) }}</a></h2>
        <div class="fgrid">@foreach ($rows as $f)@include('site.festivals._card')@endforeach</div>
    @endforeach
@endsection
