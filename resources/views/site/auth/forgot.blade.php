@extends('site.layout')

@section('content')
    <div class="narrow">
        <h1>{{ __('Forgot your password') }}</h1>
        <p class="muted">{{ __("Enter your account's email. We'll send a 6-digit code to set a new password.") }}</p>
        <form class="card form" method="post" action="{{ \App\Support\Seo::url('forgot-password') }}">
            @csrf
            @include('site.auth._errors')
            <label>{{ __('Email') }}
                <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
            </label>
            <button class="btn primary block" type="submit">{{ __('Send the code') }}</button>
        </form>
        <p style="text-align:center"><a href="{{ \App\Support\Seo::url('login') }}">{{ __('Back to sign in') }}</a></p>
    </div>
@endsection
