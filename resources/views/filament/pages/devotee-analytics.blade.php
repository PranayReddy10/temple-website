{{--
    The widgets, laid out by the page rather than by discovery.

    Each one declares its own column span, so the stat rows and the chart run
    full width while the language breakdown sits beside the popular temples.
--}}
<x-filament-panels::page>
    <x-filament-widgets::widgets
        :columns="$this->getColumns()"
        :widgets="$this->getWidgets()"
    />
</x-filament-panels::page>
