import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    CalendarDays,
    CheckCircle2,
    ExternalLink,
    GitBranch,
    GitCommitHorizontal,
    GitPullRequest,
    LayoutDashboard,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

// ─── Types ────────────────────────────────────────────────────────────────────

type Repository = {
    id: string;
    full_name: string;
    web_url: string | null;
};

type PullRequestRef = {
    number: number;
    title: string;
    web_url: string | null;
    state: string | null;
};

type Finding = {
    id: string;
    title: string;
    severity: string | null;
    category: string | null;
    file: string | null;
    line: number | null;
    explanation: string;
    suggested_fix: string;
    pull_request: PullRequestRef | null;
};

type ResolutionType = {
    value: string;
    label: string;
};

type Props = {
    repository: Repository;
    findings: Finding[];
    resolution_types: ResolutionType[];
};

// ─── Config ───────────────────────────────────────────────────────────────────

const severityConfig: Record<string, { dot: string; badge: string; label: string }> = {
    critical: { dot: 'bg-red-500', badge: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-400', label: 'Critical' },
    high:     { dot: 'bg-orange-500', badge: 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-400', label: 'High' },
    medium:   { dot: 'bg-yellow-500', badge: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-400', label: 'Medium' },
    low:      { dot: 'bg-blue-400', badge: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-400', label: 'Low' },
    informational: { dot: 'bg-gray-400', badge: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300', label: 'Info' },
};

// ─── Sub-nav ──────────────────────────────────────────────────────────────────

function ReportsNav({ active }: { active: string }) {
    const tabs = [
        { icon: LayoutDashboard,      label: 'Overview',       href: '/reports' },
        { icon: Users,                label: 'Team',           href: '/reports/developers' },
        { icon: GitBranch,            label: 'Repos',          href: '/reports/repositories' },
        { icon: GitCommitHorizontal,  label: 'Commit Quality', href: '/reports/commits' },
        { icon: CalendarDays,         label: 'Daily Activity', href: '/reports/daily' },
        { icon: Activity,             label: 'Daily Effort',   href: '/reports/developer-daily' },
    ];

    return (
        <div className="flex gap-0.5 border-b border-border">
            {tabs.map((tab) => (
                <Link
                    key={tab.href}
                    href={tab.href}
                    className={[
                        'flex items-center gap-1.5 px-3 py-2 text-sm font-medium transition-colors rounded-t-md',
                        active === tab.href
                            ? 'border-b-2 border-primary text-foreground bg-background'
                            : 'text-muted-foreground hover:text-foreground hover:bg-muted/50',
                    ].join(' ')}
                >
                    <tab.icon className="size-3.5 shrink-0" />
                    {tab.label}
                </Link>
            ))}
        </div>
    );
}

// ─── Finding row ──────────────────────────────────────────────────────────────

function FindingRow({
    finding,
    resolutionTypes,
}: {
    finding: Finding;
    resolutionTypes: ResolutionType[];
}) {
    const [selected, setSelected] = useState(resolutionTypes[0]?.value ?? '');
    const [resolving, setResolving] = useState(false);

    const sc = finding.severity ? severityConfig[finding.severity] : null;

    function resolve() {
        setResolving(true);
        router.post(
            `/admin/findings/${finding.id}/resolve`,
            { resolution_type: selected },
            {
                preserveScroll: true,
                onFinish: () => setResolving(false),
            },
        );
    }

    return (
        <div className="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-start">
            {/* Left: severity dot */}
            {sc && (
                <div className={`mt-1.5 size-2 shrink-0 rounded-full ${sc.dot}`} />
            )}

            {/* Center: content */}
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    {sc && (
                        <Badge className={`text-xs ${sc.badge}`} variant="outline">
                            {sc.label}
                        </Badge>
                    )}
                    {finding.category && (
                        <span className="rounded-full bg-muted px-2 py-0.5 text-xs capitalize text-muted-foreground">
                            {finding.category}
                        </span>
                    )}
                    {finding.pull_request && (
                        <span className="text-xs text-muted-foreground">
                            {finding.pull_request.web_url ? (
                                <a
                                    href={finding.pull_request.web_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center gap-1 hover:underline"
                                >
                                    <GitPullRequest className="size-3" />
                                    #{finding.pull_request.number}
                                </a>
                            ) : (
                                <span className="inline-flex items-center gap-1">
                                    <GitPullRequest className="size-3" />
                                    #{finding.pull_request.number}
                                </span>
                            )}
                        </span>
                    )}
                </div>

                <p className="mt-1 text-sm font-medium leading-snug">{finding.title}</p>

                {finding.file && (
                    <p className="mt-0.5 font-mono text-xs text-muted-foreground">
                        {finding.file}{finding.line ? `:${finding.line}` : ''}
                    </p>
                )}

                {finding.explanation && (
                    <p className="mt-1.5 text-xs text-muted-foreground leading-relaxed line-clamp-3">
                        {finding.explanation}
                    </p>
                )}
            </div>

            {/* Right: resolve controls */}
            <div className="flex shrink-0 items-center gap-2 sm:flex-col sm:items-end">
                <select
                    value={selected}
                    onChange={(e) => setSelected(e.target.value)}
                    className="rounded-md border border-border bg-background px-2 py-1 text-xs text-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                >
                    {resolutionTypes.map((rt) => (
                        <option key={rt.value} value={rt.value}>
                            {rt.label}
                        </option>
                    ))}
                </select>
                <Button
                    size="sm"
                    variant="outline"
                    className="h-7 gap-1.5 px-2.5 text-xs"
                    disabled={resolving}
                    onClick={resolve}
                >
                    <CheckCircle2 className="size-3 text-green-500" />
                    Resolve
                </Button>
            </div>
        </div>
    );
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function RepositoryFindings({ repository, findings, resolution_types }: Props) {
    return (
        <>
            <Head title={`Findings — ${repository.full_name}`} />

            <div className="flex flex-1 flex-col gap-6 p-6">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <Link
                                href="/reports/repositories"
                                className="text-sm text-muted-foreground hover:text-foreground"
                            >
                                Repos
                            </Link>
                            <span className="text-muted-foreground">/</span>
                            <h1 className="text-xl font-semibold">{repository.full_name}</h1>
                            {repository.web_url && (
                                <a
                                    href={repository.web_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="text-muted-foreground hover:text-foreground"
                                >
                                    <ExternalLink className="size-4" />
                                </a>
                            )}
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {findings.length === 0
                                ? 'No unresolved findings'
                                : `${findings.length} unresolved ${findings.length === 1 ? 'finding' : 'findings'}`}
                        </p>
                    </div>
                </div>

                <ReportsNav active="/reports/repositories" />

                {findings.length === 0 ? (
                    <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed py-16 text-center text-muted-foreground">
                        <CheckCircle2 className="size-8 text-green-500 opacity-60" />
                        <p className="text-sm">All findings are resolved.</p>
                    </div>
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            <div className="divide-y divide-border">
                                {findings.map((finding) => (
                                    <FindingRow
                                        key={finding.id}
                                        finding={finding}
                                        resolutionTypes={resolution_types}
                                    />
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

RepositoryFindings.layout = {
    breadcrumbs: [
        { title: 'Reports', href: '/reports' },
        { title: 'Repositories', href: '/reports/repositories' },
        { title: 'Findings' },
    ],
};
