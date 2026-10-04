<x-filament-panels::page>
    @include('filament.temple.partials.styles')
    @include('filament.temple.partials.temple-picker')

    <x-filament::section>
        <x-slot name="heading">{{ $isToday ? 'Today' : \Illuminate\Support\Carbon::parse($day['date'])->format('l, j F Y') }}</x-slot>
        <x-slot name="description">Sevas booked for the day, event tickets for the day, and hundi given that day.</x-slot>
        <x-slot name="afterHeader">
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="date" aria-label="Day" />
            </x-filament::input.wrapper>
        </x-slot>

        <div class="ds-kpis">
            <div class="ds-kpi ds-kpi--lead">
                <div class="ds-kpi__label">Total paid</div>
                <div class="ds-kpi__value">{{ $day['total'] }}</div>
                <div class="ds-kpi__note">sevas, tickets and hundi</div>
            </div>
            <div class="ds-kpi">
                <div class="ds-kpi__label">Sevas</div>
                <div class="ds-kpi__value">{{ $day['amount'] }}</div>
                <div class="ds-kpi__note">{{ $day['bookings'] }} bookings · {{ $day['people'] }} people</div>
            </div>
            <div class="ds-kpi">
                <div class="ds-kpi__label">At the counter</div>
                <div class="ds-kpi__value">{{ $day['received'] }} / {{ $day['bookings'] }}</div>
                <div class="ds-kpi__note">{{ $day['to_receive'] }} still to come</div>
            </div>
            <div class="ds-kpi">
                <div class="ds-kpi__label">Event tickets</div>
                <div class="ds-kpi__value">{{ $day['tickets']['amount'] }}</div>
                <div class="ds-kpi__note">{{ $day['tickets']['count'] }} tickets · {{ $day['tickets']['people'] }} people</div>
            </div>
            <div class="ds-kpi">
                <div class="ds-kpi__label">Online hundi</div>
                <div class="ds-kpi__value">{{ $day['donations']['amount'] }}</div>
                <div class="ds-kpi__note">{{ $day['donations']['count'] }} {{ str('gift')->plural($day['donations']['count']) }}</div>
            </div>
        </div>

        @if (count($day['by_seva']))
            <table class="ds-rows" style="margin-top: 1rem">
                <thead><tr><th>Seva</th><th class="num">Bookings</th><th class="num">People</th><th class="num">Paid</th></tr></thead>
                <tbody>
                    @foreach ($day['by_seva'] as $row)
                        <tr><td>{{ $row['seva'] }}</td><td class="num">{{ $row['bookings'] }}</td><td class="num">{{ $row['people'] }}</td><td class="num">{{ $row['amount'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        @if ($day['awaiting_payment'] || $day['cancelled'])
            <p class="ds-muted" style="margin-top: .75rem">{{ $day['awaiting_payment'] }} waiting for payment · {{ $day['cancelled'] }} cancelled or refunded{{ $day['refunded_paise'] ? ' (' . $rupees($day['refunded_paise']) . ' refunded)' : '' }}.</p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Over time">
        <table class="ds-rows">
            <thead><tr><th></th><th class="num">Sevas</th><th class="num">Event tickets</th><th class="num">Online hundi</th><th class="num">Total</th></tr></thead>
            <tbody>
                @foreach ($periods as $label => $p)
                    <tr>
                        <td><strong>{{ $label }}</strong></td>
                        <td class="num">{{ $p['amount'] }}<div class="ds-muted">{{ $p['bookings'] }} bookings</div></td>
                        <td class="num">{{ $p['tickets']['amount'] }}<div class="ds-muted">{{ $p['tickets']['count'] }} tickets</div></td>
                        <td class="num">{{ $p['donations']['amount'] }}<div class="ds-muted">{{ $p['donations']['count'] }} {{ str('gift')->plural($p['donations']['count']) }}</div></td>
                        <td class="num"><strong>{{ $p['total'] }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <div class="ds-grid ds-grid--2">
        <x-filament::section heading="Settlement">
            <x-slot name="description">Money devotees paid is held by the platform and paid to the temple's bank, less the platform fee ({{ $percent($balance['fee_percent']) }} on sevas and tickets, {{ $percent($balance['donation_fee_percent']) }} on hundi).</x-slot>
            <div class="ds-kpis">
                <div class="ds-kpi ds-kpi--lead">
                    <div class="ds-kpi__label">Ready to pay out</div>
                    <div class="ds-kpi__value">{{ $rupees($balance['ready']['net_paise']) }}</div>
                    <div class="ds-kpi__note">up to {{ \Illuminate\Support\Carbon::parse($balance['cutoff'])->format('j M') }} · {{ $rupees($balance['ready']['gross_paise']) }} less {{ $rupees($balance['ready']['fee_paise']) }} fee</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi__label">Being paid</div>
                    <div class="ds-kpi__value">{{ $rupees($balance['in_payout']['net_paise']) }}</div>
                    <div class="ds-kpi__note">{{ $balance['in_payout']['settlements'] }} payouts on the way</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi__label">Paid to date</div>
                    <div class="ds-kpi__value">{{ $rupees($balance['paid']['net_paise']) }}</div>
                    <div class="ds-kpi__note">{{ $balance['paid']['settlements'] }} payouts{{ $balance['paid']['last_paid_at'] ? ' · last ' . \Illuminate\Support\Carbon::parse($balance['paid']['last_paid_at'])->timezone(\App\Support\DevotionalClock::timezone())->format('j M Y') : '' }}</div>
                </div>
                <div class="ds-kpi">
                    <div class="ds-kpi__label">For days ahead</div>
                    <div class="ds-kpi__value">{{ $rupees($balance['upcoming']['gross_paise']) }}</div>
                    <div class="ds-kpi__note">{{ $balance['upcoming']['bookings'] }} paid for days still to come</div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section heading="Paid to">
            @if ($account?->isComplete())
                <dl class="ds-dl">
                    @if ($account->account_name)
                        <dt>Account</dt><dd>{{ $account->account_name }} · {{ $account->maskedAccountNumber() }}</dd>
                        <dt>IFSC</dt><dd>{{ $account->ifsc }}{{ $account->bank_name ? ' · ' . $account->bank_name : '' }}</dd>
                    @endif
                    @if ($account->upi_id)
                        <dt>UPI</dt><dd>{{ $account->upi_id }}</dd>
                    @endif
                    <dt>Status</dt><dd>{{ $account->kycStatusLabel() }}</dd>
                </dl>
            @else
                <p class="ds-muted">No bank account yet. {{ $owner ? 'Add it so settlements can be paid.' : 'The temple\'s owner adds it.' }}</p>
            @endif
            <div style="margin-top: 1rem">
                <x-filament::button tag="a" :href="$bankUrl" size="sm" color="gray" icon="heroicon-m-building-library">Bank & verification</x-filament::button>
            </div>
        </x-filament::section>
    </div>

    <x-filament::section heading="Recent payouts">
        <x-slot name="afterHeader"><x-filament::link :href="$settlementsUrl">See all</x-filament::link></x-slot>
        @if ($recent->isEmpty())
            <p class="ds-muted">No payouts yet. Settlements are made regularly for everything paid up to the day before.</p>
        @else
            <table class="ds-rows">
                <thead><tr><th>Reference</th><th>Period</th><th>Items</th><th>Status</th><th class="num">To the temple</th></tr></thead>
                <tbody>
                    @foreach ($recent as $s)
                        <tr>
                            <td style="font-family: ui-monospace, monospace">{{ $s->reference }}</td>
                            <td>{{ $s->periodLabel() }}</td>
                            <td>{{ $s->itemsLabel() }}</td>
                            <td><x-filament::badge :color="$s->status === \App\Models\TempleSettlement::PAID ? 'success' : 'warning'">{{ $s->statusLabel() }}</x-filament::badge></td>
                            <td class="num"><strong>{{ $rupees($s->net_paise) }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
