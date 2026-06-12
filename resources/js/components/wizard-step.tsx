import { Check, Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';

export type StepStatus = 'done' | 'active' | 'locked';

export function WizardStep({
    step,
    title,
    description,
    status,
    isLast,
    contentClassName,
    children,
}: {
    step: number;
    title: string;
    description: string;
    status: StepStatus;
    isLast: boolean;
    contentClassName?: string;
    children: ReactNode;
}) {
    const done = status === 'done';
    const locked = status === 'locked';

    return (
        <li className="relative flex gap-4 pb-8 last:pb-0">
            {!isLast && (
                <span
                    aria-hidden
                    className={cn(
                        'absolute top-9 -bottom-1 left-4 w-px -translate-x-1/2',
                        done ? 'bg-green-500/40' : 'bg-border',
                    )}
                />
            )}

            <div
                className={cn(
                    'z-10 flex size-8 shrink-0 items-center justify-center rounded-full border text-sm font-semibold',
                    done &&
                        'border-green-500 bg-green-500 text-white dark:text-green-950',
                    status === 'active' &&
                        'border-foreground bg-foreground text-background',
                    locked && 'border-border bg-muted text-muted-foreground',
                )}
            >
                {done ? (
                    <Check className="size-4" />
                ) : locked ? (
                    <Lock className="size-3.5" />
                ) : (
                    step
                )}
            </div>

            <Card className={cn('flex-1', locked && 'opacity-60')}>
                <CardHeader>
                    <CardTitle className="text-base">{title}</CardTitle>
                    <CardDescription>{description}</CardDescription>
                </CardHeader>
                <CardContent
                    className={cn('flex flex-col gap-4', contentClassName)}
                >
                    {children}
                </CardContent>
            </Card>
        </li>
    );
}

export function LockedHint({ children }: { children: ReactNode }) {
    return (
        <p className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
            <Lock className="size-3.5" />
            {children}
        </p>
    );
}
