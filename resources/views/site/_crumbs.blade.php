{{-- The trail to this page, shown and given to search engines (BreadcrumbList). $crumbs: [[name, url or null for this page], …] --}}
@php
    $trail = collect([['Home', \App\Support\Seo::url('/')]])->concat($crumbs);
    $ld = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $trail->values()->map(fn ($c, $i) => array_filter([
        '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1] ?? $canonical,
    ]))->all()];
@endphp
<p class="crumbs">
    @foreach ($trail as $c)
        @if (! $loop->last && $c[1])<a href="{{ $c[1] }}">{{ $c[0] }}</a> › @else{{ $c[0] }}@endif
    @endforeach
</p>
<script type="application/ld+json">{!! json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
