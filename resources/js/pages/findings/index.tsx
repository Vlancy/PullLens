import { Head, router } from '@inertiajs/react';
import {
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    GitPullRequest,
    Search,
    ShieldAlert,
    SlidersHorizontal,
    XCircle,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

// ─── Types ────────────────────────────────────────────────────────────────────

type Finding = {
    id: string;
    title: string;
    severity: string | null;
    category: string | null;
    file: string | null;
    line: number | null;
    confidence: string | null;
    explanation: string;
    suggested_fix: string;
    resolved_at: string | null;
    resolution_type: string | null;
    created_at: string;
    repository: { id: string; name: string; full_name: string } | null;
    pull_request: { number: number; title: string; web_url: string | null; state: string | null } | null;
};

type Stats = {
    total: number;
    critical: number;
    high: number;
    medium: number;
    low: number;
    informational: number;
    resolved: number;
    open: number;
};

type TrendPoint = { date: string; count: number };
type CategoryCount = { category: string; count: number };
type Repo = { id: string; name: string; full_name: string };
type ResolutionType = { value: string; label: string };

type Props = {
    findings: Finding[];
    total: number;
    page: number;
    per_page: number;
    stats: Stats;
    top_categories: CategoryCount[];
    trend: TrendPoint[];
    repositories: Repo[];
    categories: string[];
    resolution_types: ResolutionType[];
    filters: {
        repository_id: string;
        severity: string;
        category: string;
        status: string;
        search: string;
        sort_by: string;
    };
};

// ─── Config ───────────────────────────────────────────────────────────────────

const severityConfig: Record<string, { dot: string; bar: string; badge: string; label: string }> = {
    critical:      { dot: 'bg-red-500',    bar: 'bg-red-500',    badge: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-400',             label: 'Critical' },
    high:          { dot: 'bg-orange-500', bar: 'bg-orange-500', badge: 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-400', label: 'High' },
    medium:        { dot: 'bg-yellow-500', bar: 'bg-yellow-500', badge: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-400', label: 'Medium' },
    low:           { dot: 'bg-blue-400',   bar: 'bg-blue-400',   badge: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-400',         label: 'Low' },
    informational: { dot: 'bg-gray-400',   bar: 'bg-gray-400',   badge: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',             label: 'Info' },
};

const SEVERITIES = ['critical', 'high', 'medium', 'low', 'informational'] as const;

// ─── Severity proportional bar ────────────────────────────────────────────────

function SeverityBar({ stats }: { stats: Stats }) {
    if (stats.total === 0) return <div className="h-2.5 rounded-full bg-muted" />;
    return (
        <div className="flex h-2.5 w-full gap-0.5 overflow-hidden rounded-full">
            {SEVERITIES.map((s) => {
                const pct = (stats[s] / stats.total) * 100;
                if (!pct) return null;
                return (
                    <div
                        key={s}
                        style={{ width: `${pct}%` }}
                        className={`${severityConfig[s].bar} transition-all`}
                        title={`${severityConfig[s].label}: ${stats[s]}`}
                    />
                );
            })}
        </div>
    );
}

// ─── Trend sparkline (SVG) ────────────────────────────────────────────────────

function TrendChart({ trend }: { trend: TrendPoint[] }) {
    const W = 800;
    const H = 64;
    const PAD = 4;

    const maxVal = Math.max(...trend.map((p) => p.count), 1);
    const pts = trend.map((p, i) => ({
        x: (i / Math.max(trend.length - 1, 1)) * (W - PAD * 2) + PAD,
        y: H - PAD - (p.count / maxVal) * (H - PAD * 2),
    }));

    const linePath = pts.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ');
    const areaPath =
        linePath +
        ` L${pts[pts.length - 1].x.toFixed(1)},${H - PAD} L${pts[0].x.toFixed(1)},${H - PAD} Z`;

    return (
        <svg viewBox={`0 0 ${W} ${H}`} className="h-16 w-full" preserveAspectRatio="none">
            <defs>
                <linearGradient id="tf" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="hsl(var(--primary))" stopOpacity="0.25" />
                    <stop offset="100%" stopColor="hsl(var(--primary))" stopOpacity="0" />
                </linearGradient>
            </defs>
            <path d={areaPath} fill="url(#tf)" />
            <path d={linePath} fill="none" stroke="hsl(var(--primary))" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

// ─── Finding row ──────────────────────────────────────────────────────────────

function FindingRow({
    finding,
    resolutionTypes,
    checked,
    onToggle,
}: {
    finding: Finding;
    resolutionTypes: ResolutionType[];
    checked: boolean;
    onToggle: () => void;
}) {
    const [selected, setSelected] = useState(resolutionTypes[0]?.value ?? '');
    const [resolving, setResolving] = useState(false);

    const sc = finding.severity ? severityConfig[finding.severity] : null;
    const isResolved = !!finding.resolved_at;

    function resolve() {
        setResolving(true);
        router.post(
            `/admin/findings/${finding.id}/resolve`,
            { resolution_type: selected },
            { preserveScroll: true, onFinish: () => setResolving(false) },
        );
    }

    return (
        <div className={`flex flex-col gap-3 border-b border-border px-6 py-4 last:border-0 sm:flex-row sm:items-start${checked ? ' bg-primary/5' : ''}`}>
            <input
                type="checkbox"
                checked={checked}
                onChange={onToggle}
                className="mt-1.5 size-4 shrink-0 cursor-pointer rounded border-border accent-primary"
            />
            {sc && <div className={`mt-2 size-2 shrink-0 rounded-full ${sc.dot} ${isResolved ? 'opacity-40' : ''}`} />}

            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    {sc && (
                        <Badge className={`text-xs ${sc.badge} ${isResolved ? 'opacity-60' : ''}`} variant="outline">
                            {sc.label}
                        </Badge>
                    )}
                    {finding.category && (
                        <span className="rounded-full bg-muted px-2 py-0.5 text-xs capitalize text-muted-foreground">
                            {finding.category}
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
                                    <GitPullRequest className="size-3" />#{finding.pull_request.number}
                                </a>
                            ) : (
                                <span className="inline-flex items-center gap-1">
                                    <GitPullRequest className="size-3" />#{finding.pull_request.number}
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

                <p className={`mt-1 text-sm font-medium leading-snug${isResolved ? ' line-through opacity-60' : ''}`}>
                    {finding.title}
                </p>

                {finding.file && (
                    <p className="mt-0.5 font-mono text-xs text-muted-foreground">
                        {finding.file}
                        {finding.line ? `:${finding.line}` : ''}
                    </p>
                )}

                {finding.explanation && (
                    <p className="mt-1.5 line-clamp-2 text-xs leading-relaxed text-muted-foreground">
                        {finding.explanation}
                    </p>
                )}
            </div>

            {!isResolved && (
                <div className="flex shrink-0 items-center gap-2 sm:flex-col sm:items-end">
                    <select
                        value={selected}
                        onChange={(e) => setSelected(e.target.value)}
                        className="rounded-md border border-border bg-background px-2 py-1 text-xs text-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                    >
                        {resolutionTypes.map((rt) => (
                            <option key={rt.value} value={rt.value}>{rt.label}</option>
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

// ─── Pagination ───────────────────────────────────────────────────────────────

function Pagination({
    page,
    total,
    perPage,
    onPage,
}: {
    page: number;
    total: number;
    perPage: number;
    onPage: (p: number) => void;
}) {
    const lastPage = Math.max(1, Math.ceil(total / perPage));
    if (lastPage <= 1) return null;

    const pages: (number | '…')[] = [];
    if (lastPage <= 7) {
        for (let i = 1; i <= lastPage; i++) pages.push(i);
    } else {
        pages.push(1);
        if (page > 3) pages.push('…');
        for (let i = Math.max(2, page - 1); i <= Math.min(lastPage - 1, page + 1); i++) pages.push(i);
        if (page < lastPage - 2) pages.push('…');
        pages.push(lastPage);
    }

    return (
        <div className="flex items-center justify-between border-t border-border px-4 py-3">
            <p className="text-xs text-muted-foreground">
                {Math.min((page - 1) * perPage + 1, total)}–{Math.min(page * perPage, total)} of {total}
            </p>
            <div className="flex items-center gap-1">
                <Button variant="outline" size="sm" className="h-7 w-7 p-0" disabled={page === 1} onClick={() => onPage(page - 1)}>
                    <ChevronLeft className="size-3.5" />
                </Button>
                {pages.map((p, i) =>
                    p === '…' ? (
                        <span key={`e-${i}`} className="px-1 text-xs text-muted-foreground">…</span>
                    ) : (
                        <Button key={p} variant={p === page ? 'default' : 'outline'} size="sm" className="h-7 min-w-7 px-2 text-xs" onClick={() => onPage(p as number)}>
                            {p}
                        </Button>
                    ),
                )}
                <Button variant="outline" size="sm" className="h-7 w-7 p-0" disabled={page === lastPage} onClick={() => onPage(page + 1)}>
                    <ChevronRight className="size-3.5" />
                </Button>
            </div>
        </div>
    );
}

// ─── Main ─────────────────────────────────────────────────────────────────────

export default function FindingsIndex({
    findings,
    total,
    page,
    per_page,
    stats,
    top_categories,
    trend,
    repositories,
    categories,
    resolution_types,
    filters,
}: Props) {
    const [searchInput, setSearchInput] = useState(filters.search);
    const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
    const [bulkResolution, setBulkResolution] = useState(resolution_types[0]?.value ?? '');
    const [bulkResolving, setBulkResolving] = useState(false);

    function push(updates: Partial<typeof filters> & { page?: number }) {
        router.get('/findings', { ...filters, page: 1, ...updates } as Record<string, string>, {
            preserveScroll: true,
            replace: true,
        });
    }

    function submitSearch(e: React.FormEvent) {
        e.preventDefault();
        push({ search: searchInput, page: 1 });
    }

    function toggleSeverity(sev: string) {
        const current = filters.severity ? filters.severity.split(',').filter(Boolean) : [];
        const next = current.includes(sev) ? current.filter((s) => s !== sev) : [...current, sev];
        push({ severity: next.join(','), page: 1 });
    }

    const activeSeverities = useMemo(
        () => new Set(filters.severity ? filters.severity.split(',').filter(Boolean) : []),
        [filters.severity],
    );

    const allIds = findings.map((f) => f.id);
    const allSelected = allIds.length > 0 && allIds.every((id) => selectedIds.has(id));
    const someSelected = !allSelected && allIds.some((id) => selectedIds.has(id));

    const selectAllRef = useCallback(
        (el: HTMLInputElement | null) => { if (el) el.indeterminate = someSelected; },
        [someSelected],
    );

    function toggleOne(id: string) {
        setSelectedIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }

    function toggleAll() {
        if (allSelected) {
            setSelectedIds((prev) => { const n = new Set(prev); allIds.forEach((id) => n.delete(id)); return n; });
        } else {
            setSelectedIds((prev) => new Set([...prev, ...allIds]));
        }
    }

    function bulkResolve() {
        setBulkResolving(true);
        router.post(
            '/admin/findings/bulk-resolve',
            { finding_ids: Array.from(selectedIds), resolution_type: bulkResolution },
            {
                preserveScroll: true,
                onSuccess: () => setSelectedIds(new Set()),
                onFinish: () => setBulkResolving(false),
            },
        );
    }

    const resolutionRate = stats.total > 0 ? Math.round((stats.resolved / stats.total) * 100) : 0;
    const maxCatCount = Math.max(...top_categories.map((c) => c.count), 1);
    const maxSevCount = Math.max(stats.critical, stats.high, stats.medium, stats.low, stats.informational, 1);

    return (
        <>
            <Head title="Findings" />

            <div className="flex flex-1 flex-col gap-6 p-6">
                {/* Header */}
                <div className="flex items-center gap-3">
                    <ShieldAlert className="size-6 text-muted-foreground" />
                    <div>
                        <h1 className="text-xl font-semibold">Findings</h1>
                        <p className="text-sm text-muted-foreground">All review findings across tracked repositories</p>
                    </div>
                </div>

                {/* ── Stat cards ──────────────────────────────────────────── */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <Card>
                        <CardContent className="pt-5">
                            <p className="text-xs text-muted-foreground">Total</p>
                            <p className="mt-1 text-3xl font-bold tabular-nums">{stats.total.toLocaleString()}</p>
                            <div className="mt-3">
                                <SeverityBar stats={stats} />
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-5">
                            <p className="text-xs text-muted-foreground">Open</p>
                            <p className="mt-1 text-3xl font-bold tabular-nums text-amber-600 dark:text-amber-400">
                                {stats.open.toLocaleString()}
                            </p>
                            <p className="mt-2 text-xs text-muted-foreground">
                                {stats.critical > 0 && <span className="font-medium text-red-600 dark:text-red-400">{stats.critical} critical</span>}
                                {stats.critical > 0 && stats.high > 0 && ' · '}
                                {stats.high > 0 && <span className="font-medium text-orange-600 dark:text-orange-400">{stats.high} high</span>}
                                {!stats.critical && !stats.high && 'No critical or high'}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-5">
                            <p className="text-xs text-muted-foreground">Resolved</p>
                            <p className="mt-1 text-3xl font-bold tabular-nums text-green-600 dark:text-green-400">
                                {stats.resolved.toLocaleString()}
                            </p>
                            <p className="mt-2 text-xs text-muted-foreground">across all time</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-5">
                            <p className="text-xs text-muted-foreground">Resolution Rate</p>
                            <p className="mt-1 text-3xl font-bold tabular-nums">{resolutionRate}%</p>
                            <div className="mt-3 h-1.5 w-full rounded-full bg-muted overflow-hidden">
                                <div
                                    className="h-full rounded-full bg-green-500 transition-all"
                                    style={{ width: `${resolutionRate}%` }}
                                />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* ── Charts row ──────────────────────────────────────────── */}
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    {/* Severity breakdown */}
                    <Card>
                        <CardHeader className="pb-3 pt-4 px-4">
                            <CardTitle className="text-sm font-medium">By Severity</CardTitle>
                        </CardHeader>
                        <CardContent className="px-4 pb-4">
                            <div className="flex items-end gap-2 h-20">
                                {SEVERITIES.map((s) => {
                                    const count = stats[s];
                                    const pct = maxSevCount ? (count / maxSevCount) * 100 : 0;
                                    return (
                                        <div key={s} className="flex flex-1 flex-col items-center gap-1">
                                            <span className="text-xs font-medium tabular-nums">{count}</span>
                                            <div className="w-full bg-muted rounded-t-sm" style={{ height: '48px', display: 'flex', alignItems: 'flex-end' }}>
                                                <div
                                                    className={`w-full rounded-t-sm ${severityConfig[s].bar} transition-all`}
                                                    style={{ height: `${Math.max(pct, count > 0 ? 4 : 0)}%` }}
                                                />
                                            </div>
                                            <span className="text-[10px] text-muted-foreground capitalize">
                                                {s === 'informational' ? 'Info' : s}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Top categories */}
                    <Card>
                        <CardHeader className="pb-3 pt-4 px-4">
                            <CardTitle className="text-sm font-medium">Top Categories</CardTitle>
                        </CardHeader>
                        <CardContent className="px-4 pb-4 space-y-2">
                            {top_categories.length === 0 && (
                                <p className="text-xs text-muted-foreground">No data</p>
                            )}
                            {top_categories.slice(0, 6).map((cat) => (
                                <div key={cat.category} className="flex items-center gap-2">
                                    <span className="w-24 truncate text-xs capitalize text-muted-foreground">
                                        {cat.category}
                                    </span>
                                    <div className="flex-1 h-1.5 bg-muted rounded-full overflow-hidden">
                                        <div
                                            className="h-full bg-primary/70 rounded-full"
                                            style={{ width: `${(cat.count / maxCatCount) * 100}%` }}
                                        />
                                    </div>
                                    <span className="w-8 text-right text-xs tabular-nums text-muted-foreground">
                                        {cat.count}
                                    </span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    {/* 30-day trend */}
                    <Card>
                        <CardHeader className="pb-3 pt-4 px-4">
                            <CardTitle className="text-sm font-medium">30-Day Trend</CardTitle>
                        </CardHeader>
                        <CardContent className="px-4 pb-2">
                            <TrendChart trend={trend} />
                            <div className="mt-1 flex justify-between text-[10px] text-muted-foreground">
                                <span>{trend[0]?.date?.slice(5)}</span>
                                <span>{trend[trend.length - 1]?.date?.slice(5)}</span>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* ── Filters ─────────────────────────────────────────────── */}
                <Card>
                    <CardContent className="flex flex-wrap items-center gap-3 py-3 px-4">
                        <SlidersHorizontal className="size-4 shrink-0 text-muted-foreground" />

                        {/* Search */}
                        <form onSubmit={submitSearch} className="flex items-center gap-1">
                            <div className="relative">
                                <Search className="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                                <input
                                    value={searchInput}
                                    onChange={(e) => setSearchInput(e.target.value)}
                                    placeholder="Search findings…"
                                    className="h-8 rounded-md border border-border bg-background pl-8 pr-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring w-48"
                                />
                            </div>
                        </form>

                        {/* Repository */}
                        <select
                            value={filters.repository_id}
                            onChange={(e) => push({ repository_id: e.target.value, page: 1 })}
                            className="h-8 rounded-md border border-border bg-background px-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                        >
                            <option value="">All repos</option>
                            {repositories.map((r) => (
                                <option key={r.id} value={r.id}>{r.full_name}</option>
                            ))}
                        </select>

                        {/* Severity pills */}
                        <div className="flex items-center gap-1">
                            {SEVERITIES.map((s) => {
                                const active = activeSeverities.has(s);
                                return (
                                    <button
                                        key={s}
                                        onClick={() => toggleSeverity(s)}
                                        className={[
                                            'rounded-full px-2.5 py-0.5 text-xs font-medium transition-colors border',
                                            active
                                                ? `${severityConfig[s].badge} border-transparent`
                                                : 'border-border text-muted-foreground hover:text-foreground',
                                        ].join(' ')}
                                    >
                                        {s === 'informational' ? 'Info' : s.charAt(0).toUpperCase() + s.slice(1)}
                                    </button>
                                );
                            })}
                        </div>

                        {/* Category */}
                        <select
                            value={filters.category}
                            onChange={(e) => push({ category: e.target.value, page: 1 })}
                            className="h-8 rounded-md border border-border bg-background px-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                        >
                            <option value="">All categories</option>
                            {categories.map((c) => (
                                <option key={c} value={c} className="capitalize">{c}</option>
                            ))}
                        </select>

                        {/* Status */}
                        <div className="flex items-center gap-0.5 rounded-md border border-border bg-background p-0.5">
                            {(['open', 'resolved', 'all'] as const).map((s) => (
                                <button
                                    key={s}
                                    onClick={() => push({ status: s, page: 1 })}
                                    className={[
                                        'rounded px-2.5 py-1 text-xs font-medium transition-colors capitalize',
                                        filters.status === s
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground hover:text-foreground',
                                    ].join(' ')}
                                >
                                    {s}
                                </button>
                            ))}
                        </div>

                        {/* Sort */}
                        <select
                            value={filters.sort_by}
                            onChange={(e) => push({ sort_by: e.target.value, page: 1 })}
                            className="h-8 rounded-md border border-border bg-background px-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                        >
                            <option value="severity">Sort: severity</option>
                            <option value="date">Sort: newest</option>
                            <option value="category">Sort: category</option>
                        </select>

                        {/* Clear active filters */}
                        {(filters.repository_id || filters.severity || filters.category || filters.search) && (
                            <button
                                onClick={() => {
                                    setSearchInput('');
                                    push({ repository_id: '', severity: '', category: '', search: '', page: 1 });
                                }}
                                className="ml-auto flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                            >
                                <XCircle className="size-3.5" /> Clear filters
                            </button>
                        )}
                    </CardContent>
                </Card>

                {/* ── Bulk actions ─────────────────────────────────────────── */}
                {selectedIds.size > 0 && (
                    <div className="flex items-center gap-3 rounded-lg border border-border bg-muted/50 px-4 py-2">
                        <span className="text-sm font-medium">{selectedIds.size} selected</span>
                        <select
                            value={bulkResolution}
                            onChange={(e) => setBulkResolution(e.target.value)}
                            className="h-7 rounded-md border border-border bg-background px-2 text-xs focus:outline-none"
                        >
                            {resolution_types.map((rt) => (
                                <option key={rt.value} value={rt.value}>{rt.label}</option>
                            ))}
                        </select>
                        <Button size="sm" variant="outline" className="h-7 gap-1.5 px-2.5 text-xs" disabled={bulkResolving} onClick={bulkResolve}>
                            <CheckCircle2 className="size-3 text-green-500" />
                            Resolve selected
                        </Button>
                        <button onClick={() => setSelectedIds(new Set())} className="ml-auto text-xs text-muted-foreground hover:text-foreground">
                            Deselect all
                        </button>
                    </div>
                )}

                {/* ── Findings list ────────────────────────────────────────── */}
                <Card className="overflow-hidden">
                    {/* List header */}
                    <div className="flex items-center gap-3 border-b border-border bg-muted/30 px-6 py-2">
                        <input
                            type="checkbox"
                            ref={selectAllRef}
                            checked={allSelected}
                            onChange={toggleAll}
                            className="size-4 cursor-pointer rounded border-border accent-primary"
                        />
                        <span className="text-xs font-medium text-muted-foreground">
                            {total.toLocaleString()} finding{total !== 1 ? 's' : ''}
                            {filters.status !== 'all' && ` · ${filters.status}`}
                        </span>
                    </div>

                    {findings.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 py-16 text-muted-foreground">
                            <CheckCircle2 className="size-8 text-green-500" />
                            <p className="text-sm font-medium">No findings match your filters</p>
                            <p className="text-xs">Try adjusting or clearing the active filters.</p>
                        </div>
                    ) : (
                        findings.map((f) => (
                            <FindingRow
                                key={f.id}
                                finding={f}
                                resolutionTypes={resolution_types}
                                checked={selectedIds.has(f.id)}
                                onToggle={() => toggleOne(f.id)}
                            />
                        ))
                    )}

                    <Pagination
                        page={page}
                        total={total}
                        perPage={per_page}
                        onPage={(p) => push({ page: p })}
                    />
                </Card>
            </div>
        </>
    );
}
