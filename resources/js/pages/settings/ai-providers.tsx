import { Head, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    ChevronDown,
    ChevronRight,
    ExternalLink,
    KeyRound,
    Loader2,
    Plus,
    ShieldAlert,
    Trash2,
} from 'lucide-react';
import { useId, useState, type FormEvent } from 'react';
import { ModelSelect } from '@/components/settings/ai/model-select';
import { TestConnectionButton } from '@/components/settings/ai/test-connection-button';
import type { GuideEntry } from '@/lib/ai-models';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WizardStep, LockedHint } from '@/components/wizard-step';
import { modelsByDriver, providerGuides } from '@/lib/ai-models';
import PasswordInput from '@/components/password-input';
import { cn } from '@/lib/utils';

// ─── Types ───────────────────────────────────────────────────────────────────

type Driver = { value: string; label: string };

type AiProvider = {
    id: number;
    provider_driver: string;
    name: string;
    base_url: string | null;
    default_model: string | null;
    is_default: boolean;
    is_enabled: boolean;
    has_credentials: boolean;
    update_url: string;
    destroy_url: string;
    default_url: string;
};

type ProviderFormData = {
    provider_driver: string;
    name: string;
    api_key: string;
    base_url: string;
    default_model: string;
    is_default: boolean;
    is_enabled: boolean;
};

type Props = {
    providers: AiProvider[];
    drivers: Driver[];
    store_url: string;
    test_url: string;
};

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function AiProviders({ providers, drivers, store_url, test_url }: Props) {
    const [addOpen, setAddOpen] = useState(false);

    return (
        <>
            <Head title="AI Providers" />

            <div className="space-y-8 p-4 md:p-6">
                <div className="flex items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold">AI Providers</h1>
                        <p className="text-sm text-muted-foreground">
                            Configure encrypted provider credentials and the default model PullLens
                            uses for PR reviews.
                        </p>
                    </div>
                    {!addOpen && (
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => setAddOpen(true)}
                            className="shrink-0"
                        >
                            <Plus className="size-4" />
                            Add provider
                        </Button>
                    )}
                </div>

                {addOpen ? (
                    <AddProviderWizard
                        providers={providers}
                        drivers={drivers}
                        store_url={store_url}
                        test_url={test_url}
                        onClose={() => setAddOpen(false)}
                    />
                ) : providers.length === 0 ? (
                    <div className="rounded-lg border border-dashed px-6 py-12 text-center">
                        <KeyRound className="mx-auto mb-3 size-8 text-muted-foreground/50" />
                        <p className="text-sm font-medium">No providers configured</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Add an AI provider to enable automated PR reviews.
                        </p>
                    </div>
                ) : (
                    <div className="space-y-3">
                        {providers.map((provider) => (
                            <ProviderCard
                                key={provider.id}
                                provider={provider}
                                providers={providers}
                                drivers={drivers}
                                test_url={test_url}
                            />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

// ─── Add provider wizard ──────────────────────────────────────────────────────

function AddProviderWizard({
    providers,
    drivers,
    store_url,
    test_url,
    onClose,
}: {
    providers: AiProvider[];
    drivers: Driver[];
    store_url: string;
    test_url: string;
    onClose: () => void;
}) {
    const formId = useId();
    const [step, setStep] = useState<1 | 2>(1);
    const [nameSuffix] = useState(() => Math.random().toString(36).slice(2, 6));

    const { data, setData, post, transform, processing, errors } =
        useForm<ProviderFormData>({
            provider_driver: drivers[0]?.value ?? 'openai',
            name: '',
            api_key: '',
            base_url: '',
            default_model: '',
            is_default: providers.length === 0,
            is_enabled: true,
        });

    const driverLabel =
        drivers.find((d) => d.value === data.provider_driver)?.label ?? data.provider_driver;
    const suggestedName = `${driverLabel} - ${nameSuffix}`;
    const effectiveName = data.name.trim() || suggestedName;
    const duplicateName = hasDuplicateProviderName(effectiveName, providers);
    const guide = providerGuides[data.provider_driver];
    const isOllama = data.provider_driver === 'ollama';
    const presetModels = modelsByDriver[data.provider_driver] ?? [];

    const [preSaveTesting, setPreSaveTesting] = useState(false);
    const [preSaveError, setPreSaveError] = useState('');
    const [preSaveModalOpen, setPreSaveModalOpen] = useState(false);

    function doSave() {
        transform((d) => ({
            ...d,
            name: d.name.trim() || suggestedName,
            api_key: d.api_key.trim(),
            base_url: d.base_url.trim(),
            default_model: d.default_model.trim(),
        }));
        post(store_url, { preserveScroll: true, onSuccess: onClose });
    }

    async function submit(event: FormEvent) {
        event.preventDefault();
        if (duplicateName) return;
        setPreSaveTesting(true);
        try {
            const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
            const res = await fetch(test_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                body: JSON.stringify({
                    provider_driver: data.provider_driver,
                    api_key: data.api_key.trim(),
                    base_url: data.base_url.trim(),
                    default_model: data.default_model.trim(),
                }),
            });
            const json = await res.json() as { success: boolean; message?: string };
            if (json.success) {
                doSave();
            } else {
                setPreSaveError(json.message || 'Connection test failed');
                setPreSaveModalOpen(true);
            }
        } catch {
            setPreSaveError('Network error — could not reach the server.');
            setPreSaveModalOpen(true);
        } finally {
            setPreSaveTesting(false);
        }
    }

    const step1Status = step === 1 ? 'active' : 'done';
    const step2Status = step === 2 ? 'active' : 'locked';

    return (
        <div className="space-y-8">
            <div className="flex items-center gap-3">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={onClose}
                    aria-label="Cancel adding provider"
                >
                    <ArrowLeft className="size-4" />
                </Button>
                <div>
                    <h2 className="text-base font-semibold">Add AI provider</h2>
                    <p className="text-sm text-muted-foreground">
                        Follow the steps to configure a new provider.
                    </p>
                </div>
            </div>

            <ol className="space-y-0">
                <WizardStep
                    step={1}
                    isLast={false}
                    status={step1Status}
                    title="Choose provider"
                    description="Select the AI driver you want to use and grab your API key."
                >
                    <div className="grid gap-2 sm:grid-cols-3">
                        {drivers.map((driver) => {
                            const selected = data.provider_driver === driver.value;
                            return (
                                <button
                                    key={driver.value}
                                    type="button"
                                    disabled={step1Status === 'done'}
                                    onClick={() => {
                                        setData((d) => ({ ...d, provider_driver: driver.value, default_model: '' }));
                                    }}
                                    className={cn(
                                        'rounded-lg border px-4 py-3 text-left text-sm font-medium transition',
                                        selected
                                            ? 'border-foreground bg-foreground/5'
                                            : 'border-border hover:border-foreground/30 hover:bg-muted/40',
                                        step1Status === 'done' && 'pointer-events-none',
                                    )}
                                >
                                    <span className="flex items-center justify-between gap-2">
                                        {driver.label}
                                        {selected && <Check className="size-3.5 text-foreground" />}
                                    </span>
                                </button>
                            );
                        })}
                    </div>

                    {guide && step1Status === 'active' && (
                        <ProviderGuideBox guide={guide} />
                    )}

                    {step1Status === 'active' && (
                        <Button type="button" className="w-fit" onClick={() => setStep(2)}>
                            Continue
                            <ChevronRight className="size-4" />
                        </Button>
                    )}

                    {step1Status === 'done' && (
                        <button
                            type="button"
                            onClick={() => setStep(1)}
                            className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                        >
                            Change provider
                        </button>
                    )}
                </WizardStep>

                <WizardStep
                    step={2}
                    isLast
                    status={step2Status}
                    title="Configure credentials"
                    description="Enter your API key and model settings. Credentials are encrypted before they are stored."
                >
                    {step2Status === 'locked' ? (
                        <LockedHint>Choose a provider first.</LockedHint>
                    ) : (
                        <form onSubmit={submit} className="space-y-5">
                            {guide && <ProviderGuideBox guide={guide} />}

                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor={`${formId}-name`}>Display name</Label>
                                    <Input
                                        id={`${formId}-name`}
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder={suggestedName}
                                        aria-invalid={errors.name || duplicateName ? true : undefined}
                                    />
                                    {(errors.name || duplicateName) && (
                                        <p className="text-sm text-destructive">
                                            {errors.name ?? 'A provider with this name already exists.'}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor={`${formId}-model`}>Default model</Label>
                                    <ModelSelect
                                        key={data.provider_driver}
                                        id={`${formId}-model`}
                                        value={data.default_model}
                                        onChange={(v) => setData('default_model', v)}
                                        presetModels={presetModels}
                                        placeholder={guide?.modelPlaceholder}
                                        error={!!errors.default_model}
                                    />
                                    {errors.default_model && (
                                        <p className="text-sm text-destructive">{errors.default_model}</p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor={`${formId}-api-key`}>
                                    {isOllama ? 'API key (optional)' : 'API key'}
                                </Label>
                                <PasswordInput
                                    id={`${formId}-api-key`}
                                    value={data.api_key}
                                    onChange={(e) => setData('api_key', e.target.value)}
                                    placeholder="Provider API key"
                                    aria-invalid={errors.api_key ? true : undefined}
                                />
                                {errors.api_key && (
                                    <p className="text-sm text-destructive">{errors.api_key}</p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor={`${formId}-base-url`}>
                                    {data.provider_driver === 'bedrock' ? 'AWS region' : 'Base URL'}{' '}
                                    <span className="font-normal text-muted-foreground">(optional)</span>
                                </Label>
                                <Input
                                    id={`${formId}-base-url`}
                                    value={data.base_url}
                                    onChange={(e) => setData('base_url', e.target.value)}
                                    placeholder={guide?.baseUrlPlaceholder || 'Use driver default'}
                                    aria-invalid={errors.base_url ? true : undefined}
                                />
                                {errors.base_url && (
                                    <p className="text-sm text-destructive">{errors.base_url}</p>
                                )}
                            </div>

                            <div className="flex flex-wrap gap-6">
                                <label className="flex cursor-pointer select-none items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={data.is_enabled}
                                        onCheckedChange={(v) => setData('is_enabled', v === true)}
                                    />
                                    Enabled
                                </label>
                                <label className="flex cursor-pointer select-none items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={data.is_default}
                                        onCheckedChange={(v) => setData('is_default', v === true)}
                                    />
                                    Set as default
                                </label>
                            </div>

                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="flex items-center gap-3">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setStep(1)}
                                        disabled={processing || preSaveTesting}
                                    >
                                        Back
                                    </Button>
                                    <Button disabled={processing || preSaveTesting || duplicateName}>
                                        {preSaveTesting ? (
                                            <>
                                                <Loader2 className="size-4 animate-spin" />
                                                Testing…
                                            </>
                                        ) : (
                                            'Save provider'
                                        )}
                                    </Button>
                                </div>
                                <TestConnectionButton
                                    testUrl={test_url}
                                    getPayload={() => ({
                                        provider_driver: data.provider_driver,
                                        api_key: data.api_key.trim(),
                                        base_url: data.base_url.trim(),
                                        default_model: data.default_model.trim(),
                                    })}
                                />
                            </div>
                        </form>
                    )}
                </WizardStep>
            </ol>

            <Dialog open={preSaveModalOpen} onOpenChange={setPreSaveModalOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Connection test failed</DialogTitle>
                        <DialogDescription>
                            The provider credentials could not be verified. Fix the issue and try
                            again, or save anyway.
                        </DialogDescription>
                    </DialogHeader>
                    <pre className="max-h-64 overflow-auto rounded-md bg-muted p-4 text-xs leading-relaxed whitespace-pre-wrap break-all">
                        {preSaveError}
                    </pre>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setPreSaveModalOpen(false)}
                        >
                            Fix credentials
                        </Button>
                        <Button
                            type="button"
                            onClick={() => { setPreSaveModalOpen(false); doSave(); }}
                            disabled={processing}
                        >
                            Save anyway
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

// ─── Existing provider card (collapsible edit) ────────────────────────────────

function ProviderCard({
    provider,
    providers,
    drivers,
    test_url,
}: {
    provider: AiProvider;
    providers: AiProvider[];
    drivers: Driver[];
    test_url: string;
}) {
    const formId = useId();
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    const driverLabel =
        drivers.find((d) => d.value === provider.provider_driver)?.label ?? provider.provider_driver;
    const isOllama = provider.provider_driver === 'ollama';
    const guide = providerGuides[provider.provider_driver];
    const editPresetModels = modelsByDriver[provider.provider_driver] ?? [];

    const { data, setData, put, delete: destroy, transform, processing, errors, recentlySuccessful } =
        useForm<ProviderFormData>({
            provider_driver: provider.provider_driver,
            name: provider.name,
            api_key: '',
            base_url: provider.base_url ?? '',
            default_model: provider.default_model ?? '',
            is_default: provider.is_default,
            is_enabled: provider.is_enabled,
        });

    const duplicateName = hasDuplicateProviderName(data.name, providers, provider.id);

    const [preSaveTesting, setPreSaveTesting] = useState(false);
    const [preSaveError, setPreSaveError] = useState('');
    const [preSaveModalOpen, setPreSaveModalOpen] = useState(false);

    function doUpdate() {
        transform((d) => ({
            ...d,
            name: d.name.trim(),
            api_key: d.api_key.trim(),
            base_url: d.base_url.trim(),
            default_model: d.default_model.trim(),
        }));
        put(provider.update_url, { preserveScroll: true, onSuccess: () => setEditOpen(false) });
    }

    async function update(event: FormEvent) {
        event.preventDefault();
        if (duplicateName) return;
        setPreSaveTesting(true);
        try {
            const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
            const res = await fetch(test_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                body: JSON.stringify({
                    provider_driver: data.provider_driver,
                    api_key: data.api_key.trim(),
                    base_url: data.base_url.trim(),
                    default_model: data.default_model.trim(),
                    provider_id: provider.id,
                }),
            });
            const json = await res.json() as { success: boolean; message?: string };
            if (json.success) {
                doUpdate();
            } else {
                setPreSaveError(json.message || 'Connection test failed');
                setPreSaveModalOpen(true);
            }
        } catch {
            setPreSaveError('Network error — could not reach the server.');
            setPreSaveModalOpen(true);
        } finally {
            setPreSaveTesting(false);
        }
    }

    function markDefault() {
        put(provider.default_url, { preserveScroll: true });
    }

    function remove() {
        destroy(provider.destroy_url, {
            preserveScroll: true,
            onSuccess: () => setDeleteOpen(false),
        });
    }

    return (
        <>
            <Card>
                <CardHeader className="cursor-pointer" onClick={() => setEditOpen((o) => !o)}>
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-w-0 space-y-1">
                            <CardTitle className="flex flex-wrap items-center gap-2">
                                <span className="truncate">{provider.name}</span>
                                {provider.is_default && <Badge>Default</Badge>}
                                {!provider.is_enabled && <Badge variant="secondary">Disabled</Badge>}
                            </CardTitle>
                            <CardDescription className="flex items-center gap-2">
                                <span>{driverLabel}</span>
                                <span>·</span>
                                {provider.has_credentials ? (
                                    <span className="flex items-center gap-1">
                                        <KeyRound className="size-3.5" />
                                        Credentials saved
                                    </span>
                                ) : (
                                    <span className="flex items-center gap-1 text-amber-600 dark:text-amber-400">
                                        <ShieldAlert className="size-3.5" />
                                        No API key saved
                                    </span>
                                )}
                            </CardDescription>
                        </div>

                        <div
                            className="flex shrink-0 items-center gap-2"
                            onClick={(e) => e.stopPropagation()}
                        >
                            {!provider.is_default && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={markDefault}
                                    disabled={processing}
                                >
                                    Make default
                                </Button>
                            )}
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                onClick={() => setDeleteOpen(true)}
                                disabled={processing}
                                aria-label={`Delete ${provider.name}`}
                            >
                                <Trash2 className="size-4" />
                            </Button>
                            <ChevronDown
                                className={cn(
                                    'size-4 text-muted-foreground transition-transform duration-200',
                                    editOpen && 'rotate-180',
                                )}
                            />
                        </div>
                    </div>
                </CardHeader>

                {editOpen && (
                    <CardContent className="border-t pt-5">
                        <form onSubmit={update} className="space-y-5">
                            <div className="space-y-2">
                                <Label htmlFor={`${formId}-name`}>Display name</Label>
                                <Input
                                    id={`${formId}-name`}
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="My OpenAI"
                                    aria-invalid={errors.name || duplicateName ? true : undefined}
                                />
                                {(errors.name || duplicateName) && (
                                    <p className="text-sm text-destructive">
                                        {errors.name ?? 'A provider with this name already exists.'}
                                    </p>
                                )}
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor={`${formId}-api-key`}>
                                        {isOllama ? 'API key (optional)' : 'API key'}
                                    </Label>
                                    <PasswordInput
                                        id={`${formId}-api-key`}
                                        value={data.api_key}
                                        onChange={(e) => setData('api_key', e.target.value)}
                                        placeholder="Leave blank to keep current key"
                                        aria-invalid={errors.api_key ? true : undefined}
                                    />
                                    {errors.api_key && (
                                        <p className="text-sm text-destructive">{errors.api_key}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor={`${formId}-model`}>Default model</Label>
                                    <ModelSelect
                                        id={`${formId}-model`}
                                        value={data.default_model}
                                        onChange={(v) => setData('default_model', v)}
                                        presetModels={editPresetModels}
                                        placeholder={guide?.modelPlaceholder}
                                        error={!!errors.default_model}
                                    />
                                    {errors.default_model && (
                                        <p className="text-sm text-destructive">{errors.default_model}</p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor={`${formId}-base-url`}>
                                    {provider.provider_driver === 'bedrock' ? 'AWS region' : 'Base URL'}{' '}
                                    <span className="font-normal text-muted-foreground">(optional)</span>
                                </Label>
                                <Input
                                    id={`${formId}-base-url`}
                                    value={data.base_url}
                                    onChange={(e) => setData('base_url', e.target.value)}
                                    placeholder={guide?.baseUrlPlaceholder || 'Use driver default'}
                                    aria-invalid={errors.base_url ? true : undefined}
                                />
                                {errors.base_url && (
                                    <p className="text-sm text-destructive">{errors.base_url}</p>
                                )}
                            </div>

                            <label className="flex cursor-pointer select-none items-center gap-2 text-sm">
                                <Checkbox
                                    checked={data.is_enabled}
                                    onCheckedChange={(v) => setData('is_enabled', v === true)}
                                />
                                Enabled
                            </label>

                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="flex items-center gap-3">
                                    <Button disabled={processing || preSaveTesting || duplicateName}>
                                        {preSaveTesting ? (
                                            <>
                                                <Loader2 className="size-4 animate-spin" />
                                                Testing…
                                            </>
                                        ) : (
                                            'Update provider'
                                        )}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setEditOpen(false)}
                                        disabled={processing || preSaveTesting}
                                    >
                                        Cancel
                                    </Button>
                                    {recentlySuccessful && (
                                        <p className="text-sm text-muted-foreground">Saved.</p>
                                    )}
                                </div>
                                <TestConnectionButton
                                    testUrl={test_url}
                                    getPayload={() => ({
                                        provider_driver: data.provider_driver,
                                        api_key: data.api_key.trim(),
                                        base_url: data.base_url.trim(),
                                        default_model: data.default_model.trim(),
                                        provider_id: provider.id,
                                    })}
                                />
                            </div>
                        </form>
                    </CardContent>
                )}
            </Card>

            <Dialog open={preSaveModalOpen} onOpenChange={setPreSaveModalOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Connection test failed</DialogTitle>
                        <DialogDescription>
                            The provider credentials could not be verified. Fix the issue and try
                            again, or save anyway.
                        </DialogDescription>
                    </DialogHeader>
                    <pre className="max-h-64 overflow-auto rounded-md bg-muted p-4 text-xs leading-relaxed whitespace-pre-wrap break-all">
                        {preSaveError}
                    </pre>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setPreSaveModalOpen(false)}
                        >
                            Fix credentials
                        </Button>
                        <Button
                            type="button"
                            onClick={() => { setPreSaveModalOpen(false); doUpdate(); }}
                            disabled={processing}
                        >
                            Save anyway
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete {provider.name}?</DialogTitle>
                        <DialogDescription>
                            This will permanently remove the provider and its encrypted credentials.
                            Repositories using this provider will fall back to the default.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setDeleteOpen(false)}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={remove}
                            disabled={processing}
                        >
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

// ─── Provider guide box ───────────────────────────────────────────────────────

function ProviderGuideBox({ guide }: { guide: GuideEntry }) {
    return (
        <div className="rounded-lg border bg-muted/40 p-4 text-sm">
            <p className="text-muted-foreground">{guide.note}</p>
            <div className="mt-3 flex flex-wrap gap-2">
                {guide.keyUrl && (
                    <Button asChild type="button" variant="outline" size="sm">
                        <a href={guide.keyUrl} target="_blank" rel="noopener noreferrer">
                            Get API key
                            <ExternalLink className="size-3.5" />
                        </a>
                    </Button>
                )}
                <Button asChild type="button" variant="outline" size="sm">
                    <a href={guide.docsUrl} target="_blank" rel="noopener noreferrer">
                        View models
                        <ExternalLink className="size-3.5" />
                    </a>
                </Button>
            </div>
        </div>
    );
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function hasDuplicateProviderName(
    name: string,
    providers: AiProvider[],
    exceptId?: number,
): boolean {
    const normalized = name.trim().toLocaleLowerCase();
    if (normalized === '') return false;
    return providers.some(
        (p) => p.id !== exceptId && p.name.trim().toLocaleLowerCase() === normalized,
    );
}
