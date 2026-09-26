{{--
    Scan a seva booking's code: the camera when the device has one, a text
    box for the reference when it does not. Shared by the temple portal and
    the admin panel. html5-qrcode is loaded only on this page.
--}}
@php($tz = \App\Support\DevotionalClock::timezone())
<x-filament-panels::page>
    <div
        x-data="{
            scanner: null,
            scanning: false,
            error: null,
            async start() {
                this.error = null;
                if (! window.Html5Qrcode) {
                    await new Promise((resolve, reject) => {
                        const s = document.createElement('script');
                        s.src = 'https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js';
                        s.onload = resolve; s.onerror = reject;
                        document.head.appendChild(s);
                    }).catch(() => { this.error = 'The scanner could not be loaded. Type the reference instead.'; });
                    if (! window.Html5Qrcode) return;
                }
                this.scanner = new Html5Qrcode('booking-qr-reader');
                this.scanning = true;
                try {
                    await this.scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: 240 }, (text) => {
                        this.stop();
                        $wire.scan(text);
                    });
                } catch (e) {
                    this.scanning = false;
                    this.error = 'No camera is available here, or permission was refused. Type the reference instead.';
                }
            },
            async stop() {
                if (this.scanner && this.scanning) { await this.scanner.stop().catch(() => {}); }
                this.scanning = false;
            },
        }"
        x-on:livewire:navigating.window="stop()"
        style="display:grid;gap:1.25rem;max-width:760px"
    >
        @unless ($booking)
            <x-filament::section>
                <x-slot name="heading">Scan with the camera</x-slot>
                <x-slot name="description">Ask the devotee to open <strong>Profile → My seva bookings</strong> in the app and show the booking's code.</x-slot>
                <div id="booking-qr-reader" wire:ignore style="width:100%;max-width:360px;border-radius:12px;overflow:hidden"></div>
                <div style="margin-top:.75rem;display:flex;gap:.5rem">
                    <x-filament::button icon="heroicon-o-camera" x-show="! scanning" x-on:click="start()">Start camera</x-filament::button>
                    <x-filament::button color="gray" x-show="scanning" x-on:click="stop()">Stop</x-filament::button>
                </div>
                <p x-show="error" x-text="error" style="margin-top:.5rem;color:rgb(220 38 38);font-size:.875rem"></p>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Or type the reference</x-slot>
                <x-slot name="description">The short code under the QR, like SV7K3M9Q2X.</x-slot>
                <form wire:submit="scan" style="display:flex;gap:.5rem;flex-wrap:wrap">
                    <x-filament::input.wrapper style="flex:1;min-width:220px">
                        <x-filament::input type="text" wire:model="code" placeholder="SV…" autocapitalize="characters" />
                    </x-filament::input.wrapper>
                    <x-filament::button type="submit">Find booking</x-filament::button>
                </form>
                @if ($error)
                    <p style="margin-top:.75rem;color:rgb(220 38 38);font-size:.875rem">{{ $error }}</p>
                @endif
            </x-filament::section>
        @else
            @if ($outcome === \App\Support\Bookings\PujaBookings::ALREADY_VERIFIED)
                <div style="border:2px solid rgb(220 38 38);background:rgba(220,38,38,.08);border-radius:14px;padding:1rem;display:flex;gap:.75rem;align-items:flex-start">
                    <x-filament::icon icon="heroicon-o-x-circle" style="width:28px;height:28px;color:rgb(220 38 38);flex-shrink:0" />
                    <div>
                        <p style="font-weight:700;font-size:1.05rem;color:rgb(185 28 28)">Already verified — this code does not work again</p>
                        <p style="font-size:.9rem;margin-top:.25rem">
                            Received by {{ $booking->verifier?->name ?? 'the temple' }} on {{ $booking->verified_at?->timezone($tz)->format('d M Y') }} at {{ $booking->verified_at?->timezone($tz)->format('H:i') }}.
                            The same booking is being shown a second time.
                        </p>
                    </div>
                </div>
            @elseif ($outcome === \App\Support\Bookings\PujaBookings::VERIFIED)
                <div style="border:2px solid rgb(5 150 105);background:rgba(5,150,105,.08);border-radius:14px;padding:1rem;display:flex;gap:.75rem;align-items:flex-start">
                    <x-filament::icon icon="heroicon-o-check-badge" style="width:28px;height:28px;color:rgb(5 150 105);flex-shrink:0" />
                    <div>
                        <p style="font-weight:700;font-size:1.05rem;color:rgb(4 120 87)">Verified — receive {{ $booking->devotee_name }}</p>
                        <p style="font-size:.9rem;margin-top:.25rem">{{ $booking->people }} {{ $booking->people === 1 ? 'person' : 'people' }} for {{ $booking->puja?->name }}. The code is now used and will be refused if shown again.</p>
                    </div>
                </div>
            @elseif (! $booking->isConfirmed())
                <div style="border:2px solid rgb(217 119 6);background:rgba(217,119,6,.08);border-radius:14px;padding:1rem">
                    <p style="font-weight:700;color:rgb(180 83 9)">{{ $booking->status->getLabel() }}</p>
                    <p style="font-size:.9rem;margin-top:.25rem">
                        @if ($booking->status === \App\Enums\BookingStatus::PendingPayment)
                            The payment for this booking has not come through. Do not receive it on this code; ask the devotee to check the booking in the app.
                        @else
                            This booking is not live{{ $booking->cancel_reason ? ': '.$booking->cancel_reason : '.' }}
                        @endif
                    </p>
                </div>
            @endif

            <x-filament::section>
                <x-slot name="heading">{{ $booking->reference }}</x-slot>
                <x-slot name="description">{{ $booking->summary() }}</x-slot>
                <x-slot name="headerEnd">
                    <x-filament::button color="gray" icon="heroicon-o-qr-code" wire:click="clearScan">Scan another</x-filament::button>
                </x-slot>
                @include('filament.bookings.details', ['booking' => $booking])
                @if ($error)
                    <p style="margin-top:.75rem;color:rgb(220 38 38);font-size:.875rem">{{ $error }}</p>
                @endif
            </x-filament::section>

            @if ($booking->isConfirmed() && $outcome === null)
                <x-filament::section>
                    <x-slot name="heading">Receive this booking</x-slot>
                    <x-slot name="description">Only while the devotee is here with you. The booking is marked verified with your name, and its code stops working.</x-slot>
                    <x-filament::button icon="heroicon-o-check-badge" color="success" size="lg" wire:click="verify" wire:loading.attr="disabled">Mark received and verified</x-filament::button>
                </x-filament::section>
            @endif
        @endunless
    </div>
</x-filament-panels::page>
