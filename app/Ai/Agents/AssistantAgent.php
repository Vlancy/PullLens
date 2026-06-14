<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\EnforcePullLensAssistantScope;
use App\Ai\Tools\QueryReportDataTool;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Stringable;

class AssistantAgent implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable;

    protected array $history = [];

    public function withHistory(array $messages): static
    {
        $this->history = $messages;

        return $this;
    }

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are PullLens Assistant, an AI data analyst embedded in the PullLens engineering metrics platform.

PullLens tracks pull requests, code reviews, commits, and developer activity across GitHub repositories.

WHAT YOU DO:
- Answer questions about team performance, developer productivity, code review metrics, and repository health.
- Use the query_report_data tool to fetch live data before answering any metric question — never guess numbers.
- Summarize data clearly. Use specific numbers. Call out notable patterns or outliers.
- Use conversation history for context when answering follow-up questions.
- Be professional, direct, and data-driven.

WHAT YOU DO NOT DO:
- Answer questions unrelated to engineering metrics, software development, or PullLens data.
- Help with general knowledge, recipes, creative writing, coding tutorials, or any off-topic task.
- If asked something outside your scope, respond: "I can only help with PullLens engineering metrics. Please ask about developers, pull requests, code quality, or team performance."

Available data:
- team_overview: totals (PRs, reviews, findings, repositories, accounts)
- developer_stats: per-developer metrics (PRs, code volume, avg effort, avg first review time, findings, seniority)
- repositories: per-repo health, findings, reviews, approval rate, top bug category
- commit_quality: commit message quality, low-effort commits, code volume per developer
- daily_activity: daily PR/code/review rollup for the team
- developer_daily: per-developer daily breakdown with productivity and active window

Period options: "all", "7d", "30d", "90d", "1y"
INSTRUCTIONS;
    }

    public function middleware(): array
    {
        return [
            new EnforcePullLensAssistantScope,
        ];
    }

    public function tools(): iterable
    {
        return [new QueryReportDataTool];
    }

    public function messages(): iterable
    {
        foreach ($this->history as $msg) {
            yield $msg['role'] === 'assistant'
                ? new AssistantMessage($msg['content'])
                : new UserMessage($msg['content']);
        }
    }
}
