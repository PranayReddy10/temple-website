@extends('site.layout')

@php($u = \App\Support\Seo::class)

@section('content')
    <div class="acct">
        @include('site.account._nav')
        <div>
            <h1>🧭 {{ __('Yatra planner') }}</h1>
            <p class="muted">{{ __('Plan a pilgrimage across several temples: temples by day, the route in Google Maps, and today’s timings for each. The same plans show in the app.') }}</p>

            @if ($yatras->isNotEmpty())
                <div class="yatras">
                    @foreach ($yatras as $y)
                        <a class="card yatra-card" href="{{ $u::url('account/yatras/'.$y->id) }}">
                            <span class="pill {{ $y->status->value }}">{{ $y->status->getLabel() }}</span>
                            <b>{{ $y->title }}</b>
                            <small>{{ $y->dateLabel() }} · {{ $y->stops_count }} {{ \Illuminate\Support\Str::plural('temple', $y->stops_count) }}</small>
                        </a>
                    @endforeach
                </div>
            @endif

            <h2>{{ $yatras->isEmpty() ? __('Start your first yatra') : __('New yatra') }}</h2>
            @include('site.auth._errors')
            <form class="card form" method="post" action="{{ $u::url('account/yatras') }}">
                @csrf
                <label>{{ __('Name') }}<input name="title" value="{{ old('title') }}" placeholder="{{ __('e.g. Char Dham 2026, Telangana temples weekend') }}" required maxlength="160"></label>
                <div class="row">
                    <label>{{ __('From') }} <small>{{ __('optional') }}</small><input type="date" name="starts_on" value="{{ old('starts_on') }}"></label>
                    <label>{{ __('To') }} <small>{{ __('optional') }}</small><input type="date" name="ends_on" value="{{ old('ends_on') }}"></label>
                </div>
                <label>{{ __('People travelling') }} <small>{{ __('optional') }}</small><input type="number" name="party_size" min="1" max="500" value="{{ old('party_size') }}"></label>
                <button class="btn primary" type="submit">{{ __('Create yatra') }}</button>
            </form>
        </div>
    </div>
@endsection
