<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

#[Signature('ryvr:org:quota {organization} {--monthly= : Monthly event limit, or unlimited} {--overage= : bill or hard_cap} {--burst= : Events per second, or 0 for unlimited}')]
#[Description('Set organization quotas as an instance administrator')]
class OrganizationQuotaCommand extends Command
{
    public function handle(): int
    {
        // Console access is administrative; an authenticated caller must also be an instance admin.
        if (auth()->user() && ! auth()->user()->is_instance_admin) {
            $this->components->error('Only instance administrators may change quotas.');

            return self::FAILURE;
        }

        $identifier = (string) $this->argument('organization');
        $organization = Organization::where(Str::isUuid($identifier) ? 'id' : 'slug', $identifier)->first();
        if (! $organization) {
            $this->components->error('Organization not found.');

            return self::FAILURE;
        }

        $data = [];
        foreach (['monthly' => 'monthly_events', 'overage' => 'overage', 'burst' => 'burst_per_second'] as $option => $column) {
            if ($this->option($option) !== null) {
                $data[$column] = $this->option($option);
            }
        }
        if (($data['monthly_events'] ?? null) === 'unlimited') {
            $data['monthly_events'] = null;
        }
        $validator = Validator::make($data, [
            'monthly_events' => ['nullable', 'integer', 'min:0', 'max:'.PHP_INT_MAX],
            'overage' => [Rule::in(['bill', 'hard_cap'])],
            'burst_per_second' => ['integer', 'min:0', 'max:4294967295'],
        ]);
        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        $organization->forceFill($validator->validated())->save();
        Cache::forget('ryvr:burst:'.$organization->id);
        AuditLog::record('organization.quota_updated', $organization->id, $organization, $data);
        $this->components->info('Organization quotas saved.');

        return self::SUCCESS;
    }
}
