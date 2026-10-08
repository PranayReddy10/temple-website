@extends('site.layout')

@section('content')
    <div class="acct">
        @include('site.account._nav')
        <div>
            <p class="crumbs"><a href="{{ \App\Support\Seo::url('account/bookings') }}">{{ __('Bookings') }}</a> › {{ $ticket->reference }}</p>
            <h1>{{ $ticket->event?->title }}</h1>
            <div class="card" style="display:grid;grid-template-columns:1fr auto;gap:16px;align-items:start">
                <div>
                    <p style="margin:0"><span class="pill {{ $ticket->status->value }}">{{ $ticket->status->getLabel() }}</span></p>
                    <p><b>{{ $ticket->temple?->name }}</b></p>
                    <p>📅 {{ $ticket->occurs_on?->format('l, j F Y') }}</p>
                    <p>👥 {{ $ticket->people }} · {{ $ticket->devotee_name }}</p>
                    <p>💳 {{ $ticket->amountLabel() }}</p>
                    <p class="muted" style="font-size:.85rem">{{ __('Reference') }} {{ $ticket->reference }}</p>
                </div>
                @if ($qr)
                    <div style="text-align:center">
                        <div class="qr" style="width:200px;max-width:42vw;background:#fff;border-radius:12px;padding:6px">{!! $qr !!}</div>
                        <small class="muted">{{ __('Show this at the temple') }}</small>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
