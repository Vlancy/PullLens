<?php

namespace App\Enums\Users;

/**
 * Application roles backed by spatie/laravel-permission.
 *
 * Roles are coarse-grained buckets; fine-grained authorization is expressed with
 * the permissions in {@see UserPermission}. Every role is granted a fixed set of
 * permissions by the RolesAndPermissionsSeeder, which is the single source of
 * truth for the role/permission matrix.
 */
enum UserRole: string implements \JsonSerializable
{
    /** Full control: user administration, integrations, AI providers, observability. */
    case Admin = 'admin';

    /** Day-to-day operator: manages repositories/findings but not users or credentials. */
    case Manager = 'manager';

    /** Read-only access to dashboards, reports and findings across every repository. */
    case Member = 'member';

    /**
     * Scoped read-only access: sees only the repositories explicitly assigned to them.
     * Deliberately has no reports.view — the aggregate reports span every repository
     * and cannot be meaningfully narrowed to one person's subset.
     */
    case Contributor = 'contributor';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Manager => 'Manager',
            self::Member => 'Member',
            self::Contributor => 'Contributor (scoped to assigned repositories)',
        };
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Permissions granted to this role. Consumed by the seeder to (re)build the matrix.
     *
     * @return array<int, UserPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            // Admin receives every permission — including future ones — by definition.
            self::Admin => UserPermission::cases(),

            self::Manager => [
                UserPermission::ViewReports,
                UserPermission::ViewFindings,
                UserPermission::ResolveFindings,
                UserPermission::ViewAllRepositories,
                UserPermission::ManageRepositories,
                UserPermission::TriggerReviews,
                UserPermission::UseAssistant,
            ],

            self::Member => [
                UserPermission::ViewReports,
                UserPermission::ViewFindings,
                UserPermission::ViewAllRepositories,
            ],

            // No ViewAllRepositories: this role is the reason the assignment pivot exists.
            self::Contributor => [
                UserPermission::ViewFindings,
            ],
        };
    }
}
