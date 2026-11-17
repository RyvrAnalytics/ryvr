<?php

use App\Mcp\Servers\RyvrServer;
use Laravel\Mcp\Facades\Mcp;

// MCP over streamable HTTP. Clients send "Authorization: Bearer ryvr_pat_..." (or ryvr_org_...).
Mcp::web('/mcp', RyvrServer::class)->middleware(['api.key:analytics:read,mcp', 'throttle:120,1']);
