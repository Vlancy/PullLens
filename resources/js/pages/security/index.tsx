import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    GitPullRequest,
    KeyRound,
    Loader2,
    Package,
    Search,
    ShieldCheck,
    Wrench,
    XCircle,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

// ─── Types ────────────────────────────────────────────────────────────────────

type Kind = 'secret' | 'vulnerability' | 'misconfiguration';

type SecurityFinding = {
    id: string;
    title: string;
    severity: string | null;
    kind: Kind | null;
    rule_id: string | null;
    package: string | null;
    installed_version: string | null;
    fixed_version: string | null;
    resource: string | null;
    url: string | null;
    file: string | null;
    line: number | null;
    explanation: string;
    suggested_fix: string;
    resolved_at: string | null;
    resolution_type: string | null;
    created_at: string;
    repository: { id: string; name: string; full_name: string } | null;
    pull_request: { number: number; title: string; web_url: string | null } | null;
};

type Option = { value: string; label: string };

type Props = {
    findings: SecurityFinding[];
    total: number;
    page: number;
    per_page: number;
    tiles: {
        open_secrets: number;
        open_critical_high_vulnerabilities: number;
        open_misconfigurations: number;
        resolved_last_7_days: number;
    };
    open_counts: Record<Kind, number>;
    scanning_enabled: Record<Kind, boolean>;
    repositories: { id: string; full_name: string }[];
    resolution_types: Option[];
    kinds: Option[];
    filters: {
        repository_id: string;
        kind: Kind;
        severity: string;
        status: string;
        search: string;
    };
};

// ─── Config ───────────────────────────────────────────────────────────────────

const SEVERITIES = ['critical', 'high', 'medium'] as const;

const severityBadge: Record<string, string> = {
    critical: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
    high: 'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300',
    medium: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
};

const kindIcon: Record<Kind, typeof KeyRound> = {
    secret: KeyRound,
    vulnerability: Package,
    misconfiguration: Wrench,
};

const scannerName: Record<Kind, string> = {
    secret: 'Secret scanning',
    vulnerability: 'Vulnerability scanning',
    misconfiguration: 'Vulnerability scanning',
};

// ─── Search box ───────────────────────────────────────────────────────────────

/**
 * A search input that owns its own draft text, keyed by the caller on `filters.search`
 * so a server round-trip (a submit, or a "clear filters") remounts it with the new
 * value instead of fighting the user's typing with an effect.
 */
function SearchBox({
    initial,
    onSubmit,
}: {
    initial: string;
    onSubmit: (value: string) => void;
}) {
    const [value, setValue] = useState(initial);

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                onSubmit(value);
            }}
            className="relative"
        >
            <Search className="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
            <input
                value={value}
                onChange={(e) => setValue(e.target.value)}
                placeholder="Search title, file or package…"
                className="h-8 w-56 rounded-md border border-border bg-background pr-3 pl-8 text-sm focus:ring-1 focus:ring-ring focus:outline-none"
            />
        </form>
    );
}

// ─── Row ──────────────────────────────────────────────────────────────────────

function SecurityRow({
    finding,
    resolutionTypes,
    checked,
    onToggle,
}: {
    finding: SecurityFinding;
    resolutionTypes: Option[];
    checked: boolean;
    onToggle: () => void;
}) {
    const [selected, setSelected] = useState(resolutionTypes[0]?.value ?? '');
    const [resolving, setResolving] = useState(false);
    const isResolved = !!finding.resolved_at;
    const Icon = finding.kind ? kindIcon[finding.kind] : ShieldCheck;

    function resolve() {
        setResolving(true);
        router.post(
            `/admin/findings/${finding.id}/resolve`,
            { resolution_type: selected },
            { preserveScroll: true, onFinish: () => setResolving(false) },
        );
    }

    return (
        <div
            className={cn(
                'flex flex-wrap items-start gap-3 border-b border-border px-4 py-4 last:border-0 sm:flex-nowrap sm:px-6',
                checked && 'bg-primary/5',
            )}
        >
            <input
                type="checkbox"
                checked={checked}
                onChange={onToggle}
                className="mt-1.5 size-4 shrink-0 cursor-pointer rounded border-border accent-primary"
            />
            <Icon className="mt-1 size-4 shrink-0 text-muted-foreground" />

            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    {finding.severity && (
                        <Badge
                            variant="outline"
                            className={cn(
                                'text-xs capitalize',
                                severityBadge[finding.severity],
                                isResolved && 'opacity-60',
                            )}
                        >
                            {finding.severity}
                        </Badge>
                    )}
                    {finding.rule_id && (
                        <span className="rounded-full bg-muted px-2 py-0.5 font-mono text-xs text-muted-foreground">
                            {finding.rule_id}
                        </span>
                    )}
                    {finding.repository && (
                        <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                            {finding.repository.name}
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
                                    <GitPullRequest className="size-3" />#
                                    {finding.pull_request.number}
                                </a>
                            ) : (
                                <span className="inline-flex items-center gap-1">
                                    <GitPullRequest className="size-3" />#
                                    {finding.pull_request.number}
                                </span>
                            )}
                        </span>
                    )}
                    {isResolved && (
                        <span className="inline-flex items-center gap-1 text-xs text-green-600 dark:text-green-400">
                            <CheckCircle2 className="size-3" />
                            {finding.resolution_type ?? 'Resolved'}
                        </span>
                    )}
                </div>

                <p
                    className={cn(
                        'mt-1 text-sm leading-snug font-medium',
                        isResolved && 'line-through opacity-60',
                    )}
                >
                    {finding.title}
                </p>

                {finding.file && (
                    <p className="mt-0.5 font-mono text-xs break-all text-muted-foreground">
                        {finding.file}
                        {finding.line ? `:${finding.line}` : ''}
                    </p>
                )}

                {finding.kind === 'vulnerability' && finding.package && (
                    <p className="mt-1 text-xs text-muted-foreground">
                        <span className="font-mono">{finding.package}</span>{' '}
                        {finding.installed_version} →{' '}
                        <span className="font-medium text-foreground">
                            {finding.fixed_version ?? 'no fix yet'}
                        </span>
                    </p>
                )}

                {finding.kind === 'misconfiguration' && finding.resource && (
                    <p className="mt-1 text-xs text-muted-foreground">
                        Resource:{' '}
                        <span className="font-mono">{finding.resource}</span>
                    </p>
                )}

                <p className="mt-1.5 line-clamp-2 text-xs leading-relaxed whitespace-pre-line text-muted-foreground">
                    {finding.explanation}
                </p>

                {finding.url && (
                    <a
                        href={finding.url}
                        target="_blank"
                        rel="noreferrer"
                        className="mt-1 inline-flex items-center gap-1 text-xs text-primary hover:underline"
                    >
                        <ExternalLink className="size-3" /> Advisory
                    </a>
                )}
            </div>

            {!isResolved && (
                <div className="flex w-full shrink-0 items-center gap-2 sm:w-auto sm:flex-col sm:items-end">
                    <select
                        value={selected}
                        onChange={(e) => setSelected(e.target.value)}
                        className="rounded-md border border-border bg-background px-2 py-1 text-xs focus:ring-1 focus:ring-ring focus:outline-none"
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
            )}
        </div>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function SecurityIndex({
    findings,
    total,
    page,
    per_page,
    tiles,
    open_counts,
    scanning_enabled,
    repositories,
    resolution_types,
    kinds,
    filters,
}: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [loading, setLoading] = useState(false);
    const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
    const [bulkResolution, setBulkResolution] = useState(
        resolution_types[0]?.value ?? '',
    );
    const [bulkResolving, setBulkResolving] = useState(false);

    function push(updates: Partial<Props['filters']> & { page?: number }) {
        router.get(
            '/security',
            { ...filters, page: 1, ...updates },
            {
                preserveScroll: true,
                replace: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            },
        );
    }

    function toggleSeverity(severity: string) {
        const current = filters.severity
            ? filters.severity.split(',').filter(Boolean)
            : [];
        const next = current.includes(severity)
            ? current.filter((s) => s !== severity)
            : [...current, severity];
        push({ severity: next.join(',') });
    }

    function toggleOne(id: string) {
        setSelectedIds((prev) => {
            const next = new Set(prev);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    }

    function toggleAll() {
        if (allSelected) {
            setSelectedIds((prev) => {
                const next = new Set(prev);
                allIds.forEach((id) => next.delete(id));

                return next;
            });
        } else {
            setSelectedIds((prev) => new Set([...prev, ...allIds]));
        }
    }

    function bulkResolve() {
        setBulkResolving(true);
        router.post(
            '/admin/findings/bulk-resolve',
            {
                finding_ids: Array.from(selectedIds),
                resolution_type: bulkResolution,
            },
            {
                preserveScroll: true,
                onSuccess: () => setSelectedIds(new Set()),
                onFinish: () => setBulkResolving(false),
            },
        );
    }

    const allIds = findings.map((f) => f.id);
    const allSelected =
        allIds.length > 0 && allIds.every((id) => selectedIds.has(id));
    const someSelected =
        !allSelected && allIds.some((id) => selectedIds.has(id));

    const selectAllRef = useCallback(
        (el: HTMLInputElement | null) => {
            if (el) {
                el.indeterminate = someSelected;
            }
        },
        [someSelected],
    );

    const lastPage = Math.max(1, Math.ceil(total / per_page));
    const errorMessages = Object.values(errors ?? {});

    const tileCards = [
        { label: 'Open secrets', value: tiles.open_secrets, icon: KeyRound, tone: 'text-red-600 dark:text-red-400' },
        { label: 'Open critical & high vulnerabilities', value: tiles.open_critical_high_vulnerabilities, icon: Package, tone: 'text-orange-600 dark:text-orange-400' },
        { label: 'Open misconfigurations', value: tiles.open_misconfigurations, icon: Wrench, tone: 'text-amber-600 dark:text-amber-400' },
        { label: 'Resolved in the last 7 days', value: tiles.resolved_last_7_days, icon: CheckCircle2, tone: 'text-green-600 dark:text-green-400' },
    ];

    return (
        <>
            <Head title="Security" />

            <div className="flex flex-1 flex-col gap-4 p-4 md:gap-6 md:p-6">
                <div className="flex items-center gap-3">
                    <ShieldCheck className="size-6 text-muted-foreground" />
                    <div>
                        <h1 className="text-xl font-semibold">Security</h1>
                        <p className="text-sm text-muted-foreground">
                            Leaked secrets, vulnerable dependencies and insecure
                            infrastructure settings pull requests introduced
                        </p>
                    </div>
                </div>

                {errorMessages.length > 0 && (
                    <div className="flex items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        <div>
                            <p className="font-medium">
                                Those filters could not be applied.
                            </p>
                            <p>{errorMessages.join(' ')}</p>
                        </div>
                    </div>
                )}

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    {tileCards.map((tile) => (
                        <Card key={tile.label}>
                            <CardContent className="pt-5">
                                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                    <tile.icon className="size-3.5" />
                                    {tile.label}
                                </p>
                                <p className={cn('mt-1 text-3xl font-bold tabular-nums', tile.tone)}>
                                    {tile.value.toLocaleString()}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <div className="flex flex-wrap items-center gap-1 rounded-lg border border-border bg-muted/40 p-1">
                    {kinds.map((kind) => {
                        const value = kind.value as Kind;
                        const Icon = kindIcon[value];

                        return (
                            <button
                                key={kind.value}
                                onClick={() => push({ kind: value })}
                                className={cn(
                                    'flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                    filters.kind === value
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <Icon className="size-4" />
                                {kind.label}
                                <span className="rounded-full bg-muted px-1.5 text-xs tabular-nums">
                                    {open_counts[value] ?? 0}
                                </span>
                            </button>
                        );
                    })}
                </div>

                <Card>
                    <CardContent className="flex flex-wrap items-center gap-3 px-4 py-3">
                        <SearchBox
                            key={filters.search}
                            initial={filters.search}
                            onSubmit={(search) => push({ search })}
                        />

                        <select
                            value={filters.repository_id}
                            onChange={(e) => push({ repository_id: e.target.value })}
                            className="h-8 rounded-md border border-border bg-background px-2 text-sm focus:ring-1 focus:ring-ring focus:outline-none"
                        >
                            <option value="">All repos</option>
                            {repositories.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.full_name}
                                </option>
                            ))}
                        </select>

                        <div className="flex items-center gap-1">
                            {SEVERITIES.map((s) => {
                                const active = filters.severity.split(',').includes(s);

                                return (
                                    <button
                                        key={s}
                                        onClick={() => toggleSeverity(s)}
                                        className={cn(
                                            'rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize transition-colors',
                                            active
                                                ? `${severityBadge[s]} border-transparent`
                                                : 'border-border text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {s}
                                    </button>
                                );
                            })}
                        </div>

                        <div className="flex items-center gap-0.5 rounded-md border border-border bg-background p-0.5">
                            {(['open', 'resolved', 'all'] as const).map((s) => (
                                <button
                                    key={s}
                                    onClick={() => push({ status: s })}
                                    className={cn(
                                        'rounded px-2.5 py-1 text-xs font-medium capitalize transition-colors',
                                        filters.status === s
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {s}
                                </button>
                            ))}
                        </div>

                        {(filters.repository_id || filters.severity || filters.search) && (
                            <button
                                onClick={() =>
                                    push({ repository_id: '', severity: '', search: '' })
                                }
                                className="ml-auto flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                            >
                                <XCircle className="size-3.5" /> Clear filters
                            </button>
                        )}
                    </CardContent>
                </Card>

                {selectedIds.size > 0 && (
                    <div className="flex items-center gap-3 rounded-lg border border-border bg-muted/50 px-4 py-2">
                        <span className="text-sm font-medium">
                            {selectedIds.size} selected
                        </span>
                        <select
                            value={bulkResolution}
                            onChange={(e) => setBulkResolution(e.target.value)}
                            className="h-7 rounded-md border border-border bg-background px-2 text-xs focus:outline-none"
                        >
                            {resolution_types.map((rt) => (
                                <option key={rt.value} value={rt.value}>
                                    {rt.label}
                                </option>
                            ))}
                        </select>
                        <Button
                            size="sm"
                            variant="outline"
                            className="h-7 gap-1.5 px-2.5 text-xs"
                            disabled={bulkResolving}
                            onClick={bulkResolve}
                        >
                            <CheckCircle2 className="size-3 text-green-500" />
                            Resolve selected
                        </Button>
                        <button
                            onClick={() => setSelectedIds(new Set())}
                            className="ml-auto text-xs text-muted-foreground hover:text-foreground"
                        >
                            Deselect all
                        </button>
                    </div>
                )}

                <Card className="overflow-hidden">
                    <div className="flex items-center gap-3 border-b border-border bg-muted/30 px-4 py-2 sm:px-6">
                        {findings.length > 0 && (
                            <input
                                type="checkbox"
                                ref={selectAllRef}
                                checked={allSelected}
                                onChange={toggleAll}
                                className="size-4 cursor-pointer rounded border-border accent-primary"
                            />
                        )}
                        {loading && <Loader2 className="size-3.5 animate-spin text-muted-foreground" />}
                        <span className="text-xs font-medium text-muted-foreground">
                            {total.toLocaleString()} result{total !== 1 ? 's' : ''}
                            {filters.status !== 'all' && ` · ${filters.status}`}
                        </span>
                    </div>

                    {loading ? (
                        <div className="space-y-3 p-6">
                            {[0, 1, 2].map((i) => (
                                <div key={i} className="h-14 animate-pulse rounded-md bg-muted" />
                            ))}
                        </div>
                    ) : findings.length > 0 ? (
                        findings.map((f) => (
                            <SecurityRow
                                key={f.id}
                                finding={f}
                                resolutionTypes={resolution_types}
                                checked={selectedIds.has(f.id)}
                                onToggle={() => toggleOne(f.id)}
                            />
                        ))
                    ) : !scanning_enabled[filters.kind] ? (
                        <div className="flex flex-col items-center gap-2 px-6 py-16 text-center text-muted-foreground">
                            <ShieldCheck className="size-8" />
                            <p className="text-sm font-medium">
                                {scannerName[filters.kind]} is off for every repository you can see
                            </p>
                            <p className="text-xs">
                                Turn it on under a repository's settings, in the Security section.
                            </p>
                            <Link href="/repositories" className="text-xs text-primary hover:underline">
                                Go to repositories
                            </Link>
                        </div>
                    ) : (
                        <div className="flex flex-col items-center gap-2 py-16 text-muted-foreground">
                            <CheckCircle2 className="size-8 text-green-500" />
                            <p className="text-sm font-medium">Nothing found</p>
                            <p className="text-xs">
                                No pull request introduced anything matching these filters.
                            </p>
                        </div>
                    )}

                    {lastPage > 1 && (
                        <div className="flex items-center justify-between border-t border-border px-4 py-2 text-xs text-muted-foreground sm:px-6">
                            <span>
                                Page {page} of {lastPage}
                            </span>
                            <div className="flex gap-1">
                                <Button size="sm" variant="outline" className="h-7 px-2" disabled={page <= 1} onClick={() => push({ page: page - 1 })}>
                                    <ChevronLeft className="size-3.5" />
                                </Button>
                                <Button size="sm" variant="outline" className="h-7 px-2" disabled={page >= lastPage} onClick={() => push({ page: page + 1 })}>
                                    <ChevronRight className="size-3.5" />
                                </Button>
                            </div>
                        </div>
                    )}
                </Card>
            </div>
        </>
    );
}
