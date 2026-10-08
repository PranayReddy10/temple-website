@extends('site.layout')

@section('content')
    <div class="narrow">
        <h1>{{ __('Set a new password') }}</h1>
        <form class="card form" method="post" action="{{ \App\Support\Seo::url('reset-password') }}">
            @csrf
            @include('site.auth._errors')
            <label>{{ __('Email') }}
                <input type="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" required>
            </label>
            <label>{{ __('Code from the email') }}
                <input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus>
            </label>
            <div class="row">
                <label>{{ __('New password') }}
                    <input type="password" name="password" autocomplete="new-password" required minlength="8">
                </label>
                <label>{{ __('Again') }}
                    <input type="password" name="password_confirmation" autocomplete="new-password" required minlength="8">
                </label>
            </div>
            <button class="btn primary block" type="submit">{{ __('Change password and sign in') }}</button>
        </form>
        <p style="text-align:center"><a href="{{ \App\Support\Seo::url('forgot-password') }}">{{ __('Send a new code') }}</a></p>
    </div>
@endsection
