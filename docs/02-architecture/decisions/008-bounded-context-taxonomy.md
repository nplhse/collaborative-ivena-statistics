# ADR 008: Bounded context taxonomy

**Status:** accepted

## Context

Code under `src/` is organised by top-level directories. Not all directories represent equal architectural roles: some are core business areas, others are technical adapters or dev-only tooling. Phase 1 and Issue #258 require a clear, official list before automated architecture checks (Deptrac) can be introduced.

## Decision

Classify each top-level `src/` area as one of four types:

### Bounded contexts (business)

| Context | Responsibility |
|---------|----------------|
| `Allocation` | Core EMS data: allocations, hospitals, master data, indication review, permissions |
| `Import` | CSV upload, validation, async processing, rejects |
| `Statistics` | Projections, materialized views, analytics (see ADR 011 for submodules) |
| `User` | Authentication, registration, password reset |
| `Content` | CMS pages, blog, media |
| `Onboarding` | Participant onboarding progress |
| `Engagement` | Monthly submission reminders |
| `Kpi` | Daily KPI aggregation |
| `Feedback` | Feedback widget |
| `Shared` | Cross-cutting kernel: audit, mail, export, locale, health, monitoring |

### Technical modules (no standalone domain)

| Module | Responsibility |
|--------|----------------|
| `Admin` | EasyAdmin back office — CRUD over other contexts' entities |
| `Install` | `app:install`, `app:env:check` CLI tooling |

### Dev-only (excluded from production rules)

| Module | Responsibility |
|--------|----------------|
| `DataFixtures` | Reference YAML, demo data, fixture groups |

Production exclusions are enforced in `config/services.yaml` and `config/routes/attributes.php`.

## Consequences

**Positive:**

- Clear vocabulary for documentation, reviews, and Deptrac layers
- Admin and Install explicitly marked as non-patterns for feature code

**Negative:**

- `Shared` spans both kernel and UI concerns — further bounded by ADR 010

## Alternatives

- **Treat Admin as a bounded context** — rejected; no domain model, only CRUD orchestration
- **Split Shared into multiple kernels** — deferred; evaluate only if coupling becomes painful

## References

- [../bounded-contexts.md](../bounded-contexts.md)
- [../target-architecture.md](../target-architecture.md)
- [011-statistics-internal-modules.md](011-statistics-internal-modules.md)
