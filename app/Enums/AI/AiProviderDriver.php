<?php

namespace App\Enums\AI;

enum AiProviderDriver: string implements \JsonSerializable
{
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';
    case Azure = 'azure';
    case OpenRouter = 'openrouter';
    case Groq = 'groq';
    case Mistral = 'mistral';
    case DeepSeek = 'deepseek';
    case Cohere = 'cohere';
    case XAI = 'xai';
    case Bedrock = 'bedrock';
    case Ollama = 'ollama';

    /**
     * Return the human-readable label for the provider settings UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::OpenAI => 'OpenAI',
            self::Anthropic => 'Anthropic',
            self::Gemini => 'Gemini',
            self::Azure => 'Azure OpenAI',
            self::OpenRouter => 'OpenRouter',
            self::Groq => 'Groq',
            self::Mistral => 'Mistral',
            self::DeepSeek => 'DeepSeek',
            self::Cohere => 'Cohere',
            self::XAI => 'xAI',
            self::Bedrock => 'AWS Bedrock',
            self::Ollama => 'Ollama',
        };
    }

    /**
     * Return the model recommended for code review for this driver.
     * Used as the default when the operator does not specify one.
     *
     * Reviews run on every pull request, so these favour the balanced production tier
     * rather than each vendor's most capable model: the deepest-reasoning tiers cost
     * several times more per review without a matching gain on diff review, which is
     * a bounded, single-shot task rather than a long agentic one. The higher tiers are
     * one selection away in the provider settings, and /reports/ai-usage shows what a
     * change actually costs.
     *
     * Verified against vendor documentation in August 2026.
     */
    public function recommendedModel(): string
    {
        return match ($this) {
            // GPT-5.6 tiers: Sol reasons deepest, Terra is the production balance.
            self::OpenAI => 'gpt-5.6-terra',
            // Anthropic positions Opus 5 for complex agentic coding; Sonnet 5 is the
            // documented best combination of speed and intelligence, at 40% of the price.
            self::Anthropic => 'claude-sonnet-5',
            // Google documents 3.7 Flash as built for complex coding and agentic work.
            self::Gemini => 'gemini-3.7-flash',
            self::Azure => 'gpt-5.6-terra',
            self::OpenRouter => 'anthropic/claude-sonnet-5',
            // Groq retired the Llama 3.x production models in August 2026 and names
            // the GPT-OSS models as their replacement.
            self::Groq => 'openai/gpt-oss-120b',
            // Codestral remains Mistral's code-specialised line.
            self::Mistral => 'codestral-2508',
            self::DeepSeek => 'deepseek-v4-pro',
            self::Cohere => 'command-a-plus-05-2026',
            self::XAI => 'grok-4.6',
            self::Bedrock => 'anthropic.claude-sonnet-5',
            // Best quality per GB of VRAM for a self-hosted reviewer.
            self::Ollama => 'qwen3-coder:30b',
        };
    }

    /**
     * Serialize as the backing string value for JSON responses and Inertia props.
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * Return all backing values for validation.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Return value/label pairs for provider driver select options.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $driver) => ['value' => $driver->value, 'label' => $driver->label()],
            self::cases(),
        );
    }
}
