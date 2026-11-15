import { router } from '@inertiajs/react';
import { Plus, RefreshCw, X } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { refresh as refreshProfile } from '@/routes/integrations/repositories/profile';
import {
    destroy as destroyTag,
    store as storeTag,
} from '@/routes/integrations/repositories/tags';

export type RepositoryTag = {
    id: string;
    name: string;
    kind: string;
    source: 'ai' | 'manual';
};

export type TagKindOption = { value: string; label: string };

type Props = {
    repositoryId: string | number;
    tags: RepositoryTag[];
    tagKinds: TagKindOption[];
    profileRefreshedAt: string | null;
};

/**
 * Profile tag chips with add, remove and "Refresh with AI" controls for one repository.
 */
export function RepositoryTagsEditor({
    repositoryId,
    tags,
    tagKinds,
    profileRefreshedAt,
}: Props) {
    const [tagName, setTagName] = useState('');
    const [tagKind, setTagKind] = useState('other');
    const [tagBusy, setTagBusy] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [tagError, setTagError] = useState<string | null>(null);

    function addTag(event: FormEvent) {
        event.preventDefault();

        if (tagName.trim() === '') {
            return;
        }

        setTagBusy(true);
        setTagError(null);
        router.post(
            storeTag(repositoryId).url,
            { name: tagName, kind: tagKind },
            {
                preserveScroll: true,
                onSuccess: () => setTagName(''),
                onError: (errors) =>
                    setTagError(
                        errors.name ?? errors.kind ?? 'Could not add tag.',
                    ),
                onFinish: () => setTagBusy(false),
            },
        );
    }

    function removeTag(tag: RepositoryTag) {
        router.delete(
            destroyTag({
                gitRepository: repositoryId,
                tag: tag.id,
            }).url,
            { preserveScroll: true },
        );
    }

    function refresh() {
        setRefreshing(true);
        router.post(
            refreshProfile(repositoryId).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setRefreshing(false),
            },
        );
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap gap-2">
                {tags.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No tags yet.
                    </p>
                )}
                {tags.map((tag) => (
                    <Badge
                        key={tag.id}
                        variant={tag.source === 'ai' ? 'secondary' : 'default'}
                        className="gap-1"
                    >
                        {tag.name}
                        <span className="text-[10px] uppercase opacity-70">
                            {tag.kind}
                        </span>
                        <button
                            type="button"
                            aria-label={`Remove tag ${tag.name}`}
                            onClick={() => removeTag(tag)}
                            className="rounded-full hover:opacity-70"
                        >
                            <X className="size-3" />
                        </button>
                    </Badge>
                ))}
            </div>

            <form onSubmit={addTag} className="flex flex-col gap-2 sm:flex-row">
                <Input
                    value={tagName}
                    onChange={(event) => setTagName(event.target.value)}
                    placeholder="Add a tag"
                    aria-label="Tag name"
                    maxLength={50}
                    className="sm:w-56"
                />
                <Select value={tagKind} onValueChange={setTagKind}>
                    <SelectTrigger
                        aria-label="Tag kind"
                        className="w-full sm:w-40"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {tagKinds.map((kind) => (
                            <SelectItem key={kind.value} value={kind.value}>
                                {kind.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Button type="submit" disabled={tagBusy}>
                    <Plus className="size-4" />
                    Add
                </Button>
            </form>
            {tagError && <p className="text-sm text-destructive">{tagError}</p>}

            <div className="flex flex-wrap items-center gap-3">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={refresh}
                    disabled={refreshing}
                >
                    <RefreshCw className="size-4" />
                    Refresh with AI
                </Button>
                <span className="text-sm text-muted-foreground">
                    {profileRefreshedAt
                        ? `Last refreshed ${new Date(profileRefreshedAt).toLocaleString()}`
                        : 'Not refreshed yet'}
                </span>
            </div>
        </div>
    );
}
