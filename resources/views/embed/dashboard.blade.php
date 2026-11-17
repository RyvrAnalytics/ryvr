<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <meta name="referrer" content="no-referrer">
        <title>Analytics</title>
        <style>
            html, body { margin: 0; padding: 0; background: transparent; }
        </style>
    </head>
    <body>
        <div id="ryvr-embed" data-kid="{{ $kid }}" data-host="{{ $host }}"></div>
        <script src="{{ $script }}" nonce="{{ $nonce }}"></script>
        <script nonce="{{ $nonce }}">
            window.RyvrEmbed && window.RyvrEmbed.frame(document.getElementById('ryvr-embed'));
        </script>
    </body>
</html>
