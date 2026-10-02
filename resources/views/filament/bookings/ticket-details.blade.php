{{--
    One event ticket ("I'll join" or paid) at the counter: the event, the
    day, who and how many, and what was paid.
--}}
@php($tz = \App\Support\DevotionalClock::timezone())
<div style="display:grid;gap:.75rem">
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <x-filament::badge :color="$ticket->status->getColor()" :icon="$ticket->status->getIcon()">{{ $ticket->status->getLabel() }}</x-filament::badge>
        <x-filament::badge color="gray" icon="heroicon-o-musical-note">{{ $ticket->isFree() ? 'Event registration' : 'Event ticket' }}</x-filament::badge>
        @if ($ticket->isVerified())
            <span style="font-size:.85rem;opacity:.8">by {{ $ticket->verifier?->name ?? 'the temple' }} · {{ $ticket->verified_at?->timezone($tz)->format('d M Y, H:i') }}</span>
        @elseif ($ticket->cancel_reason)
            <span style="font-size:.85rem;opacity:.8">{{ $ticket->cancel_reason }}</span>
        @endif
    </div>

    <dl style="display:grid;grid-template-columns:auto 1fr;gap:.35rem .9rem;font-size:.9rem;margin:0">
        <dt style="opacity:.65">Event</dt><dd style="margin:0;font-weight:600">{{ $ticket->event?->title ?? '—' }} <span style="opacity:.65;font-weight:400">· {{ $ticket->event?->type?->getLabel() }}</span></dd>
        @if ($ticket->event?->group_name)
            <dt style="opacity:.65">Led by</dt><dd style="margin:0">{{ $ticket->event->group_name }}</dd>
        @endif
        <dt style="opacity:.65">Temple</dt><dd style="margin:0">{{ $ticket->temple?->name }}{{ $ticket->temple?->city ? ', '.$ticket->temple->city : '' }}</dd>
        <dt style="opacity:.65">Day</dt><dd style="margin:0;font-weight:600">{{ $ticket->occurs_on?->format('l, d M Y') }}@if ($ticket->event?->starts_at) · {{ substr((string) $ticket->event->starts_at, 0, 5) }}@endif</dd>
        <dt style="opacity:.65">People</dt><dd style="margin:0">{{ $ticket->people }}</dd>
        <dt style="opacity:.65">In the name of</dt><dd style="margin:0">{{ $ticket->devotee_name }}@if ($ticket->devotee_phone) · {{ $ticket->devotee_phone }}@endif</dd>
        <dt style="opacity:.65">Amount</dt>
        <dd style="margin:0">
            <strong>{{ $ticket->amountLabel() }}</strong>
            @if ($ticket->payment)
                · {{ ucfirst($ticket->payment->status) }} via {{ \App\Models\Payment::GATEWAYS[$ticket->payment->gateway] ?? $ticket->payment->gateway }}
            @endif
        </dd>
        <dt style="opacity:.65">Booked</dt><dd style="margin:0">{{ $ticket->created_at?->timezone($tz)->format('d M Y, H:i') }}</dd>
    </dl>
</div>
