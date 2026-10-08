{{-- A booking or event ticket, laid out like a ticket: details on the left, the counter's code on the right. --}}
<div class="ticket">
    <div class="t-main">
        <div class="t-kind">{{ $t['kind'] }} <span class="pill {{ $status->value }}">{{ $status->getLabel() }}</span></div>
        <h2 class="t-title">{{ $t['title'] }}</h2>
        <p class="t-temple">{{ $t['temple'] }}</p>
        <dl>
            <div><dt>{{ __('Date') }}</dt><dd>{{ $t['when'] }}</dd></div>
            @if ($t['slot'])<div><dt>{{ __('Time') }}</dt><dd>{{ $t['slot'] }}</dd></div>@endif
            <div><dt>{{ __('People') }}</dt><dd>{{ $t['people'] }}</dd></div>
            <div><dt>{{ __('Name') }}</dt><dd>{{ $t['name'] }}</dd></div>
            @foreach ($extra ?? [] as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach
            <div><dt>{{ __('Amount') }}</dt><dd>{{ $t['amount'] }}</dd></div>
        </dl>
    </div>
    <div class="t-stub">
        @if ($qr)
            <div class="qr">{!! $qr !!}</div>
            <b class="t-ref">{{ $t['reference'] }}</b>
            <small>{{ __('Show this at the temple counter') }}</small>
        @else
            <b class="t-ref">{{ $t['reference'] }}</b>
            <small>{{ $status === \App\Enums\BookingStatus::PendingPayment ? __('The code appears once payment is confirmed.') : __('No code: this booking is not active.') }}</small>
        @endif
    </div>
</div>
