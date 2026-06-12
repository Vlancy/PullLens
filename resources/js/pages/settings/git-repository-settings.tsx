import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, Globe, Lock, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { ModelSelect } from '@/components/settings/ai/model-select';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { modelsByDriver } from '@/lib/ai-models';

type Repository = {
    id: number;
    name: string;
    full_name: string;
    owner_login: string;
    owner_type: string | null;
    default_branch: string | null;
    is_private: boolean;
    web_url: string | null;
    reviews_enabled: boolean;
    auto_review_on_open: boolean;
    auto_approve: boolean;
    auto_apply_labels: boolean;
    allow_comment_replies: boolean;
    auto_merge: boolean;
    auto_merge_method: string;
    review_language: string;
    review_tone: string;
    use_emoji: boolean;
    base_branches: string[];
    tracked_branches: string[];
    ai_provider_id: string | null;
    ai_model: string | null;
    review_intensity: string;
};

type AiProvider = {
    id: string;
    name: string;
    provider_driver: string;
    default_model: string | null;
    is_default: boolean;
};

type Props = {
    repository: Repository;
    ai_providers: AiProvider[];
    branches: string[];
    languages: { value: string; label: string }[];
    merge_methods: string[];
    review_intensities: string[];
    review_tones: string[];
    update_url: string;
    sync_branches_url: string;
    back_url: string;
    status?: string | null;
};

type ToggleField =
    | 'reviews_enabled'
    | 'auto_review_on_open'
    | 'auto_approve'
    | 'auto_apply_labels'
    | 'allow_comment_replies'
    | 'auto_merge'
    | 'use_emoji';

export default function GitRepositorySettings({
    repository,
    ai_providers,
    branches,
    languages,
    merge_methods,
    review_intensities,
    review_tones,
    update_url,
    sync_branches_url,
    back_url,
    status,
}: Props) {
    const [syncingBranches, setSyncingBranches] = useState(false);
    const [overrideModel, setOverrideModel] = useState(
        repository.ai_model !== null && repository.ai_model !== '',
    );

    function syncBranches() {
        setSyncingBranches(true);
        router.post(
            sync_branches_url,
            {},
            {
                onFinish: () => setSyncingBranches(false),
            },
        );
    }

    const { data, setData, put, processing, recentlySuccessful } = useForm({
        reviews_enabled: repository.reviews_enabled,
        auto_review_on_open: repository.auto_review_on_open,
        auto_approve: repository.auto_approve,
        auto_apply_labels: repository.auto_apply_labels,
        allow_comment_replies: repository.allow_comment_replies,
        auto_merge: repository.auto_merge,
        auto_merge_method: repository.auto_merge_method,
        review_language: repository.review_language || 'en',
        review_tone: repository.review_tone || 'professional',
        use_emoji: repository.use_emoji,
        base_branches: repository.base_branches,
        tracked_branches: repository.tracked_branches,
        ai_provider_id: repository.ai_provider_id,
        ai_model: repository.ai_model ?? '',
        review_intensity: repository.review_intensity,
    });

    const selectedProvider =
        ai_providers.find((p) => p.id === data.ai_provider_id) ?? null;
    const presetModels =
        modelsByDriver[selectedProvider?.provider_driver ?? ''] ?? [];

    function submit(event: FormEvent) {
        event.preventDefault();
        put(update_url, { preserveScroll: true });
    }

    function toggleBranch(name: string, checked: boolean) {
        const next = checked
            ? [...data.tracked_branches, name]
            : data.tracked_branches.filter((branch) => branch !== name);
        setData({ ...data, tracked_branches: next, base_branches: next });
    }

    const mergeMethodLabels: Record<string, string> = {
        merge: 'Merge commit',
        squash: 'Squash and merge',
        rebase: 'Rebase and merge',
    };

    const toneLabels: Record<string, string> = {
        professional: 'Professional',
        friendly: 'Friendly',
        concise: 'Concise',
        detailed: 'Detailed',
    };

    const toneDescriptions: Record<string, string> = {
        professional:
            'Formal and objective. Uses precise technical language without casual expressions.',
        friendly:
            'Encouraging and approachable. Acknowledges what the author did well alongside findings.',
        concise:
            'Short and direct. Minimal prose — only what is needed to understand the issue.',
        detailed:
            'Thorough explanations for every finding, including context, impact, and step-by-step fixes.',
    };

    const intensityLabels: Record<string, string> = {
        light: 'Light',
        balanced: 'Balanced',
        strict: 'Strict',
    };

    const intensityDescriptions: Record<string, string> = {
        light: 'Only flags critical and high severity issues. Keeps the walkthrough concise. Best for low-risk repositories or teams that want a quick sanity check.',
        balanced:
            'Reports all findings with relevant detail. The recommended default for most repositories.',
        strict: 'Exhaustive review — includes medium, low, and informational findings, test coverage gaps, edge cases, and maintainability concerns.',
    };

    return (
        <>
            <Head title={`${repository.full_name} settings`} />

            <div className="space-y-8 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                        <Button
                            asChild
                            variant="ghost"
                            size="icon"
                            aria-label="Back to git platform settings"
                        >
                            <Link href={back_url}>
                                <ArrowLeft className="size-4" />
                            </Link>
                        </Button>
                        <div>
                            <div className="flex items-center gap-2">
                                <p className="text-lg font-semibold">
                                    {repository.full_name}
                                </p>
                                {repository.is_private ? (
                                    <Badge variant="outline">
                                        <Lock className="size-3" />
                                        Private
                                    </Badge>
                                ) : (
                                    <Badge variant="outline">
                                        <Globe className="size-3" />
                                        Public
                                    </Badge>
                                )}
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Configure how PullLens reviews pull requests in
                                this repository.
                            </p>
                        </div>
                    </div>

                    {repository.web_url && (
                        <Button asChild variant="outline" size="sm">
                            <a
                                href={repository.web_url}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <ExternalLink className="size-4" />
                                Open on provider
                            </a>
                        </Button>
                    )}
                </div>

                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Reviews</CardTitle>
                            <CardDescription>
                                Control whether and when PullLens reviews pull
                                requests.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-1">
                            <ToggleRow
                                field="reviews_enabled"
                                label="Enable reviews"
                                description="Pause or resume all PullLens reviews for this repository without untracking it."
                                checked={data.reviews_enabled}
                                onChange={(checked) =>
                                    setData('reviews_enabled', checked)
                                }
                            />
                            <ToggleRow
                                field="auto_review_on_open"
                                label="Auto-review on PR open"
                                description="Review automatically when a pull request is opened instead of only on request."
                                checked={data.auto_review_on_open}
                                disabled={!data.reviews_enabled}
                                onChange={(checked) =>
                                    setData('auto_review_on_open', checked)
                                }
                            />
                            <ToggleRow
                                field="auto_approve"
                                label="Auto-approve and submit"
                                description="Submit an approving review automatically when PullLens finds no blocking issues."
                                checked={data.auto_approve}
                                disabled={!data.reviews_enabled}
                                onChange={(checked) =>
                                    setData('auto_approve', checked)
                                }
                            />
                            <ToggleRow
                                field="auto_apply_labels"
                                label="Auto-apply labels"
                                description="Let PullLens add labels to the pull request based on its review."
                                checked={data.auto_apply_labels}
                                disabled={!data.reviews_enabled}
                                onChange={(checked) =>
                                    setData('auto_apply_labels', checked)
                                }
                            />
                            <ToggleRow
                                field="allow_comment_replies"
                                label="Allow replies to PR comments"
                                description="Let PullLens reply to review comments and follow-up questions on the pull request."
                                checked={data.allow_comment_replies}
                                disabled={!data.reviews_enabled}
                                onChange={(checked) =>
                                    setData('allow_comment_replies', checked)
                                }
                            />

                            <div className="flex flex-col gap-2 py-4 sm:flex-row sm:items-center sm:justify-between">
                                <div className="space-y-0.5">
                                    <Label htmlFor="review_language">
                                        Review language
                                    </Label>
                                    <p className="text-sm text-muted-foreground">
                                        The language PullLens writes its reviews
                                        in.
                                    </p>
                                </div>
                                <Select
                                    value={data.review_language}
                                    onValueChange={(value) =>
                                        setData('review_language', value)
                                    }
                                >
                                    <SelectTrigger
                                        id="review_language"
                                        className="w-full sm:w-56"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {languages.map((lang) => (
                                            <SelectItem
                                                key={lang.value}
                                                value={lang.value}
                                            >
                                                {lang.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Merging</CardTitle>
                            <CardDescription>
                                Decide what PullLens does after a successful
                                review.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-1">
                            <ToggleRow
                                field="auto_merge"
                                label="Auto-merge approved PRs"
                                description="Merge the pull request automatically once it is approved and all checks pass."
                                checked={data.auto_merge}
                                disabled={!data.reviews_enabled}
                                onChange={(checked) =>
                                    setData('auto_merge', checked)
                                }
                            />

                            <div className="flex flex-col gap-2 py-4 sm:flex-row sm:items-center sm:justify-between">
                                <div className="space-y-0.5">
                                    <Label htmlFor="auto_merge_method">
                                        Merge method
                                    </Label>
                                    <p className="text-sm text-muted-foreground">
                                        How PullLens merges when auto-merge is
                                        on.
                                    </p>
                                </div>
                                <Select
                                    value={data.auto_merge_method}
                                    onValueChange={(value) =>
                                        setData('auto_merge_method', value)
                                    }
                                >
                                    <SelectTrigger
                                        id="auto_merge_method"
                                        className="w-full sm:w-56"
                                        disabled={!data.auto_merge}
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {merge_methods.map((method) => (
                                            <SelectItem
                                                key={method}
                                                value={method}
                                            >
                                                {mergeMethodLabels[method] ??
                                                    method}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <CardTitle>Branches</CardTitle>
                                    <CardDescription>
                                        Select the branches PullLens watches.
                                        PRs targeting these branches will be
                                        reviewed. Leave empty to include all
                                        branches.
                                    </CardDescription>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={syncingBranches}
                                    onClick={syncBranches}
                                    className="shrink-0"
                                >
                                    <RefreshCw
                                        className={`size-4 ${syncingBranches ? 'animate-spin' : ''}`}
                                    />
                                    {syncingBranches ? 'Syncing…' : 'Sync'}
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {status === 'Branches synced.' && (
                                <p className="mb-3 text-sm text-green-600">
                                    Branches synced successfully.
                                </p>
                            )}
                            {branches.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No branches found. Click "Sync" to fetch
                                    them.
                                </p>
                            ) : (
                                <BranchPicker
                                    branches={branches}
                                    defaultBranch={repository.default_branch}
                                    selected={data.tracked_branches}
                                    onToggle={toggleBranch}
                                />
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>AI review engine</CardTitle>
                            <CardDescription>
                                Use the global default provider or override the
                                provider and model for this repository.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="ai_provider_id">
                                        Provider
                                    </Label>
                                    <Select
                                        value={data.ai_provider_id ?? 'default'}
                                        onValueChange={(value) => {
                                            const providerId =
                                                value === 'default'
                                                    ? null
                                                    : value;
                                            const chosen = ai_providers.find(
                                                (p) => p.id === value,
                                            );

                                            if (chosen?.default_model) {
                                                setOverrideModel(true);
                                                setData((d) => ({
                                                    ...d,
                                                    ai_provider_id: providerId,
                                                    ai_model:
                                                        chosen.default_model ??
                                                        '',
                                                }));
                                            } else {
                                                setOverrideModel(false);
                                                setData((d) => ({
                                                    ...d,
                                                    ai_provider_id: providerId,
                                                    ai_model: '',
                                                }));
                                            }
                                        }}
                                    >
                                        <SelectTrigger id="ai_provider_id">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {!ai_providers.some(
                                                (p) =>
                                                    p.is_default &&
                                                    p.default_model,
                                            ) && (
                                                <SelectItem value="default">
                                                    Global default
                                                </SelectItem>
                                            )}
                                            {ai_providers.map((provider) => (
                                                <SelectItem
                                                    key={provider.id}
                                                    value={provider.id}
                                                >
                                                    {provider.name}
                                                    {provider.is_default
                                                        ? ' (default)'
                                                        : ''}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-3">
                                    <label className="flex cursor-pointer items-center gap-2 text-sm font-medium select-none">
                                        <Checkbox
                                            checked={overrideModel}
                                            onCheckedChange={(checked) => {
                                                const on = checked === true;
                                                setOverrideModel(on);

                                                if (!on) {
                                                    setData('ai_model', '');
                                                }
                                            }}
                                        />
                                        Override model
                                    </label>
                                    {overrideModel ? (
                                        <ModelSelect
                                            key={
                                                data.ai_provider_id ?? 'default'
                                            }
                                            id="ai_model"
                                            value={data.ai_model}
                                            onChange={(v) =>
                                                setData('ai_model', v)
                                            }
                                            presetModels={presetModels}
                                            placeholder="e.g. gpt-4o"
                                        />
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            Uses the provider's configured
                                            default model.
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="review_intensity">
                                        Review intensity
                                    </Label>
                                    <Select
                                        value={data.review_intensity}
                                        onValueChange={(value) =>
                                            setData('review_intensity', value)
                                        }
                                    >
                                        <SelectTrigger id="review_intensity">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {review_intensities.map(
                                                (intensity) => (
                                                    <SelectItem
                                                        key={intensity}
                                                        value={intensity}
                                                    >
                                                        {intensityLabels[
                                                            intensity
                                                        ] ?? intensity}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                    {intensityDescriptions[
                                        data.review_intensity
                                    ] && (
                                        <p className="text-sm text-muted-foreground">
                                            {
                                                intensityDescriptions[
                                                    data.review_intensity
                                                ]
                                            }
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="review_tone">Review tone</Label>
                                <Select
                                    value={data.review_tone}
                                    onValueChange={(value) =>
                                        setData('review_tone', value)
                                    }
                                >
                                    <SelectTrigger id="review_tone">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {review_tones.map((tone) => (
                                            <SelectItem key={tone} value={tone}>
                                                {toneLabels[tone] ?? tone}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {toneDescriptions[data.review_tone] && (
                                    <p className="text-sm text-muted-foreground">
                                        {toneDescriptions[data.review_tone]}
                                    </p>
                                )}
                            </div>

                            <ToggleRow
                                field="use_emoji"
                                label="Use emoji in reviews"
                                description="Let PullLens add emoji to enhance scannability (e.g. 🔒 for security, ⚡ for performance)."
                                checked={data.use_emoji}
                                onChange={(checked) =>
                                    setData('use_emoji', checked)
                                }
                            />
                        </CardContent>
                    </Card>

                    <div className="flex items-center gap-4">
                        <Button disabled={processing}>Save settings</Button>
                        {recentlySuccessful && (
                            <p className="text-sm text-muted-foreground">
                                Saved.
                            </p>
                        )}
                    </div>
                </form>
            </div>
        </>
    );
}

function ToggleRow({
    field,
    label,
    description,
    checked,
    disabled,
    onChange,
}: {
    field: ToggleField;
    label: string;
    description: string;
    checked: boolean;
    disabled?: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <div className="flex items-start justify-between gap-4 border-b py-4 last:border-b-0">
            <div className="space-y-0.5">
                <Label htmlFor={field}>{label}</Label>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            <Checkbox
                id={field}
                checked={checked}
                disabled={disabled}
                onCheckedChange={(value) => onChange(value === true)}
            />
        </div>
    );
}

function BranchPicker({
    branches,
    defaultBranch,
    selected,
    onToggle,
}: {
    branches: string[];
    defaultBranch: string | null;
    selected: string[];
    onToggle: (name: string, checked: boolean) => void;
}): ReactNode {
    return (
        <ul className="grid gap-2 rounded-md border p-3 sm:grid-cols-2">
            {branches.map((branch) => (
                <li key={branch} className="flex items-center gap-2">
                    <Checkbox
                        id={`branch-${branch}`}
                        checked={selected.includes(branch)}
                        onCheckedChange={(value) =>
                            onToggle(branch, value === true)
                        }
                    />
                    <label
                        htmlFor={`branch-${branch}`}
                        className="flex min-w-0 cursor-pointer items-center gap-2 text-sm"
                    >
                        <span className="truncate">{branch}</span>
                        {branch === defaultBranch && (
                            <Badge variant="secondary">default</Badge>
                        )}
                    </label>
                </li>
            ))}
        </ul>
    );
}
