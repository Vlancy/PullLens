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
     */
    public function recommendedModel(): string
    {
        return match ($this) {
            self::OpenAI => 'gpt-4.1',
            self::Anthropic => 'claude-sonnet-4-6',
            self::Gemini => 'gemini-2.5-pro',
            self::Azure => 'gpt-4o',
            self::OpenRouter => 'anthropic/claude-sonnet-4-5',
            self::Groq => 'llama-3.3-70b-versatile',
            self::Mistral => 'codestral-latest',
            self::DeepSeek => 'deepseek-chat',
            self::Cohere => 'command-r-plus',
            self::XAI => 'grok-3',
            self::Bedrock => 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            self::Ollama => 'qwen2.5-coder:14b',
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
