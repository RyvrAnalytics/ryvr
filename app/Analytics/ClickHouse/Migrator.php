<?php

namespace App\Analytics\ClickHouse;

use Illuminate\Support\Facades\File;

/**
 * Applies the versioned SQL files in database/clickhouse in order and records each one
 * in a ledger table, so every install converges on the same schema.
 */
class Migrator
{
    public const MINIMUM_VERSION = '24.8';

    public function __construct(private readonly ClickHouse $clickhouse) {}

    /**
     * @return list<string> the migrations applied by this run
     */
    public function run(?callable $output = null): array
    {
        $version = $this->clickhouse->version();
        if (version_compare($version, self::MINIMUM_VERSION, '<')) {
            throw new ClickHouseException("ClickHouse {$version} is not supported. RYVR needs ".self::MINIMUM_VERSION.' or newer.');
        }

        $this->clickhouse->statement(
            'CREATE TABLE IF NOT EXISTS ryvr_migrations (name String, applied_at DateTime DEFAULT now()) ENGINE = MergeTree ORDER BY name'
        );

        $done = array_column($this->clickhouse->select('SELECT name FROM ryvr_migrations'), 'name');
        $applied = [];

        foreach ($this->files() as $name => $path) {
            if (in_array($name, $done, true)) {
                continue;
            }
            foreach ($this->statements(File::get($path)) as $sql) {
                $this->clickhouse->statement($sql);
            }
            $this->clickhouse->insert('ryvr_migrations', [['name' => $name]], async: false);
            $applied[] = $name;
            $output && $output($name);
        }

        return $applied;
    }

    /**
     * @return array<string, string>
     */
    public function files(): array
    {
        $files = [];
        foreach (File::glob(database_path('clickhouse/*.sql')) as $path) {
            $files[basename($path, '.sql')] = $path;
        }
        ksort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function statements(string $sql): array
    {
        $lines = array_filter(explode("\n", $sql), fn ($l) => ! str_starts_with(ltrim($l), '--'));

        return array_values(array_filter(array_map('trim', explode(';', implode("\n", $lines)))));
    }
}
