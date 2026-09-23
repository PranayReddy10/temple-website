{{--
    Shown by the service worker when a page could not be fetched.

    Entirely self-contained: no stylesheet link, no font, no script. It is
    rendered at the moment the network is gone, so anything it asks for is
    something it will not get.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>No connection</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32.png">
    <style>
        :root {
            color-scheme: light dark;
            --ink: #3e2723;
            --muted: rgba(62, 39, 35, 0.66);
            --surface: #fffdf9;
            --saffron: #e07a1f;
            --rule: rgba(201, 162, 39, 0.35);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --ink: #f5ebdc;
                --muted: rgba(245, 235, 220, 0.66);
                --surface: #1a1412;
                --rule: rgba(201, 162, 39, 0.28);
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            padding: 2rem 1rem;
            background-color: var(--surface);
            color: var(--ink);
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            line-height: 1.55;
        }

        .card { max-width: 24rem; text-align: center; }

        .card img { width: 72px; height: 72px; border-radius: 18px; }

        h1 { margin: 1.25rem 0 0.5rem; font-size: 1.35rem; }

        p { margin: 0 0 0.75rem; color: var(--muted); }

        button {
            margin-top: 0.75rem;
            padding: 0.6rem 1.4rem;
            border: 1px solid var(--rule);
            border-radius: 999px;
            background-color: var(--saffron);
            color: #fff;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="card">
        <img src="/icons/icon-192.png" alt="">
        <h1>No connection</h1>
        <p>
            The panel needs the internet for this. Nothing you had saved is lost —
            it is on the server, waiting.
        </p>
        <p>Try again once you are back on Wi-Fi or mobile data.</p>
        <button type="button" onclick="location.reload()">Try again</button>
    </div>
</body>
</html>
