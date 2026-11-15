import { router } from '@inertiajs/react';
import { Layers } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { preview, start } from '@/routes/projects/feature-grouping';

export type GroupingStatus = {
    ungrouped: number;
    batches: number;
    running: boolean;
    processed: number;
    total: number;
    error: string | null;
};

type GroupingScope = { project_id: string } | { repository_id: string };

const POLL_MS = 3000;

function ProgressBar({
    processed,
    total,
}: {
    processed: number;
    total: number;
}) {
    const percent =
        total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;

    return (
        <div className="space-y-1">
            <div
                className="h-2 w-full overflow-hidden rounded-full bg-muted"
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent}
            >
                <div
                    className="h-full bg-primary transition-all"
                    style={{ width: `${percent}%` }}
                />
            </div>
            <p className="text-xs text-muted-foreground">
                {processed} of {total} task{total === 1 ? '' : 's'} processed
            </p>
        </div>
    );
}

export function FeatureGroupingButton({
    scope,
    initial,
}: {
    scope: GroupingScope;
    initial: GroupingStatus;
}) {
    const [status, setStatus] = useState<GroupingStatus>(initial);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [starting, setStarting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const previewUrl = preview.url({ query: scope });

    const refresh = useCallback(async () => {
        try {
            const response = await fetch(previewUrl, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                setError('Could not load the grouping status.');

                return;
            }

            setStatus((await response.json()) as GroupingStatus);
        } catch {
            setError('Network error - could not reach the server.');
        }
    }, [previewUrl]);

    useEffect(() => {
        if (!status.running) {
            return;
        }

        const timer = window.setInterval(() => void refresh(), POLL_MS);

        return () => window.clearInterval(timer);
    }, [status.running, refresh]);

    async function openDialog() {
        setOpen(true);
        setError(null);
        setLoading(true);
        await refresh();
        setLoading(false);
    }

    function confirm() {
        setStarting(true);
        setError(null);
        router.post(start.url(), scope, {
            preserveScroll: true,
            onSuccess: () => {
                setStatus((current) => ({
                    ...current,
                    running: current.ungrouped > 0,
                    processed: 0,
                    total: current.ungrouped,
                    error: null,
                }));
                void refresh();
            },
            onError: (errors) =>
                setError(
                    errors.scope ??
                        errors.repository_id ??
                        errors.project_id ??
                        'Could not start grouping.',
                ),
            onFinish: () => setStarting(false),
        });
    }

    return (
        <div className="space-y-2">
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => void openDialog()}
            >
                <Layers className="size-4" />
                Group existing tasks
            </Button>
            {status.running && (
                <ProgressBar
                    processed={status.processed}
                    total={status.total}
                />
            )}

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Group existing tasks</DialogTitle>
                        <DialogDescription>
                            Files tasks recorded before features existed under
                            features, using your AI provider. Tasks you sorted
                            by hand are left alone.
                        </DialogDescription>
                    </DialogHeader>

                    {loading ? (
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Spinner />
                            Counting tasks…
                        </div>
                    ) : status.running ? (
                        <div className="space-y-2">
                            <p className="text-sm">
                                Grouping is running. It carries on if you close
                                this dialog.
                            </p>
                            <ProgressBar
                                processed={status.processed}
                                total={status.total}
                            />
                        </div>
                    ) : status.ungrouped === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Every task already has a feature.
                        </p>
                    ) : (
                        <p className="text-sm">
                            {status.ungrouped} task
                            {status.ungrouped === 1 ? '' : 's'} without a
                            feature. Grouping takes about {status.batches} AI
                            call{status.batches === 1 ? '' : 's'}.
                        </p>
                    )}

                    {!status.running && status.error && (
                        <p className="text-sm text-destructive">
                            {status.error}
                        </p>
                    )}
                    {error && (
                        <p className="text-sm text-destructive">{error}</p>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Close
                        </Button>
                        {!status.running && (
                            <Button
                                type="button"
                                disabled={
                                    loading ||
                                    starting ||
                                    status.ungrouped === 0
                                }
                                onClick={confirm}
                            >
                                Group {status.ungrouped} task
                                {status.ungrouped === 1 ? '' : 's'}
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
