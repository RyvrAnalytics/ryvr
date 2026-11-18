<?php

namespace App\Enums;

enum Capability: string
{
    case ViewAnalytics = 'analytics.view';
    case ExportData = 'analytics.export';
    case UseApi = 'api.use';
    case ManageGoals = 'goals.manage';
    case ManageProjects = 'projects.manage';
    case ManageExclusions = 'exclusions.manage';
    case ManageMembers = 'members.manage';
    case ManageApiKeys = 'api_keys.manage';
    case ManageEmbeds = 'embeds.manage';
    case ViewAuditLog = 'audit.view';
    case ManageOrganization = 'organization.manage';
    case ManageOwners = 'owners.manage';
    case ManageBilling = 'billing.manage';
    case ViewUsage = 'usage.view';
}
