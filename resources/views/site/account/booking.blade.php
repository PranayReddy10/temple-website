@extends('site.layout')

@php
    $u = \App\Support\Seo::class;
    $t = [
        'kind' => __('Seva booking'),
        'title' => $booking->puja?->name ?? __('Seva'),
        'temple' => $booking->temple ? \App\Http\Controllers\Site\BookingController::named($booking->temple) : '',
        'when' => (string) $booking->booked_for?->format('D, j M Y'),
        'slot' => $booking->slotLabel(),
        'people' => $booking->people,
        'name' => (string) $booking->devotee_name,
        'amount' => $booking->amountLabel(),
        'reference' => $booking->reference,
        'url' => $booking->qrUrl(),
        'extra' => collect([$booking->gotram ? __('Gotram').': '.$booking->gotram : null, $booking->nakshatram ? __('Nakshatram').': '.$booking->nakshatram : null])->filter()->implode(' · ') ?: null,
    ];
    $extra = array_filter([__('Gotram') => $booking->gotram, __('Nakshatram') => $booking->nakshatram]);
@endphp

@section('content')
    <div class="acct">
        @include('site.account._nav')
        <div>
            <p class="crumbs"><a href="{{ $u::url('account/bookings') }}">{{ __('Bookings') }}</a> › {{ $booking->reference }}</p>
            @include('site.auth._errors')
            @include('site.account._ticket_card', ['status' => $booking->status])
            @if ($qr)@include('site.account._ticket_share')@endif
            @if ($booking->puja?->booking_instructions && $booking->isLive())<p class="card" style="margin-top:16px">ℹ️ {{ strip_tags($booking->puja->booking_instructions) }}</p>@endif
            <div class="ticket-share">
                @if ($booking->status === \App\Enums\BookingStatus::PendingPayment)
                    <form method="post" action="{{ $u::url('account/bookings/'.$booking->reference.'/pay') }}">@csrf<button class="btn primary" type="submit">{{ __('Pay now') }}</button></form>
                @endif
                @if ($booking->temple && $booking->temple->hasCoordinates())
                    <a class="btn" href="https://www.google.com/maps/dir/?api=1&destination={{ $booking->temple->latitude }},{{ $booking->temple->longitude }}" target="_blank" rel="noopener">🧭 {{ __('Directions') }}</a>
                @endif
                @if ($qr)<button class="btn" type="button" onclick="window.print()">🖨️ {{ __('Print') }}</button>@endif
                @if ($booking->canBeCancelledByDevotee())
                    <form method="post" action="{{ $u::url('account/bookings/'.$booking->reference.'/cancel') }}" onsubmit="return confirm('{{ __('Cancel this booking?') }}')">@csrf<button class="btn" type="submit">{{ __('Cancel booking') }}</button></form>
                @endif
            </div>
            @if ($booking->status === \App\Enums\BookingStatus::PendingPayment)
                <p class="muted" style="font-size:.88rem">{{ __('Not paid yet. If money left your account, the booking confirms itself within a few minutes; refresh this page.') }}</p>
            @endif
        </div>
    </div>
@endsection
