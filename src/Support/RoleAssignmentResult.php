<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Support;

enum RoleAssignmentResult: string
{
    case NotConfigured = 'not_configured';
    case NotSupported = 'not_supported';
    case AlreadyAssigned = 'already_assigned';
    case Assigned = 'assigned';
    case Failed = 'failed';

    public function describe(string $role): string
    {
        return match ($this) {
            self::NotConfigured => 'No super-admin role could be resolved.',
            self::NotSupported => 'Role assignment skipped: User model does not use Spatie HasRoles.',
            self::AlreadyAssigned => sprintf('Role "%s" was already assigned.', $role),
            self::Assigned => sprintf('Role "%s" assigned successfully.', $role),
            self::Failed => sprintf('Role "%s" FAILED to assign. The role probably does not exist yet.', $role),
        };
    }

    public function isProblem(): bool
    {
        return $this === self::Failed;
    }
}
