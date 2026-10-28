# Architecture Decision Records

This directory contains Architecture Decision Records (ADRs) for significant, irreversible design choices.

## Format

Each ADR includes:

- **Status** — proposed, accepted, deprecated, superseded
- **Context** — the problem or forces at play
- **Decision** — what was decided
- **Consequences** — positive and negative outcomes
- **Alternatives** — options considered

## Index

| ADR | Title | Status |
|-----|-------|--------|
| [001](001-projection-and-materialized-views.md) | Projection and materialized views instead of live queries | accepted |
| [002](002-hospital-permission-bitmask.md) | Hospital permission bitmask | accepted |
| [003](003-doctrine-messenger-transport.md) | Doctrine as Messenger transport | accepted |
| [004](004-colocated-templates.md) | Colocated Twig templates | accepted |
| [005](005-reject-writer-strategy.md) | Reject writer strategy (db vs. csv) | accepted |
| [006](006-analysis-explorer-saved-views.md) | Analysis Explorer saved views (JSON schema v3) | accepted |
| [007](007-bounded-contexts-and-dependency-directions.md) | Bounded contexts, typing, and allowed dependency directions | accepted |
| [008](008-shared-platform-and-domain-framework-coupling.md) | Shared platform role and domain framework coupling | accepted |
| [009](009-ports-repositories-query-objects-and-naming.md) | Ports, repositories, query objects, and naming | accepted |
| [010](010-architecture-guardrails-and-beta-scope.md) | Architecture guardrails and beta complexity scope | accepted |
| [011](011-collaborative-explore-allocation-visibility.md) | Collaborative Explore allocation visibility | accepted |
| [012](012-explore-data-catalog.md) | Explore data catalog | accepted |
| [013](013-page-multilingual-content.md) | Multilingual CMS page content via PageTranslation | accepted |
| [014](014-persisted-user-activity-projection.md) | Persistierte User-Activity-Projektion | accepted |
| [015](015-closure-volume-metrics-and-reference-window.md) | Closure volume metrics, symmetric reference window, and incremental rebuild | accepted |
| [016](016-notification-system.md) | Multi-channel notification system with declarative definitions and user preferences | accepted |
| [017](017-storage-monitoring.md) | Storage monitoring with daily measurements, capacity levels and admin alerts | accepted |
| [018](018-job-run-monitoring.md) | One run log and shared telemetry for long-running background jobs | accepted |
| [019](019-messenger-monitoring.md) | Messenger monitoring with hourly metrics, scheduler jobs in the run log, and bounded retention | accepted |
| [020](020-portable-reference-catalog.md) | Portable reference catalog, private reference data outside the repository, and synthetic data from code | accepted |
| [021](021-wall-clock-access.md) | Read the current time only through the Symfony clock | accepted |
| [022](022-synthetic-data-generation.md) | Synthetic data generation with hospital profiles, pattern packages and scenario overlays | accepted |

## Template

When adding a new ADR, copy the structure from an existing record and assign the next sequential number.
