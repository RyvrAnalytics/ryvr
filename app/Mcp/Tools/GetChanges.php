<?php

namespace App\Mcp\Tools;

use App\Analytics\Reports;
use App\Tenancy\Access;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_changes')]
#[Description('What changed versus the previous period: rising, falling and new referrers, pages, channels, campaigns, countries and events for one site, or which sites moved (or went silent) across the portfolio when no site is given. Start here when asked "what happened" or "anything unusual".')]
class GetChanges extends RyvrTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('Site id or domain. Omit to compare every site.'),
            ...$this->rangeSchema($schema),
        ];
    }

    protected function run(Reports $reports, Access $access, array $arguments): array
    {
        return $reports->changes($access, isset($arguments['site']) ? (string) $arguments['site'] : null, $this->rangeParams($arguments));
    }
}
