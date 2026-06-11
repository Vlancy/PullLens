import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, Globe, Lock } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
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
    base_branches: string[];
    tracked_branches: string[];
};

type Props = {
    repository: Repository;
    branches: string[];
    languages: string[];
    merge_methods: string[];
    update_url: string;
    back_url: string;
};

type ToggleField =
    | 'reviews_enabled'
    | 'auto_review_on_open'
    | 'auto_approve'
    | 'auto_apply_labels'
    | 'allow_comment_replies'
    | 'auto_merge';

type BranchField = 'base_branches' | 'tracked_branches';

export default function GitRepositorySettings({
    repository,
    branches,
    languages,
    merge_methods,
    update_url,
    back_url,
}: Props) {
    const { data, setData, put, processing, recentlySuccessful } = useForm({
        reviews_enabled: repository.reviews_enabled,
        auto_review_on_open: repository.auto_review_on_open,
        auto_approve: repository.auto_approve,
        auto_apply_labels: repository.auto_apply_labels,
        allow_comment_replies: repository.allow_comment_replies,
        auto_merge: repository.auto_merge,
        auto_merge_method: repository.auto_merge_method,
        review_language: repository.review_language,
        base_branches: repository.base_branches,
        tracked_branches: repository.tracked_branches,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        put(update_url, { preserveScroll: true });
    }

    function toggleBranch(field: BranchField, name: string, checked: boolean) {
        const current = data[field];

        setData(
            field,
            checked
                ? [...current, name]
                : current.filter((branch) => branch !== name),
        );
    }

    const mergeMethodLabels: Record<string, string> = {
        merge: 'Merge commit',
        squash: 'Squash and merge',
        rebase: 'Rebase and merge',
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

                            <div className="flex flex-col gap-2 border-t py-4 sm:flex-row sm:items-center sm:justify-between">
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
                                        {languages.map((language) => (
                                            <SelectItem
                                                key={language}
                                                value={language}
                                            >
                                                {language}
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

                            <div className="flex flex-col gap-2 border-t py-4 sm:flex-row sm:items-center sm:justify-between">
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
                            <CardTitle>Branches</CardTitle>
                            <CardDescription>
                                Limit which branches PullLens tracks and which
                                base branches it reviews against. Leave a list
                                empty to include every branch.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {branches.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No branches were stored for this repository
                                    yet.
                                </p>
                            ) : (
                                <>
                                    <BranchPicker
                                        title="Tracked branches"
                                        description="Branches PullLens keeps in sync. Empty means all branches."
                                        branches={branches}
                                        defaultBranch={repository.default_branch}
                                        selected={data.tracked_branches}
                                        onToggle={(name, checked) =>
                                            toggleBranch(
                                                'tracked_branches',
                                                name,
                                                checked,
                                            )
                                        }
                                    />
                                    <BranchPicker
                                        title="Base branches to review"
                                        description="Only review PRs targeting these base branches. Empty means all."
                                        branches={branches}
                                        defaultBranch={repository.default_branch}
                                        selected={data.base_branches}
                                        onToggle={(name, checked) =>
                                            toggleBranch(
                                                'base_branches',
                                                name,
                                                checked,
                                            )
                                        }
                                    />
                                </>
                            )}
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
    title,
    description,
    branches,
    defaultBranch,
    selected,
    onToggle,
}: {
    title: string;
    description: string;
    branches: string[];
    defaultBranch: string | null;
    selected: string[];
    onToggle: (name: string, checked: boolean) => void;
}): ReactNode {
    return (
        <div className="space-y-2">
            <div className="space-y-0.5">
                <p className="text-sm font-medium">{title}</p>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            <ul className="grid gap-2 rounded-md border p-3 sm:grid-cols-2">
                {branches.map((branch) => (
                    <li key={branch} className="flex items-center gap-2">
                        <Checkbox
                            id={`${title}-${branch}`}
                            checked={selected.includes(branch)}
                            onCheckedChange={(value) =>
                                onToggle(branch, value === true)
                            }
                        />
                        <label
                            htmlFor={`${title}-${branch}`}
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
        </div>
    );
}
