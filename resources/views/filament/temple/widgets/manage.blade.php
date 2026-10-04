<x-filament-widgets::widget>
    <x-filament::section heading="Manage">
        <div class="ds-manage">
            @foreach ($groups as $group => $links)
                <div>
                    <div class="ds-manage__group">{{ $group }}</div>
                    <div class="ds-manage__grid">
                        @foreach ($links as [$label, $icon, $url])
                            <a href="{{ $url }}" class="ds-manage__tile">
                                <x-filament::icon :icon="$icon" class="ds-manage__icon" />
                                <span>{{ $label }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
    <style>
        .ds-manage { display: grid; gap: 1rem; }
        .ds-manage__group { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; opacity: .65; margin-bottom: .4rem; }
        .ds-manage__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(7.5rem, 1fr)); gap: .5rem; }
        .ds-manage__tile { display: flex; align-items: center; gap: .5rem; padding: .55rem .65rem; border-radius: .6rem; border: 1px solid rgba(120, 113, 108, .22); font-size: .8125rem; font-weight: 500; transition: background .15s, border-color .15s; }
        .ds-manage__tile:hover, .ds-manage__tile:focus-visible { background: rgba(155, 27, 48, .06); border-color: rgba(155, 27, 48, .45); }
        .ds-manage__icon { width: 1.15rem; height: 1.15rem; flex: none; color: #9b1b30; }
        .dark .ds-manage__icon { color: #e0455e; }
    </style>
</x-filament-widgets::widget>
