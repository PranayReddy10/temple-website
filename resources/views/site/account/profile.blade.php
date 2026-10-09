@extends('site.layout')

@php($u = \App\Support\Seo::class)

@section('content')
    <div class="acct">
        @include('site.account._nav')
        <div>
            <h1>{{ __('My profile') }}</h1>
            @include('site.auth._errors')
            <form class="card form" method="post" action="{{ $u::url('account/profile') }}">
                @csrf
                <label>{{ __('Name') }}<input name="name" value="{{ old('name', $devotee->name) }}" required maxlength="120"></label>
                <div class="row">
                    <label>{{ __('Email') }}<input type="email" name="email" value="{{ old('email', $devotee->email) }}"></label>
                    <label>{{ __('Phone') }}<input type="tel" name="phone" value="{{ old('phone', $devotee->phone) }}"></label>
                </div>
                <div class="row">
                    <label>{{ __('Home state') }}
                        <select name="home_state_id"><option value="">—</option>
                            @foreach ($states as $s)<option value="{{ $s->id }}" @selected((string) old('home_state_id', $devotee->home_state_id) === (string) $s->id)>{{ $s->name }}</option>@endforeach
                        </select>
                    </label>
                    <label>{{ __('Date of birth') }}<input type="date" name="date_of_birth" value="{{ old('date_of_birth', $devotee->date_of_birth?->toDateString()) }}"></label>
                </div>
                <label>{{ __('Language') }}
                    <select name="locale">
                        @foreach ($locales as $code => $l)<option value="{{ $code }}" @selected(old('locale', $devotee->locale) === $code)>{{ is_array($l) ? ($l['native'] ?? $l['name'] ?? $code) : $l }}</option>@endforeach
                    </select>
                </label>
                <button class="btn primary" type="submit">{{ __('Save') }}</button>
            </form>

            <h2>{{ filled($devotee->password) ? __('Change password') : __('Set a password') }}</h2>
            <form class="card form" method="post" action="{{ $u::url('account/password') }}">
                @csrf
                @if (filled($devotee->password))<label>{{ __('Current password') }}<input type="password" name="current_password" autocomplete="current-password" required></label>@endif
                <div class="row">
                    <label>{{ __('New password') }}<input type="password" name="password" autocomplete="new-password" minlength="8" required></label>
                    <label>{{ __('Again') }}<input type="password" name="password_confirmation" autocomplete="new-password" minlength="8" required></label>
                </div>
                <button class="btn" type="submit">{{ __('Save password') }}</button>
            </form>

            <h2>{{ __('Delete account') }}</h2>
            <form class="card form" method="post" action="{{ $u::url('account/delete') }}">
                @csrf
                <p style="margin:0">{{ __('This deletes your account here and in the app: your profile, check-ins, photos, memories, reviews and saved temples. Bookings and payments are kept without your name, as accounting law requires, so a seva already booked still takes place.') }}</p>
                <label>{{ __('Type DELETE to confirm') }}<input name="confirm" autocomplete="off" required></label>
                <button class="btn" type="submit" style="border-color:#a3162c;color:#a3162c">{{ __('Delete my account') }}</button>
            </form>
        </div>
    </div>
@endsection
