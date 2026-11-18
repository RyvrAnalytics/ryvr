<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · RYVR</title>
    <link rel="icon" href="/favicon.ico?v=20261118" sizes="any">
    <link rel="icon" href="/favicon-32.png?v=20261118" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=20261118">
    <style nonce="{{ Vite::cspNonce() }}">
        :root { --canvas: #eef0ec; --panel: #fff; --ink: #1a1a18; --muted: #6f6c66; --river: #1d6a86; }
        @media (prefers-color-scheme: dark) { :root { --canvas: #121312; --panel: #1c1c1a; --ink: #ecebe8; --muted: #a19e98; --river: #5fa8c4; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: var(--canvas); color: var(--ink); font: 15px/1.5 "IBM Plex Sans", ui-sans-serif, system-ui, sans-serif; display: flex; flex-direction: column; padding: 32px; }
        .mark { display: inline-flex; align-self: flex-start; color: #0b131a; }
        .mark img { height: 24px; width: auto; display: block; }
        @media (prefers-color-scheme: dark) { .mark { color: #fff; } }
        main { flex: 1; display: flex; align-items: center; }
        .panel { background: var(--panel); border-radius: 16px; padding: 32px; max-width: 30rem; margin-left: 12vw; }
        .code { font-size: 64px; font-weight: 300; line-height: 1; letter-spacing: -0.025em; color: var(--river); font-variant-numeric: tabular-nums; }
        h1 { font-size: 22px; font-weight: 400; margin: 16px 0 4px; }
        p { color: var(--muted); margin: 0; }
        a.home { display: inline-block; margin-top: 24px; color: var(--ink); text-underline-offset: 4px; }
        @media (max-width: 640px) { .panel { margin-left: 0; } body { padding: 16px; } }
    </style>
</head>
<body>
    <a class="mark" href="/" aria-label="RYVR home">
        <picture>
            <source media="(prefers-color-scheme: dark)" srcset="/brand/ryvr-logo-on-dark.png?v=20261118">
            <img src="/brand/ryvr-logo.png?v=20261118" width="1469" height="330" alt="RYVR">
        </picture>
    </a>
    <main>
        <div class="panel">
            <div class="code">@yield('code')</div>
            <h1>@yield('message')</h1>
            <p>@yield('hint', 'If you followed a link here, it may be out of date.')</p>
            <a class="home" href="/">Back to your sites</a>
        </div>
    </main>
</body>
</html>
