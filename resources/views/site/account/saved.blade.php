@extends('site.layout')

@section('content')
    <div class="acct">
        @include('site.account._nav')
        <div>
            <h1>{{ __('Saved temples') }}</h1>
            @if ($temples->isEmpty())
                <p class="card">{{ __('Tap ♡ Save on any temple page to keep it here.') }} <a href="{{ \App\Support\Seo::url('temples') }}">{{ __('Browse temples') }}</a></p>
            @else
                <div class="list">
                    @foreach ($temples as $t)
                        <a class="card item" href="{{ \App\Support\Seo::url('temples/'.$t->slug) }}">
                            <span><b>{{ $t->name }}</b><small>{{ collect([$t->deity?->name, $t->city, $t->state?->name])->filter()->implode(' · ') }}</small></span>
                            <span>→</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
