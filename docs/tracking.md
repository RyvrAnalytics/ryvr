# Tracking

## The snippet

Each site has its own key. Copy the snippet from the site's settings page and paste it into the `<head>` of every page:

```html
<script
    defer
    src="https://analytics.example.com/r.js"
    data-key="ryvr_ingest_..."
></script>
```

The tracker is about 1.4 KB gzipped, works with single-page apps and records outbound links and file downloads on its own. It sets no cookies and stores nothing about the visitor in the browser.

## Custom events and revenue

```js
ryvr.track('Signup', { plan: 'pro' });
ryvr.track('Purchase', { sku: 'A1' }, { revenue: 49, currency: 'CAD' });
```

Events and their properties appear in the Events panel of the site dashboard. A goal can count visitors who reached a page or triggered an event.

To count a click without writing code, add `data-ryvr-event` to a link:

```html
<a href="/brochure.pdf" data-ryvr-event="Download brochure">Brochure</a>
```

## Script options

| Attribute                 | What it does                                                                     |
| ------------------------- | -------------------------------------------------------------------------------- |
| `data-key`                | The site's ingest key. Required.                                                 |
| `data-api`                | Send hits to another endpoint. Defaults to `/e` on the script's origin.          |
| `data-hash`               | Count `#/routes` as separate pages, for apps that use hash-based routing.        |
| `data-local`              | Also track `localhost`, `127.*`, `*.local` and `*.test` (skipped by default).    |
| `data-auto-links="false"` | Do not record outbound links and file downloads automatically.                   |
| `data-consent="required"` | Hold hits until you call `ryvr.consent(true)`. `ryvr.consent(false)` drops them. |

## Ignoring your own visits

Open any page of your site with `?ryvr_ignore=1` added to the address to stop counting visits from that browser. `?ryvr_ignore=0` undoes it. For whole networks or tools, add an exclusion rule in the site or organization settings.
