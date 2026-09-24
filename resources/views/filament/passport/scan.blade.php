{{--
    Scan a devotee's passport code: the camera when the device has one, a
    text box when it does not. Shared by the temple portal, where the counter
    can also mark today's visit, and the admin panel, which links through to
    the devotee's record. html5-qrcode is loaded only on this page.
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
                this.scanner = new Html5Qrcode('passport-qr-reader');
                this.scanning = true;
                try {
                    await this.scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: 240 }, (text) => {
                        this.stop();
                        $wire.scan(text);
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
        style="display:grid;gap:1.25rem;max-width:720px"
    >
        @unless ($passport)
            <x-filament::section>
                <x-slot name="heading">Scan with the camera</x-slot>
                <x-slot name="description">Ask the devotee to open <strong>Passport → My QR</strong> in the app.</x-slot>
                <div id="passport-qr-reader" wire:ignore style="width:100%;max-width:360px;border-radius:12px;overflow:hidden"></div>
                <div style="margin-top:.75rem;display:flex;gap:.5rem">
                    <x-filament::button icon="heroicon-o-camera" x-show="! scanning" x-on:click="start()">Start camera</x-filament::button>
                    <x-filament::button color="gray" x-show="scanning" x-on:click="stop()">Stop</x-filament::button>
                </div>
                <p x-show="error" x-text="error" style="margin-top:.5rem;color:rgb(220 38 38);font-size:.875rem"></p>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Or paste the code</x-slot>
                <form wire:submit="scan" style="display:flex;gap:.5rem;flex-wrap:wrap">
                    <x-filament::input.wrapper style="flex:1;min-width:220px">
                        <x-filament::input type="text" wire:model="code" placeholder="https://…/passport/…" />
                    </x-filament::input.wrapper>
                    <x-filament::button type="submit">Open passport</x-filament::button>
                </form>
                @if ($error)
                    <p style="margin-top:.75rem;color:rgb(220 38 38);font-size:.875rem">{{ $error }}</p>
                @endif
            </x-filament::section>
        @else
            <x-filament::section>
                <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
                    @if ($passport['avatar_url'])
                        <img src="{{ $passport['avatar_url'] }}" alt="" style="width:64px;height:64px;border-radius:9999px;object-fit:cover">
                    @else
                        <div style="width:64px;height:64px;border-radius:9999px;display:grid;place-items:center;background:rgba(180,83,9,.12);font-size:1.5rem;font-weight:600">
                            {{ mb_strtoupper(mb_substr($passport['name'], 0, 1)) }}
                        </div>
                    @endif
                    <div style="flex:1;min-width:180px">
                        <p style="font-size:1.25rem;font-weight:600">{{ $passport['name'] }}</p>
                        <p style="font-size:.875rem;opacity:.75">
                            {{ $passport['home_state'] ?? 'Home state not given' }}
                            @if ($passport['joined_at']) · member since {{ \Illuminate\Support\Carbon::parse($passport['joined_at'])->format('M Y') }} @endif
                        </p>
                    </div>
                    <x-filament::button color="gray" icon="heroicon-o-qr-code" wire:click="clearScan">Scan another</x-filament::button>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:.75rem;margin-top:1rem">
                    @foreach (['stamps' => 'Verified stamps', 'temples_visited' => 'Temples', 'visits_recorded' => 'Visits', 'states_covered' => 'States'] as $key => $label)
                        <div style="border:1px solid rgba(120,113,108,.25);border-radius:12px;padding:.75rem;text-align:center">
                            <p style="font-size:1.5rem;font-weight:700">{{ $passport[$key] }}</p>
                            <p style="font-size:.75rem;opacity:.7">{{ $label }}</p>
                        </div>
                    @endforeach
                </div>
                @if ($recordUrl)
                    <div style="margin-top:1rem">
                        <x-filament::button tag="a" :href="$recordUrl" icon="heroicon-o-arrow-top-right-on-square">Open devotee record</x-filament::button>
                    </div>
                @endif
            </x-filament::section>

            @if ($canMark)
                <x-filament::section>
                    <x-slot name="heading">Mark today's visit</x-slot>
                    <x-slot name="description">Only while the devotee is here with you. The visit is verified and recorded as marked by you.</x-slot>
                    <div style="display:grid;gap:.5rem">
                        @forelse ($temples as $temple)
                            <div wire:key="mark-{{ $temple['id'] }}" style="display:flex;gap:.75rem;align-items:center;justify-content:space-between;border:1px solid rgba(120,113,108,.25);border-radius:12px;padding:.75rem;flex-wrap:wrap">
                                <div>
                                    <p style="font-weight:600">{{ $temple['name'] }}</p>
                                    <p style="font-size:.8rem;opacity:.7">{{ $temple['city'] }}</p>
                                </div>
                                @if (! $temple['published'])
                                    <x-filament::badge color="gray">Not published yet</x-filament::badge>
                                @elseif ($temple['today'])
                                    <x-filament::badge color="success" icon="heroicon-m-check-badge">Stamped today</x-filament::badge>
                                @else
                                    <x-filament::button
                                        icon="heroicon-o-check-badge"
                                        color="success"
                                        wire:click="markVisited({{ $temple['id'] }})"
                                        wire:loading.attr="disabled"
                                    >{{ $temple['today'] === null ? 'Mark visited today' : 'Verify today\'s visit' }}</x-filament::button>
                                @endif
                            </div>
                        @empty
                            <p style="font-size:.875rem;opacity:.75">Your account has no approved temple yet, so there is nowhere to mark a visit.</p>
                        @endforelse
                    </div>
                </x-filament::section>
            @endif

            <x-filament::section>
                <x-slot name="heading">Passport</x-slot>
                @if (count($passport['visits']) === 0)
                    <p style="font-size:.875rem;opacity:.75">No visits shared yet.</p>
                @else
                    <div style="display:grid;gap:.5rem">
                        @foreach ($passport['visits'] as $visit)
                            <div style="display:flex;gap:.75rem;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(120,113,108,.15);padding:.4rem 0;flex-wrap:wrap">
                                <div>
                                    <p style="font-weight:500">{{ $visit['temple']['name'] ?? 'Temple' }}</p>
                                    <p style="font-size:.8rem;opacity:.7">
                                        {{ collect([$visit['temple']['city'] ?? null, $visit['temple']['state'] ?? null])->filter()->implode(', ') }}
                                        · {{ \Illuminate\Support\Carbon::parse($visit['visited_on'])->format('d M Y') }}
                                    </p>
                                </div>
                                <div style="display:flex;gap:.35rem;align-items:center">
                                    <x-filament::badge color="gray">{{ $visit['method']['label'] }}</x-filament::badge>
                                    @if ($visit['is_verified'])
                                        <x-filament::badge color="success" icon="heroicon-m-check-badge">Stamp</x-filament::badge>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        @endunless
    </div>
</x-filament-panels::page>
