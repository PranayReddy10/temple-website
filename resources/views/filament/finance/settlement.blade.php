{{--
    One settlement in full: the money, where it goes, and every booking it
    covers. Staff also see the account number, to make the transfer.
--}}
@php($tz = \App\Support\DevotionalClock::timezone())
@php($rs = fn (int $p): string => \App\Models\TempleSettlement::rupees($p))
<div style="display:grid;gap:1rem">
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <x-filament::badge :color="match ($settlement->status) { 'paid' => 'success', 'pending' => 'warning', default => 'gray' }">{{ $settlement->statusLabel() }}</x-filament::badge>
        @if ($settlement->isPaid())
            <span style="font-size:.85rem;opacity:.8">{{ $settlement->paid_at?->timezone($tz)->format('d M Y, H:i') }} · {{ \App\Models\TempleSettlement::METHODS[$settlement->method] ?? $settlement->method }} @if ($settlement->transaction_ref) · <span style="font-family:monospace">{{ $settlement->transaction_ref }}</span>@endif @if ($staff && $settlement->payer) · by {{ $settlement->payer->name }}@endif</span>
        @elseif ($settlement->cancel_reason)
            <span style="font-size:.85rem;opacity:.8">{{ $settlement->cancel_reason }}</span>
        @endif
    </div>

    <dl style="display:grid;grid-template-columns:auto 1fr;gap:.35rem .9rem;font-size:.9rem;margin:0">
        <dt style="opacity:.65">Seva days</dt><dd style="margin:0">{{ $settlement->periodLabel() }}</dd>
        <dt style="opacity:.65">Bookings</dt><dd style="margin:0">{{ $settlement->bookings_count }}</dd>
        <dt style="opacity:.65">Paid by devotees</dt><dd style="margin:0">{{ $rs($settlement->gross_paise) }}</dd>
        <dt style="opacity:.65">Platform fee</dt><dd style="margin:0">{{ $rs($settlement->fee_paise) }} <span style="opacity:.65">({{ rtrim(rtrim(number_format((float) $settlement->fee_percent, 2), '0'), '.') }}%)</span></dd>
        <dt style="opacity:.65">To the temple</dt><dd style="margin:0;font-weight:700;font-size:1.05rem">{{ $rs($settlement->net_paise) }}</dd>
        @if ($staff)
            <dt style="opacity:.65">Pay to</dt>
            <dd style="margin:0">
                @if ($payout)
                    {{ $payout['account_name'] ?? '' }}
                    @if (! empty($payout['account_number']))<br><span style="font-family:monospace">{{ $payout['account_number'] }}</span> · {{ $payout['ifsc'] ?? '' }} @if (! empty($payout['bank_name'])) · {{ $payout['bank_name'] }}@endif @endif
                    @if (! empty($payout['upi_id']))<br>UPI <span style="font-family:monospace">{{ $payout['upi_id'] }}</span>@endif
                    @if (empty($payout['verified']))<br><span style="color:#b45309">Not verified when prepared: confirm with the temple before transferring.</span>@endif
                @else
                    <span style="color:#b45309">No payout details when prepared. See the temple's current details under Temple balances.</span>
                @endif
            </dd>
            <dt style="opacity:.65">Prepared</dt><dd style="margin:0">{{ $settlement->created_at?->timezone($tz)->format('d M Y, H:i') }}@if ($settlement->creator) by {{ $settlement->creator->name }}@endif</dd>
        @endif
        @if ($settlement->note)
            <dt style="opacity:.65">Note</dt><dd style="margin:0">{{ $settlement->note }}</dd>
        @endif
    </dl>

    <div style="overflow-x:auto">
        <table style="width:100%;font-size:.85rem;border-collapse:collapse">
            <thead>
                <tr style="text-align:left;opacity:.65">
                    <th style="padding:.35rem .5rem">Day</th>
                    <th style="padding:.35rem .5rem">Seva</th>
                    <th style="padding:.35rem .5rem">Devotee</th>
                    <th style="padding:.35rem .5rem">Ref</th>
                    <th style="padding:.35rem .5rem;text-align:right">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($settlement->bookings as $b)
                    <tr style="border-top:1px solid rgba(127,127,127,.2)">
                        <td style="padding:.35rem .5rem;white-space:nowrap">{{ $b->booked_for?->format('d M Y') }}</td>
                        <td style="padding:.35rem .5rem">{{ $b->puja?->name }}</td>
                        <td style="padding:.35rem .5rem">{{ $b->devotee_name }} <span style="opacity:.65">· {{ $b->people }}</span></td>
                        <td style="padding:.35rem .5rem;font-family:monospace">{{ $b->reference }}</td>
                        <td style="padding:.35rem .5rem;text-align:right">{{ $b->amountLabel() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:.5rem;opacity:.65">{{ $settlement->status === 'cancelled' ? 'Cancelled: its bookings went back into the balance.' : 'No bookings.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
