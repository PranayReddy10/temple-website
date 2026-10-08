@extends('site.layout')

@php($u = \App\Support\Seo::class)

@section('content')
    <div class="acct">
        @include('site.account._nav')
        <div>
            <p class="crumbs"><a href="{{ $u::url('account/bookings') }}">{{ __('Bookings') }}</a> › {{ $booking->reference }}</p>
            <h1>{{ $booking->puja?->name }}</h1>
            @include('site.auth._errors')
            <div class="card" style="display:grid;grid-template-columns:1fr auto;gap:16px;align-items:start">
                <div>
                    <p style="margin:0"><span class="pill {{ $booking->status->value }}">{{ $booking->status->getLabel() }}</span></p>
                    <p><b>{{ $booking->temple?->name }}</b>{{ $booking->temple?->city ? ', '.$booking->temple->city : '' }}</p>
                    <p>📅 {{ $booking->booked_for?->format('l, j F Y') }}@if ($booking->slotLabel())<br>🕰️ {{ $booking->slotLabel() }}@endif</p>
                    <p>👥 {{ $booking->people }} · {{ $booking->devotee_name }}@if ($booking->gotram) · {{ __('Gotram') }}: {{ $booking->gotram }}@endif @if ($booking->nakshatram) · {{ $booking->nakshatram }}@endif</p>
                    <p>💳 {{ $booking->amountLabel() }}</p>
                    <p class="muted" style="font-size:.85rem">{{ __('Reference') }} {{ $booking->reference }}</p>
                </div>
                @if ($qr)
                    <div style="text-align:center">
                        <div class="qr" style="width:200px;max-width:42vw;background:#fff;border-radius:12px;padding:6px">{!! $qr !!}</div>
                        <small class="muted">{{ __('Show this at the temple counter') }}</small>
                    </div>
                @endif
            </div>
            @if ($booking->puja?->booking_instructions && $booking->isLive())<p class="card">{{ strip_tags($booking->puja->booking_instructions) }}</p>@endif
            <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:16px">
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
