import { Form, Head, router } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    ChevronRight,
    ExternalLink,
    GithubIcon,
    Link,
    RefreshCw,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import GitRepositoryManager from '@/components/git-repository-manager';
import type { TrackedRepository } from '@/components/git-repository-manager';
import Heading from '@/components/heading';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { StepStatus } from '@/components/wizard-step';
import { WizardStep, LockedHint } from '@/components/wizard-step';
import { cn, toUrl } from '@/lib/utils';
import { destroy, edit, redirect } from '@/routes/integrations';

type GitProvider = {
    value: string;
    label: string;
    scopes: string[];
    configured: boolean;
    app_name: string | null;
    install_url: string | null;
    github_settings_url: string | null;
    delete_url: string;
    setup_url: string | null;
    test_url: string | null;
    connect_url: string | null;
    sync_url: string | null;
    callback_url: string;
    webhook_url: string | null;
    repositories_browse_url: string;
    repositories_store_url: string;
};

type GitAccount = {
    id: number;
    provider: string;
    provider_label: string;
    provider_user_id: string;
    nickname: string | null;
    name: string | null;
    email: string | null;
    avatar_url: string | null;
    scopes: string[];
    connected_at: string | null;
    last_used_at: string | null;
};

type Props = {
    accounts: GitAccount[];
    providers: GitProvider[];
    repositories: TrackedRepository[];
    status?: string;
};

const providerIcons: Record<string, ReactNode> = {
    github: <GithubIcon className="size-5" />,
};

export default function GitPlatforms({
    accounts,
    providers,
    repositories,
    status,
}: Props) {
    // Resume a provider whose setup is still mid-flow so returning users land
    // back where they left off. A fully finished provider (app configured,
    // account connected, repositories tracked) stays on the selection screen.
    const [selectedValue, setSelectedValue] = useState<string | null>(() => {
        const inProgress = providers.find((provider) => {
            const accountConnected = accounts.some(
                (account) => account.provider === provider.value,
            );
            const started = provider.configured || accountConnected;

            if (!started) {
                return false;
            }

            const hasRepositories = repositories.some(
                (repository) => repository.provider === provider.value,
            );
            const done =
                provider.configured && accountConnected && hasRepositories;

            return !done;
        });

        return inProgress?.value ?? null;
    });

    const selected =
        providers.find((provider) => provider.value === selectedValue) ?? null;

    return (
        <>
            <Head title="Git Providers" />

            <h1 className="sr-only">Git Providers</h1>

            <div className="space-y-8 p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Git Providers"
                    description="Manage global PullLens configuration for this self-hosted instance."
                />

                {status && (
                    <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800 dark:border-green-900/60 dark:bg-green-950/30 dark:text-green-300">
                        {status}
                    </div>
                )}

                {selected ? (
                    <ProviderWizard
                        provider={selected}
                        accounts={accounts.filter(
                            (account) => account.provider === selected.value,
                        )}
                        repositories={repositories.filter(
                            (repository) =>
                                repository.provider === selected.value,
                        )}
                        onBack={() => setSelectedValue(null)}
                    />
                ) : (
                    <ProviderSelector
                        providers={providers}
                        accounts={accounts}
                        onSelect={setSelectedValue}
                    />
                )}
            </div>
        </>
    );
}

GitPlatforms.layout = {
    breadcrumbs: [
        {
            title: 'Git Providers',
            href: edit(),
        },
    ],
};

/**
 * First screen: pick which Git platform to configure before the guided setup.
 */
function ProviderSelector({
    providers,
    accounts,
    onSelect,
}: {
    providers: GitProvider[];
    accounts: GitAccount[];
    onSelect: (value: string) => void;
}) {
    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-base font-semibold">
                    Choose a Git platform
                </h2>
                <p className="text-sm text-muted-foreground">
                    Select the provider you want to connect. PullLens will walk
                    you through the setup one step at a time.
                </p>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                {providers.map((provider) => {
                    const available = Boolean(provider.setup_url);
                    const connectedCount = accounts.filter(
                        (account) => account.provider === provider.value,
                    ).length;

                    return (
                        <button
                            key={provider.value}
                            type="button"
                            disabled={!available}
                            onClick={() => onSelect(provider.value)}
                            className={cn(
                                'group flex items-center gap-4 rounded-xl border bg-card p-5 text-left transition',
                                available
                                    ? 'hover:border-foreground/30 hover:shadow-sm'
                                    : 'cursor-not-allowed opacity-60',
                            )}
                        >
                            <div className="flex size-12 shrink-0 items-center justify-center rounded-full bg-foreground text-background">
                                {providerIcons[provider.value] ?? (
                                    <ShieldCheck className="size-5" />
                                )}
                            </div>

                            <div className="min-w-0 flex-1 space-y-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {provider.label}
                                    </span>
                                    {provider.configured ? (
                                        <Badge variant="secondary">
                                            <CheckCircle2 className="size-3" />
                                            App configured
                                        </Badge>
                                    ) : available ? (
                                        <Badge variant="outline">
                                            Setup required
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline">
                                            Coming soon
                                        </Badge>
                                    )}
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {connectedCount > 0
                                        ? `${connectedCount} account${connectedCount === 1 ? '' : 's'} connected`
                                        : 'Create the app, connect an account, then grant repository access.'}
                                </p>
                            </div>

                            {available && (
                                <ChevronRight className="size-5 shrink-0 text-muted-foreground transition group-hover:translate-x-0.5 group-hover:text-foreground" />
                            )}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

/**
 * Guided, step-by-step setup for a single selected Git provider.
 */
function ProviderWizard({
    provider,
    accounts,
    repositories,
    onBack,
}: {
    provider: GitProvider;
    accounts: GitAccount[];
    repositories: TrackedRepository[];
    onBack: () => void;
}) {
    const appConfigured = provider.configured;
    const accountConnected = accounts.length > 0;
    const [connectMode, setConnectMode] = useState<'create' | 'connect'>('create');

    const connectStatus: StepStatus = !appConfigured
        ? 'locked'
        : accountConnected
          ? 'done'
          : 'active';

    const repoStatus: StepStatus = !accountConnected
        ? 'locked'
        : repositories.length > 0
          ? 'done'
          : 'active';

    return (
        <div className="space-y-8">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-3">
                    <Button
                        variant="ghost"
                        size="icon"
                        onClick={onBack}
                        aria-label="Back to provider selection"
                    >
                        <ArrowLeft className="size-4" />
                    </Button>
                    <div className="flex size-10 items-center justify-center rounded-full bg-foreground text-background">
                        {providerIcons[provider.value] ?? (
                            <ShieldCheck className="size-5" />
                        )}
                    </div>
                    <div>
                        <h2 className="text-base font-semibold">
                            Connect {provider.label}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {provider.app_name
                                ? `App: ${provider.app_name}`
                                : 'Follow the steps below to finish setup.'}
                        </p>
                    </div>
                </div>

                {appConfigured ? (
                    <Badge className="w-fit" variant="secondary">
                        <CheckCircle2 className="size-3" />
                        App configured
                    </Badge>
                ) : (
                    <Badge className="w-fit" variant="outline">
                        Setup required
                    </Badge>
                )}
            </div>

            <ol className="space-y-0">
                <WizardStep
                    step={1}
                    isLast={false}
                    contentClassName="gap-3"
                    status={appConfigured ? 'done' : 'active'}
                    title={`Create the ${provider.label} App`}
                    description={`PullLens builds an app manifest with the right permissions, callback, and webhook. You'll create it on ${provider.label} and be returned here automatically. Secrets are encrypted before they are stored.`}
                >
                    <div className="flex flex-wrap gap-2">
                        {provider.scopes.map((scope) => (
                            <Badge key={scope} variant="secondary">
                                {scope}
                            </Badge>
                        ))}
                    </div>

                    <ReferenceRow
                        label="OAuth callback URL"
                        value={provider.callback_url}
                    />
                    {provider.webhook_url && (
                        <ReferenceRow
                            label="Webhook URL"
                            value={provider.webhook_url}
                        />
                    )}

                    {appConfigured ? (
                        <div className="flex flex-wrap items-center gap-3 pt-1">
                            <span className="inline-flex items-center gap-1.5 text-sm font-medium text-green-700 dark:text-green-400">
                                <CheckCircle2 className="size-4" />
                                {provider.app_name ??
                                    `${provider.label} App`}{' '}
                                connected
                            </span>
                            {provider.sync_url && (
                                <Form
                                    action={provider.sync_url}
                                    method="post"
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            <RefreshCw
                                                className={`size-4 ${processing ? 'animate-spin' : ''}`}
                                            />
                                            Sync
                                        </Button>
                                    )}
                                </Form>
                            )}
                            <ConfirmDeleteForm
                                action={provider.delete_url}
                                title={`Delete ${provider.label} app configuration?`}
                                description={`This uninstalls the ${provider.label} App from every account, removes the encrypted credentials, and disconnects ${provider.label} accounts from PullLens. ${provider.github_settings_url ? `${provider.label} has no API to delete the app itself, so its settings page will open in a new tab where you can finish by deleting the app registration.` : ''}`}
                                submitLabel="Uninstall & delete"
                                openOnConfirm={provider.github_settings_url}
                                trigger={
                                    <Button variant="outline" size="sm">
                                        Reconfigure app
                                    </Button>
                                }
                            />
                        </div>
                    ) : provider.setup_url ? (
                        <div className="space-y-4">
                            <div className="flex flex-wrap gap-2">
                                <Button asChild className="w-fit">
                                    <a href={provider.setup_url} rel="noopener noreferrer">
                                        <GithubIcon className="size-4" />
                                        Create {provider.label} App automatically
                                    </a>
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="w-fit"
                                    onClick={() =>
                                        setConnectMode((m) =>
                                            m === 'connect' ? 'create' : 'connect',
                                        )
                                    }
                                >
                                    <Link className="size-4" />
                                    {connectMode === 'connect'
                                        ? 'Cancel'
                                        : 'Connect existing'}
                                </Button>
                            </div>

                            {connectMode === 'connect' && provider.connect_url && (
                                <ConnectExistingAppForm
                                    action={provider.connect_url}
                                    testUrl={provider.test_url ?? ''}
                                />
                            )}
                        </div>
                    ) : (
                        <Button disabled variant="secondary" className="w-fit">
                            Coming soon
                        </Button>
                    )}
                </WizardStep>

                <WizardStep
                    step={2}
                    isLast={false}
                    contentClassName="gap-3"
                    status={connectStatus}
                    title="Connect an operator account"
                    description={`Sign in with the ${provider.label} account PullLens should act as when posting reviews. You can connect more than one.`}
                >
                    {accountConnected && (
                        <div className="grid gap-2">
                            {accounts.map((account) => (
                                <ConnectedAccountRow
                                    key={account.id}
                                    account={account}
                                />
                            ))}
                        </div>
                    )}

                    {connectStatus === 'locked' ? (
                        <LockedHint>
                            Create the {provider.label} App first.
                        </LockedHint>
                    ) : (
                        <Button
                            asChild
                            variant={accountConnected ? 'outline' : 'default'}
                            className="w-fit"
                        >
                            <a
                                href={toUrl(redirect(provider.value))}
                                rel="noopener noreferrer"
                            >
                                {accountConnected
                                    ? `Connect another ${provider.label} account`
                                    : `Connect ${provider.label}`}
                            </a>
                        </Button>
                    )}
                </WizardStep>

                <WizardStep
                    step={3}
                    isLast
                    contentClassName="gap-3"
                    status={repoStatus}
                    title="Grant repository access"
                    description={`Install the ${provider.label} App on the repositories PullLens should review. Choose every repository or only selected ones — you can change this any time.`}
                >
                    {repoStatus === 'locked' ? (
                        <LockedHint>
                            Connect an operator account first.
                        </LockedHint>
                    ) : (
                        <>
                            {provider.install_url && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="w-fit"
                                >
                                    <a
                                        href={provider.install_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <ExternalLink className="size-4" />
                                        Install / select repositories on{' '}
                                        {provider.label}
                                    </a>
                                </Button>
                            )}

                            <GitRepositoryManager
                                providerLabel={provider.label}
                                accounts={accounts.map((account) => ({
                                    id: account.id,
                                    label:
                                        account.nickname ??
                                        account.name ??
                                        account.provider_user_id,
                                }))}
                                browseUrl={provider.repositories_browse_url}
                                storeUrl={provider.repositories_store_url}
                                tracked={repositories}
                            />
                        </>
                    )}
                </WizardStep>
            </ol>
        </div>
    );
}

/**
 * Read-only reference value (callback/webhook URL) shown inside a setup step.
 */
function ReferenceRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-md border bg-muted/40 px-3 py-2 text-sm">
            <p className="font-medium">{label}</p>
            <p className="break-all text-muted-foreground">{value}</p>
        </div>
    );
}

/**
 * Compact connected-account card with disconnect action.
 */
function ConnectedAccountRow({ account }: { account: GitAccount }) {
    const displayName =
        account.nickname ?? account.name ?? account.provider_user_id;

    return (
        <div className="flex items-center justify-between gap-4 rounded-lg border p-3">
            <div className="flex min-w-0 items-center gap-3">
                <Avatar className="size-9">
                    <AvatarImage
                        src={account.avatar_url ?? ''}
                        alt={account.nickname ?? ''}
                    />
                    <AvatarFallback>{initials(account)}</AvatarFallback>
                </Avatar>
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <p className="truncate text-sm font-medium">
                            {displayName}
                        </p>
                        <Badge variant="secondary">
                            <CheckCircle2 className="size-3" />
                            Connected
                        </Badge>
                    </div>
                    <p className="truncate text-xs text-muted-foreground">
                        {account.email ?? `ID: ${account.provider_user_id}`}
                    </p>
                </div>
            </div>

            <ConfirmDeleteForm
                action={destroy.url(account.id)}
                title="Disconnect Git account?"
                description={`This removes ${displayName} from PullLens. You can reconnect it later.`}
                submitLabel="Disconnect"
                trigger={
                    <Button variant="outline" size="sm">
                        Disconnect
                    </Button>
                }
            />
        </div>
    );
}

type TestState = 'idle' | 'testing' | 'success' | 'error';

function ConnectExistingAppForm({
    action,
    testUrl,
}: {
    action: string;
    testUrl: string;
}) {
    const [fields, setFields] = useState({
        app_id: '',
        slug: '',
        client_id: '',
        client_secret: '',
        webhook_secret: '',
        private_key: '',
    });
    const [testState, setTestState] = useState<TestState>('idle');
    const [testMessage, setTestMessage] = useState('');
    const [connecting, setConnecting] = useState(false);

    function setField(key: keyof typeof fields) {
        return (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
            setFields((prev) => ({ ...prev, [key]: e.target.value }));
            setTestState('idle');
            setTestMessage('');
        };
    }

    async function test(): Promise<boolean> {
        setTestState('testing');
        setTestMessage('');
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
                body: JSON.stringify({ app_id: fields.app_id, private_key: fields.private_key }),
            });
            const json = (await res.json()) as { success: boolean; message?: string };
            if (json.success) {
                setTestState('success');
                setTestMessage(json.message ?? 'Connected');
                return true;
            }
            setTestState('error');
            setTestMessage(json.message ?? 'Connection failed');
            return false;
        } catch {
            setTestState('error');
            setTestMessage('Network error — could not reach the server.');
            return false;
        }
    }

    async function handleConnect() {
        setConnecting(true);
        const ok = await test();
        if (ok) {
            router.post(action, fields as Record<string, string>);
        } else {
            setConnecting(false);
        }
    }

    const canTest = fields.app_id.trim() !== '' && fields.private_key.trim() !== '';
    const busy = testState === 'testing' || connecting;

    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="app_id">App ID</Label>
                    <Input
                        id="app_id"
                        name="app_id"
                        placeholder="123456"
                        required
                        value={fields.app_id}
                        onChange={setField('app_id')}
                    />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="slug">App slug</Label>
                    <Input
                        id="slug"
                        name="slug"
                        placeholder="my-pulllens-app"
                        required
                        value={fields.slug}
                        onChange={setField('slug')}
                    />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="client_id">Client ID</Label>
                    <Input
                        id="client_id"
                        name="client_id"
                        placeholder="Iv1.abc123"
                        required
                        value={fields.client_id}
                        onChange={setField('client_id')}
                    />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="client_secret">Client secret</Label>
                    <Input
                        id="client_secret"
                        name="client_secret"
                        type="password"
                        required
                        value={fields.client_secret}
                        onChange={setField('client_secret')}
                    />
                </div>
                <div className="space-y-1.5 sm:col-span-2">
                    <Label htmlFor="webhook_secret">
                        Webhook secret{' '}
                        <span className="font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    <Input
                        id="webhook_secret"
                        name="webhook_secret"
                        type="password"
                        value={fields.webhook_secret}
                        onChange={setField('webhook_secret')}
                    />
                </div>
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="private_key">Private key (PEM)</Label>
                <textarea
                    id="private_key"
                    name="private_key"
                    rows={7}
                    placeholder={'-----BEGIN RSA PRIVATE KEY-----\n...\n-----END RSA PRIVATE KEY-----'}
                    className="w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-xs shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                    required
                    value={fields.private_key}
                    onChange={setField('private_key')}
                />
            </div>

            <div className="space-y-2">
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={!canTest || busy}
                        onClick={test}
                    >
                        {testState === 'testing' && !connecting ? (
                            <><RefreshCw className="size-4 animate-spin" />Testing…</>
                        ) : (
                            'Test connection'
                        )}
                    </Button>
                    <Button
                        type="button"
                        disabled={!canTest || busy}
                        onClick={handleConnect}
                    >
                        {connecting ? (
                            <><RefreshCw className="size-4 animate-spin" />Connecting…</>
                        ) : (
                            'Connect app'
                        )}
                    </Button>
                </div>

                {testState === 'success' && (
                    <p className="flex items-center gap-1.5 text-sm text-green-600 dark:text-green-400">
                        <CheckCircle2 className="size-4" />{testMessage}
                    </p>
                )}
                {testState === 'error' && (
                    <p className="text-sm text-destructive">{testMessage}</p>
                )}
            </div>
        </div>
    );
}

function initials(account: GitAccount) {
    return (account.nickname ?? account.name ?? account.provider_label)
        .slice(0, 2)
        .toUpperCase();
}

function ConfirmDeleteForm({
    action,
    title,
    description,
    submitLabel,
    trigger,
    openOnConfirm,
}: {
    action: string;
    title: string;
    description: string;
    submitLabel: string;
    trigger: ReactNode;
    openOnConfirm?: string | null;
}) {
    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Form
                        action={action}
                        method="delete"
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                variant="destructive"
                                disabled={processing}
                                onClick={() => {
                                    if (openOnConfirm) {
                                        window.open(
                                            openOnConfirm,
                                            '_blank',
                                            'noopener,noreferrer',
                                        );
                                    }
                                }}
                            >
                                {submitLabel}
                            </Button>
                        )}
                    </Form>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
