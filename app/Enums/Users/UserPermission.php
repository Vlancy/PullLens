<?php

namespace App\Enums\Users;

/**
 * Fine-grained permissions checked by policies, form requests and route middleware.
 *
 * Guard every state-changing endpoint with one of these rather than with a role
 * name, so the role/permission matrix can change without touching call sites.
 */
enum UserPermission: string implements \JsonSerializable
{
    // ── Users ────────────────────────────────────────────────────────────────
    case ManageUsers = 'users.manage';

    // ── Reporting ────────────────────────────────────────────────────────────
    case ViewReports = 'reports.view';

    // ── Tasks ────────────────────────────────────────────────────────────────
    case ViewTasks = 'tasks.view';

    // ── Findings ─────────────────────────────────────────────────────────────
    case ViewFindings = 'findings.view';
    case ResolveFindings = 'findings.resolve';

    // ── Git integration ──────────────────────────────────────────────────────
    /**
     * See every repository. Users WITHOUT this permission see only the repositories
     * explicitly assigned to them (see RepositoryAccessLevel), everywhere in the app.
     */
    case ViewAllRepositories = 'repositories.view-all';

    case ManageRepositories = 'repositories.manage';
    case ManageIntegrations = 'integrations.manage';
    case TriggerReviews = 'reviews.trigger';

    // ── AI ───────────────────────────────────────────────────────────────────
    case ManageAiProviders = 'ai-providers.manage';
    case UseAssistant = 'assistant.use';

    // ── Operations ───────────────────────────────────────────────────────────
    case ViewObservability = 'observability.view';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::ManageUsers => 'Manage users',
            self::ViewReports => 'View reports',
            self::ViewTasks => 'View delivered tasks',
            self::ViewFindings => 'View findings',
            self::ResolveFindings => 'Resolve findings',
            self::ViewAllRepositories => 'See every repository',
            self::ManageRepositories => 'Manage repositories',
            self::ManageIntegrations => 'Manage git integrations',
            self::TriggerReviews => 'Trigger AI reviews',
            self::ManageAiProviders => 'Manage AI providers',
            self::UseAssistant => 'Use the AI assistant',
            self::ViewObservability => 'View Horizon and Telescope',
        };
    }

    /**
     * Serialize as the backing string value, so the enum crosses the wire
     * as a plain scalar rather than an object the front end must unwrap.
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * All backing values, for validation rules and "in" comparisons.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
