<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class AssessPatchRiskTool implements Tool
{
    private const LANGUAGE_MAP = [
        'php' => 'PHP',
        'js' => 'JavaScript',
        'ts' => 'TypeScript',
        'tsx' => 'TypeScript (React)',
        'jsx' => 'JavaScript (React)',
        'py' => 'Python',
        'rb' => 'Ruby',
        'go' => 'Go',
        'rs' => 'Rust',
        'java' => 'Java',
        'kt' => 'Kotlin',
        'swift' => 'Swift',
        'c' => 'C',
        'cpp' => 'C++',
        'cc' => 'C++',
        'cs' => 'C#',
        'scala' => 'Scala',
        'ex' => 'Elixir',
        'exs' => 'Elixir',
        'dart' => 'Dart',
        'lua' => 'Lua',
        'r' => 'R',
        'html' => 'HTML',
        'css' => 'CSS',
        'scss' => 'SCSS',
        'sass' => 'Sass',
        'less' => 'Less',
        'vue' => 'Vue',
        'svelte' => 'Svelte',
        'sql' => 'SQL',
        'sh' => 'Shell',
        'bash' => 'Shell',
        'zsh' => 'Shell',
        'ps1' => 'PowerShell',
        'yaml' => 'YAML',
        'yml' => 'YAML',
        'json' => 'JSON',
        'toml' => 'TOML',
        'xml' => 'XML',
        'md' => 'Markdown',
        'rst' => 'reStructuredText',
        'tf' => 'Terraform',
        'hcl' => 'HCL',
        'proto' => 'Protocol Buffers',
        'graphql' => 'GraphQL',
        'gql' => 'GraphQL',
    ];

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Classifies changed file paths and patch snippets into review risk areas, and detects programming languages from file extensions, without reading files or accessing external systems.';
    }

    /**
     * Execute the risk classification for provided file paths and optional patch text.
     */
    public function handle(Request $request): Stringable|string
    {
        $paths = array_values(array_filter((array) $request->array('paths')));
        $patch = strtolower((string) $request->string('patch', ''));
        $areas = [];
        $languages = [];

        foreach ($paths as $path) {
            $lower = strtolower((string) $path);

            if (str_contains($lower, 'auth') || str_contains($lower, 'permission') || str_contains($lower, 'policy') || str_contains($lower, 'middleware')) {
                $areas[] = 'authorization/authentication';
            }

            if (str_contains($lower, 'migration') || str_contains($lower, 'model') || str_contains($lower, 'repository')) {
                $areas[] = 'data integrity';
            }

            if (str_contains($lower, 'webhook') || str_contains($lower, 'controller') || str_contains($lower, 'request')) {
                $areas[] = 'input validation and request handling';
            }

            if (str_contains($lower, 'job') || str_contains($lower, 'queue') || str_contains($lower, 'horizon')) {
                $areas[] = 'async processing reliability';
            }

            $lang = $this->detectLanguage((string) $path);
            if ($lang !== null) {
                $languages[] = $lang;
            }
        }

        foreach (['token', 'secret', 'password', 'private_key', 'access_key', 'credential'] as $needle) {
            if (str_contains($patch, $needle)) {
                $areas[] = 'secret handling';
                break;
            }
        }

        if (str_contains($patch, 'raw(') || str_contains($patch, 'selectraw') || str_contains($patch, 'whereraw')
            || str_contains($patch, 'orderbyraw') || str_contains($patch, 'havingraw')
            || str_contains($patch, 'db::statement') || str_contains($patch, 'db::unprepared')) {
            $areas[] = 'query safety';
        }

        if (str_contains($patch, 'http::') || str_contains($patch, 'curl') || str_contains($patch, 'guzzle')) {
            $areas[] = 'outbound network calls';
        }

        $areas = array_values(array_unique($areas));
        $languages = array_values(array_unique($languages));

        return json_encode([
            'risk_areas' => $areas,
            'detected_languages' => $languages,
            'guidance' => $areas === []
                ? 'No obvious high-risk area detected by heuristics; still review correctness and tests.'
                : 'Focus review on the listed risk areas and verify evidence from the provided diff before reporting findings.',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'paths' => $schema->array()
                ->items($schema->string())
                ->description('Repository-relative paths changed by the PR.')
                ->required(),
            'patch' => $schema->string()
                ->description('Optional patch snippet to classify. Keep it short.')
                ->nullable(),
        ];
    }

    /**
     * Best-effort language for a path, taken from its extension.
     */
    private function detectLanguage(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::LANGUAGE_MAP[$extension] ?? null;
    }
}
