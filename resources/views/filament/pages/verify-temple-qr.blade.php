{{--
    Verify a temple QR code: the camera when the device has one, a text box
    when it does not. html5-qrcode is loaded only on this page.
--}}
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
                    }).catch(() => { this.error = 'The scanner could not be loaded. Paste the code instead.'; });
                    if (! window.Html5Qrcode) return;
                }
                this.scanner = new Html5Qrcode('temple-qr-reader');
                this.scanning = true;
                try {
                    await this.scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: 240 }, (text) => {
                        this.stop();
                        $wire.verify(text);
                    });
                } catch (e) {
                    this.scanning = false;
                    this.error = 'No camera is available here, or permission was refused. Paste the code instead.';
                }
            },
            async stop() {
                if (this.scanner && this.scanning) { await this.scanner.stop().catch(() => {}); }
                this.scanning = false;
            },
        }"
        x-on:livewire:navigating.window="stop()"
        style="display:grid;gap:1.25rem;max-width:560px"
    >
        <x-filament::section>
            <x-slot name="heading">Scan with the camera</x-slot>
            <div id="temple-qr-reader" style="width:100%;max-width:360px;border-radius:12px;overflow:hidden"></div>
            <div style="margin-top:.75rem;display:flex;gap:.5rem">
                <x-filament::button icon="heroicon-o-camera" x-show="! scanning" x-on:click="start()">Start camera</x-filament::button>
                <x-filament::button color="gray" x-show="scanning" x-on:click="stop()">Stop</x-filament::button>
            </div>
            <p x-show="error" x-text="error" style="margin-top:.5rem;color:rgb(220 38 38);font-size:.875rem"></p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Or paste the code</x-slot>
            <form wire:submit="verify" style="display:flex;gap:.5rem;flex-wrap:wrap">
                <x-filament::input.wrapper style="flex:1;min-width:220px">
                    <x-filament::input type="text" wire:model="code" placeholder="https://…/temples/…/checkin?s=…" />
                </x-filament::input.wrapper>
                <x-filament::button type="submit">Verify</x-filament::button>
            </form>
        </x-filament::section>

        @if ($result)
            <x-filament::section>
                <div style="display:flex;gap:.75rem;align-items:flex-start">
                    <x-filament::icon
                        :icon="$result['valid'] ? 'heroicon-o-check-badge' : 'heroicon-o-exclamation-triangle'"
                        style="width:2rem;height:2rem;flex-shrink:0;color:{{ $result['valid'] ? 'rgb(22 163 74)' : 'rgb(220 38 38)' }}"
                    />
                    <div>
                        <p style="font-weight:600">{{ $result['valid'] ? 'Genuine code' : 'Not a genuine code' }}</p>
                        <p style="font-size:.875rem;opacity:.8">{{ $result['reason'] }}</p>
                        @if ($result['url'])
                            <a href="{{ $result['url'] }}" style="font-size:.875rem;text-decoration:underline">Open {{ $result['temple'] }}</a>
                        @endif
                    </div>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
