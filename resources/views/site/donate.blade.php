@extends('site.layout')

@php
    use App\Support\Seo;
@endphp

@push('head')
    <style>
        .amounts { display: flex; flex-wrap: wrap; gap: 8px; }
        .amounts button { font: inherit; font-weight: 600; padding: 8px 16px; border-radius: 999px; border: 1px solid var(--line); background: #fff; color: var(--deep); cursor: pointer; }
        .amounts button:hover { border-color: var(--saffron); }
    </style>
@endpush

@section('content')
    <p class="crumbs"><a href="{{ Seo::url('/') }}">Home</a> › <a href="{{ Seo::url('temples/'.$temple->slug) }}">{{ $temple->name }}</a> › {{ __('Donate') }}</p>
    <div class="narrow" style="max-width:560px">
        <h1>🪔 {{ $title }}</h1>
        <p class="muted">{{ __('Your offering goes to the temple, whose bank details are verified by :app. You get a receipt by email and under My offerings.', ['app' => config('brand.name')]) }}</p>

        @if (! $paymentsOpen)
            <p class="errors">{{ __('Online offerings are not open yet. Please give at the temple for now.') }}</p>
        @else
            <form class="card form" method="post" action="{{ Seo::url('temples/'.$temple->slug.'/donate') }}">
                @csrf
                @include('site.auth._errors')
                <label>{{ __('Amount (₹)') }}
                    <input type="number" name="amount" id="amount" value="{{ old('amount', 501) }}" min="10" max="500000" step="1" required>
                </label>
                <div class="amounts" aria-label="{{ __('Quick amounts') }}">
                    @foreach ([101, 251, 501, 1001, 2501, 5001] as $a)
                        <button type="button" onclick="document.getElementById('amount').value={{ $a }}">₹{{ number_format($a) }}</button>
                    @endforeach
                </div>
                <label>{{ __('For') }}
                    <select name="purpose">
                        @foreach ($purposes as $value => $label)<option value="{{ $value }}" @selected(old('purpose') === $value)>{{ $label }}</option>@endforeach
                    </select>
                </label>
                @if ($devotee)
                    <label>{{ __('Name on the receipt') }}
                        <input name="donor_name" value="{{ old('donor_name', $devotee->name) }}" maxlength="120">
                    </label>
                    <label class="check"><input type="checkbox" name="is_anonymous" value="1" @checked(old('is_anonymous'))> {{ __('Give anonymously (the temple does not see your name)') }}</label>
                    <label>{{ __('Note') }} <small>{{ __('optional') }}</small>
                        <input name="note" value="{{ old('note') }}" maxlength="300">
                    </label>
                    <button class="btn saffron block" type="submit">{{ __('Continue to pay') }}</button>
                @else
                    <a class="btn saffron block" href="{{ Seo::url('login').'?'.http_build_query(['next' => $canonical]) }}" rel="nofollow">{{ __('Sign in to donate') }}</a>
                    <p class="hint" style="margin:0;text-align:center">{{ __('An account keeps your receipts. It takes a minute, or continue with Google.') }}</p>
                @endif
            </form>
        @endif
        <p><a href="{{ Seo::url('temples/'.$temple->slug) }}">← {{ $temple->name }}</a></p>
    </div>
@endsection
