export type ModelOption = {
    value: string;
    label: string;
    recommended?: boolean;
};

export type GuideEntry = {
    keyUrl?: string;
    docsUrl: string;
    modelPlaceholder: string;
    baseUrlPlaceholder: string;
    note: string;
};

export const providerGuides: Record<string, GuideEntry> = {
    openai: {
        keyUrl: 'https://platform.openai.com/api-keys',
        docsUrl: 'https://platform.openai.com/docs/models',
        modelPlaceholder: 'gpt-5.6-terra',
        baseUrlPlaceholder: 'https://api.openai.com/v1',
        note: 'Use an OpenAI platform API key. ChatGPT/Codex login is separate from API access.',
    },
    anthropic: {
        keyUrl: 'https://console.anthropic.com/settings/keys',
        docsUrl: 'https://docs.anthropic.com/en/docs/about-claude/models',
        modelPlaceholder: 'claude-sonnet-5',
        baseUrlPlaceholder: 'https://api.anthropic.com/v1',
        note: 'Use an Anthropic Console API key with access to the selected Claude model.',
    },
    openrouter: {
        keyUrl: 'https://openrouter.ai/settings/keys',
        docsUrl: 'https://openrouter.ai/models',
        modelPlaceholder: 'anthropic/claude-sonnet-5',
        baseUrlPlaceholder: 'https://openrouter.ai/api/v1',
        note: 'OpenRouter is useful when you want one key for multiple model providers.',
    },
    gemini: {
        keyUrl: 'https://aistudio.google.com/app/apikey',
        docsUrl: 'https://ai.google.dev/gemini-api/docs/models',
        modelPlaceholder: 'gemini-3.7-flash',
        baseUrlPlaceholder: 'https://generativelanguage.googleapis.com/v1beta/',
        note: 'Use a Google AI Studio API key for Gemini models.',
    },
    groq: {
        keyUrl: 'https://console.groq.com/keys',
        docsUrl: 'https://console.groq.com/docs/models',
        modelPlaceholder: 'openai/gpt-oss-120b',
        baseUrlPlaceholder: '',
        note: 'Groq is optimized for fast hosted inference with supported open models.',
    },
    mistral: {
        keyUrl: 'https://console.mistral.ai/api-keys',
        docsUrl:
            'https://docs.mistral.ai/getting-started/models/models_overview/',
        modelPlaceholder: 'codestral-2508',
        baseUrlPlaceholder: '',
        note: 'Use a Mistral Console API key for hosted Mistral models.',
    },
    deepseek: {
        keyUrl: 'https://platform.deepseek.com/api_keys',
        docsUrl: 'https://api-docs.deepseek.com/quick_start/pricing',
        modelPlaceholder: 'deepseek-v4-pro',
        baseUrlPlaceholder: '',
        note: 'Use a DeepSeek platform API key for DeepSeek chat models.',
    },
    xai: {
        keyUrl: 'https://console.x.ai/',
        docsUrl: 'https://docs.x.ai/docs/models',
        modelPlaceholder: 'grok-4.6',
        baseUrlPlaceholder: '',
        note: 'Use an xAI Console API key for Grok models.',
    },
    azure: {
        keyUrl: 'https://portal.azure.com/#view/Microsoft_Azure_ProjectOxford/CognitiveServicesHub/~/OpenAI',
        docsUrl:
            'https://learn.microsoft.com/en-us/azure/ai-services/openai/concepts/models',
        modelPlaceholder: 'gpt-5.6-terra',
        baseUrlPlaceholder: 'https://<resource>.openai.azure.com',
        note: 'Use your Azure OpenAI API key. The base URL is your resource endpoint (e.g. https://my-resource.openai.azure.com). The default model field sets the deployment name.',
    },
    cohere: {
        keyUrl: 'https://dashboard.cohere.com/api-keys',
        docsUrl: 'https://docs.cohere.com/docs/models',
        modelPlaceholder: 'command-a-plus-05-2026',
        baseUrlPlaceholder: '',
        note: 'Use a Cohere dashboard API key for Command models.',
    },
    bedrock: {
        docsUrl:
            'https://docs.aws.amazon.com/bedrock/latest/userguide/models-supported.html',
        modelPlaceholder: 'anthropic.claude-sonnet-5',
        baseUrlPlaceholder: 'us-east-1',
        note: 'AWS Bedrock uses IAM credentials configured on your server (recommended) or an AWS bearer token in the API key field. The base URL field sets the AWS region (default: us-east-1).',
    },
    ollama: {
        docsUrl: 'https://ollama.com/library',
        modelPlaceholder: 'qwen3-coder:30b',
        baseUrlPlaceholder: 'http://localhost:11434',
        note: 'Ollama runs locally and does not require a cloud API key. Set the base URL reachable by the PullLens server.',
    },
};

export const modelsByDriver: Record<string, ModelOption[]> = {
    openai: [
        { value: 'gpt-5.6-terra', label: 'GPT-5.6 Terra (balanced)', recommended: true },
        { value: 'gpt-5.6-sol', label: 'GPT-5.6 Sol (deepest reasoning)' },
        { value: 'gpt-5.3-codex', label: 'GPT-5.3 Codex (code specialist)' },
        { value: 'gpt-5.6-luna', label: 'GPT-5.6 Luna (fast, low cost)' },
    ],
    anthropic: [
        { value: 'claude-sonnet-5', label: 'Claude Sonnet 5 (balanced)', recommended: true },
        { value: 'claude-opus-5', label: 'Claude Opus 5 (complex coding)' },
        { value: 'claude-fable-5', label: 'Claude Fable 5 (highest capability)' },
        { value: 'claude-haiku-4-5-20251001', label: 'Claude Haiku 4.5 (fast, low cost)' },
    ],
    openrouter: [
        {
            value: 'anthropic/claude-sonnet-5',
            label: 'Claude Sonnet 5 (balanced)',
            recommended: true,
        },
        { value: 'anthropic/claude-opus-5', label: 'Claude Opus 5 (complex coding)' },
        { value: 'openai/gpt-5.6-terra', label: 'GPT-5.6 Terra' },
        { value: 'google/gemini-3.7-flash', label: 'Gemini 3.7 Flash' },
        { value: 'deepseek/deepseek-v4-pro', label: 'DeepSeek V4 Pro (low cost)' },
    ],
    gemini: [
        {
            value: 'gemini-3.7-flash',
            label: 'Gemini 3.7 Flash (built for coding)',
            recommended: true,
        },
        { value: 'gemini-3.1-pro-preview', label: 'Gemini 3.1 Pro (deepest reasoning)' },
        { value: 'gemini-3.5-flash', label: 'Gemini 3.5 Flash' },
        { value: 'gemini-3.1-flash-lite', label: 'Gemini 3.1 Flash-Lite (low cost)' },
    ],
    groq: [
        {
            value: 'openai/gpt-oss-120b',
            label: 'GPT-OSS 120B',
            recommended: true,
        },
        { value: 'openai/gpt-oss-20b', label: 'GPT-OSS 20B (fastest)' },
    ],
    mistral: [
        {
            value: 'codestral-2508',
            label: 'Codestral 25.08 (code specialist)',
            recommended: true,
        },
        { value: 'mistral-medium-3.5', label: 'Mistral Medium 3.5' },
        { value: 'mistral-large-3', label: 'Mistral Large 3' },
    ],
    deepseek: [
        {
            value: 'deepseek-v4-pro',
            label: 'DeepSeek V4 Pro',
            recommended: true,
        },
        { value: 'deepseek-v4-flash', label: 'DeepSeek V4 Flash (low cost)' },
    ],
    xai: [
        { value: 'grok-4.6', label: 'Grok 4.6', recommended: true },
        { value: 'grok-4', label: 'Grok 4' },
    ],
    azure: [
        {
            value: 'gpt-5.6-terra',
            label: 'gpt-5.6-terra (deployment name)',
            recommended: true,
        },
        { value: 'gpt-5.6-sol', label: 'gpt-5.6-sol (deployment name)' },
        { value: 'gpt-4.1', label: 'gpt-4.1 (deployment name)' },
    ],
    cohere: [
        { value: 'command-a-plus-05-2026', label: 'Command A+', recommended: true },
        { value: 'command-a-reasoning', label: 'Command A Reasoning' },
        { value: 'command-r-plus', label: 'Command R+ (legacy)' },
    ],
    bedrock: [
        {
            value: 'anthropic.claude-sonnet-5',
            label: 'Claude Sonnet 5 (balanced)',
            recommended: true,
        },
        { value: 'anthropic.claude-opus-5', label: 'Claude Opus 5 (complex coding)' },
        { value: 'anthropic.claude-fable-5', label: 'Claude Fable 5 (highest capability)' },
        { value: 'anthropic.claude-haiku-4-5', label: 'Claude Haiku 4.5 (fast, low cost)' },
    ],
    ollama: [
        {
            value: 'qwen3-coder:30b',
            label: 'Qwen3-Coder 30B (best quality per GB)',
            recommended: true,
        },
        { value: 'devstral:24b', label: 'Devstral 24B (agentic coding)' },
        { value: 'glm-4.7-flash', label: 'GLM 4.7 Flash' },
        { value: 'gpt-oss:20b', label: 'GPT-OSS 20B (16GB RAM)' },
    ],
};

/**
 * The model to pre-select when an operator picks a provider.
 *
 * Returns the entry flagged `recommended`, falling back to the first option. Keeping
 * this beside the model lists means the default can never drift from the list it is
 * supposed to point into.
 */
export function recommendedModelFor(driver: string): string {
    const models = modelsByDriver[driver] ?? [];

    return (models.find((model) => model.recommended) ?? models[0])?.value ?? '';
}
