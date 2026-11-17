<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · RYVR</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style nonce="{{ Vite::cspNonce() }}">
        :root { --canvas: #eef0ec; --panel: #fff; --ink: #1a1a18; --muted: #6f6c66; --river: #1d6a86; }
        @media (prefers-color-scheme: dark) { :root { --canvas: #121312; --panel: #1c1c1a; --ink: #ecebe8; --muted: #a19e98; --river: #5fa8c4; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: var(--canvas); color: var(--ink); font: 15px/1.5 "IBM Plex Sans", ui-sans-serif, system-ui, sans-serif; display: flex; flex-direction: column; padding: 32px; }
        .mark { display: inline-flex; align-items: center; gap: 8px; color: var(--river); text-decoration: none; font-weight: 600; }
        .mark span { color: var(--ink); }
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
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 5.5c4 0 5 3 9 3s5-3 9-3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M3 12c4 0 5 3 9 3s5-3 9-3" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity=".55"/><path d="M3 18.5c4 0 5 3 9 3s5-3 9-3" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity=".3"/></svg>
        <span>ryvr</span>
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
