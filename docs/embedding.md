# Embed analytics in your app

Show read-only RYVR charts inside your own product: a customer portal, an admin panel, an
agency client dashboard. Viewers never sign in to RYVR. Your server decides what each viewer
may see and signs a short-lived **embed token** for them; the browser only ever holds that token.

Embedding is in beta and available on every plan.

## How it works

1. An owner or admin creates an **embed key** in **Organization > Embedding**. A key has a public
   id (`ek_...`), a secret (`ryvr_embed_...`, shown once) and limits: allowed origins, sites,
   widgets and the longest token lifetime.
2. Your server keeps the key id and secret in environment variables.
3. When a signed-in user opens a page with charts, your page asks your server for a token. Your
   server checks its own session, decides which sites and widgets the user may see, and signs a
   token that lives a few minutes.
4. The RYVR embed library (or the iframe page) calls the read-only embed API with that token in
   the `Authorization` header. No cookies are used, so third-party cookie blocking has no effect.

```
browser ── fetchToken() ──> your server ── signs token with ryvr_embed_ secret
browser ── Authorization: Bearer <token> ──> RYVR /embed/v1/{key id}/...
```

The secret never reaches the browser. RYVR API keys (`ryvr_pat_`, `ryvr_org_`) are never needed
in the browser either.

## 1. Create an embed key

Organization > Embedding > Create an embed key:

| Field                  | Meaning                                                                                                                                                                                     |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Allowed origins        | Where the charts may appear: `https://app.example.com`, `https://*.example.com` (any subdomain), or `http://localhost:3000` while developing. No `*`, no other `http://` origins, no paths. |
| Widgets                | Which widgets tokens may request.                                                                                                                                                           |
| Sites                  | Which sites tokens may name. Leave empty for every site you can see.                                                                                                                        |
| Longest token lifetime | 5 to 60 minutes. Tokens that live longer are rejected.                                                                                                                                      |

Copy the two values shown after creation into your server's environment:

```bash
RYVR_EMBED_KEY_ID=ek_...
RYVR_EMBED_SECRET=ryvr_embed_...
```

## 2. Sign tokens on your server

A token is a standard JWT signed with HMAC-SHA256 (HS256) using the secret.

**Header**, exactly these three fields:

```json
{ "alg": "HS256", "typ": "JWT", "kid": "ek_..." }
```

**Claims:**

| Claim                     | Required | Meaning                                                                                                                                                  |
| ------------------------- | -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `iss`                     | yes      | The key id, same as `kid`                                                                                                                                |
| `aud`                     | yes      | Always `ryvr:embed`                                                                                                                                      |
| `iat`, `exp`              | yes      | Seconds since the epoch. `exp - iat` must not exceed the key's lifetime (default 600 seconds, at most 3600)                                              |
| `jti`                     | yes      | A unique id per token (8 to 128 characters), for audit and revocation                                                                                    |
| `sub`                     | no       | An opaque id for the viewer in your app. Do not put an email address or a name here                                                                      |
| `ryvr.sites`              | yes      | Site ids the viewer may see (1 to 50). Must be within the key's sites                                                                                    |
| `ryvr.widgets`            | yes      | Any of `kpi`, `trend`, `top_pages`, `top_sources`, `realtime`. Must be within the key's widgets                                                          |
| `ryvr.origins`            | yes      | Origins this token may be shown on. Must be within the key's allowed origins                                                                             |
| `ryvr.period`             | no       | `{ "default": "30d", "allowed": ["7d", "30d", "90d"] }`. Periods: `today`, `yesterday`, `24h`, `7d`, `30d`, `month`, `last_month`, `90d`, `12mo`, `year` |
| `ryvr.filters.locked`     | no       | Filters that always apply, for example `{ "hostname": "shop.example.com" }`. The viewer cannot change them                                               |
| `ryvr.filters.adjustable` | no       | Dimensions the viewer may filter on, for example `["page", "country"]`                                                                                   |
| `ryvr.timezone`           | no       | Reporting timezone, for example `Europe/London`. Defaults to the site's timezone                                                                         |
| `ryvr.theme`              | no       | `light`, `dark` or `auto`                                                                                                                                |

Unknown keys inside `ryvr` are rejected, so a typo fails loudly instead of quietly showing more.
Embeds never show city-level data; `city` cannot be used as a filter.

Find site ids with `GET /api/v1/sites` (any API key with `analytics:read`).

### Node

```js
import { SignJWT } from 'jose';
import { randomUUID } from 'node:crypto';

const secret = new TextEncoder().encode(process.env.RYVR_EMBED_SECRET);
const kid = process.env.RYVR_EMBED_KEY_ID;

// requireLogin is your own authentication middleware.
app.get('/internal/ryvr-embed-token', requireLogin, async (req, res) => {
    // Decide on the server what this user may see. Never trust site ids from the browser.
    const sites = await sitesVisibleTo(req.user);

    const token = await new SignJWT({
        ryvr: {
            sites,
            widgets: ['kpi', 'trend', 'top_pages', 'top_sources', 'realtime'],
            origins: ['https://app.example.com'],
            period: { default: '30d', allowed: ['7d', '30d', '90d'] },
        },
    })
        .setProtectedHeader({ alg: 'HS256', typ: 'JWT', kid })
        .setIssuer(kid)
        .setAudience('ryvr:embed')
        .setSubject(String(req.user.id))
        .setJti(randomUUID())
        .setIssuedAt()
        .setExpirationTime('10m')
        .sign(secret);

    res.set('Cache-Control', 'no-store').json({ token });
});
```

### PHP (Laravel)

```php
use Firebase\JWT\JWT;
use Illuminate\Support\Str;

Route::middleware('auth')->get('/internal/ryvr-embed-token', function () {
    $kid = config('services.ryvr.embed_key_id');   // ek_...
    $secret = config('services.ryvr.embed_secret'); // ryvr_embed_..., from the environment
    $now = time();

    $token = JWT::encode([
        'iss' => $kid,
        'aud' => 'ryvr:embed',
        'sub' => (string) auth()->id(),
        'iat' => $now,
        'exp' => $now + 600,
        'jti' => (string) Str::uuid(),
        'ryvr' => [
            'sites' => auth()->user()->ryvrSiteIds(), // decided on the server
            'widgets' => ['kpi', 'trend', 'top_pages', 'top_sources', 'realtime'],
            'origins' => ['https://app.example.com'],
            'period' => ['default' => '30d', 'allowed' => ['7d', '30d', '90d']],
            'filters' => ['adjustable' => ['page', 'country']],
        ],
    ], $secret, 'HS256', $kid);

    return response()->json(['token' => $token])->header('Cache-Control', 'no-store');
});
```

### No JWT library? Let RYVR mint the token

Create an API key with the `embed:mint` permission (Organization > API and MCP) and call this
from your **server**:

```http
POST /api/v1/embed/tokens
Authorization: Bearer ryvr_org_...
Content-Type: application/json

{
  "key_id": "ek_...",
  "ttl": 600,
  "sub": "viewer-81f3",
  "sites": ["9b2f..."],
  "widgets": ["kpi", "trend"],
  "origins": ["https://app.example.com"],
  "period": { "default": "30d", "allowed": ["7d", "30d"] },
  "filters": { "locked": { "hostname": "shop.example.com" } }
}
```

```json
{
    "token": "eyJ...",
    "key_id": "ek_...",
    "jti": "...",
    "expires_at": "2026-11-16T12:10:00Z"
}
```

Every field except `key_id` is optional and defaults to the key's limits. RYVR applies the same
checks as when the token is used, and never mints access to sites the API key cannot read.
Requests that carry a browser `Origin` header are refused.

## 3. Show the widgets

### JavaScript library

```html
<script src="https://ryvr.example.com/embed.js"></script>

<div id="analytics"></div>
<script>
    RyvrEmbed.mount(document.getElementById('analytics'), {
        // Called on start and about a minute before the token expires.
        fetchToken: () =>
            fetch('/internal/ryvr-embed-token')
                .then((r) => r.json())
                .then((d) => d.token),
        theme: 'auto', // light, dark or auto
        // widgets: ['kpi', 'trend'],  // default: every widget in the token
    });
</script>
```

`mount()` shows period and site pickers when the token allows more than one; pass
`controls: false` to hide them. It returns `{ destroy, refresh }`.

Single widgets as elements:

```html
<ryvr-widget type="kpi"></ryvr-widget>
<ryvr-widget type="trend" period="7d"></ryvr-widget>
<ryvr-widget type="top_pages" limit="10"></ryvr-widget>
<ryvr-widget type="top_sources" by="referrer"></ryvr-widget>
<ryvr-widget type="realtime" site="9b2f..."></ryvr-widget>

<script>
    RyvrEmbed.configure({ fetchToken: () => getTokenFromYourServer() });
</script>
```

Call `RyvrEmbed.destroy()` on logout to drop the token and stop all widgets.

Events bubble from the mounted element: `ryvr:ready`, `ryvr:error` (with `detail.code`) and
`ryvr:expired`.

**Theming.** Set custom properties on the element or any ancestor: `--ryvr-font`, `--ryvr-fg`,
`--ryvr-muted`, `--ryvr-bg`, `--ryvr-accent`, `--ryvr-grid`, `--ryvr-radius`, `--ryvr-positive`,
`--ryvr-negative`. For more, style `::part(card)`, `::part(title)`, `::part(value)`,
`::part(chart)` and `::part(table)`.

**Your page's Content Security Policy** needs `script-src` and `connect-src` for your RYVR host
(and `frame-src` for the iframe option).

### Iframe

No build step and full isolation from your page's styles. With the library (the token travels by
`postMessage`, never in a URL):

```html
<div id="analytics"></div>
<script src="https://ryvr.example.com/embed.js"></script>
<script>
    RyvrEmbed.iframe(document.getElementById('analytics'), {
        kid: 'ek_...',
        fetchToken: () => getTokenFromYourServer(),
    });
</script>
```

The iframe resizes itself to its content.

Without JavaScript on your side, render the token into the URL **fragment** (`#token=`), which
browsers do not send to servers or in `Referer`:

```html
<iframe
    src="https://ryvr.example.com/embed/v1/ek_.../dashboard#token=eyJ..."
    title="Website analytics"
    style="width:100%;height:900px;border:0"
    loading="lazy"
    referrerpolicy="strict-origin-when-cross-origin"
    sandbox="allow-scripts allow-same-origin"
></iframe>
```

The page removes the token from its address right away. When the token expires, the charts
stay visible with a note to reload. Add `theme=dark` to the fragment for the dark theme.

The iframe page sends `Content-Security-Policy: frame-ancestors` with your key's allowed
origins, so browsers refuse to show it anywhere else. Do not set `referrerpolicy="no-referrer"`
on the iframe: the page also checks its parent's origin against the token.

## Security model

- **Tokens can only narrow.** Every request is limited to the token's claims intersected with
  the key's limits and the organization's current sites. Archived or deleted sites drop out of
  live embeds without new tokens.
- **Viewers can only choose within the token.** A request may pick one of the token's sites, one
  of its periods and filters on its adjustable dimensions. Anything else is a `400`.
  Locked filters are applied last and cannot be overridden.
- **Strict verification.** Only `HS256` with the exact header above. `none` and every other
  algorithm are rejected, signatures are compared in constant time, and up to 60 seconds of
  clock difference is tolerated.
- **Origins.** CORS answers only the key's allowed origins, never with credentials. A request
  from a browser page whose origin is not in the token is refused.
- **No cookies or sessions** on embed routes. Responses are `no-store` and `noindex`.
- **Rate limits** per token (120 a minute), per key (600 a minute by default) and per
  organization. Clients sending invalid tokens are slowed down. Over the limit: `429` with
  `Retry-After`.
- **Audit.** Key creation, rotation and revocation are in the audit log, with one
  `embed.viewed` entry per token (not per request). Viewer IP addresses are not stored.

Errors look like `{ "error": { "code": "token_expired", "message": "..." } }`. Codes include
`token_missing`, `token_malformed`, `invalid_header`, `key_not_found`, `key_mismatch`,
`signature_invalid`, `token_expired`, `token_not_yet_valid`, `token_lifetime_too_long`,
`token_revoked`, `invalid_claims`, `origin_not_allowed`, `site_not_allowed`, `no_sites`,
`widget_not_allowed`, `period_not_allowed`, `filter_not_allowed`, `invalid_parameter` and
`rate_limited`.

## Rotation and revocation

| Need                   | What to do                                                                                                                                                    |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Routine rotation       | **Rotate** creates a new key with the same limits. Deploy the new id and secret, wait for old tokens to expire, then revoke the old key. Both work meanwhile. |
| Secret may have leaked | **Revoke** the key. Its tokens stop working on their next request.                                                                                            |
| One token leaked       | `POST /api/v1/embed/tokens/{jti}/revoke` with an `embed:mint` API key. That token stops working.                                                              |

## What not to do

- Do not put the embed secret, or any `ryvr_pat_` / `ryvr_org_` key, in browser code, HTML, a
  mobile app or a public repository. If one is exposed, revoke it.
- Do not issue long-lived tokens to avoid a token endpoint. The hard cap is 60 minutes; use
  `fetchToken` instead.
- Do not sign site ids, filters or periods sent by the browser without checking them. Your
  token endpoint decides what the signed-in user may see.
- Do not put tokens in a URL path or query string. Use the `Authorization` header (the library
  does this), `postMessage`, or the fragment.
- Do not rely on hiding a widget or a picker to restrict data. Only the token's claims restrict
  data.
- Do not share one embed key between unrelated apps or customers. Use one key per app with the
  narrowest sites and origins.

## Self-hosting notes

If you run RYVR behind your own reverse proxy, do not add `X-Frame-Options` to
`/embed/v1/*`: the app sends `frame-ancestors` for those pages itself. The bundled Caddy
configuration already does this. Build the library with `npm run build:embed`.
