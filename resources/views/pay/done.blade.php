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
        @if ($payment->isForBooking())
            <p class="muted">Your booking for {{ $payment->description() }} is confirmed. Its code is in the app under My seva bookings; show it at the temple counter.</p>
        @else
            <p class="muted">{{ $payment->description() }} is active on your account. You can return to the app.</p>
        @endif
    @elseif ($failed)
        <div class="mark">!</div>
        <h1>Payment not completed</h1>
        <p class="muted">{{ $error ?? $payment->failure_reason ?? 'No money was taken.' }} If money did leave your account, it is confirmed or refunded automatically.</p>
    @else
        <div class="spinner"></div>
        <h1>Confirming your payment…</h1>
        <p class="muted">This can take a minute. You can return to the app; {{ $payment->isForBooking() ? 'your booking is confirmed' : 'your plan switches on' }} as soon as the bank confirms.</p>
        <script>setTimeout(function () { location.reload(); }, 5000);</script>
    @endif
    {{-- Paid from the website: back to it, which shows this result. The
         tab the website opened closes if the browser allows; otherwise it
         goes back to the website itself. --}}
    @php($back = rtrim((string) config('brand.website'), '/').'/?payment='.$payment->uuid)
    @if ($paid || $failed)
        <p style="margin-top:18px"><a class="button" href="{{ $back }}">Return to {{ config('brand.name') }}</a></p>
        @if (($payment->meta['client'] ?? null) === 'web')
            <p class="muted" style="font-size:.85rem">Taking you back…</p>
            <script>
                setTimeout(function () {
                    try { window.close(); } catch (e) {}
                    // Still open: the browser did not let the tab close.
                    setTimeout(function () { location.replace(@json($back)); }, 600);
                }, 2000);
            </script>
        @endif
    @endif
</main>
@endsection
