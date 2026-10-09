@extends('site.layout')

@section('content')
    <div class="acct">
        @include('site.account._nav')
        <div>
            <h1>{{ __('My offerings') }}</h1>
            @if ($donations->isEmpty())
                <p class="card">{{ __('No offerings yet. Temples that take online offerings have a Donate button on their page.') }}</p>
            @else
                <div class="list">
                    @foreach ($donations as $d)
                        <div class="card item">
                            <span>
                                <b>{{ $d->amountLabel() }} · {{ $d->temple?->name }}</b>
                                <small>{{ $d->purposeLabel() }} · {{ ($d->paid_at ?? $d->created_at)?->format('j M Y') }} · {{ __('Receipt') }} {{ $d->reference }}</small>
                            </span>
                            <span class="pill {{ $d->status }}">{{ $d->statusLabel() }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
