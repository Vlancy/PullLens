import { Link } from '@inertiajs/react';
import {
    Activity,
    Coins,
    CalendarDays,
    GitBranch,
    GitCommitHorizontal,
    LayoutDashboard,
    ListChecks,
    Users,
} from 'lucide-react';

/**
 * Sub-navigation shared by every report page.
 *
 * Previously this component was copy-pasted into each of the seven report pages,
 * so adding a report meant editing all of them and they drifted apart. It now lives
 * in one place; `active` is the href of the page rendering it.
 */
const TABS = [
    { icon: LayoutDashboard, label: 'Overview', href: '/reports' },
    { icon: ListChecks, label: 'Tasks', href: '/reports/tasks' },
    { icon: Users, label: 'Team', href: '/reports/developers' },
    { icon: GitBranch, label: 'Repos', href: '/reports/repositories' },
    { icon: GitCommitHorizontal, label: 'Commit Quality', href: '/reports/commits' },
    { icon: CalendarDays, label: 'Daily Activity', href: '/reports/daily' },
    { icon: Activity, label: 'Daily Effort', href: '/reports/developer-daily' },
    { icon: Coins, label: 'AI Usage', href: '/reports/ai-usage' },
];

export function ReportsNav({ active }: { active: string }) {
    return (
        <div className="flex flex-wrap gap-0.5 border-b border-border">
            {TABS.map((tab) => (
                <Link
                    key={tab.href}
                    href={tab.href}
                    className={[
                        'flex items-center gap-1.5 rounded-t-md px-3 py-2 text-sm font-medium transition-colors',
                        active === tab.href
                            ? 'border-b-2 border-primary bg-background text-foreground'
                            : 'text-muted-foreground hover:bg-muted/50 hover:text-foreground',
                    ].join(' ')}
                >
                    <tab.icon className="size-3.5 shrink-0" />
                    {tab.label}
                </Link>
            ))}
        </div>
    );
}

export default ReportsNav;
