# REST API and MCP

Both are read-only and use the same keys. Create a key under **Organization > API and MCP**. A personal key (`ryvr_pat_`) never sees more than the person who made it; an organization key (`ryvr_org_`) keeps working when people leave. Keys can be limited to some sites and given an expiry date.

Send the key as a bearer token:

```
Authorization: Bearer ryvr_pat_...
```

Requests are limited to 120 a minute per key by default (`RYVR_API_RATE_LIMIT`).

## REST API

All routes live under `/api/v1` and need the `analytics:read` permission.

| Route                               | Returns                                             |
| ----------------------------------- | --------------------------------------------------- |
| `GET /sites`                        | The sites the key can read                          |
| `GET /sites/compare`                | Every site side by side for a period                |
| `GET /realtime`                     | Visitors in the last five minutes on each site      |
| `GET /sites/{site}/overview`        | Headline numbers with the previous period           |
| `GET /sites/{site}/trend`           | Visitors, visits and pageviews over time            |
| `GET /sites/{site}/top/{dimension}` | Top pages, referrers, channels, countries and so on |
| `GET /sites/{site}/goals`           | Goal conversions and custom event counts            |

## MCP

The MCP server speaks streamable HTTP at `/mcp`. Point an MCP-capable assistant at `https://your-ryvr-host/mcp` with the bearer header above. It offers these tools:

| Tool            | What it answers                                                     |
| --------------- | ------------------------------------------------------------------- |
| `list_sites`    | Which sites the key can read, with ids, domains and timezones       |
| `compare_sites` | Every site side by side, with change versus the previous period     |
| `get_changes`   | What rose, fell or is new, for one site or across all of them       |
| `get_overview`  | Headline numbers for one site, plus visitors online now             |
| `get_trend`     | Visitors, visits and pageviews by hour, day or month                |
| `get_top`       | Top values for one dimension, such as pages, referrers or countries |
| `get_goals`     | Goal conversions and custom event counts                            |
| `get_realtime`  | Visitors active in the last five minutes on each site               |
