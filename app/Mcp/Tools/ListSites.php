<?php

namespace App\Mcp\Tools;

use App\Analytics\Reports;
use App\Tenancy\Access;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_sites')]
#[Description('List the websites this key can read, with their ids, domains and timezones. Call this first.')]
class ListSites extends RyvrTool
{
    protected function run(Reports $reports, Access $access, array $arguments): array
    {
        return ['sites' => $reports->sites($access)];
    }
}
