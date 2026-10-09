@extends('site.layout')

@section('content')
    <div class="narrow">
        <h1>{{ __('Sign in') }}</h1>
        <p class="muted">{{ __('The same account as the :app app. Book sevas, give to temples and see your bookings and passport.', ['app' => config('brand.name')]) }}</p>
        <div class="card form">
            @include('site.auth._errors')
            @include('site.auth._google')
            @if ($passwords)
                <form class="form" method="post" action="{{ \App\Support\Seo::url('login') }}">
                    @csrf
                    <label>{{ __('Email or phone number') }}
                        <input name="identifier" value="{{ old('identifier') }}" autocomplete="username" required autofocus>
                    </label>
                    <label>{{ __('Password') }}
                        <input type="password" name="password" autocomplete="current-password" required>
                    </label>
                    <label class="check"><input type="checkbox" name="remember" value="1" checked> {{ __('Keep me signed in') }}</label>
                    <button class="btn primary block" type="submit">{{ __('Sign in') }}</button>
                </form>
                @if ($resetEnabled)<p style="margin:0;text-align:center"><a href="{{ \App\Support\Seo::url('forgot-password') }}">{{ __('Forgot your password?') }}</a></p>@endif
            @endif
        </div>
        @if ($passwords)<p style="text-align:center">{{ __('New here?') }} <a href="{{ \App\Support\Seo::url('register') }}"><b>{{ __('Create a free account') }}</b></a></p>@endif
    </div>
@endsection
