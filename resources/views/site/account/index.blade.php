@extends('site.layout')

@section('content')
    <div class="acct">
        @include('site.account._nav')
        <div>
            <h1>{{ __('Namaste, :name', ['name' => $devotee->name]) }}</h1>
            <div class="stats">
                <a class="card" href="{{ \App\Support\Seo::url('account/passport') }}" style="text-decoration:none;color:inherit"><b>{{ $stamps }}</b>{{ __('passport stamps') }}</a>
                <a class="card" href="{{ \App\Support\Seo::url('account/passport') }}" style="text-decoration:none;color:inherit"><b>{{ $visited }}</b>{{ __('temples visited') }}</a>
                <a class="card" href="{{ \App\Support\Seo::url('account/saved') }}" style="text-decoration:none;color:inherit"><b>{{ $saved }}</b>{{ __('saved temples') }}</a>
            </div>
            <h2>{{ __('Coming up') }}</h2>
            @if ($upcoming->isEmpty())
                <p class="card">{{ __('No sevas booked yet.') }} <a href="{{ \App\Support\Seo::url('temples') }}">{{ __('Find a temple') }}</a> {{ __('and choose Book a seva.') }}</p>
            @else
                <div class="list">@foreach ($upcoming as $b)@include('site.account._booking_row')@endforeach</div>
                <p><a href="{{ \App\Support\Seo::url('account/bookings') }}">{{ __('All bookings') }} →</a></p>
            @endif
        </div>
    </div>
@endsection
