import { GitBranch } from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/** Where a piece of work last went: the branches of its latest pull request. */
export type BranchInfo = {
    source: string | null;
    target: string | null;
    state: string | null;
    number: number | null;
    url: string | null;
    /**
     * Every branch merged work reached, in order - e.g. ['stage', 'prod'] when a
     * later release carried it from stage to prod. The last entry is where it is now.
     */
    path?: string[];
    /** The release merge that took it furthest, when it moved past its first target. */
    promoted_by?: { number: number | null; url: string | null } | null;
};

/** The branch merged work lives on now: the end of its release path. */
function landedOn(branch: BranchInfo): string | null {
    const path = branch.path ?? [];

    return path.length > 0 ? path[path.length - 1] : branch.target;
}

const STYLES = {
    merged: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    open: 'border-border bg-background text-foreground',
    closed: 'border-border bg-muted text-muted-foreground',
} as const;

function tone(state: string | null): keyof typeof STYLES {
    if (state === 'merged') {
        return 'merged';
    }

    return state === 'closed' ? 'closed' : 'open';
}

/** The sentence behind the chip, so a truncated branch name is still readable. */
function describe(branch: BranchInfo): string {
    const pr = branch.number === null ? 'Pull request' : `#${branch.number}`;
    const from = branch.source ?? 'an unknown branch';
    const into = branch.target ?? 'an unknown branch';

    switch (branch.state) {
        case 'merged': {
            const path = branch.path ?? [];
            const merged = `Merged ${pr} from ${from} into ${into}`;

            if (path.length < 2) {
                return merged;
            }

            const release =
                branch.promoted_by?.number != null
                    ? ` (latest release #${branch.promoted_by.number})`
                    : '';

            return `${merged}, then released ${path.join(' → ')}${release}`;
        }
        case 'closed':
            return `Closed ${pr} without merging (${from} into ${into})`;
        case 'draft':
            return `Draft ${pr}: ${from} into ${into}`;
        default:
            return `Open ${pr}: ${from} into ${into}`;
    }
}

function Name({ value, compact }: { value: string; compact: boolean }) {
    return (
        <span
            className={cn(
                'truncate',
                compact ? 'max-w-[8rem]' : 'max-w-[10rem] sm:max-w-[14rem]',
            )}
        >
            {value}
        </span>
    );
}

/**
 * The branch a feature or task last landed on. Merged work shows where it
 * landed (dev, main); open work shows the branch and where it is headed; a pull
 * request closed without merging is muted. Links to the pull request when there
 * is a URL and `linked` is set.
 */
export function BranchChip({
    branch,
    compact = false,
    linked = true,
    className,
}: {
    branch: BranchInfo;
    /** Narrower names, for the dense task rows. */
    compact?: boolean;
    /** Whether the chip links to its pull request. */
    linked?: boolean;
    className?: string;
}) {
    if (branch.source === null && branch.target === null) {
        return null;
    }

    const kind = tone(branch.state);
    const label =
        kind === 'merged' ? (
            <Name
                value={landedOn(branch) ?? branch.source ?? ''}
                compact={compact}
            />
        ) : (
            <>
                <Name value={branch.source ?? '?'} compact={compact} />
                {branch.target && (
                    <>
                        <span aria-hidden className="opacity-60">
                            →
                        </span>
                        <Name value={branch.target} compact={compact} />
                    </>
                )}
                {kind === 'closed' && <span>· closed</span>}
            </>
        );

    const classes = cn(
        'inline-flex max-w-full min-w-0 items-center gap-1 rounded-md border px-1.5 py-0.5 font-mono text-[11px] leading-4',
        STYLES[kind],
        linked && branch.url && 'hover:underline',
        className,
    );

    const content = (
        <>
            <GitBranch className="size-3 shrink-0" aria-hidden />
            {label}
        </>
    );

    const description = describe(branch);

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                {linked && branch.url ? (
                    <a
                        href={branch.url}
                        target="_blank"
                        rel="noreferrer"
                        aria-label={description}
                        className={classes}
                    >
                        {content}
                    </a>
                ) : (
                    <span
                        tabIndex={0}
                        aria-label={description}
                        className={classes}
                    >
                        {content}
                    </span>
                )}
            </TooltipTrigger>
            <TooltipContent>{description}</TooltipContent>
        </Tooltip>
    );
}
