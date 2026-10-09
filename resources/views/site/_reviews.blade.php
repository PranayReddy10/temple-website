{{-- What devotees say about a temple: a summary per dimension (no single overall score, on purpose), the latest accounts, and the form. --}}
@php
    use App\Models\TempleReview;
    use App\Support\Seo;
    $dims = TempleReview::DIMENSIONS;
    $signedIn = auth('devotee_web')->check();
    $mine = $myReview ?? null;
@endphp
<section id="reviews" class="reviews">
    <h2>{{ __('Devotees’ reviews of :name', ['name' => $name]) }}</h2>
    @if ($reviewSummary['count'] > 0)
        <div class="card rsum">
            <div class="rcount"><b>{{ $reviewSummary['count'] }}</b> {{ \Illuminate\Support\Str::plural('review', $reviewSummary['count']) }}
                @if ($reviewSummary['average_wait_minutes'] !== null)<br><span class="muted">⏳ {{ __('Usual wait') }} ~{{ $reviewSummary['average_wait_minutes'] }} {{ __('min') }}</span>@endif
            </div>
            <div class="bars">
                @foreach ($reviewSummary['dimensions'] as $key => $d)
                    @continue($d['average'] === null)
                    <div class="bar"><span>{{ __($d['label']) }}</span><i><em style="width:{{ $d['average'] / 5 * 100 }}%"></em></i><b>{{ number_format($d['average'], 1) }}</b></div>
                @endforeach
            </div>
        </div>
        <div class="rlist">
            @foreach ($reviews as $r)
                <article class="card review">
                    <header><b>{{ \Illuminate\Support\Str::before($r->devotee?->name ?? __('Devotee'), ' ') ?: __('Devotee') }}</b>@if ($r->devotee?->homeState) <span class="muted">· {{ $r->devotee->homeState->name }}</span>@endif <span class="muted">· {{ __('visited') }} {{ $r->visited_on?->format('M Y') }}</span></header>
                    <div class="chips">
                        @foreach ($r->ratings() as $key => $v)<span class="chip">{{ __($dims[$key]['label']) }} <b>{{ str_repeat('★', $v) }}<span class="off">{{ str_repeat('★', 5 - $v) }}</span></b></span>@endforeach
                        @if ($r->wait_minutes !== null)<span class="chip">⏳ {{ $r->wait_minutes }} {{ __('min wait') }}</span>@endif
                    </div>
                    @if ($r->body)<p>{{ $r->body }}</p>@endif
                    @if ($r->temple_reply)<p class="reply"><b>{{ __('Reply from the temple') }}:</b> {{ $r->temple_reply }}</p>@endif
                </article>
            @endforeach
        </div>
    @else
        <p class="muted">{{ __('No reviews yet. Been here? Help the next devotee: how long was the queue, how clean, how easy to get around?') }}</p>
    @endif

    @if ($signedIn)
        <details class="card write" @if ($errors->any() || ! $mine) open @endif>
            <summary><b>{{ $mine ? __('Edit your review') : __('Write a review') }}</b>@if ($mine && $mine->status->value === 'pending') <span class="pill pending_payment">{{ __('Being checked') }}</span>@endif</summary>
            <form class="form" method="post" action="{{ Seo::url('temples/'.$temple->slug.'/reviews') }}" style="margin-top:12px">
                @csrf
                @include('site.auth._errors')
                @foreach ($dims as $key => $meta)
                    <fieldset class="stars-in">
                        <legend>{{ __($meta['label']) }} <small class="muted">({{ __($meta['low']) }} → {{ __($meta['high']) }})</small></legend>
                        @for ($n = 5; $n >= 1; $n--)
                            <input type="radio" id="{{ $key }}-{{ $n }}" name="{{ $key }}" value="{{ $n }}" @checked((int) old($key, $mine?->{$key}) === $n)><label for="{{ $key }}-{{ $n }}" title="{{ $n }}">★</label>
                        @endfor
                    </fieldset>
                @endforeach
                <div class="row">
                    <label>{{ __('When did you visit?') }}<input type="date" name="visited_on" value="{{ old('visited_on', $mine?->visited_on?->toDateString() ?? now()->toDateString()) }}" max="{{ now()->toDateString() }}"></label>
                    <label>{{ __('Wait for darshan (minutes)') }}<input type="number" name="wait_minutes" min="0" max="1440" value="{{ old('wait_minutes', $mine?->wait_minutes) }}"></label>
                </div>
                <label>{{ __('Your experience') }} <small>{{ __('optional') }}</small><textarea name="body" rows="4" maxlength="3000" placeholder="{{ __('Tips for other devotees: best time to go, queues, parking, prasadam …') }}">{{ old('body', $mine?->body) }}</textarea></label>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <button class="btn primary" type="submit">{{ $mine ? __('Update review') : __('Post review') }}</button>
                </div>
            </form>
            @if ($mine)
                <form method="post" action="{{ Seo::url('temples/'.$temple->slug.'/reviews/delete') }}" onsubmit="return confirm('{{ __('Remove your review?') }}')" style="margin-top:8px">@csrf<button class="btn" type="submit">{{ __('Remove my review') }}</button></form>
            @endif
        </details>
    @else
        <p><a class="btn" href="{{ Seo::url('login').'?'.http_build_query(['next' => $canonical.'#reviews']) }}" rel="nofollow">✍️ {{ __('Sign in to write a review') }}</a></p>
    @endif
</section>
