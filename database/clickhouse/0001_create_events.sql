CREATE TABLE IF NOT EXISTS events
(
    project_id       UUID,
    organization_id  UUID,
    ts               DateTime64(3, 'UTC'),
    event_id         UUID,
    name             LowCardinality(String),
    visitor_id       UInt64,
    session_id       UInt64,
    hostname         LowCardinality(String),
    pathname         String,
    referrer         String,
    referrer_domain  String,
    channel          LowCardinality(String),
    utm_source       String,
    utm_medium       LowCardinality(String),
    utm_campaign     String,
    utm_content      String,
    utm_term         String,
    country          LowCardinality(String),
    region           LowCardinality(String),
    city             String,
    browser          LowCardinality(String),
    browser_version  LowCardinality(String),
    os               LowCardinality(String),
    os_version       LowCardinality(String),
    device           LowCardinality(String),
    screen_width     UInt16,
    language         LowCardinality(String),
    props            Map(LowCardinality(String), String),
    engagement_ms    UInt32,
    revenue          Decimal(18, 4),
    currency         LowCardinality(String),
    consent          LowCardinality(String)
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(ts)
ORDER BY (project_id, toDate(ts), visitor_id, ts)
SETTINGS index_granularity = 8192;
