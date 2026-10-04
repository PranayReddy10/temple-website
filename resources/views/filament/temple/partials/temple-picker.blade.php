@if (count($this->templeOptions()) > 1)
    <div class="ds-picker">
        <label for="ds-temple-picker">Temple</label>
        <x-filament::input.wrapper>
            <x-filament::input.select id="ds-temple-picker" wire:model.live="templeId">
                @foreach ($this->templeOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>
@endif
