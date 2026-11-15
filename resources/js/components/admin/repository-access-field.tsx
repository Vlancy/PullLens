import { Search, X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type RepositoryOption = {
    id: string;
    full_name: string;
};

export type AccessLevelOption = {
    value: string;
    label: string;
};

/** Repository id => access level. Absent key means no access. */
export type RepositoryGrants = Record<string, string>;

/** Sentinel for the "no access" choice; never sent to the server. */
export const NO_ACCESS = 'none';

function AccessSelect({
    id,
    value,
    accessLevels,
    placeholder,
    onChange,
    className,
}: {
    id?: string;
    value: string | undefined;
    accessLevels: AccessLevelOption[];
    placeholder?: string;
    onChange: (value: string) => void;
    className?: string;
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger id={id} className={className}>
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NO_ACCESS}>No access</SelectItem>
                {accessLevels.map((level) => (
                    <SelectItem key={level.value} value={level.value}>
                        {level.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/**
 * Per-repository grants for roles that do not see every repository.
 *
 * Rendered only for scoped roles: for the others the grants would have no effect, and
 * the server clears them rather than storing rows that silently do nothing. Supports
 * searching, selecting (all visible or one by one) and setting the access level of
 * every selected repository at once.
 */
export function RepositoryAccessField({
    idPrefix,
    repositories,
    accessLevels,
    grants,
    onChange,
}: {
    idPrefix: string;
    repositories: RepositoryOption[];
    accessLevels: AccessLevelOption[];
    grants: RepositoryGrants;
    onChange: (grants: RepositoryGrants) => void;
}) {
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState<string[]>([]);

    if (repositories.length === 0) {
        return (
            <div className="space-y-1.5 sm:col-span-2">
                <Label>Repository access</Label>
                <p className="rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                    No repositories are tracked yet. Connect one first, then
                    come back to grant access.
                </p>
            </div>
        );
    }

    const query = search.trim().toLowerCase();
    const visible = query
        ? repositories.filter((repository) =>
              repository.full_name.toLowerCase().includes(query),
          )
        : repositories;
    const visibleIds = visible.map((repository) => repository.id);
    const selectedVisible = visibleIds.filter((id) => selected.includes(id));
    const allVisibleSelected =
        visibleIds.length > 0 && selectedVisible.length === visibleIds.length;
    const someVisibleSelected =
        selectedVisible.length > 0 && !allVisibleSelected;
    const grantedCount = Object.keys(grants).length;

    function apply(ids: string[], level: string) {
        const next = { ...grants };

        for (const id of ids) {
            if (level === NO_ACCESS) {
                delete next[id];
            } else {
                next[id] = level;
            }
        }

        onChange(next);
    }

    function toggleAllVisible(checked: boolean) {
        setSelected((current) =>
            checked
                ? Array.from(new Set([...current, ...visibleIds]))
                : current.filter((id) => !visibleIds.includes(id)),
        );
    }

    function toggleOne(id: string, checked: boolean) {
        setSelected((current) =>
            checked ? [...current, id] : current.filter((s) => s !== id),
        );
    }

    function applyToSelected(level: string) {
        apply(selected, level);
        setSelected([]);
    }

    return (
        <div className="space-y-2 sm:col-span-2">
            <div className="flex items-center justify-between gap-2">
                <Label>Repository access</Label>
                <span className="text-xs text-muted-foreground">
                    {grantedCount} of {repositories.length} granted
                </span>
            </div>
            <p className="text-xs text-muted-foreground">
                This role only sees the repositories granted below. Everything
                else - dashboard totals, findings, pull requests - narrows to
                the same set.
            </p>

            <div className="rounded-md border">
                {/* Toolbar: select all (visible) + search; bulk action row once something is selected. */}
                <div className="flex flex-wrap items-center gap-2 border-b p-2">
                    <Checkbox
                        id={`${idPrefix}-repo-select-all`}
                        aria-label={
                            query
                                ? 'Select all matching repositories'
                                : 'Select all repositories'
                        }
                        checked={
                            allVisibleSelected
                                ? true
                                : someVisibleSelected
                                  ? 'indeterminate'
                                  : false
                        }
                        onCheckedChange={(checked) =>
                            toggleAllVisible(checked === true)
                        }
                        className="ml-1"
                    />
                    <div className="relative min-w-0 flex-1">
                        <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search repositories"
                            aria-label="Search repositories"
                            className="h-8 pl-8"
                        />
                    </div>
                </div>

                {selected.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2 border-b bg-muted/40 px-3 py-2">
                        <span className="text-sm font-medium">
                            {selected.length} selected
                        </span>
                        <AccessSelect
                            value={undefined}
                            placeholder="Set access…"
                            accessLevels={accessLevels}
                            onChange={applyToSelected}
                            className="h-8 w-40"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="ml-auto"
                            onClick={() => setSelected([])}
                        >
                            <X className="size-3.5" />
                            Clear selection
                        </Button>
                    </div>
                )}

                <div className="max-h-64 space-y-1 overflow-y-auto p-2">
                    {visible.length === 0 && (
                        <p className="px-1 py-3 text-center text-sm text-muted-foreground">
                            No repositories match “{search.trim()}”.
                        </p>
                    )}
                    {visible.map((repository) => {
                        const checkboxId = `${idPrefix}-repo-check-${repository.id}`;

                        return (
                            <div
                                key={repository.id}
                                className="flex items-center gap-3 rounded-md px-1 py-0.5 hover:bg-muted/50"
                            >
                                <Checkbox
                                    id={checkboxId}
                                    checked={selected.includes(repository.id)}
                                    onCheckedChange={(checked) =>
                                        toggleOne(
                                            repository.id,
                                            checked === true,
                                        )
                                    }
                                />
                                <label
                                    htmlFor={checkboxId}
                                    className="min-w-0 flex-1 cursor-pointer truncate font-mono text-xs"
                                >
                                    {repository.full_name}
                                </label>
                                <AccessSelect
                                    id={`${idPrefix}-repo-${repository.id}`}
                                    value={grants[repository.id] ?? NO_ACCESS}
                                    accessLevels={accessLevels}
                                    onChange={(value) =>
                                        apply([repository.id], value)
                                    }
                                    className="h-8 w-40 shrink-0"
                                />
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}
