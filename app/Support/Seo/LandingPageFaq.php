<?php

namespace App\Support\Seo;

/**
 * The questions the landing page answers, in one place.
 *
 * Answer engines - Google's AI Overviews, ChatGPT, Claude, Perplexity - quote a
 * page when it states an answer plainly rather than implying one across three
 * paragraphs of marketing. That means the same wording has to appear twice: once
 * as visible prose a human reads, and once inside FAQPage structured data a
 * crawler parses. Two copies drift, and a FAQPage whose answers are not on the
 * page is a spam signal, so both are rendered from this list.
 *
 * The React page receives it as an Inertia prop; the JSON-LD block builds the
 * FAQPage from the identical array.
 */
final class LandingPageFaq
{
    /**
     * The question and answer pairs, in the order they are shown.
     *
     * Answers are deliberately self-contained: each one has to make sense when
     * it is lifted out of the page and quoted on its own.
     *
     * @return list<array{question: string, answer: string}>
     */
    public static function all(): array
    {
        return [
            [
                'question' => 'What is PullLens?',
                'answer' => 'PullLens is a self-hosted AI code reviewer for pull requests. It runs on your own infrastructure, reviews every pull request automatically as it is opened, and posts security, quality and risk findings as inline comments before the change is merged. It also turns those reviews into tasks and team reports, so the same work that catches bugs also tells you where quality is drifting.',
            ],
            [
                'question' => 'Is PullLens free?',
                'answer' => 'Yes. PullLens is source-available under the MIT licence with the Commons Clause, with no paid tier and no features held back. You can run it personally, at work or across an entire organisation, and fork or modify it. The one thing the licence does not allow is selling PullLens or offering it as a paid hosted service. You still pay your own AI provider for the tokens each review consumes.',
            ],
            [
                'question' => 'Does my source code leave my infrastructure?',
                'answer' => 'PullLens itself is fully self-hosted, so the application, the database and every review it has ever produced stay on your servers. The only thing that leaves is the diff you send to the AI provider you configured, and that provider is your choice. Point PullLens at a local Ollama model and no code leaves your network at all.',
            ],
            [
                'question' => 'Which AI providers and models does PullLens support?',
                'answer' => 'Twelve providers are supported: Anthropic, OpenAI, Google Gemini, Groq, Mistral, DeepSeek, xAI, Cohere, Amazon Bedrock, OpenRouter, Azure OpenAI and Ollama for local models. You bring your own API key and pay the provider directly at cost, and you can set a different provider or model per repository.',
            ],
            [
                'question' => 'Which Git platforms does PullLens work with?',
                'answer' => 'PullLens integrates with GitHub today, through a GitHub App and signed webhooks. You authorise it once, pick the repositories and branches it should watch, and every pull request opened against them is reviewed automatically. Nothing is installed on a developer machine and there is nothing for engineers to opt into.',
            ],
            [
                'question' => 'How long does it take to set up?',
                'answer' => 'Minutes, not a migration project. One installer script brings up the application, the queue worker, PostgreSQL, Redis and mail, runs the migrations and prints your login URL. You then paste an AI provider API key, authorise GitHub and choose the repositories to watch. The illustrated user guide walks through every screen and setting.',
            ],
            [
                'question' => 'How is this different from Copilot or pasting a diff into ChatGPT?',
                'answer' => 'An assistant reviews what a developer remembers to paste, when they remember to paste it, and forgets it afterwards. PullLens reviews every pull request whether or not anyone asks, keeps the history so findings, tasks and trends accumulate, and never sends your code anywhere you did not choose. It is review coverage and an audit trail, not a chat window.',
            ],
            [
                'question' => 'Does PullLens replace human code reviewers?',
                'answer' => 'No. It removes the mechanical half of review - the missed null check, the unescaped input, the migration without a rollback, the test that was never written - so a human reviewer spends their attention on design, trade-offs and intent. Findings are advisory; nothing merges or blocks on its own.',
            ],
            [
                'question' => 'What does it cost to run?',
                'answer' => 'You pay your AI provider for the tokens each review consumes, and nothing to PullLens. Because the diff dominates that bill, PullLens caps how much of a pull request it will send - by default 120 KB across the whole request and 20 KB from any single file - so one generated file cannot consume the budget. An AI usage report shows exactly what has been spent, by repository and by model.',
            ],
            [
                'question' => 'Can I control which repositories and branches are reviewed?',
                'answer' => 'Yes. Reviewing is opt-in per repository, and each repository has its own settings: which branches to watch, which AI provider and model to use, and what the reviewer should focus on. Access inside PullLens is role based, so a contributor scoped to a few repositories only ever sees those.',
            ],
        ];
    }
}
