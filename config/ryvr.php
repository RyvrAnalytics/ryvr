<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ClickHouse event store
    |--------------------------------------------------------------------------
    */
    'clickhouse' => [
        'url' => env('CLICKHOUSE_URL', 'http://127.0.0.1:8123'),
        'database' => env('CLICKHOUSE_DATABASE', 'ryvr'),
        'username' => env('CLICKHOUSE_USERNAME', 'default'),
        'password' => env('CLICKHOUSE_PASSWORD', ''),
        'timeout' => (int) env('CLICKHOUSE_TIMEOUT', 30),
        'query_max_execution_time' => (int) env('CLICKHOUSE_MAX_EXECUTION_TIME', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingestion
    |--------------------------------------------------------------------------
    */
    'ingest' => [
        // Redis stream that buffers accepted events between the collector and the writer.
        'stream' => env('RYVR_INGEST_STREAM', 'ryvr:ingest'),
        'consumer_group' => 'writers',
        'batch_size' => (int) env('RYVR_INGEST_BATCH', 500),
        // Inactivity gap that ends a session, in minutes.
        'session_timeout' => (int) env('RYVR_SESSION_TIMEOUT', 30),
        // Hard caps that protect the event store from abusive payloads.
        'max_props' => 30,
        'max_prop_key_length' => 64,
        'max_prop_value_length' => 512,
        'max_url_length' => 2048,
        'max_event_name_length' => 120,
        'max_body_bytes' => 8192,
        // Header that carries the real client IP when RYVR sits behind a CDN or proxy you
        // control (for example CF-Connecting-IP). Leave empty otherwise: it can be forged.
        'client_ip_header' => env('RYVR_CLIENT_IP_HEADER'),
        // Hits per minute accepted from one IP for one project before the rest are dropped.
        'rate_limit' => (int) env('RYVR_INGEST_RATE_LIMIT', 300),
    ],

    'geoip' => [
        'database' => env('RYVR_GEOIP_DATABASE'),
        'trust_cdn_country' => (bool) env('RYVR_TRUST_CDN_COUNTRY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Query guard rails
    |--------------------------------------------------------------------------
    */
    'query' => [
        'cache_seconds' => (int) env('RYVR_QUERY_CACHE', 60),
        'max_breakdown_rows' => 500,
        'max_range_days' => 3660,
    ],

    // Allow new sign-ups. The first user can always register on an empty instance.
    'registration' => (bool) env('RYVR_REGISTRATION', false),

    // Require new accounts to confirm their email address (needs working mail).
    'require_email_verification' => (bool) env('RYVR_REQUIRE_EMAIL_VERIFICATION', false),

];
