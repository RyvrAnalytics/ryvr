<?php

namespace App\Analytics;

use App\Models\Organization;
use Carbon\CarbonImmutable;

class UsageReport
{
    public function __construct(private readonly Analytics $analytics) {}

    /**
     * @param  list<string>|null  $projectIds
     * @return array<string, mixed>
     */
    public function forOrganization(Organization $organization, ?array $projectIds = null): array
    {
        $today = CarbonImmutable::now('UTC');
        $month = $today->startOfMonth();
        $lastMonth = $month->subMonth();
        $empty = ['accepted' => 0, 'filtered' => 0, 'shed' => 0, 'bytes' => 0];
        $sites = $organization->projects()->when($projectIds !== null, fn ($q) => $q->whereIn('id', $projectIds))->orderBy('name')->get()
            ->mapWithKeys(fn ($p) => [$p->id => ['id' => $p->id, 'name' => $p->name, 'domain' => $p->domain, 'this_month' => $empty, 'last_month' => $empty]])->all();
        $daily = [];
        for ($day = $month; $day <= $today->startOfDay(); $day = $day->addDay()) {
            $daily[$day->toDateString()] = ['day' => $day->toDateString()] + $empty;
        }
        $totals = ['this_month' => $empty, 'last_month' => $empty];
        $rows = $this->analytics->usage($organization->id, $lastMonth, $today->addHour()->startOfHour(), projectIds: $projectIds);
        foreach ($rows as $row) {
            $id = $row['project_id'];
            $sites[$id] ??= ['id' => $id, 'name' => 'Removed site', 'domain' => '', 'this_month' => $empty, 'last_month' => $empty];
            $period = $row['bucket'] >= $month->toDateString() ? 'this_month' : 'last_month';
            foreach (array_keys($empty) as $metric) {
                $sites[$id][$period][$metric] += $row[$metric];
                $totals[$period][$metric] += $row[$metric];
                if (isset($daily[$row['bucket']])) {
                    $daily[$row['bucket']][$metric] += $row[$metric];
                }
            }
        }

        return [
            'timezone' => 'UTC', 'this_month' => $month->format('Y-m'), 'last_month' => $lastMonth->format('Y-m'),
            'sites' => array_values($sites), 'totals' => $totals, 'daily' => array_values($daily),
        ];
    }
}
