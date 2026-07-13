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
| [007](007-pragmatic-domain-layer-with-doctrine.md) | Pragmatic domain layer with Doctrine | accepted |
| [008](008-bounded-context-taxonomy.md) | Bounded context taxonomy | accepted |
| [009](009-cross-context-dependency-rules.md) | Cross-context dependency rules | accepted |
| [010](010-shared-kernel-boundaries.md) | Shared kernel boundaries | accepted |
| [011](011-statistics-internal-modules.md) | Statistics internal modules | accepted |
| [012](012-domain-events-for-significant-state-changes.md) | Domain events for significant state changes | accepted |

## Template

When adding a new ADR, copy the structure from an existing record and assign the next sequential number.
