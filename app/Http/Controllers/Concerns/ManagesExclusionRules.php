<?php

namespace App\Http\Controllers\Concerns;

use App\Ingestion\Exclusion\Field;
use App\Ingestion\Exclusion\Operator;
use App\Models\AuditLog;
use App\Models\ExclusionRule;
use App\Models\Project;
use App\Tenancy\Access;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

trait ManagesExclusionRules
{
    /**
     * @return list<array<string, mixed>>
     */
    protected function rulesFor(Access $access, ?Project $project): array
    {
        return ExclusionRule::where('organization_id', $access->organization->id)
            ->where('project_id', $project?->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (ExclusionRule $r) => [
                'id' => $r->id,
                'field' => $r->field->value,
                'field_label' => $r->field->label(),
                'operator' => $r->operator->value,
                'operator_label' => $r->operator->label(),
                'value' => $r->value,
                'note' => $r->note,
                'enabled' => $r->enabled,
            ])->all();
    }

    /**
     * @return list<array{value: string, label: string, operators: list<array{value: string, label: string}>}>
     */
    protected function fieldOptions(): array
    {
        return array_map(fn (Field $f) => [
            'value' => $f->value,
            'label' => $f->label(),
            'operators' => array_map(fn (Operator $o) => ['value' => $o->value, 'label' => $o->label()], $f->operators()),
        ], Field::cases());
    }

    protected function createRule(Request $request, Access $access, ?Project $project): ExclusionRule
    {
        $data = $request->validate([
            'field' => ['required', Rule::enum(Field::class)],
            'operator' => ['required', Rule::enum(Operator::class)],
            'value' => ['required', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $field = Field::from($data['field']);
        $operator = Operator::from($data['operator']);
        $value = trim($data['value']);

        if (! in_array($operator, $field->operators(), true)) {
            throw ValidationException::withMessages(['operator' => "That condition does not apply to {$field->label()}."]);
        }
        if ($operator === Operator::Regex && ! Operator::validRegex($value)) {
            throw ValidationException::withMessages(['value' => 'This is not a valid regular expression.']);
        }
        if ($field === Field::Ip && $operator === Operator::Equals && ! filter_var($value, FILTER_VALIDATE_IP)) {
            throw ValidationException::withMessages(['value' => 'Enter a single IPv4 or IPv6 address.']);
        }
        if ($operator === Operator::Cidr && ! $this->validCidr($value)) {
            throw ValidationException::withMessages(['value' => 'Enter a range such as 203.0.113.0/24 or 2001:db8::/32.']);
        }
        if ($field === Field::Country) {
            $value = strtoupper($value);
            if (! preg_match('/^[A-Z]{2}$/', $value)) {
                throw ValidationException::withMessages(['value' => 'Use a two-letter country code, such as CA.']);
            }
        }

        $rule = ExclusionRule::create([
            'organization_id' => $access->organization->id,
            'project_id' => $project?->id,
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'note' => $data['note'] ?? null,
        ]);

        AuditLog::record('exclusion.created', $access->organization->id, $rule, ['field' => $field->value, 'operator' => $operator->value, 'project_id' => $project?->id]);

        return $rule;
    }

    protected function toggleRule(Request $request, Access $access, ExclusionRule $rule): void
    {
        $rule->update(['enabled' => $request->boolean('enabled')]);
        AuditLog::record($rule->enabled ? 'exclusion.enabled' : 'exclusion.disabled', $access->organization->id, $rule);
    }

    protected function deleteRule(Access $access, ExclusionRule $rule): void
    {
        $rule->delete();
        AuditLog::record('exclusion.deleted', $access->organization->id, $rule, ['field' => $rule->field->value, 'value' => $rule->value]);
    }

    private function validCidr(string $value): bool
    {
        if (! str_contains($value, '/')) {
            return false;
        }
        [$ip, $bits] = explode('/', $value, 2);
        $max = str_contains($ip, ':') ? 128 : 32;

        return filter_var($ip, FILTER_VALIDATE_IP) && ctype_digit($bits) && (int) $bits <= $max && IpUtils::checkIp($ip, $value);
    }
}
