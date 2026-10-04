<x-filament-panels::page>
    @include('filament.temple.partials.styles')
    @include('filament.temple.partials.temple-picker')

    <x-filament::section>
        <div class="ds-qr">
            <div class="ds-qr__code" aria-label="Check-in QR code for {{ $temple->name }}">{!! $svg !!}</div>
            <div class="ds-qr__body">
                <h2 class="ds-qr__title">Check in with Darshan Saathi</h2>
                <p>Print this at the entrance. Devotees scan it with the app, or with any phone camera, to check in at {{ $temple->name }} and stamp their temple passport. A phone without the app opens the temple's page and is offered the app.</p>
                @unless ($published)
                    <p class="ds-muted">The temple is not published yet: the code works once our team publishes it.</p>
                @endunless
                <div class="ds-qr__actions">
                    <x-filament::button tag="a" :href="$printUrl" target="_blank" icon="heroicon-m-printer">Print the A4 poster</x-filament::button>
                    <x-filament::button tag="a" :href="$downloadUrl" color="gray" icon="heroicon-m-arrow-down-tray">Download the code (SVG)</x-filament::button>
                </div>
                <div x-data="{ copied: false }" class="ds-qr__link">
                    <code>{{ $url }}</code>
                    <x-filament::link tag="button" type="button" x-on:click="navigator.clipboard.writeText(@js($url)); copied = true; setTimeout(() => copied = false, 2000)" icon="heroicon-m-clipboard">
                        <span x-text="copied ? 'Copied' : 'Copy the link'">Copy the link</span>
                    </x-filament::link>
                </div>
            </div>
        </div>
    </x-filament::section>
    <style>
        .ds-qr { display: grid; gap: 1.5rem; align-items: center; }
        @media (min-width: 48rem) { .ds-qr { grid-template-columns: 16rem 1fr; } }
        .ds-qr__code { background: #fff; padding: 1rem; border-radius: .75rem; border: 1px solid rgba(120, 113, 108, .25); max-width: 16rem; }
        .ds-qr__code svg { width: 100%; height: auto; display: block; }
        .ds-qr__title { font-size: 1.25rem; font-weight: 700; margin-bottom: .5rem; }
        .ds-qr__body > p { margin-bottom: .5rem; font-size: .9375rem; }
        .ds-qr__actions { display: flex; flex-wrap: wrap; gap: .75rem; margin: 1rem 0; }
        .ds-qr__link { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; font-size: .8125rem; }
        .ds-qr__link code { word-break: break-all; opacity: .8; }
    </style>
</x-filament-panels::page>
