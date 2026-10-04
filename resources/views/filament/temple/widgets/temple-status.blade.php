<x-filament-widgets::widget>
    @if ($temple)
        <x-filament::section>
            <div class="ds-status">
                <div class="ds-status__cover">
                    @if ($cover)
                        <img src="{{ $cover }}" alt="" loading="lazy">
                    @else
                        <x-filament::icon icon="heroicon-o-building-library" class="ds-status__placeholder" />
                    @endif
                </div>
                <div class="ds-status__body">
                    <div class="ds-status__title">
                        <h2>{{ $temple->name }}</h2>
                        <x-filament::badge :color="$published ? 'success' : 'warning'">{{ $statusLabel }}</x-filament::badge>
                        @if ($temple->deity)
                            <x-filament::badge color="gray">{{ $temple->deity->name }}</x-filament::badge>
                        @endif
                    </div>
                    <p class="ds-status__muted">{{ collect([$temple->address ?: null, $temple->city])->filter()->implode(', ') ?: 'Add the address in the temple details.' }}</p>
                    @unless ($published)
                        <p class="ds-status__muted">Not on the app and website yet: our team publishes it after checking the details.</p>
                    @endunless
                    <div class="ds-status__links">
                        <x-filament::link :href="$editUrl" icon="heroicon-m-pencil-square">Edit temple details</x-filament::link>
                        @if ($publicUrl)
                            <x-filament::link :href="$publicUrl" target="_blank" icon="heroicon-m-arrow-top-right-on-square">See it on the website</x-filament::link>
                        @endif
                    </div>
                </div>
                <div @class(['ds-pay', 'ds-pay--' . $payments])>
                    <div class="ds-pay__head">
                        <x-filament::icon :icon="match ($payments) { 'approved' => 'heroicon-o-check-badge', 'pending' => 'heroicon-o-clock', 'rejected' => 'heroicon-o-exclamation-triangle', default => 'heroicon-o-banknotes' }" class="ds-pay__icon" />
                        <div>
                            <strong>Online payments</strong>
                            <div>{{ $paymentsLabel }}</div>
                        </div>
                    </div>
                    @if ($rejection)
                        <p class="ds-pay__note">Why: {{ $rejection }}</p>
                    @elseif ($payments === 'missing')
                        <p class="ds-pay__note">Add the bank account and the owner's documents so devotees can book paid sevas and give to the online hundi.</p>
                    @endif
                    <p class="ds-pay__note">Online hundi: <strong>{{ $hundi ? 'on' : 'off' }}</strong> · Platform fee {{ rtrim(rtrim(number_format($feePercent, 2), '0'), '.') }}% on sevas and tickets, {{ rtrim(rtrim(number_format($donationFeePercent, 2), '0'), '.') }}% on hundi.</p>
                    <x-filament::button :href="$bankUrl" tag="a" size="sm" :color="$payments === 'approved' ? 'gray' : 'primary'" icon="heroicon-m-building-library">
                        {{ $payments === 'approved' ? 'Bank details' : ($owner ? 'Set up payments' : 'See payment details') }}
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @endif

    <style>
        .ds-status { display: grid; grid-template-columns: 7rem 1fr; gap: 1.25rem; align-items: start; }
        @media (min-width: 64rem) { .ds-status { grid-template-columns: 8rem 1fr 22rem; } }
        .ds-status__cover { width: 7rem; height: 7rem; border-radius: .75rem; overflow: hidden; background: rgb(var(--gray-100, 245 245 244)); display: grid; place-items: center; }
        @media (min-width: 64rem) { .ds-status__cover { width: 8rem; height: 8rem; } }
        .ds-status__cover img { width: 100%; height: 100%; object-fit: cover; }
        .ds-status__placeholder { width: 2.5rem; height: 2.5rem; opacity: .4; }
        .ds-status__title { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
        .ds-status__title h2 { font-size: 1.35rem; font-weight: 700; line-height: 1.3; margin-right: .25rem; }
        .ds-status__muted { margin-top: .35rem; font-size: .875rem; opacity: .75; }
        .ds-status__links { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: .75rem; }
        .ds-pay { grid-column: 1 / -1; border-radius: .75rem; padding: 1rem; border: 1px solid rgba(120, 113, 108, .25); display: grid; gap: .6rem; }
        @media (min-width: 64rem) { .ds-pay { grid-column: auto; } }
        .ds-pay__head { display: flex; gap: .75rem; align-items: center; }
        .ds-pay__icon { width: 1.75rem; height: 1.75rem; flex: none; }
        .ds-pay__note { font-size: .8125rem; opacity: .85; }
        .ds-pay--approved { border-color: rgba(16, 185, 129, .45); background: rgba(16, 185, 129, .07); }
        .ds-pay--approved .ds-pay__icon { color: rgb(5, 150, 105); }
        .ds-pay--pending { border-color: rgba(201, 162, 39, .5); background: rgba(201, 162, 39, .08); }
        .ds-pay--pending .ds-pay__icon { color: #a37f12; }
        .ds-pay--rejected, .ds-pay--missing { border-color: rgba(155, 27, 48, .4); background: rgba(155, 27, 48, .06); }
        .ds-pay--rejected .ds-pay__icon, .ds-pay--missing .ds-pay__icon { color: #9b1b30; }
        .dark .ds-pay--rejected .ds-pay__icon, .dark .ds-pay--missing .ds-pay__icon { color: #e0455e; }
        .dark .ds-pay--pending .ds-pay__icon { color: #d9b23a; }
    </style>
</x-filament-widgets::widget>
