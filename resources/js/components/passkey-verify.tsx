import type { UrlMethodPair } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    routes?: {
        options: UrlMethodPair;
        submit: UrlMethodPair;
    };
    label?: string;
    loadingLabel?: string;
    separator?: string;
};

export default function PasskeyVerify({
    routes,
    label,
    loadingLabel,
    separator,
}: Props = {}) {
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const isSupported =
        typeof window !== 'undefined' && 'PublicKeyCredential' in window;

    async function verify() {
        setIsLoading(true);
        setError(null);

        try {
            const { Passkeys } = await import('@laravel/passkeys');
            const response = await Passkeys.verify({
                ...(routes && {
                    routes: {
                        options: routes.options.url,
                        submit: routes.submit.url,
                    },
                }),
            });

            router.visit(response.redirect ?? '/dashboard');
        } catch (error) {
            setError(
                error instanceof Error
                    ? error.message
                    : 'Passkey authentication failed.',
            );
        } finally {
            setIsLoading(false);
        }
    }

    if (!isSupported) {
        return null;
    }

    return (
        <>
            <div className="grid gap-2">
                <Button
                    type="button"
                    variant="outline"
                    className="w-full"
                    onClick={verify}
                    disabled={isLoading}
                >
                    {isLoading ? <Spinner /> : <KeyRound className="h-4 w-4" />}
                    {isLoading
                        ? (loadingLabel ?? 'Authenticating...')
                        : (label ?? 'Sign in with a passkey')}
                </Button>
                {error && (
                    <InputError message={error} className="text-center" />
                )}
            </div>

            <div className="relative my-6">
                <div className="absolute inset-0 flex items-center">
                    <Separator className="w-full" />
                </div>
                <div className="relative flex justify-center text-xs uppercase">
                    <span className="bg-background px-2 text-muted-foreground">
                        {separator ?? 'Or continue with email'}
                    </span>
                </div>
            </div>
        </>
    );
}
