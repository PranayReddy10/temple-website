<a class="card item" href="{{ \App\Support\Seo::url('account/bookings/'.$b->reference) }}">
    <span>
        <b>{{ $b->puja?->name ?? __('Seva') }}</b>
        <small>{{ $b->temple?->name }} · {{ $b->booked_for?->format('D j M Y') }}@if ($b->slotLabel()) · {{ $b->slotLabel() }}@endif · {{ $b->people }} {{ \Illuminate\Support\Str::plural('person', $b->people) }}</small>
    </span>
    <span class="pill {{ $b->status->value }}">{{ $b->status->getLabel() }}</span>
</a>
