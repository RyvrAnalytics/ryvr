<?php

namespace App\Console\Commands;

use App\Analytics\ClickHouse\Migrator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ryvr:migrate {--force : Run in production without asking} {--clickhouse-only : Skip the PostgreSQL migrations}')]
#[Description('Migrate the PostgreSQL control plane and the ClickHouse event store')]
class MigrateCommand extends Command
{
    public function handle(Migrator $migrator): int
    {
        if (! $this->option('clickhouse-only')) {
            $code = $this->call('migrate', ['--force' => $this->option('force')]);
            if ($code !== self::SUCCESS) {
                return $code;
            }
        }

        $this->components->info('Migrating the ClickHouse event store.');
        $applied = $migrator->run(fn (string $name) => $this->components->task($name));

        if ($applied === []) {
            $this->components->info('ClickHouse is up to date.');
        }

        return self::SUCCESS;
    }
}
