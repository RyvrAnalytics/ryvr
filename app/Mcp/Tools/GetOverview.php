<?php

namespace App\Mcp\Tools;

use App\Analytics\Reports;
use App\Tenancy\Access;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_overview')]
#[Description('Headline numbers for one site: visitors, visits, pageviews, bounce rate, visit duration and events, with the previous period for comparison and visitors online now.')]
class GetOverview extends RyvrTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['site' => $this->siteSchema($schema), ...$this->rangeSchema($schema)];
    }

    protected function run(Reports $reports, Access $access, array $arguments): array
    {
        return $reports->overview($access, (string) ($arguments['site'] ?? ''), $this->rangeParams($arguments));
    }
}
