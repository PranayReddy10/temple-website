{{--
    One seva booking in full: the code as the devotee's phone shows it, who
    it is for, and where it stands. Opened from the bookings lists.
--}}
@php($tz = \App\Support\DevotionalClock::timezone())
<div style="display:grid;gap:1rem;grid-template-columns:minmax(0,1fr) 180px;align-items:start">
    <div style="display:grid;gap:.75rem">
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
            <x-filament::badge :color="$booking->status->getColor()" :icon="$booking->status->getIcon()">{{ $booking->status->getLabel() }}</x-filament::badge>
            @if ($booking->isVerified())
                <span style="font-size:.85rem;opacity:.8">by {{ $booking->verifier?->name ?? 'the temple' }} · {{ $booking->verified_at?->timezone($tz)->format('d M Y, H:i') }}</span>
            @elseif ($booking->cancel_reason)
                <span style="font-size:.85rem;opacity:.8">{{ $booking->cancel_reason }}</span>
            @endif
        </div>

        <dl style="display:grid;grid-template-columns:auto 1fr;gap:.35rem .9rem;font-size:.9rem;margin:0">
            <dt style="opacity:.65">Seva</dt><dd style="margin:0;font-weight:600">{{ $booking->puja?->name ?? '—' }} <span style="opacity:.65;font-weight:400">· {{ $booking->puja?->kind?->getLabel() }}</span></dd>
            <dt style="opacity:.65">Temple</dt><dd style="margin:0">{{ $booking->temple?->name }}{{ $booking->temple?->city ? ', '.$booking->temple->city : '' }}</dd>
            <dt style="opacity:.65">Day</dt><dd style="margin:0;font-weight:600">{{ $booking->booked_for?->format('l, d M Y') }}@if ($booking->puja?->starts_at) · {{ substr((string) $booking->puja->starts_at, 0, 5) }}@endif</dd>
            <dt style="opacity:.65">People</dt><dd style="margin:0">{{ $booking->people }}</dd>
            <dt style="opacity:.65">In the name of</dt><dd style="margin:0">{{ $booking->devotee_name }}@if ($booking->devotee_phone) · {{ $booking->devotee_phone }}@endif</dd>
            @if ($booking->gotram || $booking->nakshatram)
                <dt style="opacity:.65">Sankalpam</dt><dd style="margin:0">{{ collect([$booking->gotram ? 'Gotram '.$booking->gotram : null, $booking->nakshatram ? 'Nakshatram '.$booking->nakshatram : null])->filter()->implode(' · ') }}</dd>
            @endif
            @if ($booking->note)
                <dt style="opacity:.65">Note</dt><dd style="margin:0">{{ $booking->note }}</dd>
            @endif
            <dt style="opacity:.65">Amount</dt>
            <dd style="margin:0">
                <strong>{{ $booking->amountLabel() }}</strong>
                @if ($booking->payment)
                    · {{ ucfirst($booking->payment->status) }} via {{ \App\Models\Payment::GATEWAYS[$booking->payment->gateway] ?? $booking->payment->gateway }}
                    @if ($booking->payment->gateway_payment_id)<br><span style="font-family:monospace;font-size:.8rem;opacity:.8">{{ $booking->payment->gateway_payment_id }}</span>@endif
                @endif
            </dd>
            <dt style="opacity:.65">Account</dt><dd style="margin:0">{{ $booking->devotee?->name }} <span style="opacity:.65">{{ $booking->devotee?->email ?? $booking->devotee?->phone }}</span></dd>
            <dt style="opacity:.65">Booked</dt><dd style="margin:0">{{ $booking->created_at?->timezone($tz)->format('d M Y, H:i') }}</dd>
        </dl>

        @if ($booking->puja?->booking_instructions)
            <p style="font-size:.85rem;opacity:.8;margin:0"><strong>Told to the devotee:</strong> {{ $booking->puja->booking_instructions }}</p>
        @endif
    </div>

    <div style="text-align:center">
        <div style="background:#fff;padding:8px;border-radius:12px;display:inline-block">
            {!! \App\Support\BookingQr::svg($booking) !!}
        </div>
        <p style="font-family:monospace;font-size:1.05rem;font-weight:700;letter-spacing:.08em;margin:.5rem 0 0">{{ $booking->reference }}</p>
        <p style="font-size:.7rem;opacity:.6;margin:.25rem 0 0">Read out at the counter</p>
    </div>
</div>
