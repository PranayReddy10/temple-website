@if ($googleClientId)
    {{-- Google's own button, in redirect mode: it posts the signed ID token to /login/google. --}}
    <div id="g_id_onload" data-client_id="{{ $googleClientId }}" data-ux_mode="redirect"
         data-login_uri="{{ \App\Support\Seo::url('login/google') }}" data-auto_prompt="false"></div>
    <div class="g-wrap"><div class="g_id_signin" id="g-btn" data-type="standard" data-shape="pill" data-theme="outline" data-text="continue_with" data-size="large" data-logo_alignment="center"></div></div>
    <script>
        // As wide as the box it sits in (Google allows 200 to 400 px), so it never spills off a phone.
        (function () { var b = document.getElementById('g-btn'); b.setAttribute('data-width', String(Math.max(200, Math.min(400, Math.floor(b.parentNode.clientWidth))))); })();
    </script>
    <script src="https://accounts.google.com/gsi/client" async></script>
    @if ($passwords)<div class="or">{{ __('or') }}</div>@endif
@endif
