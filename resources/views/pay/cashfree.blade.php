@extends('pay.layout')
@section('title', $payment->plan?->name ?? 'Payment')
@section('body')
<main class="card">
    <h1>{{ $payment->plan?->name }}</h1>
    <p class="amount">{{ $payment->amountLabel() }}</p>
    <p class="muted">Secure payment by Cashfree.</p>
    <div class="spinner"></div>
</main>
<script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
<script>
    Cashfree({ mode: {{ \Illuminate\Support\Js::from($mode) }} }).checkout({ paymentSessionId: {{ \Illuminate\Support\Js::from($session_id) }}, redirectTarget: '_self' });
</script>
@endsection
