<?php

namespace App\Mcp\Tools;

use App\Analytics\Reports;
use App\Tenancy\Access;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_realtime')]
#[Description('Visitors active in the last five minutes on each site.')]
class GetRealtime extends RyvrTool
{
    protected function run(Reports $reports, Access $access, array $arguments): array
    {
        return $reports->realtime($access);
    }
}
