@extends('pay.layout')
@section('title', $payment->description() ?? 'Payment')
@section('body')
{{-- A gateway that takes a signed form post (PayU): sent on load. --}}
<main class="card">
    <h1>{{ $payment->description() }}</h1>
    <p class="amount">{{ $payment->amountLabel() }}</p>
    <div class="spinner"></div>
    <form id="go" method="POST" action="{{ $action }}">
        @foreach ($fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <noscript><button type="submit">Continue to payment</button></noscript>
    </form>
</main>
<script>document.getElementById('go').submit();</script>
@endsection
