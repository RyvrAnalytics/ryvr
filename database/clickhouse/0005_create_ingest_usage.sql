CREATE TABLE IF NOT EXISTS ingest_usage
(
    organization_id UUID,
    project_id      UUID,
    hour            DateTime('UTC'),
    accepted        UInt64,
    filtered        UInt64,
    shed            UInt64,
    bytes           UInt64
)
ENGINE = SummingMergeTree((accepted, filtered, shed, bytes))
ORDER BY (organization_id, project_id, hour)
SETTINGS non_replicated_deduplication_window = 1000;
