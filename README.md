<p>
  <img src="public/favicon.svg" width="40" height="40" alt="" />
</p>

# RYVR

Privacy-first web analytics for people who run more than one site.

RYVR counts visits without cookies, filters out bots, AI agents and monitoring probes, and puts all of your sites side by side. Each organization can hold many sites, give clients access to only their own, and let AI assistants read the numbers over MCP.

- **One view of every site.** Visitors, pageviews, bounce rate and duration for all sites at once, with trends and period comparisons.
- **A clean dashboard per site.** Pages, entry and exit pages, referrers, channels (including AI assistants), campaigns, countries, devices, custom events with properties, and goals.
- **Traffic you can trust.** Crawlers, AI agents, headless browsers, uptime checks and your own exclusion rules (IP ranges, user agents, paths, hostnames) never reach your numbers, and the dashboard shows how much was filtered.
- **No cookies, no personal data.** Visitors are counted with a hash that changes every day. IP addresses are never stored.
- **Teams and clients.** Owners, admins, analysts, viewers and billing roles, site-limited members, groups, invitations and an audit log.
- **API and MCP.** A read-only REST API and an MCP server, both scoped by keys that never see more than their owner.

## Run it

RYVR is a Laravel application backed by PostgreSQL, ClickHouse and Redis. The quickest way to run it is Docker Compose:

```bash
cp .env.production.example .env   # fill in the domain and passwords
docker compose up -d
```

Open your domain, create the first account, add a site and paste the tracking snippet into its `<head>`. See [docs/self-hosting.md](docs/self-hosting.md) for the details, upgrades and backups.

## Track

```html
<script
    defer
    src="https://analytics.example.com/r.js"
    data-key="ryvr_ingest_..."
></script>
```

```js
ryvr.track('Signup', { plan: 'pro' });
ryvr.track('Purchase', { sku: 'A1' }, { revenue: 49, currency: 'CAD' });
```

The tracker is about 1.4 KB gzipped, works with single-page apps and records outbound links and file downloads on its own.

## Develop

```bash
docker compose -f compose.dev.yaml up -d   # PostgreSQL, ClickHouse, Redis
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan ryvr:migrate
php artisan db:seed --class=DevSeeder && php artisan ryvr:demo
composer dev
```

Run `php artisan test` for the test suite and `php artisan ryvr:smoke` for an end-to-end ingest check against real services.

## Licence

The application is licensed under the [GNU AGPL v3](LICENSE). The tracker in `packages/tracker` is MIT licensed so it can be embedded anywhere.
