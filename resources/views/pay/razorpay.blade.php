@extends('pay.layout')
@section('title', $payment->plan?->name ?? 'Payment')
@section('body')
<main class="card">
    <h1>{{ $payment->plan?->name }}</h1>
    <p class="amount">{{ $payment->amountLabel() }}</p>
    <p class="muted">Secure payment by Razorpay: UPI, cards, net banking and wallets.</p>
    <div class="spinner" id="wait"></div>
    <button type="button" id="pay" hidden>Pay {{ $payment->amountLabel() }}</button>
    <form id="back" method="POST" action="{{ route('pay.return', ['payment' => $payment, 'gateway' => 'razorpay']) }}">
        <input type="hidden" name="razorpay_payment_id">
        <input type="hidden" name="razorpay_order_id">
        <input type="hidden" name="razorpay_signature">
    </form>
</main>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
    (function () {
        var back = document.getElementById('back');
        var rzp = new Razorpay({
            key: {{ \Illuminate\Support\Js::from($key) }},
            order_id: {{ \Illuminate\Support\Js::from($order_id) }},
            amount: {{ $payment->amount_paise }},
            currency: {{ \Illuminate\Support\Js::from($payment->currency) }},
            name: {{ \Illuminate\Support\Js::from(config('brand.name')) }},
            description: {{ \Illuminate\Support\Js::from($payment->plan?->name) }},
            prefill: { name: {{ \Illuminate\Support\Js::from($devotee->name) }}, email: {{ \Illuminate\Support\Js::from($devotee->email) }}, contact: {{ \Illuminate\Support\Js::from($devotee->phone) }} },
            theme: { color: {{ \Illuminate\Support\Js::from(config('brand.colors.kumkum.hex')) }} },
            handler: function (r) {
                back.razorpay_payment_id.value = r.razorpay_payment_id;
                back.razorpay_order_id.value = r.razorpay_order_id;
                back.razorpay_signature.value = r.razorpay_signature;
                back.submit();
            },
            modal: { ondismiss: function () { window.location = {{ \Illuminate\Support\Js::from(route('pay.return', ['payment' => $payment, 'gateway' => 'razorpay', 'cancelled' => 1])) }}; } }
        });
        document.getElementById('wait').hidden = true;
        var pay = document.getElementById('pay');
        pay.hidden = false;
        pay.onclick = function () { rzp.open(); };
        rzp.open();
    })();
</script>
@endsection
