import { useState } from 'react';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { ModelOption } from '@/lib/ai-models';

/**
 * Model picker: shows a preset dropdown when models are available, or a free-text input.
 * Supports "Other…" to escape to a custom input at any time.
 *
 * Pass `key={driverId}` when the driver can change at runtime so React resets the
 * internal custom-mode state when the user picks a different provider.
 */
export function ModelSelect({
    id,
    value,
    onChange,
    presetModels,
    placeholder,
    error,
}: {
    id?: string;
    value: string;
    onChange: (value: string) => void;
    presetModels: ModelOption[];
    placeholder?: string;
    error?: boolean;
}) {
    const recommendedValue =
        presetModels.find((m) => m.recommended)?.value ?? '';
    const valueInPresets = presetModels.some((m) => m.value === value);

    const [customMode, setCustomMode] = useState(
        () => value !== '' && presetModels.length > 0 && !valueInPresets,
    );

    const showCustom =
        customMode ||
        (value !== '' && presetModels.length > 0 && !valueInPresets);

    if (presetModels.length === 0) {
        return (
            <Input
                id={id}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder ?? 'model-name'}
                aria-invalid={error ? true : undefined}
            />
        );
    }

    if (showCustom) {
        return (
            <div className="space-y-1.5">
                <Input
                    id={id}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder={
                        recommendedValue || placeholder || 'model-name'
                    }
                    aria-invalid={error ? true : undefined}
                    autoFocus
                />
                <button
                    type="button"
                    className="text-xs text-muted-foreground underline-offset-4 hover:underline"
                    onClick={() => {
                        setCustomMode(false);
                        onChange('');
                    }}
                >
                    Cancel — pick from list
                </button>
            </div>
        );
    }

    return (
        <Select
            value={value || ''}
            onValueChange={(v) => {
                if (v === '__other__') {
                    setCustomMode(true);
                    onChange('');
                } else {
                    setCustomMode(false);
                    onChange(v);
                }
            }}
        >
            <SelectTrigger id={id} aria-invalid={error ? true : undefined}>
                <SelectValue
                    placeholder={
                        recommendedValue
                            ? `${recommendedValue} (recommended)`
                            : 'Select model…'
                    }
                />
            </SelectTrigger>
            <SelectContent>
                {presetModels.map((m) => (
                    <SelectItem key={m.value} value={m.value}>
                        {m.label}
                        {m.recommended ? ' — recommended' : ''}
                    </SelectItem>
                ))}
                <SelectItem value="__other__">Other…</SelectItem>
            </SelectContent>
        </Select>
    );
}
