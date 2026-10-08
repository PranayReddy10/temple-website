@extends('site.layout')

@section('content')
    <div class="acct">
        @include('site.account._nav')
        <div>
            <h1>{{ __('My temple passport') }}</h1>
            <div class="card" style="display:grid;grid-template-columns:1fr auto;gap:16px;align-items:center">
                <div>
                    <p style="margin-top:0"><b style="font-size:1.4rem;color:var(--kumkum)">{{ $stamps }}</b> {{ __('verified stamps') }} · {{ $visits->count() }} {{ __('visits recorded') }}</p>
                    <p class="muted" style="margin-bottom:0">{{ __('Stamps are collected by checking in at the temple: scan the temple’s QR code with your phone camera, or show this passport code at the temple counter.') }}</p>
                </div>
                <div style="width:150px;background:#fff;border-radius:12px;padding:6px">{!! $qr !!}</div>
            </div>
            <h2>{{ __('Visits') }}</h2>
            @if ($visits->isEmpty())
                <p class="card">{{ __('No visits yet.') }}</p>
            @else
                <div class="list">
                    @foreach ($visits as $v)
                        <a class="card item" href="{{ $v->temple ? \App\Support\Seo::url('temples/'.$v->temple->slug) : '#' }}">
                            <span><b>{{ $v->temple?->name }}</b><small>{{ $v->dateLabel() }}@if ($v->temple?->state) · {{ $v->temple->state->name }}@endif</small></span>
                            <span class="pill {{ $v->is_verified ? 'verified' : '' }}">{{ $v->is_verified ? __('Stamped') : __('Recorded') }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
