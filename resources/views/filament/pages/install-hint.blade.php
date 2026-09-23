{{--
    Per-platform instructions, chosen in the browser rather than from the
    user agent on the server.

    Sniffing the user agent server-side would be cached with the page and be
    wrong for whoever loaded it next; the question is about the device reading
    this sentence, and only that device can answer it.
--}}
<div class="temple-install" data-install-hint hidden>
    <ol class="temple-install__steps" data-install-steps="ios" hidden>
        <li>Tap <strong>Share</strong> — the square with an arrow, at the bottom of Safari.</li>
        <li>Scroll down and tap <strong>Add to Home Screen</strong>.</li>
        <li>Name it <strong>{{ $label }}</strong> and tap <strong>Add</strong>.</li>
    </ol>

    <ol class="temple-install__steps" data-install-steps="android" hidden>
        <li>Open the browser's <strong>⋮</strong> menu.</li>
        <li>Tap <strong>Install app</strong>, or <strong>Add to Home screen</strong>.</li>
        <li>Confirm, and it appears with your other apps.</li>
    </ol>

    <ol class="temple-install__steps" data-install-steps="desktop" hidden>
        <li>Look for the <strong>install</strong> icon at the right of the address bar.</li>
        <li>Chrome and Edge show it here; Safari on a Mac uses <strong>File → Add to Dock</strong>.</li>
        <li>Firefox does not install web apps — a bookmark is the nearest thing.</li>
    </ol>

    <p class="temple-install__note" data-install-note-ios hidden>
        It has to be <strong>Safari</strong>. Chrome and Firefox on an iPhone cannot
        add anything to the home screen — that is an iOS restriction, not a
        setting.
    </p>

    <p class="temple-install__note">
        You stay signed in either way, and signing out of one signs you out of
        both. Nothing is stored on the phone but the icon.
    </p>
</div>

<p class="temple-install__installed" data-install-done hidden>
    Already installed — this is running from your home screen.
</p>

<script>
    (function () {
        var hint = document.querySelector('[data-install-hint]');
        var done = document.querySelector('[data-install-done]');

        if (!hint || !done) {
            return;
        }

        // navigator.standalone is iOS's own flag and predates the media
        // query, which Safari only began answering in 16.4. Both are checked
        // because a phone running either is a phone this has to be right on.
        var installed = window.navigator.standalone === true
            || window.matchMedia('(display-mode: standalone)').matches;

        if (installed) {
            done.hidden = false;

            return;
        }

        // iPadOS reports itself as a Mac, and tells on itself only by having
        // a touch screen. Without this, an iPad is shown the desktop steps.
        var ios = /iPad|iPhone|iPod/.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

        var android = /Android/.test(navigator.userAgent);
        var platform = ios ? 'ios' : (android ? 'android' : 'desktop');

        hint.querySelector('[data-install-steps="' + platform + '"]').hidden = false;

        if (ios) {
            hint.querySelector('[data-install-note-ios]').hidden = false;
        }

        hint.hidden = false;
    })();
</script>
