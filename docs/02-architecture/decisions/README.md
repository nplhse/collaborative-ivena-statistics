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

## Template

When adding a new ADR, copy the structure from an existing record and assign the next sequential number.
