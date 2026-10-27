# ADR 0001: Modular monolith

## Status

Accepted — 2026-10-27.

## Context

The first team is small, while the product spans identity, organizations, families, catalog, scheduling, booking, attendance, billing, settlements, notifications and audit. Independent services would multiply deployment, observability and data-consistency work before the domain boundaries are proven.

## Decision

Build one Laravel API as a modular monolith and one Next.js web application. Each business domain lives under `app/Domain/<Domain>` and owns its models, policies, commands, queries and events.

- Domains may call another domain only through its public application interfaces or documented domain events.
- Direct imports of another domain's internal models, repositories or database details are forbidden.
- HTTP controllers translate transport concerns and delegate business decisions to application services.
- Cross-domain workflows use explicit orchestration and database transactions; money, attendance and audit updates must remain atomic where required.
- PostgreSQL is the source of truth. Redis is disposable infrastructure for cache, rate limiting and queues, never the authoritative ledger.
- Code may be extracted into a separate service only after a measured scaling, security or team-ownership need appears.

## Consequences

Deployment and local development stay simple, transactions remain reliable, and domain boundaries can evolve without distributed-system overhead. Automated architecture checks will be needed to prevent accidental coupling. A later extraction requires replacing an application interface with a network contract without changing its consumers.
