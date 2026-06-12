export type ModelOption = { value: string; label: string; recommended?: boolean };

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
        modelPlaceholder: 'gpt-4.1',
        baseUrlPlaceholder: 'https://api.openai.com/v1',
        note: 'Use an OpenAI platform API key. ChatGPT/Codex login is separate from API access.',
    },
    anthropic: {
        keyUrl: 'https://console.anthropic.com/settings/keys',
        docsUrl: 'https://docs.anthropic.com/en/docs/about-claude/models',
        modelPlaceholder: 'claude-sonnet-4-6',
        baseUrlPlaceholder: 'https://api.anthropic.com/v1',
        note: 'Use an Anthropic Console API key with access to the selected Claude model.',
    },
    openrouter: {
        keyUrl: 'https://openrouter.ai/settings/keys',
        docsUrl: 'https://openrouter.ai/models',
        modelPlaceholder: 'anthropic/claude-sonnet-4-5',
        baseUrlPlaceholder: 'https://openrouter.ai/api/v1',
        note: 'OpenRouter is useful when you want one key for multiple model providers.',
    },
    gemini: {
        keyUrl: 'https://aistudio.google.com/app/apikey',
        docsUrl: 'https://ai.google.dev/gemini-api/docs/models',
        modelPlaceholder: 'gemini-2.5-pro',
        baseUrlPlaceholder: 'https://generativelanguage.googleapis.com/v1beta/',
        note: 'Use a Google AI Studio API key for Gemini models.',
    },
    groq: {
        keyUrl: 'https://console.groq.com/keys',
        docsUrl: 'https://console.groq.com/docs/models',
        modelPlaceholder: 'llama-3.3-70b-versatile',
        baseUrlPlaceholder: '',
        note: 'Groq is optimized for fast hosted inference with supported open models.',
    },
    mistral: {
        keyUrl: 'https://console.mistral.ai/api-keys',
        docsUrl: 'https://docs.mistral.ai/getting-started/models/models_overview/',
        modelPlaceholder: 'codestral-latest',
        baseUrlPlaceholder: '',
        note: 'Use a Mistral Console API key for hosted Mistral models.',
    },
    deepseek: {
        keyUrl: 'https://platform.deepseek.com/api_keys',
        docsUrl: 'https://api-docs.deepseek.com/quick_start/pricing',
        modelPlaceholder: 'deepseek-chat',
        baseUrlPlaceholder: '',
        note: 'Use a DeepSeek platform API key for DeepSeek chat models.',
    },
    xai: {
        keyUrl: 'https://console.x.ai/',
        docsUrl: 'https://docs.x.ai/docs/models',
        modelPlaceholder: 'grok-3',
        baseUrlPlaceholder: '',
        note: 'Use an xAI Console API key for Grok models.',
    },
    azure: {
        keyUrl: 'https://portal.azure.com/#view/Microsoft_Azure_ProjectOxford/CognitiveServicesHub/~/OpenAI',
        docsUrl: 'https://learn.microsoft.com/en-us/azure/ai-services/openai/concepts/models',
        modelPlaceholder: 'gpt-4o',
        baseUrlPlaceholder: 'https://<resource>.openai.azure.com',
        note: 'Use your Azure OpenAI API key. The base URL is your resource endpoint (e.g. https://my-resource.openai.azure.com). The default model field sets the deployment name.',
    },
    cohere: {
        keyUrl: 'https://dashboard.cohere.com/api-keys',
        docsUrl: 'https://docs.cohere.com/docs/models',
        modelPlaceholder: 'command-r-plus',
        baseUrlPlaceholder: '',
        note: 'Use a Cohere dashboard API key for Command models.',
    },
    bedrock: {
        docsUrl: 'https://docs.aws.amazon.com/bedrock/latest/userguide/models-supported.html',
        modelPlaceholder: 'anthropic.claude-3-5-sonnet-20241022-v2:0',
        baseUrlPlaceholder: 'us-east-1',
        note: 'AWS Bedrock uses IAM credentials configured on your server (recommended) or an AWS bearer token in the API key field. The base URL field sets the AWS region (default: us-east-1).',
    },
    ollama: {
        docsUrl: 'https://ollama.com/library',
        modelPlaceholder: 'qwen2.5-coder:14b',
        baseUrlPlaceholder: 'http://localhost:11434',
        note: 'Ollama runs locally and does not require a cloud API key. Set the base URL reachable by the PullLens server.',
    },
};

export const modelsByDriver: Record<string, ModelOption[]> = {
    openai: [
        { value: 'gpt-4.1',     label: 'GPT-4.1',                   recommended: true },
        { value: 'gpt-4o',      label: 'GPT-4o' },
        { value: 'o4-mini',     label: 'o4-mini (reasoning)' },
        { value: 'gpt-4o-mini', label: 'GPT-4o mini' },
        { value: 'o3',          label: 'o3 (reasoning)' },
    ],
    anthropic: [
        { value: 'claude-sonnet-4-6',         label: 'Claude Sonnet 4.6', recommended: true },
        { value: 'claude-opus-4-8',           label: 'Claude Opus 4.8' },
        { value: 'claude-haiku-4-5-20251001', label: 'Claude Haiku 4.5' },
    ],
    openrouter: [
        { value: 'anthropic/claude-sonnet-4-5',    label: 'Claude Sonnet 4.5',  recommended: true },
        { value: 'openai/gpt-4.1',                 label: 'GPT-4.1' },
        { value: 'google/gemini-2.5-pro',          label: 'Gemini 2.5 Pro' },
        { value: 'deepseek/deepseek-chat-v3-0324', label: 'DeepSeek Chat V3' },
    ],
    gemini: [
        { value: 'gemini-2.5-pro',   label: 'Gemini 2.5 Pro',   recommended: true },
        { value: 'gemini-2.5-flash', label: 'Gemini 2.5 Flash' },
        { value: 'gemini-2.0-flash', label: 'Gemini 2.0 Flash' },
    ],
    groq: [
        { value: 'llama-3.3-70b-versatile',       label: 'Llama 3.3 70B',          recommended: true },
        { value: 'deepseek-r1-distill-llama-70b', label: 'DeepSeek R1 (Llama 70B)' },
        { value: 'qwen-qwq-32b',                  label: 'Qwen QwQ 32B' },
    ],
    mistral: [
        { value: 'codestral-latest',      label: 'Codestral (code-focused)', recommended: true },
        { value: 'mistral-large-latest',  label: 'Mistral Large' },
        { value: 'mistral-medium-latest', label: 'Mistral Medium' },
    ],
    deepseek: [
        { value: 'deepseek-chat',     label: 'DeepSeek Chat (V3)',      recommended: true },
        { value: 'deepseek-reasoner', label: 'DeepSeek Reasoner (R1)' },
    ],
    xai: [
        { value: 'grok-3',      label: 'Grok 3',      recommended: true },
        { value: 'grok-3-mini', label: 'Grok 3 Mini' },
        { value: 'grok-4',      label: 'Grok 4' },
    ],
    azure: [
        { value: 'gpt-4o',  label: 'gpt-4o (deployment name)',  recommended: true },
        { value: 'gpt-4.1', label: 'gpt-4.1 (deployment name)' },
    ],
    cohere: [
        { value: 'command-r-plus', label: 'Command R+', recommended: true },
        { value: 'command-r',      label: 'Command R' },
    ],
    bedrock: [
        { value: 'anthropic.claude-3-5-sonnet-20241022-v2:0', label: 'Claude 3.5 Sonnet v2', recommended: true },
        { value: 'anthropic.claude-3-haiku-20240307-v1:0',    label: 'Claude 3 Haiku' },
        { value: 'amazon.nova-pro-v1:0',                      label: 'Amazon Nova Pro' },
    ],
    ollama: [
        { value: 'qwen2.5-coder:14b', label: 'Qwen 2.5 Coder 14B', recommended: true },
        { value: 'deepseek-coder-v2', label: 'DeepSeek Coder V2' },
        { value: 'codellama:34b',     label: 'CodeLlama 34B' },
        { value: 'llama3.1:70b',      label: 'Llama 3.1 70B' },
    ],
};
