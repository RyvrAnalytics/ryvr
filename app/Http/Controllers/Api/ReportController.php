<?php

namespace App\Http\Controllers\Api;

use App\Analytics\Reports;
use App\Http\Controllers\Controller;
use App\Tenancy\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * REST API v1. Read-only. Authenticated by AuthenticateApiKey.
 *
 * Common query parameters: period (today, 7d, 30d, ...), from/to (YYYY-MM-DD with period=custom),
 * filters[dimension]=value.
 */
class ReportController extends Controller
{
    public function __construct(private readonly Reports $reports) {}

    public function sites(Access $access): JsonResponse
    {
        return response()->json(['data' => $this->reports->sites($access)]);
    }

    public function overview(Request $request, Access $access, string $site): JsonResponse
    {
        return $this->respond(fn () => $this->reports->overview($access, $site, $this->params($request)));
    }

    public function trend(Request $request, Access $access, string $site): JsonResponse
    {
        return $this->respond(fn () => $this->reports->trend($access, $site, $this->params($request)));
    }

    public function top(Request $request, Access $access, string $site, string $dimension): JsonResponse
    {
        return $this->respond(fn () => $this->reports->top($access, $site, $dimension, $this->params($request)));
    }

    public function goals(Request $request, Access $access, string $site): JsonResponse
    {
        return $this->respond(fn () => $this->reports->goals($access, $site, $this->params($request)));
    }

    public function compare(Request $request, Access $access): JsonResponse
    {
        return $this->respond(fn () => $this->reports->compare($access, $this->params($request) + ['sites' => (array) $request->query('sites', [])]));
    }

    public function realtime(Access $access): JsonResponse
    {
        return response()->json(['data' => $this->reports->realtime($access)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function params(Request $request): array
    {
        return [
            'period' => $request->query('period', '30d'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'interval' => $request->query('interval'),
            'limit' => (int) $request->query('limit', 10),
            'filters' => (array) $request->query('filters', []),
        ];
    }

    private function respond(\Closure $callback): JsonResponse
    {
        try {
            return response()->json(['data' => $callback()]);
        } catch (InvalidArgumentException $e) {
            $status = str_starts_with($e->getMessage(), 'Site not found') ? 404 : 422;

            return response()->json(['message' => $e->getMessage()], $status);
        }
    }
}
