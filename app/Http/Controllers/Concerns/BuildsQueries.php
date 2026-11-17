<?php

namespace App\Http\Controllers\Concerns;

use App\Analytics\Query;
use Illuminate\Http\Request;

trait BuildsQueries
{
    /**
     * Read period, custom range and filters from the request. Unknown filters are dropped.
     *
     * @param  list<string>  $projectIds  already authorized by the caller's Access
     */
    protected function queryFromRequest(Request $request, array $projectIds, string $timezone): Query
    {
        $period = (string) $request->query('period', '30d');
        if (! in_array($period, Query::PERIODS, true)) {
            $period = '30d';
        }

        $filters = [];
        foreach ((array) $request->query('f', []) as $dimension => $value) {
            if (isset(Query::DIMENSIONS[$dimension]) && is_string($value) && $value !== '' && mb_strlen($value) <= 500) {
                $filters[$dimension] = $value;
            }
        }

        $date = fn (string $key) => is_string($request->query($key)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query($key)) ? $request->query($key) : null;

        return Query::forPeriod($projectIds, $period, $timezone, $filters, $date('from'), $date('to'));
    }

    /**
     * @return array{period: string, from: string, to: string, filters: \stdClass, interval: string, timezone: string}
     */
    protected function describeQuery(Query $query): array
    {
        return [
            'period' => $query->period,
            'from' => $query->from->setTimezone($query->timezone)->toDateString(),
            'to' => $query->to->setTimezone($query->timezone)->subSecond()->toDateString(),
            'filters' => (object) $query->filters,
            'interval' => $query->interval(),
            'timezone' => $query->timezone,
        ];
    }
}
