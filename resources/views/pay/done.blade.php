@extends('pay.layout')
@section('title', 'Payment')
@section('body')
@php($paid = $payment->isPaid())
@php($failed = $error !== null || in_array($payment->status, ['failed', 'refunded'], true))
{{-- The app's in-app browser closes itself when it reaches this page. --}}
<main @class(['card', 'bad' => $failed])>
    @if ($paid)
        <div class="mark">✓</div>
        <h1>Thank you</h1>
        <p class="muted">{{ $payment->plan?->name }} is active on your account. You can return to the app.</p>
    @elseif ($failed)
        <div class="mark">!</div>
        <h1>Payment not completed</h1>
        <p class="muted">{{ $error ?? $payment->failure_reason ?? 'No money was taken.' }} If money did leave your account, it is confirmed or refunded automatically.</p>
    @else
        <div class="spinner"></div>
        <h1>Confirming your payment…</h1>
        <p class="muted">This can take a minute. You can return to the app; your plan switches on as soon as the bank confirms.</p>
        <script>setTimeout(function () { location.reload(); }, 5000);</script>
    @endif
</main>
@endsection
