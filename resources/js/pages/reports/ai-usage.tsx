import { Head, router } from '@inertiajs/react';
import { Coins, Cpu, Gauge, Zap } from 'lucide-react';
import { ReportsNav } from '@/components/reports-nav';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

// ─── Types ────────────────────────────────────────────────────────────────────

type Option = { value: string; label: string };

type Props = {
    stats: {
        calls: number;
        tokens: number;
        prompt_tokens: number;
        completion_tokens: number;
        cache_read_tokens: number;
        cost_usd: number;
        avg_tokens_per_call: number;
        avg_cost_per_call: number;
        avg_duration_ms: number;
        cache_hit_rate: number | null;
    };
    by_operation: {
        operation: string;
        label: string;
        automatic: boolean;
        calls: number;
        tokens: number;
        cost_usd: number;
    }[];
    by_model: {
        model: string;
        calls: number;
        tokens: number;
        cost_usd: number;
        avg_tokens: number;
    }[];
    by_repository: {
        repository: string;
        calls: number;
        tokens: number;
        cost_usd: number;
    }[];
    largest_calls: {
        id: string;
        operation: string | null;
        model: string | null;
        tokens: number;
        prompt_tokens: number;
        completion_tokens: number;
        cost_usd: number | null;
        duration_ms: number | null;
        created_at: string | null;
        repository: string | null;
        pull_request: {
            number: number;
            title: string;
            web_url: string | null;
        } | null;
    }[];
    trend: { date: string; calls: number; tokens: number; cost_usd: number }[];
    period: string;
    periods: Option[];
};

// ─── Helpers ─────────────────────────────────────────────────────────────────

function formatTokens(tokens: number): string {
    if (tokens >= 1_000_000) {
        return `${(tokens / 1_000_000).toFixed(1)}M`;
    }

    if (tokens >= 1_000) {
        return `${(tokens / 1_000).toFixed(1)}k`;
    }

    return String(tokens);
}

function formatCost(cost: number | null): string {
    if (cost === null) {
        return '-';
    }

    // Sub-cent figures round to $0.00 and look free, which they are not.
    return cost > 0 && cost < 0.01 ? '<$0.01' : `$${cost.toFixed(2)}`;
}

function StatCard({
    label,
    value,
    hint,
    icon: Icon,
}: {
    label: string;
    value: string;
    hint?: string;
    icon: typeof Coins;
}) {
    return (
        <Card>
            <CardContent className="flex items-start gap-3 p-4">
                <div className="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted">
                    <Icon className="size-4 text-muted-foreground" />
                </div>
                <div className="min-w-0">
                    <p className="text-xs text-muted-foreground">{label}</p>
                    <p className="text-xl font-semibold">{value}</p>
                    {hint && (
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {hint}
                        </p>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

/** Horizontal bar, sized against the largest value in its table. */
function CostBar({ value, max }: { value: number; max: number }) {
    const pct = max > 0 ? Math.max(2, (value / max) * 100) : 0;

    return (
        <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
            <div
                className="h-full rounded-full bg-primary"
                style={{ width: `${pct}%` }}
            />
        </div>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function AiUsage({
    stats,
    by_operation,
    by_model,
    by_repository,
    largest_calls,
    trend,
    period,
    periods,
}: Props) {
    const maxOperationCost = Math.max(
        ...by_operation.map((row) => row.cost_usd),
        0,
    );
    const maxModelCost = Math.max(...by_model.map((row) => row.cost_usd), 0);
    const maxRepoCost = Math.max(
        ...by_repository.map((row) => row.cost_usd),
        0,
    );
    const maxTrendTokens = Math.max(...trend.map((day) => day.tokens), 1);

    return (
        <>
            <Head title="AI usage" />

            <div className="space-y-6 p-4 md:p-6">
                <ReportsNav active="/reports/ai-usage" />

                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold">AI usage</h1>
                        <p className="text-sm text-muted-foreground">
                            Tokens and estimated cost for every call PullLens
                            makes to your provider. Costs are estimated from a
                            maintained price table, not billed figures.
                        </p>
                    </div>

                    <Select
                        value={period}
                        onValueChange={(value) =>
                            router.get(
                                '/reports/ai-usage',
                                { period: value },
                                {
                                    preserveState: true,
                                    replace: true,
                                },
                            )
                        }
                    >
                        <SelectTrigger className="w-48" aria-label="Period">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {periods.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {/* Totals */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Estimated cost"
                        value={formatCost(stats.cost_usd)}
                        hint={`${formatCost(stats.avg_cost_per_call)} per call`}
                        icon={Coins}
                    />
                    <StatCard
                        label="Tokens"
                        value={formatTokens(stats.tokens)}
                        hint={`${formatTokens(stats.avg_tokens_per_call)} per call`}
                        icon={Cpu}
                    />
                    <StatCard
                        label="Calls"
                        value={String(stats.calls)}
                        icon={Zap}
                    />
                    <StatCard
                        label="Cache hit rate"
                        value={
                            stats.cache_hit_rate === null
                                ? '-'
                                : `${stats.cache_hit_rate}%`
                        }
                        hint={
                            stats.cache_hit_rate === null ||
                            stats.cache_hit_rate < 1
                                ? 'Prompt caching inactive'
                                : `${formatTokens(stats.cache_read_tokens)} served from cache`
                        }
                        icon={Gauge}
                    />
                </div>

                {stats.calls === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-lg border border-dashed py-16 text-center">
                        <Coins className="mb-3 size-10 text-muted-foreground/40" />
                        <p className="text-sm font-medium">
                            No AI usage recorded in this period
                        </p>
                        <p className="mt-1 max-w-md text-xs text-muted-foreground">
                            Usage is recorded from the moment this feature was
                            deployed. Earlier reviews are not included.
                        </p>
                    </div>
                ) : (
                    <>
                        {/* Trend */}
                        <Card>
                            <CardContent className="p-4">
                                <p className="mb-3 text-sm font-medium">
                                    Tokens per day
                                </p>
                                <div className="flex h-24 items-end gap-0.5">
                                    {trend.map((day) => (
                                        <div
                                            key={day.date}
                                            className="group relative flex-1 rounded-t bg-primary/70 transition-colors hover:bg-primary"
                                            style={{
                                                height: `${Math.max(2, (day.tokens / maxTrendTokens) * 100)}%`,
                                            }}
                                            title={`${day.date}: ${formatTokens(day.tokens)} tokens, ${formatCost(day.cost_usd)}`}
                                        />
                                    ))}
                                </div>
                            </CardContent>
                        </Card>

                        <div className="grid gap-4 lg:grid-cols-2">
                            {/* By operation */}
                            <Card>
                                <CardContent className="space-y-3 p-4">
                                    <p className="text-sm font-medium">
                                        Cost by operation
                                    </p>
                                    {by_operation.map((row) => (
                                        <div
                                            key={row.operation}
                                            className="space-y-1"
                                        >
                                            <div className="flex items-center justify-between gap-2 text-sm">
                                                <span className="flex items-center gap-1.5">
                                                    {row.label}
                                                    {row.automatic && (
                                                        <Badge
                                                            variant="outline"
                                                            className="h-4 px-1 text-[10px]"
                                                        >
                                                            automatic
                                                        </Badge>
                                                    )}
                                                </span>
                                                <span className="shrink-0 text-muted-foreground">
                                                    {formatCost(row.cost_usd)} ·{' '}
                                                    {formatTokens(row.tokens)}
                                                </span>
                                            </div>
                                            <CostBar
                                                value={row.cost_usd}
                                                max={maxOperationCost}
                                            />
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>

                            {/* By model */}
                            <Card>
                                <CardContent className="space-y-3 p-4">
                                    <p className="text-sm font-medium">
                                        Cost by model
                                    </p>
                                    {by_model.map((row) => (
                                        <div
                                            key={row.model}
                                            className="space-y-1"
                                        >
                                            <div className="flex items-center justify-between gap-2 text-sm">
                                                <span className="truncate font-mono text-xs">
                                                    {row.model}
                                                </span>
                                                <span className="shrink-0 text-muted-foreground">
                                                    {formatCost(row.cost_usd)} ·{' '}
                                                    {formatTokens(
                                                        row.avg_tokens,
                                                    )}
                                                    /call
                                                </span>
                                            </div>
                                            <CostBar
                                                value={row.cost_usd}
                                                max={maxModelCost}
                                            />
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        </div>

                        {/* By repository */}
                        {by_repository.length > 0 && (
                            <Card>
                                <CardContent className="space-y-3 p-4">
                                    <p className="text-sm font-medium">
                                        Cost by repository
                                    </p>
                                    {by_repository.map((row) => (
                                        <div
                                            key={row.repository}
                                            className="space-y-1"
                                        >
                                            <div className="flex items-center justify-between gap-2 text-sm">
                                                <span className="truncate font-mono text-xs">
                                                    {row.repository}
                                                </span>
                                                <span className="shrink-0 text-muted-foreground">
                                                    {formatCost(row.cost_usd)} ·{' '}
                                                    {row.calls} calls
                                                </span>
                                            </div>
                                            <CostBar
                                                value={row.cost_usd}
                                                max={maxRepoCost}
                                            />
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        )}

                        {/* Largest calls */}
                        <Card>
                            <CardContent className="p-4">
                                <p className="mb-3 text-sm font-medium">
                                    Largest single calls
                                </p>
                                <div className="space-y-2">
                                    {largest_calls.map((call) => (
                                        <div
                                            key={call.id}
                                            className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b pb-2 text-xs last:border-b-0 last:pb-0"
                                        >
                                            <Badge
                                                variant="outline"
                                                className="shrink-0"
                                            >
                                                {call.operation}
                                            </Badge>
                                            {call.repository && (
                                                <span className="font-mono text-muted-foreground">
                                                    {call.repository}
                                                </span>
                                            )}
                                            {call.pull_request && (
                                                <a
                                                    href={
                                                        call.pull_request
                                                            .web_url ?? '#'
                                                    }
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="hover:underline"
                                                >
                                                    #{call.pull_request.number}
                                                </a>
                                            )}
                                            <span className="ml-auto shrink-0 text-muted-foreground">
                                                {formatTokens(
                                                    call.prompt_tokens,
                                                )}{' '}
                                                in ·{' '}
                                                {formatTokens(
                                                    call.completion_tokens,
                                                )}{' '}
                                                out ·{' '}
                                                {formatCost(call.cost_usd)}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    </>
                )}
            </div>
        </>
    );
}
