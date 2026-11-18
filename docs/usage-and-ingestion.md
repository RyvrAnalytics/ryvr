# Usage and ingestion

Organization owners, administrators and billing members can open **Organization > Usage**.
Calendar months and daily buckets use UTC. Accepted events, filtered hits, shed hits and
approximate bytes come from the ingestion meter. Counts become available after the writer
flushes its buffer. Archived and removed sites remain in metering totals.

`GET /api/v1/usage` returns the same report under `data`. Authenticate with an organization
API key carrying `analytics:read`. A key restricted to selected sites only returns their
usage. Personal API keys cannot read this report.

## Quotas

Instance administrators set quotas from the console:

```sh
php artisan ryvr:org:quota <organization-id-or-slug> --monthly=1000000 --overage=hard_cap --burst=100
php artisan ryvr:org:quota <organization-id-or-slug> --monthly=unlimited --overage=bill --burst=0
```

Omitted options retain their previous values. Defaults are an unlimited monthly allowance,
`bill` overages and no burst limit. `bill` records overages without rejecting events; it
does not create invoices. `hard_cap` sheds events once accepted monthly usage reaches the
limit. Usage is cached for 60 seconds, so enforcement follows persisted counts with that
delay plus writer lag. A zero monthly hard cap rejects every otherwise eligible event.

The burst limit is a shared token bucket across an organization's sites, with capacity and
refill rate equal to the configured events per second. Both quota limits record the drop
reason `quota`, counted as shed usage. Member settings and API keys cannot change quotas.

## Server ingestion

Create a secret ingest key in a site's settings. The `ryvr_sk_` secret is shown once and
stored only as an HMAC-SHA256 digest keyed with the application key. Rotation replaces the
old credential immediately; revocation also takes effect immediately.

Send `POST /v1/batch` with `Authorization: Bearer <secret-ingest-key>` and a JSON array of
at most 500 event objects. Each object requires `name` and an HTTP(S) `url` belonging to
the site. Optional fields are `referrer`, `props` (an object), `revenue`, `currency`, `ts`,
`event_id`, `ip` and `user_agent`. Enrichment and traffic exclusions match the public
collector. An explicitly supplied IP is used for enrichment and discarded.

`ts` accepts an ISO timestamp or Unix seconds and is clamped to 72 hours before receipt
through five minutes after receipt. Omitted timestamps use receipt time. Metering always
uses the receipt hour. A string `event_id` prevents duplicate accepted events for 24 hours
within a site, including across key rotation. Quota rejections do not consume that ID.
The response is `202 {"accepted": <count>, "rejected": <count>}`. Invalid individual
events, excluded events and duplicate IDs contribute to `rejected`. Invalid batch shapes
return 422. Per-key request limits return 429 with `Retry-After`.

Set `RYVR_BATCH_RATE_LIMIT` to adjust the default 120 requests per minute per secret key.
Use a shared cache store for token buckets and duplicate detection across processes.

Apply the new control-plane migrations and ClickHouse migration before starting the
updated writer. Metering begins with this migration; earlier event history is not backfilled.
