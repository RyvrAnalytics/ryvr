<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Analyst = 'analyst';
    case Viewer = 'viewer';
    case Billing = 'billing';

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 40,
            self::Admin => 30,
            self::Analyst => 20,
            self::Viewer => 10,
            self::Billing => 0,
        };
    }

    public function atLeast(self $role): bool
    {
        return $this->rank() >= $role->rank();
    }

    public function can(Capability $capability): bool
    {
        return in_array($capability, self::capabilities()[$this->value], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Roles that may be given on a single project or group (never Owner or Billing).
     *
     * @return list<self>
     */
    public static function projectRoles(): array
    {
        return [self::Admin, self::Analyst, self::Viewer];
    }

    /**
     * The capability map: the one place that says what each role may do.
     *
     * @return array<string, list<Capability>>
     */
    public static function capabilities(): array
    {
        $viewer = [Capability::ViewAnalytics, Capability::ExportData];
        $analyst = [...$viewer, Capability::ManageGoals, Capability::UseApi];
        $admin = [...$analyst, Capability::ManageProjects, Capability::ManageExclusions, Capability::ManageMembers,
            Capability::ManageApiKeys, Capability::ManageEmbeds, Capability::ViewAuditLog, Capability::ViewUsage];

        return [
            self::Viewer->value => $viewer,
            self::Analyst->value => $analyst,
            self::Admin->value => $admin,
            self::Owner->value => [...$admin, Capability::ManageOrganization, Capability::ManageOwners],
            self::Billing->value => [Capability::ManageBilling, Capability::ViewUsage],
        ];
    }
}
