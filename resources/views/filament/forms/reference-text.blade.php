{{-- The English text a translation is made from, with a button to copy it. --}}
<div class="ds-ref" x-data="{ copied: false }">
    <div class="ds-ref__head">
        <span class="ds-ref__label">English (for reference)</span>
        @if (filled($text))
            <button type="button" class="ds-ref__copy"
                x-on:click="navigator.clipboard.writeText($refs.text.innerText).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                <x-filament::icon icon="heroicon-m-clipboard-document" class="ds-ref__icon" />
                <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
            </button>
        @endif
    </div>
    @if (filled($text))
        <div class="ds-ref__text" x-ref="text">{{ $text }}</div>
    @elseif ($picked)
        <div class="ds-ref__empty">This field is empty in English. Fill it in on the temple first, then translate it.</div>
    @else
        <div class="ds-ref__empty">Pick a field to see the English text.</div>
    @endif
    <style>
        .ds-ref { border: 1px solid rgba(120, 113, 108, .3); border-radius: .5rem; padding: .6rem .75rem; background: rgba(120, 113, 108, .06); }
        .ds-ref__head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; margin-bottom: .35rem; }
        .ds-ref__label { font-size: .875rem; font-weight: 500; }
        .ds-ref__copy { display: inline-flex; align-items: center; gap: .3rem; font-size: .8125rem; font-weight: 600; padding: .2rem .55rem; border-radius: .4rem; border: 1px solid rgba(120, 113, 108, .35); }
        .ds-ref__copy:hover { background: rgba(120, 113, 108, .12); }
        .ds-ref__icon { width: 1rem; height: 1rem; }
        .ds-ref__text { white-space: pre-line; font-size: .875rem; line-height: 1.5; max-height: 14rem; overflow-y: auto; user-select: text; }
        .ds-ref__empty { font-size: .8125rem; opacity: .7; }
    </style>
</div>
