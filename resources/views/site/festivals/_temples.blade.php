@if ($deity && $temples->isNotEmpty())
    <h2>{{ __(':deity temples to visit', ['deity' => \App\Http\Controllers\PublicTempleController::deityPhrase($deity->name)]) }}</h2>
    <div class="tgrid">
        @foreach ($temples as $t)
            <a class="card" href="{{ \App\Support\Seo::url('temples/'.$t->slug) }}"><b>{{ $t->name }}</b><small>{{ collect([$t->city, $t->state?->name])->filter()->implode(', ') }}</small></a>
        @endforeach
    </div>
    @if ($deity->slug)<p><a href="{{ \App\Support\Seo::url('deities/'.$deity->slug) }}">{{ __('All :deity temples, with timings', ['deity' => \App\Http\Controllers\PublicTempleController::deityPhrase($deity->name)]) }} →</a></p>@endif
@endif
