@extends('site.layout')

@section('content')
    <div class="acct">
        @include('site.account._nav')
        <div>
            <h1>{{ __('Bookings & tickets') }}</h1>
            @if ($bookings->isEmpty() && $tickets->isEmpty())
                <p class="card">{{ __('Nothing booked yet.') }} <a href="{{ \App\Support\Seo::url('temples') }}">{{ __('Find a temple') }}</a></p>
            @endif
            @if ($bookings->isNotEmpty())
                <h2>{{ __('Sevas') }}</h2>
                <div class="list">@foreach ($bookings as $b)@include('site.account._booking_row')@endforeach</div>
            @endif
            @if ($tickets->isNotEmpty())
                <h2>{{ __('Event tickets') }}</h2>
                <div class="list">
                    @foreach ($tickets as $t)
                        <a class="card item" href="{{ \App\Support\Seo::url('account/tickets/'.$t->reference) }}">
                            <span><b>{{ $t->event?->title }}</b><small>{{ $t->temple?->name }} · {{ $t->occurs_on?->format('D j M Y') }} · {{ $t->people }} {{ \Illuminate\Support\Str::plural('person', $t->people) }}</small></span>
                            <span class="pill {{ $t->status->value }}">{{ $t->status->getLabel() }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
