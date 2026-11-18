# Self-hosting RYVR

## What you need

- A Linux server with Docker and Docker Compose. 4 GB of memory works for small sites; 8 GB is comfortable.
- A hostname for RYVR (for example `analytics.example.com`) whose DNS points at the server.
- Ports 80 and 443 open. Caddy gets and renews the TLS certificate.

## Install

```bash
git clone https://github.com/RyvrAnalytics/ryvr.git && cd ryvr
cp .env.production.example .env
```

Edit `.env`:

| Setting                              | What to put                                                             |
| ------------------------------------ | ----------------------------------------------------------------------- |
| `APP_URL`, `RYVR_DOMAIN`             | Your RYVR hostname                                                      |
| `APP_KEY`                            | Output of `docker compose run --rm app php artisan key:generate --show` |
| `DB_PASSWORD`, `CLICKHOUSE_PASSWORD` | Long random strings                                                     |
| `ACME_EMAIL`                         | Where certificate expiry notices go                                     |
| `MAIL_*`                             | Optional. Without mail, invitation links are shown in the app           |

Then start it:

```bash
docker compose up -d --build
docker compose exec app php artisan ryvr:geoip:update   # country and city lookups
```

Open `https://your-hostname/register`. The first account becomes the instance admin; after that, sign-ups are closed and people join by invitation.

## Running behind a CDN

If a CDN such as Cloudflare proxies your RYVR hostname, set `RYVR_CLIENT_IP_HEADER=CF-Connecting-IP` so visitor IPs (used for exclusions and the daily visitor hash, never stored) are read correctly. Only do this when the CDN is the only way to reach the server: the header can be forged otherwise.

## Processes

| Service     | Job                                                                        |
| ----------- | -------------------------------------------------------------------------- |
| `web`       | Caddy: TLS, static files, the tracker script                               |
| `app`       | PHP-FPM: dashboard, API, MCP, the `/e` collector. Runs migrations on start |
| `writer`    | Moves buffered hits from Redis into ClickHouse                             |
| `scheduler` | Deletes old visitor salts, applies retention, refreshes GeoIP              |
| `queue`     | Background jobs such as invitation mail                                    |

`GET /health` returns `{"status":"ok"}` with HTTP 200, or `{"status":"error"}` with HTTP 503 when a dependency fails. Requests from localhost (`127.0.0.1` or `::1`) also receive database, ClickHouse, Redis and writer checks. To allow remote clients to see these details, set `RYVR_HEALTH_TOKEN` and send it as `Authorization: Bearer <token>`. Responses are not cached.

## Capacity

On a laptop running the compose stack, the collector accepted about 550 hits a second end to end (p95 under 100 ms) with the writer keeping the buffer empty. That is roughly 45 million hits a day; real servers do better.

## Upgrades

```bash
git pull
docker compose up -d --build
```

The `app` container applies PostgreSQL and ClickHouse migrations before it starts serving.

## Backups

Back up three things:

1. PostgreSQL: `docker compose exec postgres pg_dump -U ryvr ryvr > ryvr.sql`
2. ClickHouse: `docker compose exec clickhouse clickhouse-client --query "BACKUP DATABASE ryvr TO File('backup-$(date +%F)')"` (needs a `backups` disk), or snapshot the `chdata` volume while the `writer` is stopped.
3. Your `.env` file.

Redis only holds a short buffer of incoming hits and caches, so it needs no backup. It does need persistence: the compose file runs it with `appendonly yes` and `noeviction`, so buffered hits survive a Redis restart. If you bring your own Redis, set the same two options.

If the writer stops for a long time, the buffer grows until `RYVR_INGEST_MAX_BUFFERED` entries (2 million by default). Past that, new hits are refused rather than old ones discarded, and each refused hit is counted as a "shed" drop on the site dashboard. A batch the writer retries after a crash is not counted twice.

## Retention

`RYVR_RETENTION_MONTHS` deletes raw events older than that many months, every night. `0` keeps everything.

## Privacy

- No cookies or local identifiers are set on tracked sites.
- Visitor ids are a hash of a daily random salt, the site, the IP address and the user agent. Salts are deleted after two days, so ids cannot be linked across days, and nobody, including the server operator, can recompute them.
- IP addresses are used during the request (exclusion rules, location lookup) and are never written to disk.
- Sites can ask for consent first: add `data-consent="required"` to the script and call `ryvr.consent(true)` after the visitor agrees.
