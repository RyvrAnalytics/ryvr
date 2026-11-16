CREATE TABLE IF NOT EXISTS ingest_drops
(
    project_id UUID,
    day        Date,
    reason     LowCardinality(String),
    hits       UInt64
)
ENGINE = SummingMergeTree(hits)
ORDER BY (project_id, day, reason);
