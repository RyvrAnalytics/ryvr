<?php

namespace Tests\Fakes;

use App\Analytics\ClickHouse\ClickHouse;

/**
 * Records every query instead of running it, so feature tests need no ClickHouse server
 * and can assert on the tenant predicate each query carried.
 */
class FakeClickHouse extends ClickHouse
{
    /** @var list<array{sql: string, params: array<string, mixed>}> */
    public array $queries = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public function __construct()
    {
        parent::__construct([]);
    }

    public function select(string $sql, array $params = []): array
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];

        return $this->rows;
    }

    public function statement(string $sql, array $params = []): void
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];
    }

    public function insert(string $table, array $rows, bool $async = true): void
    {
        $this->queries[] = ['sql' => "INSERT INTO {$table}", 'params' => ['rows' => $rows]];
    }

    public function ping(): bool
    {
        return true;
    }

    /**
     * Every project id that any recorded query was allowed to read.
     *
     * @return list<string>
     */
    public function queriedProjectIds(): array
    {
        $ids = [];
        foreach ($this->queries as $query) {
            foreach (['projects', 'p'] as $key) {
                $value = $query['params'][$key] ?? null;
                $ids = array_merge($ids, (array) $value);
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
