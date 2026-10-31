@extends('site.layout')
@section('ads', '1')
@include('site.festivals._styles')

@section('content')
    @include('site._crumbs')
    <h1>{{ __('Hindu festivals in :month', ['month' => $label]) }}</h1>
    <p class="muted">{{ trans_choice(':n festival or vrat|:n festivals and vrats', $rows->count(), ['n' => $rows->count()]) }} {{ __('in :month, with dates and tithi.', ['month' => $label]) }}</p>
    <table class="ftable" style="margin-top:14px">
        <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Festival / vrat') }}</th><th>{{ __('Tithi') }}</th></tr></thead>
        <tbody>
            @foreach ($rows as $f)
                <tr><td>{{ $f->starts_on->format('j M, D') }}</td><td><a href="{{ \App\Http\Controllers\FestivalPageController::url($f) }}">{{ $f->name }}</a>@if ($f->is_major)<span class="tag major">{{ __('Major') }}</span>@endif</td><td>{{ $f->tithi }}</td></tr>
            @endforeach
        </tbody>
    </table>
    @include('site._ad', ['place' => 'festival'])

    <nav class="monthnav">
        <span>@if ($prev)<a href="{{ $prev[1] }}">← {{ $prev[0] }}</a>@endif</span>
        <span>@if ($nextMonth)<a href="{{ $nextMonth[1] }}">{{ $nextMonth[0] }} →</a>@endif</span>
    </nav>
@endsection
