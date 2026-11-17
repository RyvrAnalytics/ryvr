<?php

namespace App\Mcp\Tools;

use App\Analytics\Reports;
use App\Tenancy\Access;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('compare_sites')]
#[Description('Every site side by side for a period: visitors, visits, pageviews, bounce rate, duration and change versus the previous period. Totals are a sum of sites, not unique people.')]
class CompareSites extends RyvrTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['sites' => $schema->array()->items($schema->string())->description('Limit to these site ids. Omit for all sites.'), ...$this->rangeSchema($schema)];
    }

    protected function run(Reports $reports, Access $access, array $arguments): array
    {
        return $reports->compare($access, $this->rangeParams($arguments) + ['sites' => is_array($arguments['sites'] ?? null) ? $arguments['sites'] : []]);
    }
}
