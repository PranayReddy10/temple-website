{{-- One AdSense unit, labelled, where a page allows it: @include('site._ad', ['place' => 'temple']). Nothing when ads are off or no unit is set for the place. --}}
@if (\App\Support\WebAds::enabled() && ($adSlot = \App\Support\WebAds::slot($place)))
    <aside class="ad" aria-label="{{ __('Advertisement') }}">
        <small>{{ __('Advertisement') }}</small>
        <ins class="adsbygoogle" style="display:block" data-ad-client="{{ \App\Support\WebAds::client() }}" data-ad-slot="{{ $adSlot }}" data-ad-format="auto" data-full-width-responsive="true" @if (\App\Support\WebAds::testMode()) data-adtest="on" @endif></ins>
        <script>(adsbygoogle = window.adsbygoogle || []).push({});</script>
    </aside>
@endif
