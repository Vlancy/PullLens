import { Check, Loader2, X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type TestStatus = 'idle' | 'loading' | 'success' | 'error';

export function TestConnectionButton({
    testUrl,
    getPayload,
}: {
    testUrl: string;
    getPayload: () => Record<string, unknown>;
}) {
    const [status, setStatus] = useState<TestStatus>('idle');
    const [message, setMessage] = useState('');
    const [latency, setLatency] = useState<number | null>(null);
    const [errorOpen, setErrorOpen] = useState(false);

    async function runTest() {
        setStatus('loading');
        setMessage('');
        setLatency(null);

        try {
            const csrf =
                (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)
                    ?.content ?? '';

            const res = await fetch(testUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                },
                body: JSON.stringify(getPayload()),
            });

            const json = (await res.json()) as {
                success: boolean;
                message?: string;
                latency_ms?: number;
            };

            if (json.success) {
                setStatus('success');
                setLatency(json.latency_ms ?? null);
            } else {
                setStatus('error');
                setMessage(json.message || 'Connection failed');
                setErrorOpen(true);
            }
        } catch {
            setStatus('error');
            setMessage('Network error — could not reach the server.');
            setErrorOpen(true);
        }
    }

    return (
        <>
            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={runTest}
                    disabled={status === 'loading'}
                >
                    {status === 'loading' ? (
                        <>
                            <Loader2 className="size-4 animate-spin" />
                            Testing…
                        </>
                    ) : (
                        'Test connection'
                    )}
                </Button>

                {status === 'success' && (
                    <span className="flex items-center gap-1.5 text-sm text-green-600 dark:text-green-400">
                        <Check className="size-4" />
                        Connected{latency !== null ? ` (${latency}ms)` : ''}
                    </span>
                )}

                {status === 'error' && (
                    <button
                        type="button"
                        onClick={() => setErrorOpen(true)}
                        className="flex items-center gap-1.5 text-sm text-destructive underline-offset-4 hover:underline"
                    >
                        <X className="size-4" />
                        Failed — view details
                    </button>
                )}
            </div>

            <Dialog open={errorOpen} onOpenChange={setErrorOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Connection failed</DialogTitle>
                        <DialogDescription>
                            The provider returned an error. Check your API key, model name, and base
                            URL, then try again.
                        </DialogDescription>
                    </DialogHeader>
                    <pre className="max-h-64 overflow-auto rounded-md bg-muted p-4 text-xs leading-relaxed whitespace-pre-wrap break-all">
                        {message}
                    </pre>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setErrorOpen(false)}
                        >
                            Close
                        </Button>
                        <Button
                            type="button"
                            onClick={() => {
                                setErrorOpen(false);
                                runTest();
                            }}
                            disabled={status === 'loading'}
                        >
                            Retry
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
