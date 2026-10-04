@extends('site.layout')

@push('head')
    <style>
        article.page { max-width: 760px; }
        article.page h2 { margin-top: 32px; }
        article.page li { margin: 4px 0; }
        article.page .updated { color: var(--muted); font-size: .85rem; margin-top: 32px; }
        form.request { display: grid; gap: 12px; max-width: 520px; }
        form.request label { display: grid; gap: 4px; font-weight: 600; font-size: .92rem; }
        form.request input, form.request textarea { font: inherit; padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px; background: #fff; color: var(--deep); }
        form.request button { justify-self: start; font: inherit; font-weight: 600; background: var(--kumkum); color: #fff; border: 0; border-radius: 999px; padding: 10px 20px; cursor: pointer; }
        .notice { background: #eef7ee; border: 1px solid #b9dcb9; border-radius: 12px; padding: 12px 16px; }
        .error { color: #9B1B30; font-size: .85rem; font-weight: 400; }
    </style>
@endpush

@section('content')
    <p class="crumbs"><a href="{{ \App\Support\Seo::url('/') }}">Home</a> › {{ $title }}</p>
    <article class="page">
        <h1>{{ $title }}</h1>
        {!! $page->renderedBody() !!}

        @if ($page->slug === 'account-deletion')
            <h2 id="request">Request deletion</h2>
            @if (session('deletion_requested'))
                <p class="notice">Thank you. Your request <b>{{ session('deletion_requested') }}</b> has been received. We will confirm it with you on the account's email or phone, then delete the account within 7 days.</p>
            @else
                <form class="request" method="post" action="{{ route('site.account-deletion', absolute: false) }}">
                    @csrf
                    <label>Email or phone number on your account
                        <input name="contact" value="{{ old('contact') }}" required maxlength="255" autocomplete="email">
                        @error('contact')<span class="error">{{ $message }}</span>@enderror
                    </label>
                    <label>Your name <input name="name" value="{{ old('name') }}" maxlength="120" autocomplete="name"></label>
                    <label>Anything we should know (optional) <textarea name="reason" rows="3" maxlength="2000">{{ old('reason') }}</textarea></label>
                    <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
                    <button type="submit">Request account deletion</button>
                </form>
            @endif
        @endif

        <p class="updated">Last updated {{ $page->updated_at?->format('j F Y') }}</p>
    </article>
@endsection
