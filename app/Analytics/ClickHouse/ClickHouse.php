<?php

namespace App\Analytics\ClickHouse;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for ClickHouse.
 *
 * Every value reaches the server as a bound query parameter ({name:Type}), never by string
 * interpolation, so callers cannot build injectable SQL by accident.
 */
class ClickHouse
{
    /**
     * @param  array{url: string, database: string, username: string, password: string, timeout?: int, query_max_execution_time?: int}  $config
     */
    public function __construct(private readonly array $config, private readonly bool $readOnly = false) {}

    /**
     * Run a SELECT and return its rows as associative arrays.
     *
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $body = $this->send($sql.' FORMAT JSONEachRow', $params, [
            'max_execution_time' => $this->config['query_max_execution_time'] ?? 20,
            'readonly' => 2,
            // Numbers come back as JSON numbers, not quoted strings.
            'output_format_json_quote_64bit_integers' => 0,
        ]);

        $rows = [];
        foreach (preg_split('/\n/', trim($body)) ?: [] as $line) {
            if ($line !== '') {
                $rows[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $rows;
    }

    /**
     * Run a statement that returns no rows (DDL, mutations).
     *
     * @param  array<string, mixed>  $params
     */
    public function statement(string $sql, array $params = []): void
    {
        $this->ensureWritable();

        $this->send($sql, $params);
    }

    /**
     * Insert rows with JSONEachRow. Uses server-side async inserts so many small batches
     * are merged into few parts.
     *
     * With a deduplication token the insert is synchronous and idempotent: sending the same
     * batch again with the same token (a retry after a crash) adds no rows, as long as the
     * table has a deduplication window (see database/clickhouse/0003 and 0004).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function insert(string $table, array $rows, bool $async = true, ?string $deduplicationToken = null): void
    {
        $this->ensureWritable();

        if ($rows === []) {
            return;
        }

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name [{$table}].");
        }

        $payload = implode("\n", array_map(
            fn (array $row) => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $rows,
        ));

        $settings = match (true) {
            $deduplicationToken !== null => ['insert_deduplication_token' => $deduplicationToken, 'insert_deduplicate' => 1],
            $async => ['async_insert' => 1, 'wait_for_async_insert' => 1],
            default => [],
        };

        $this->request()
            ->withQueryParameters($this->baseQuery($settings + [
                'query' => "INSERT INTO {$table} FORMAT JSONEachRow",
                'input_format_skip_unknown_fields' => 1,
                'date_time_input_format' => 'best_effort',
            ]))
            ->withBody($payload, 'application/x-ndjson')
            ->post($this->config['url'])
            ->throw(fn ($response) => throw new ClickHouseException(trim($response->body())));
    }

    public function ping(): bool
    {
        try {
            return trim($this->request()->timeout(3)->get(rtrim($this->config['url'], '/').'/ping')->body()) === 'Ok.';
        } catch (ConnectionException) {
            return false;
        }
    }

    public function version(): string
    {
        return (string) ($this->select('SELECT version() AS v')[0]['v'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $settings
     */
    private function send(string $sql, array $params, array $settings = []): string
    {
        $query = $this->baseQuery($settings);
        foreach ($params as $name => $value) {
            $query['param_'.$name] = self::formatParam($value);
        }

        $response = $this->request()
            ->withQueryParameters($query)
            ->withBody($sql, 'text/plain')
            ->post($this->config['url']);

        // A streaming response can contain an exception after HTTP headers were sent.
        $body = $response->body();
        $hasException = preg_match('/^Code:\s*(\d+)\b/m', $body, $matches);
        if ($response->failed() || $hasException) {
            throw new ClickHouseException(trim($body), (int) ($response->header('X-ClickHouse-Exception-Code') ?: ($matches[1] ?? 0)));
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function baseQuery(array $settings): array
    {
        if (request()->is('embed/v1/*')) {
            $settings = array_replace($settings, [
                'max_execution_time' => max(1, (int) config('ryvr.embed.max_execution_time', 5)),
                'max_rows_to_read' => max(1, (int) config('ryvr.embed.max_rows_to_read', 200_000_000)),
                'timeout_overflow_mode' => 'throw',
                'read_overflow_mode' => 'throw',
            ]);
        }

        // Parameters are always sent in UTC; pin the session so the server's own timezone never matters.
        return ['database' => $this->config['database'], 'session_timezone' => 'UTC'] + $settings;
    }

    private function ensureWritable(): void
    {
        if ($this->readOnly) {
            throw new \LogicException('The read-only ClickHouse client cannot execute writes.');
        }
    }

    private function request(): PendingRequest
    {
        return Http::timeout($this->config['timeout'] ?? 30)
            ->withHeaders([
                'X-ClickHouse-User' => $this->config['username'],
                'X-ClickHouse-Key' => (string) $this->config['password'],
            ]);
    }

    /**
     * Format a PHP value in ClickHouse's text format for query parameters.
     */
    public static function formatParam(mixed $value): string
    {
        if (is_array($value)) {
            return '['.implode(',', array_map(
                fn ($v) => is_string($v) ? "'".self::escape($v)."'" : self::formatParam($v),
                $value,
            )).']';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \DateTime || $value instanceof \DateTimeImmutable) {
            return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
