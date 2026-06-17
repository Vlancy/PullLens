import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    CheckCircle2,
    ExternalLink,
    GitBranch,
    GitCommitHorizontal,
    GitPullRequest,
    LayoutDashboard,
    Search,
    Users,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
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

const SEVERITY_ORDER: Record<string, number> = {
    critical: 1, high: 2, medium: 3, low: 4, informational: 5,
};

const severityConfig: Record<string, { dot: string; badge: string; label: string }> = {
    critical:      { dot: 'bg-red-500',    badge: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-400',         label: 'Critical' },
    high:          { dot: 'bg-orange-500', badge: 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-400', label: 'High' },
    medium:        { dot: 'bg-yellow-500', badge: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-400', label: 'Medium' },
    low:           { dot: 'bg-blue-400',   badge: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-400',     label: 'Low' },
    informational: { dot: 'bg-gray-400',   badge: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',        label: 'Info' },
};

const SEVERITIES = ['critical', 'high', 'medium', 'low', 'informational'];
const PAGE_SIZE = 20;

// ─── Sub-nav ──────────────────────────────────────────────────────────────────

function ReportsNav({ active }: { active: string }) {
    const tabs = [
        { icon: LayoutDashboard,     label: 'Overview',       href: '/reports' },
        { icon: Users,               label: 'Team',           href: '/reports/developers' },
        { icon: GitBranch,           label: 'Repos',          href: '/reports/repositories' },
        { icon: GitCommitHorizontal, label: 'Commit Quality', href: '/reports/commits' },
        { icon: CalendarDays,        label: 'Daily Activity', href: '/reports/daily' },
        { icon: Activity,            label: 'Daily Effort',   href: '/reports/developer-daily' },
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

    function resolve() {
        setResolving(true);
        router.post(
            `/admin/findings/${finding.id}/resolve`,
            { resolution_type: selected },
            { preserveScroll: true, onFinish: () => setResolving(false) },
        );
    }

    return (
        <div className={`flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-start${checked ? ' bg-primary/5' : ''}`}>
            <input
                type="checkbox"
                checked={checked}
                onChange={onToggle}
                className="mt-1.5 size-4 shrink-0 cursor-pointer rounded border-border accent-primary"
            />
            {sc && <div className={`mt-1.5 size-2 shrink-0 rounded-full ${sc.dot}`} />}

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
                                <a href={finding.pull_request.web_url} target="_blank" rel="noreferrer"
                                   className="inline-flex items-center gap-1 hover:underline">
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
                    <p className="mt-1.5 line-clamp-3 text-xs leading-relaxed text-muted-foreground">
                        {finding.explanation}
                    </p>
                )}
            </div>

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
                <Button size="sm" variant="outline" className="h-7 gap-1.5 px-2.5 text-xs"
                        disabled={resolving} onClick={resolve}>
                    <CheckCircle2 className="size-3 text-green-500" />
                    Resolve
                </Button>
            </div>
        </div>
    );
}

// ─── Pagination ───────────────────────────────────────────────────────────────

function Pagination({ page, total, pageSize, onChange }: {
    page: number; total: number; pageSize: number; onChange: (p: number) => void;
}) {
    const lastPage = Math.max(1, Math.ceil(total / pageSize));
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
                {Math.min((page - 1) * pageSize + 1, total)}–{Math.min(page * pageSize, total)} of {total}
            </p>
            <div className="flex items-center gap-1">
                <Button variant="outline" size="sm" className="h-7 w-7 p-0"
                        disabled={page === 1} onClick={() => onChange(page - 1)}>
                    <ChevronLeft className="size-3.5" />
                </Button>
                {pages.map((p, i) =>
                    p === '…' ? (
                        <span key={`ellipsis-${i}`} className="px-1 text-xs text-muted-foreground">…</span>
                    ) : (
                        <Button key={p} variant={p === page ? 'default' : 'outline'} size="sm"
                                className="h-7 min-w-7 px-2 text-xs"
                                onClick={() => onChange(p as number)}>
                            {p}
                        </Button>
                    )
                )}
                <Button variant="outline" size="sm" className="h-7 w-7 p-0"
                        disabled={page === lastPage} onClick={() => onChange(page + 1)}>
                    <ChevronRight className="size-3.5" />
                </Button>
            </div>
        </div>
    );
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function RepositoryFindings({ repository, findings, resolution_types }: Props) {
    const [search, setSearch]         = useState('');
    const [severity, setSeverity]     = useState(() => {
        const param = new URLSearchParams(window.location.search).get('severity') ?? '';
        const parts = param.split(',').filter(s => SEVERITIES.includes(s));
        if (parts.length === 0) return 'all';
        if (parts.length === 1) return parts[0];
        return parts.join(',');
    });
    const [category, setCategory]     = useState('all');
    const [sortBy, setSortBy]         = useState<'severity' | 'pr' | 'category' | 'file'>('severity');
    const [sortDir, setSortDir]       = useState<'asc' | 'desc'>('asc');
    const [page, setPage]             = useState(1);
    const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
    const [bulkResolution, setBulkResolution] = useState(resolution_types[0]?.value ?? '');
    const [bulkResolving, setBulkResolving]   = useState(false);

    const categories = useMemo(() => {
        const set = new Set(findings.map((f) => f.category).filter(Boolean) as string[]);
        return Array.from(set).sort();
    }, [findings]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        let out = findings.filter((f) => {
            if (severity !== 'all') {
                const severitySet = new Set(severity.split(','));
                if (!severitySet.has(f.severity ?? '')) return false;
            }
            if (category !== 'all' && f.category !== category) return false;
            if (q) {
                const haystack = [f.title, f.file, f.explanation, f.category, f.pull_request?.title]
                    .filter(Boolean).join(' ').toLowerCase();
                if (!haystack.includes(q)) return false;
            }
            return true;
        });

        out = [...out].sort((a, b) => {
            let cmp = 0;
            if (sortBy === 'severity') {
                cmp = (SEVERITY_ORDER[a.severity ?? ''] ?? 99) - (SEVERITY_ORDER[b.severity ?? ''] ?? 99);
            } else if (sortBy === 'pr') {
                cmp = (a.pull_request?.number ?? 0) - (b.pull_request?.number ?? 0);
            } else if (sortBy === 'category') {
                cmp = (a.category ?? '').localeCompare(b.category ?? '');
            } else if (sortBy === 'file') {
                cmp = (a.file ?? '').localeCompare(b.file ?? '');
            }
            return sortDir === 'desc' ? -cmp : cmp;
        });

        return out;
    }, [findings, search, severity, category, sortBy, sortDir]);

    const paginated = useMemo(
        () => filtered.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE),
        [filtered, page],
    );

    const filteredIds = useMemo(() => filtered.map(f => f.id), [filtered]);
    const allSelected = filteredIds.length > 0 && filteredIds.every(id => selectedIds.has(id));
    const someSelected = !allSelected && filteredIds.some(id => selectedIds.has(id));

    const selectAllRefCallback = useCallback((el: HTMLInputElement | null) => {
        if (el) el.indeterminate = someSelected;
    }, [someSelected]);

    function toggleOne(id: string) {
        setSelectedIds(prev => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    }

    function toggleAll() {
        if (allSelected) {
            setSelectedIds(prev => {
                const next = new Set(prev);
                filteredIds.forEach(id => next.delete(id));
                return next;
            });
        } else {
            setSelectedIds(prev => new Set([...prev, ...filteredIds]));
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

    function handleFilter(fn: () => void) {
        fn();
        setPage(1);
    }

    function toggleSort(col: typeof sortBy) {
        if (sortBy === col) {
            setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
        } else {
            setSortBy(col);
            setSortDir('asc');
        }
        setPage(1);
    }

    const SortIndicator = ({ col }: { col: typeof sortBy }) =>
        sortBy === col ? (
            <span className="ml-0.5 text-[10px]">{sortDir === 'asc' ? '↑' : '↓'}</span>
        ) : null;

    return (
        <>
            <Head title={`Findings — ${repository.full_name}`} />

            <div className="flex flex-1 flex-col gap-6 p-6">
                {/* Header */}
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <Link href="/reports/repositories"
                                  className="text-sm text-muted-foreground hover:text-foreground">
                                Repos
                            </Link>
                            <span className="text-muted-foreground">/</span>
                            <h1 className="text-xl font-semibold">{repository.full_name}</h1>
                            {repository.web_url && (
                                <a href={repository.web_url} target="_blank" rel="noreferrer"
                                   className="text-muted-foreground hover:text-foreground">
                                    <ExternalLink className="size-4" />
                                </a>
                            )}
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {filtered.length === 0
                                ? 'No findings match your filters'
                                : `${filtered.length} finding${filtered.length === 1 ? '' : 's'}${filtered.length !== findings.length ? ` (filtered from ${findings.length})` : ''}`}
                        </p>
                    </div>
                </div>

                <ReportsNav active="/reports/repositories" />

                {/* Controls */}
                <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
                    {/* Search */}
                    <div className="relative">
                        <Search className="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                        <input
                            type="text"
                            placeholder="Search findings…"
                            value={search}
                            onChange={(e) => handleFilter(() => setSearch(e.target.value))}
                            className="h-8 w-full rounded-md border border-border bg-background pl-8 pr-3 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring sm:w-56"
                        />
                    </div>

                    {/* Severity filter */}
                    <div className="flex flex-wrap gap-1">
                        {['all', ...SEVERITIES].map((s) => {
                            const sc = s !== 'all' ? severityConfig[s] : null;
                            return (
                                <button
                                    key={s}
                                    onClick={() => handleFilter(() => setSeverity(s))}
                                    className={[
                                        'rounded-full px-2.5 py-0.5 text-xs font-medium transition-colors',
                                        severity === s
                                            ? sc
                                                ? sc.badge
                                                : 'bg-foreground text-background'
                                            : 'bg-muted text-muted-foreground hover:bg-muted/80',
                                    ].join(' ')}
                                >
                                    {s === 'all' ? 'All' : severityConfig[s]?.label ?? s}
                                </button>
                            );
                        })}
                    </div>

                    {/* Category filter */}
                    {categories.length > 0 && (
                        <select
                            value={category}
                            onChange={(e) => handleFilter(() => setCategory(e.target.value))}
                            className="h-8 rounded-md border border-border bg-background px-2 text-sm text-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                        >
                            <option value="all">All categories</option>
                            {categories.map((c) => (
                                <option key={c} value={c}>{c}</option>
                            ))}
                        </select>
                    )}

                    {/* Sort */}
                    <div className="flex items-center gap-1 text-xs text-muted-foreground">
                        <span>Sort:</span>
                        {(['severity', 'pr', 'category', 'file'] as const).map((col) => (
                            <button
                                key={col}
                                onClick={() => toggleSort(col)}
                                className={[
                                    'rounded px-2 py-0.5 capitalize transition-colors',
                                    sortBy === col
                                        ? 'bg-muted font-medium text-foreground'
                                        : 'hover:bg-muted/60',
                                ].join(' ')}
                            >
                                {col === 'pr' ? 'PR#' : col}
                                <SortIndicator col={col} />
                            </button>
                        ))}
                    </div>
                </div>

                {/* Bulk action bar */}
                {selectedIds.size > 0 && (
                    <div className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-background px-4 py-2.5 shadow-sm">
                        <span className="text-sm font-medium">{selectedIds.size} selected</span>
                        <div className="flex-1" />
                        <select
                            value={bulkResolution}
                            onChange={e => setBulkResolution(e.target.value)}
                            className="h-8 rounded-md border border-border bg-background px-2 text-sm text-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                        >
                            {resolution_types.map(rt => (
                                <option key={rt.value} value={rt.value}>{rt.label}</option>
                            ))}
                        </select>
                        <Button size="sm" className="h-8 gap-1.5 text-xs" disabled={bulkResolving} onClick={bulkResolve}>
                            <CheckCircle2 className="size-3.5 text-green-500" />
                            Resolve {selectedIds.size}
                        </Button>
                        <Button size="sm" variant="ghost" className="h-8 text-xs text-muted-foreground"
                                onClick={() => setSelectedIds(new Set())}>
                            Deselect all
                        </Button>
                    </div>
                )}

                {/* List */}
                {filtered.length === 0 ? (
                    <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed py-16 text-center text-muted-foreground">
                        <CheckCircle2 className="size-8 text-green-500 opacity-60" />
                        <p className="text-sm">
                            {findings.length === 0
                                ? 'All findings are resolved.'
                                : 'No findings match your filters.'}
                        </p>
                        {findings.length > 0 && (
                            <button
                                className="text-xs underline"
                                onClick={() => { setSearch(''); setSeverity('all'); setCategory('all'); setPage(1); }}
                            >
                                Clear filters
                            </button>
                        )}
                    </div>
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            {/* Select-all header */}
                            <div className="flex items-center gap-3 border-b border-border px-6 py-2.5">
                                <input
                                    ref={selectAllRefCallback}
                                    type="checkbox"
                                    checked={allSelected}
                                    onChange={toggleAll}
                                    className="size-4 cursor-pointer rounded border-border accent-primary"
                                />
                                <span className="text-xs text-muted-foreground">
                                    {allSelected
                                        ? `All ${filteredIds.length} selected`
                                        : someSelected
                                            ? `${selectedIds.size} of ${filteredIds.length} selected`
                                            : `Select all ${filteredIds.length}`}
                                </span>
                            </div>
                            <div className="divide-y divide-border">
                                {paginated.map((finding) => (
                                    <FindingRow
                                        key={finding.id}
                                        finding={finding}
                                        resolutionTypes={resolution_types}
                                        checked={selectedIds.has(finding.id)}
                                        onToggle={() => toggleOne(finding.id)}
                                    />
                                ))}
                            </div>
                            <Pagination
                                page={page}
                                total={filtered.length}
                                pageSize={PAGE_SIZE}
                                onChange={setPage}
                            />
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
