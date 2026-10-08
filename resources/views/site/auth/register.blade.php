@extends('site.layout')

@section('content')
    <div class="narrow">
        <h1>{{ __('Create your account') }}</h1>
        <p class="muted">{{ __('Free. Use it here and in the :app app.', ['app' => config('brand.name')]) }}</p>
        <div class="card form">
            @include('site.auth._errors')
            @include('site.auth._google')
            @if ($passwords)
                <form class="form" method="post" action="{{ \App\Support\Seo::url('register') }}">
                    @csrf
                    <label>{{ __('Your name') }}
                        <input name="name" value="{{ old('name') }}" autocomplete="name" required maxlength="120">
                    </label>
                    <label>{{ __('Email') }}
                        <input type="email" name="email" value="{{ old('email') }}" autocomplete="email">
                    </label>
                    <label>{{ __('Phone number') }} <small>{{ __('Email or phone, or both.') }}</small>
                        <input type="tel" name="phone" value="{{ old('phone') }}" autocomplete="tel" placeholder="+919876543210">
                    </label>
                    <div class="row">
                        <label>{{ __('Password') }}
                            <input type="password" name="password" autocomplete="new-password" required minlength="8">
                        </label>
                        <label>{{ __('Password again') }}
                            <input type="password" name="password_confirmation" autocomplete="new-password" required minlength="8">
                        </label>
                    </div>
                    <p class="hint" style="margin:0">{{ __('By creating an account you agree to the') }} <a href="{{ \App\Support\Seo::url('terms-and-conditions') }}">{{ __('terms') }}</a> {{ __('and') }} <a href="{{ \App\Support\Seo::url('privacy-policy') }}">{{ __('privacy policy') }}</a>.</p>
                    <button class="btn primary block" type="submit">{{ __('Create account') }}</button>
                </form>
            @endif
        </div>
        <p style="text-align:center">{{ __('Already have an account?') }} <a href="{{ \App\Support\Seo::url('login') }}"><b>{{ __('Sign in') }}</b></a></p>
    </div>
@endsection
