# PullLens

A self-hosted AI code reviewer focused on meaningful risk detection, not style comments.

PullLens is an open-source AI-powered code review platform for teams, organizations, and individuals who want to track and improve code quality, performance, reliability, and security in their own repositories. It includes a dashboard UI for tech leads, engineering managers, and team owners to track review history, risk levels, findings, and repository health.

The project is designed for self-hosted usage. It is not a SaaS product, does not include billing, and is intended to be freely used, forked, modified, and extended by anyone.

## Purpose

PullLens connects to source code repositories, listens for pull request events, analyzes code changes with configurable AI providers, and posts professional code review feedback directly on pull requests.

The reviewer focuses on meaningful engineering risks, including:

- Security vulnerabilities
- Language and framework best practices
- Performance issues
- Reliability problems
- Maintainability concerns

PullLens does not focus on formatting or subjective style comments.

## Mission

PullLens exists to help teams catch meaningful code risks before they reach production.

Its mission is to make AI code review accessible, self-hosted, transparent, and useful for real engineering workflows without forcing teams into a SaaS platform or billing model.

## Vision

The goal is to provide a self-hosted alternative to hosted AI code review tools, with an architecture that favors transparency, extensibility, and practical engineering feedback.

Future versions may support GitHub Apps, multiple source control providers, specialized review agents, review memory, and AI-generated fixes.

## Installation

PullLens can be installed with Docker Compose using the included production Docker stack.

See [DOCKER.md](DOCKER.md) for the full Docker installation guide.

## Open Source

PullLens is free and open source under the MIT License. See [LICENSE](LICENSE) for the full license text.

Copyright belongs to Vlancy LTD: https://vlancy.com

Anyone may:

- Use it personally or inside an organization
- Fork it
- Modify it
- Self-host it
- Extend it for internal team workflows
- Build integrations on top of it

## Vlancy LTD

PullLens is created and maintained by Vlancy LTD.

Vlancy LTD is a UK-registered technology company focused on solving cross-disciplinary problems where AI, hardware, software, operations, and real-world ambition meet.

Website: https://vlancy.com
Email: hello@vlancy.com
