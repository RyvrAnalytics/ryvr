<?php

namespace App\Mcp\Tools;

use App\Analytics\Analytics;
use App\Analytics\Query;
use App\Analytics\Reports;
use App\Tenancy\Access;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_top')]
#[Description('Top values for one dimension of one site: pages, entry or exit pages, referrers, channels, campaigns, countries, browsers, devices or custom events.')]
class GetTop extends RyvrTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['site' => $this->siteSchema($schema), 'dimension' => $schema->string()->enum(array_merge(array_keys(Query::DIMENSIONS), Analytics::SESSION_DIMENSIONS))->description('What to rank.')->required(), 'limit' => $schema->integer()->description('Rows to return, 1 to 100. Default 10.'), ...$this->rangeSchema($schema)];
    }

    protected function run(Reports $reports, Access $access, array $arguments): array
    {
        return $reports->top($access, (string) ($arguments['site'] ?? ''), (string) ($arguments['dimension'] ?? ''), $this->rangeParams($arguments) + ['limit' => min(100, max(1, (int) ($arguments['limit'] ?? 10)))]);
    }
}
