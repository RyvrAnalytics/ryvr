<?php

namespace App\Mcp\Tools;

use App\Analytics\Reports;
use App\Tenancy\Access;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_trend')]
#[Description('Visitors, visits and pageviews over time for one site, by hour, day or month.')]
class GetTrend extends RyvrTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['site' => $this->siteSchema($schema), 'interval' => $schema->string()->enum(['hour', 'day', 'month'])->description('Bucket size. Chosen from the range when omitted.'), ...$this->rangeSchema($schema)];
    }

    protected function run(Reports $reports, Access $access, array $arguments): array
    {
        return $reports->trend($access, (string) ($arguments['site'] ?? ''), $this->rangeParams($arguments) + ['interval' => $arguments['interval'] ?? null]);
    }
}
