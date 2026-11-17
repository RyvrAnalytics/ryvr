<?php

namespace App\Mcp\Tools;

use App\Analytics\Reports;
use App\Tenancy\Access;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_goals')]
#[Description('Goal conversions (visitors and conversion rate) and custom event counts for one site.')]
class GetGoals extends RyvrTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['site' => $this->siteSchema($schema), ...$this->rangeSchema($schema)];
    }

    protected function run(Reports $reports, Access $access, array $arguments): array
    {
        return $reports->goals($access, (string) ($arguments['site'] ?? ''), $this->rangeParams($arguments));
    }
}
