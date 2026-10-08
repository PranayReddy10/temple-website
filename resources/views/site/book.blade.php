@extends('site.layout')

@php
    use App\Support\Seo;

    $each = $seva->requiresPayment() ? (float) $seva->fee_amount : 0;
@endphp

@section('content')
    <p class="crumbs"><a href="{{ Seo::url('temples/'.$temple->slug) }}">{{ $temple->name }}</a> › <a href="{{ Seo::url('temples/'.$temple->slug.'/sevas') }}">Sevas</a> › {{ __('Book') }}</p>
    <div class="narrow" style="max-width:620px">
        <h1>{{ $seva->name }}</h1>
        <p class="muted">{{ \App\Http\Controllers\Site\BookingController::named($temple) }} · <b style="color:var(--kumkum)">{{ $seva->feeLabel() }}</b>@if ($seva->fee_per_person && $seva->requiresPayment()) {{ __('per person') }}@endif</p>
        @if ($seva->booking_instructions)<p class="card" style="font-size:.92rem">{{ strip_tags($seva->booking_instructions) }}</p>@endif

        @if (! $paymentsOpen)
            <p class="errors">{{ __('Online payment is not open for this temple yet. Please book at the temple counter for now.') }}</p>
        @else
            <form class="card form" method="post" action="{{ Seo::url('temples/'.$temple->slug.'/sevas/'.$seva->id.'/book') }}" id="book">
                @csrf
                @include('site.auth._errors')
                <div class="row">
                    <label>{{ __('Day') }}
                        <input type="date" name="booked_for" id="day" value="{{ old('booked_for', $today->toDateString()) }}" min="{{ $today->toDateString() }}" max="{{ $last->toDateString() }}" required>
                    </label>
                    <label>{{ __('People') }}
                        <input type="number" name="people" id="people" value="{{ old('people', 1) }}" min="1" max="{{ $seva->max_people_per_booking }}" required>
                    </label>
                </div>
                <label id="slot-wrap" @if (! $seva->hasSlots()) hidden @endif>{{ __('Time slot') }}
                    <select name="slot_id" id="slot"><option value="">{{ __('Loading…') }}</option></select>
                </label>
                <p class="hint" id="avail" style="margin:0"></p>
                <div class="row">
                    <label>{{ __('Name for the seva') }}
                        <input name="devotee_name" value="{{ old('devotee_name', $devotee->name) }}" maxlength="120">
                    </label>
                    <label>{{ __('Phone') }}
                        <input type="tel" name="devotee_phone" value="{{ old('devotee_phone', $devotee->phone) }}" maxlength="20">
                    </label>
                </div>
                <div class="row">
                    <label>{{ __('Gotram') }} <small>{{ __('optional') }}</small>
                        <input name="gotram" value="{{ old('gotram') }}" maxlength="80">
                    </label>
                    <label>{{ __('Nakshatram') }} <small>{{ __('optional') }}</small>
                        <input name="nakshatram" value="{{ old('nakshatram') }}" maxlength="80">
                    </label>
                </div>
                <label>{{ __('Note for the temple') }} <small>{{ __('optional') }}</small>
                    <textarea name="note" rows="2" maxlength="500">{{ old('note') }}</textarea>
                </label>
                <button class="btn primary block" type="submit" id="go">
                    @if ($seva->requiresPayment()){{ __('Pay') }} <span id="total">₹{{ number_format($each, 2) }}</span> {{ __('and book') }}@else{{ __('Book now') }}@endif
                </button>
                <p class="hint" style="margin:0;text-align:center">@if ($seva->requiresPayment()){{ __('You pay securely on the next page, by UPI, card or net banking.') }} @endif{{ __('You get a code to show at the temple counter.') }}</p>
            </form>
        @endif
    </div>

    <script>
        (function () {
            var day = document.getElementById('day'), people = document.getElementById('people');
            var slot = document.getElementById('slot'), wrap = document.getElementById('slot-wrap');
            var avail = document.getElementById('avail'), total = document.getElementById('total');
            var each = {{ $each }}, perPerson = {{ $seva->fee_per_person ? 'true' : 'false' }};
            var url = @json(Seo::url('temples/'.$temple->slug.'/sevas/'.$seva->id.'/slots'));
            var chosen = @json(old('slot_id'));
            if (!day) return;
            function price() {
                if (!total) return;
                var n = Math.max(1, parseInt(people.value || '1', 10));
                total.textContent = '₹' + (each * (perPerson ? n : 1)).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }
            function load() {
                avail.textContent = '';
                fetch(url + '?date=' + encodeURIComponent(day.value), { headers: { Accept: 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.remaining !== null && d.remaining !== undefined) {
                            avail.textContent = d.remaining > 0 ? d.remaining + ' bookings left on this day.' : 'Fully booked on this day. Choose another day.';
                        }
                        if (!d.slots || !d.slots.length) { wrap.hidden = true; slot.innerHTML = ''; return; }
                        wrap.hidden = false;
                        slot.innerHTML = '';
                        d.slots.forEach(function (s) {
                            var o = document.createElement('option');
                            o.value = s.id;
                            o.textContent = s.label + (s.bookable ? (s.available !== null ? ' · ' + s.available + ' left' : '') : ' · full');
                            o.disabled = !s.bookable;
                            if (String(s.id) === String(chosen)) o.selected = true;
                            slot.appendChild(o);
                        });
                    })
                    .catch(function () {});
            }
            day.addEventListener('change', load);
            people.addEventListener('input', price);
            price();
            load();
        })();
    </script>
@endsection
