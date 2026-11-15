import { AlertTriangle } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type TaskSummary = {
    total: number;
    first_time_right: number;
    reworked: number;
    first_time_right_rate: number | null;
    estimated_hours: number;
};

const TILE =
    'flex min-w-0 flex-col gap-0.5 rounded-lg border bg-card px-3 py-2.5 text-left';

function Tile({
    label,
    value,
    hint,
    valueClassName,
}: {
    label: ReactNode;
    value: ReactNode;
    hint?: ReactNode;
    valueClassName?: string;
}) {
    return (
        <>
            <span className="truncate text-xs text-muted-foreground">
                {label}
            </span>
            <span
                className={cn(
                    'text-2xl leading-tight font-semibold tabular-nums',
                    valueClassName,
                )}
            >
                {value}
            </span>
            {hint && (
                <span className="truncate text-[11px] text-muted-foreground">
                    {hint}
                </span>
            )}
        </>
    );
}

/**
 * The board's headline numbers under the current filters. The Came back tile
 * doubles as the "came back only" toggle, so the number you notice is the
 * number you can drill into.
 */
export function StatTiles({
    summary,
    reworkOnly,
    onToggleRework,
}: {
    summary: TaskSummary;
    reworkOnly: boolean;
    onToggleRework: () => void;
}) {
    const cameBack = summary.reworked;

    return (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 sm:gap-3">
            <div className={TILE}>
                <Tile label="Tasks" value={summary.total} />
            </div>

            <div className={TILE}>
                <Tile
                    label="First time right"
                    value={
                        summary.first_time_right_rate === null
                            ? '-'
                            : `${summary.first_time_right_rate}%`
                    }
                    hint={
                        summary.total > 0
                            ? `${summary.first_time_right} of ${summary.total}`
                            : undefined
                    }
                />
            </div>

            <button
                type="button"
                aria-pressed={reworkOnly}
                onClick={onToggleRework}
                title={
                    reworkOnly
                        ? 'Show all work again'
                        : 'Show only work that came back'
                }
                className={cn(
                    TILE,
                    'transition-colors outline-none hover:bg-muted/50 focus-visible:ring-[3px] focus-visible:ring-ring/50',
                    reworkOnly &&
                        'border-amber-500/40 bg-amber-500/10 ring-1 ring-amber-500/40 hover:bg-amber-500/15',
                )}
            >
                <Tile
                    label={
                        <span className="inline-flex items-center gap-1">
                            <AlertTriangle
                                className={cn(
                                    'size-3',
                                    cameBack > 0 &&
                                        'text-amber-600 dark:text-amber-400',
                                )}
                                aria-hidden
                            />
                            Came back
                        </span>
                    }
                    value={cameBack}
                    valueClassName={
                        cameBack > 0
                            ? 'text-amber-700 dark:text-amber-400'
                            : undefined
                    }
                    hint={reworkOnly ? 'Filtering - click to clear' : undefined}
                />
            </button>

            <div className={TILE}>
                <Tile
                    label="Estimated effort"
                    value={`${summary.estimated_hours}h`}
                />
            </div>
        </div>
    );
}
