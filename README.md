<div align="center">

<img src="public/favicon.png" alt="PullLens" width="110">

# PullLens

### Know what your team shipped. Catch what they missed. On your own servers.

**An AI code reviewer and engineering-visibility platform you host yourself - so your source code never leaves your infrastructure.**

Free to use. No seats. No usage tiers. No SaaS account.

**[📖 Documentation](https://vlancy.github.io/PullLens/guide/)** · [Install](#get-started-in-about-five-minutes) · [Connect an AI provider](https://vlancy.github.io/PullLens/guide/ai-providers.html) · [Connect GitHub](https://vlancy.github.io/PullLens/guide/github.html)

<img src="docs/images/tour.gif" alt="A tour of PullLens: dashboard, findings and delivered tasks" width="100%">

</div>

---

## The problem it solves

If you lead an engineering team, two questions are surprisingly hard to answer honestly:

**"What did everyone actually do this month?"**
You can scroll pull requests. You can count commits - and learn nothing, because one commit is a typo fix and the next is a payment gateway. Standups tell you what people *say* they did. By month end, the real answer is buried in a hundred merged branches nobody will read again.

**"What did we merge that we shouldn't have?"**
Reviews get rushed. The reviewer who catches the SQL injection is on holiday. A missing authorization check ships on a Friday and nobody notices until it matters. Not because your team is careless - because reviewing every diff properly, every time, is more attention than any human has.

PullLens answers both, automatically, from the code itself.

---

## What you get

### Every pull request reviewed, properly, every time

PullLens watches your repositories and reviews each pull request as it opens - hunting for the things that actually hurt: **SQL injection, missing authorization, leaked secrets, race conditions, N+1 queries, unbounded queries, silent data corruption.**

It deliberately does not comment on your brace style. A reviewer that cries wolf about formatting gets muted, and then it catches nothing at all.

<img src="docs/images/findings.jpg" alt="Findings page showing severity breakdown and critical security issues" width="100%">

> Every finding is tracked until it's closed - with a reason. Fixed, acknowledged, or false positive. So "we'll deal with it later" becomes a number you can actually see.

### It reviews where the work already happens

Everything lands in the pull request itself. Nobody has to open another tab to find out what's wrong.

PullLens posts a summary comment carrying the risk level, what changed and a table of every finding - then comments **on the exact lines**, one per finding, each with the severity, the category, why it matters, and a fix you can read in ten seconds. Blockers are marked as blockers.

<img src="docs/images/pr-review-comment.jpg" alt="PullLens posting a critical finding as an inline pull request comment, a developer replying, and PullLens answering in the thread" width="100%">

**And it answers back.** Reply to any of its comments - push back, ask why, say you've fixed it - and it responds in the thread with the whole review still in context. It will defend a finding, concede a false positive, or confirm your fix. When it confirms one, the finding is closed in PullLens automatically, so the dashboard and the conversation never drift apart.

You decide how far it goes, per repository: inline comments and replies, PR labels, a generated description and title, an approving review, even auto-merge once it's satisfied - each one a switch you turn on yourself.

### Know who did what - without asking anyone

This is the part other review tools don't do.

When PullLens reviews a pull request, it also works out **what that PR actually delivered** - as tasks a human would recognise. Not "14 commits". Instead: *"Added multi-currency support to checkout - feature, ~12h"*, linked to the PR and the commits that delivered it.

<img src="docs/images/tasks-board.jpg" alt="Tasks board with search, filters and first-time-right rate" width="100%">

Then it tracks what happened next:

- **Was it right the first time?** Or did someone have to come back and fix it two weeks later?
- **What is this work a follow-up to?** Tasks link to each other - *fixes*, *extends*, *reverts* - so you can see the whole thread.
- **Which ticket was it?** Issue keys are picked up from branch names and PR titles automatically.

At month end you open one page and see exactly what each person shipped.

<img src="docs/images/report-tasks.jpg" alt="Per-developer delivered tasks report" width="100%">

### Performance you can talk about in a 1:1

Throughput, code volume, review outcomes, how often work comes back, and how quickly PRs get reviewed - per developer, per repository, over any period.

<img src="docs/images/report-team.jpg" alt="Team performance report" width="100%">

**A word of caution, because it matters:** these numbers are a conversation starter, not a scoreboard. Lines of code is not productivity, and anyone measured on it will happily give you more of it. Use this to notice that someone's work keeps coming back and ask *why* - maybe the area is under-tested, maybe they were handed the worst part of the codebase. The data tells you where to look, not what to conclude.

### Know exactly what the AI costs you

Every provider call is logged - tokens, model, which repository, what it cost. No surprise bills, no wondering which repo is burning the budget.

<img src="docs/images/report-ai-usage.jpg" alt="AI usage and cost report" width="100%">

You bring your own API key, so you pay your provider directly at cost. PullLens takes nothing.

---

## Your code stays yours

This is the whole reason PullLens is built the way it is.

**There is no PullLens cloud.** No account to create, no server of ours your code passes through, no vendor retaining your repositories to train on. You run it on your own machine, your own VPS, or inside your own VPC. We have no access to any of it - not because we promise not to look, but because there is nothing for us to look at.

The only outbound call is to **the AI provider you choose, with the API key you own.** That is worth being precise about: to review a diff, the diff is sent to that provider. If your policy forbids code leaving the network entirely, point PullLens at **[Ollama](https://ollama.com) running locally** - then nothing leaves your infrastructure at all, and you still get the reviews, the tasks and the reports.

Also built in, because self-hosted shouldn't mean unlocked:

- **Public sign-up is disabled.** Accounts are created by an administrator - there is no registration endpoint to find.
- **Roles and permissions** you edit in the UI, with per-repository access, so a contractor sees only the repositories you grant them.
- **Provider credentials and webhook secrets are encrypted at rest**, and webhooks are rejected without a valid signature.

---

## What it looks like day to day

<img src="docs/images/dashboard.jpg" alt="PullLens dashboard" width="100%">

Open the dashboard and see where things stand: what's outstanding, what's risky, what shipped.

---

## Documentation

A complete, illustrated user guide - fifteen pages taking you from an empty server to a repository under review, with a screenshot of every screen and every setting explained one by one.

**→ [Read the guide](https://vlancy.github.io/PullLens/guide/)**

| Getting started | Connecting | Using PullLens | Administration |
| --- | --- | --- | --- |
| [Introduction](https://vlancy.github.io/PullLens/guide/index.html) | [AI provider](https://vlancy.github.io/PullLens/guide/ai-providers.html) | [Dashboard](https://vlancy.github.io/PullLens/guide/dashboard.html) | [Users, roles & access](https://vlancy.github.io/PullLens/guide/users-roles.html) |
| [Installing](https://vlancy.github.io/PullLens/guide/installation.html) | [Connecting GitHub](https://vlancy.github.io/PullLens/guide/github.html) | [Findings](https://vlancy.github.io/PullLens/guide/findings.html) | [Your account](https://vlancy.github.io/PullLens/guide/account.html) |
| [First run & login](https://vlancy.github.io/PullLens/guide/first-run.html) | [Adding repositories](https://vlancy.github.io/PullLens/guide/repositories.html) | [Tasks](https://vlancy.github.io/PullLens/guide/tasks.html) | [Troubleshooting](https://vlancy.github.io/PullLens/guide/troubleshooting.html) |
| | [Repository settings](https://vlancy.github.io/PullLens/guide/repository-settings.html) | [Reports](https://vlancy.github.io/PullLens/guide/reports.html) | |
| | | [AI assistant](https://vlancy.github.io/PullLens/guide/assistant.html) | |

The guide is plain HTML with no build step, so it also works straight from a clone - open `docs/guide/index.html` in a browser.

## Get started in about five minutes

```bash
git clone https://github.com/Vlancy/PullLens.git
cd PullLens
./install.sh
```

The installer sets up Docker if you need it, builds the containers, runs migrations, and prints your login URL.

Then, in the UI:

1. **Connect your AI provider** - Anthropic, OpenAI, Gemini, Groq, Mistral, DeepSeek, xAI, Cohere, Bedrock, OpenRouter, or a local Ollama. Recommended models for code review are pre-selected.
2. **Connect GitHub** and pick the repositories to watch.
3. **Open a pull request.** The review lands on it within a minute.

Stuck on any of it? The [installation guide](https://vlancy.github.io/PullLens/guide/installation.html) and [troubleshooting page](https://vlancy.github.io/PullLens/guide/troubleshooting.html) cover each step in detail. See also [INSTALL.md](INSTALL.md) and [DOCKER.md](DOCKER.md) for lower-level details.

**Want to see it with data first?** Seed a demo installation with fictional repositories, developers and findings:

```bash
php artisan db:seed --class=DemoDataSeeder
```

Everything the screenshots above show is that seeder - invented repositories, invented people, invented bugs.

---

## Under the hood

Laravel 13 · PHP 8.4 · React + Inertia · PostgreSQL · Redis · Horizon

Built to be worked on: a repository and service layer with no query logic in controllers, enums instead of magic strings, form requests for validation and authorization, and a test suite covering the parts that would hurt if they broke.

---

## Free - and the honest small print

PullLens is **free**. Use it personally, use it at work, run it for your whole engineering org, fork it, modify it. There is no paid tier and nothing is held back.

It is released under the **MIT License with the [Commons Clause](LICENSE)**, which means one thing is not allowed: **you can't sell it.** No repackaging it as a paid product, and no offering it to third parties as a paid hosted or managed service. Everything else is fair game.

Strictly speaking that makes it *source-available* rather than OSI open source - we'd rather say so plainly than stretch a label.

---

## Built by Vlancy

PullLens is made and maintained by **[Vlancy LTD](https://vlancy.com)**, a UK technology company working where AI, hardware, software and operations meet.

Questions, ideas, or something broken? **hello@vlancy.com** - or open an issue.

<div align="center">

**If PullLens saves you one bad merge, it has paid for itself. It's free, so that's not a high bar.**

⭐ Star the repo if you'd like to follow along.

</div>
