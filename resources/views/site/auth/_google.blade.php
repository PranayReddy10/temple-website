@if ($googleClientId)
    {{-- Google's own button, in redirect mode: it posts the signed ID token to /login/google. --}}
    <script src="https://accounts.google.com/gsi/client" async></script>
    <div id="g_id_onload" data-client_id="{{ $googleClientId }}" data-ux_mode="redirect"
         data-login_uri="{{ \App\Support\Seo::url('login/google') }}" data-auto_prompt="false"></div>
    <div class="g_id_signin" data-type="standard" data-shape="pill" data-theme="outline" data-text="continue_with" data-size="large" data-width="380" data-logo_alignment="center" style="display:flex;justify-content:center;min-height:44px"></div>
    @if ($passwords)<div class="or">{{ __('or') }}</div>@endif
@endif
