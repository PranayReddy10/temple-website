@extends('site.layout')

@php
    $t = [
        'kind' => __('Event ticket'),
        'title' => $ticket->event?->title ?? __('Event'),
        'temple' => $ticket->temple ? \App\Http\Controllers\Site\BookingController::named($ticket->temple) : '',
        'when' => (string) $ticket->occurs_on?->format('D, j M Y'),
        'slot' => null,
        'people' => $ticket->people,
        'name' => (string) $ticket->devotee_name,
        'amount' => $ticket->amountLabel(),
        'reference' => $ticket->reference,
        'url' => $ticket->qrUrl(),
        'extra' => null,
    ];
@endphp

@section('content')
    <div class="acct">
        @include('site.account._nav')
        <div>
            <p class="crumbs"><a href="{{ \App\Support\Seo::url('account/bookings') }}">{{ __('Bookings') }}</a> › {{ $ticket->reference }}</p>
            @include('site.account._ticket_card', ['status' => $ticket->status])
            @if ($qr)@include('site.account._ticket_share')@endif
            @if ($ticket->temple && $ticket->temple->hasCoordinates())
                <div class="ticket-share"><a class="btn" href="https://www.google.com/maps/dir/?api=1&destination={{ $ticket->temple->latitude }},{{ $ticket->temple->longitude }}" target="_blank" rel="noopener">🧭 {{ __('Directions') }}</a></div>
            @endif
        </div>
    </div>
@endsection
