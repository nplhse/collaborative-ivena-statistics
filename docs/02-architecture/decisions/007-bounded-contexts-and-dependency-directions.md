# ADR 007: Bounded contexts, typing, and allowed dependency directions

**Status:** accepted

## Context

The monolith under `src/` is organized by bounded contexts, documented in [bounded-contexts.md](../bounded-contexts.md). An architecture review before beta (Issue #258) confirmed that most top-level directories are coherent, but dependency directions between contexts are uneven: some bidirectional cycles are accidental (e.g. foreign contexts importing EasyAdmin controller classes), while others reflect real domain relationships (e.g. `Allocation` ↔ `Import`, `User` ↔ `Hospital`).

Without an explicit map of context types and allowed edges, automated checks (Deptrac) and refactors risk either blocking legitimate partnerships or allowing new cycles by default.

## Decision

### Official top-level contexts

The following directories under `src/` are the official bounded contexts / modules:

| Name | Type | Notes |
|------|------|-------|
| `Allocation` | Domain context | Core EMS allocation and master data |
| `Import` | Domain context | CSV import pipeline |
| `Statistics` | Domain context | Projections and analytics (internal submodules are not separate top-level contexts) |
| `User` | Domain context | Identity, auth, registration |
| `Content` | Domain context | Pages, blog, media |
| `Feedback` | Domain context | Feedback widget |
| `Engagement` | Domain context | Monthly submission reminders |
| `Kpi` | Domain context | Daily KPI aggregation |
| `Onboarding` | Domain context | Participant onboarding progress |
| `Admin` | Technical UI module | EasyAdmin back office; no own domain model |
| `Shared` | Platform / shared kernel | Cross-cutting infrastructure (see ADR 008) |
| `Install` | Technical module | Install and environment checks |
| `DataFixtures` | Dev-only | Excluded from production routing and services |

`App\Kernel` remains outside these contexts.

### Documented partnerships (allowed)

These couplings are intentional and may remain until a dedicated follow-up ADR changes them:

- **Allocation ↔ Import** at the domain association level (`Allocation` / `MciCase` reference `Import`)
- **User ↔ Allocation** for hospital ownership (`User` ↔ `Hospital`)
- **Import → Statistics** for projection rebuild / deduplication via Statistics application contracts
- **Statistics → Allocation / User** as a read model over allocation domain types and identity
- **Engagement** may read Statistics, Kpi, Allocation, and Import application/infrastructure read APIs to compose reminder content
- **Admin** may depend on other contexts’ domain entities and application services for CRUD

### Edges to avoid or remove over time

- Other contexts must not import `Admin\UI\…Controller` (or other Admin UI types) for URL generation; use named routes or thin URL-generator contracts instead
- `Shared` must not depend on Feedback, Content, Allocation, Import, Statistics, Engagement, or Admin feature types (User exception: ADR 008)
- Do not introduce new bidirectional context cycles without an ADR
- `DataFixtures` may depend on any context; production code must not depend on `DataFixtures`

## Consequences

**Positive:**

- Clear vocabulary for reviews and Deptrac layers
- Accidental Admin/Shared cycles become actionable instead of “style opinions”
- Legitimate domain partnerships are not treated as defects

**Negative:**

- Some existing edges remain until follow-up PRs (Admin controller imports, Shared feature leaks)
- Partnership list must be kept in sync when Deptrac rules land (ADR 010)

## Alternatives

- **Strict acyclic context graph immediately** — rejected for beta; would force large domain refactors (e.g. replace ORM associations with IDs) without proportional benefit
- **Treat Admin as a full domain context** — rejected; Admin has no own aggregates
- **Leave directions undocumented** — rejected; blocks reliable architecture automation

## References

- [../bounded-contexts.md](../bounded-contexts.md)
- [../overview.md](../overview.md)
- [008-shared-platform-and-domain-framework-coupling.md](008-shared-platform-and-domain-framework-coupling.md)
- [010-architecture-guardrails-and-beta-scope.md](010-architecture-guardrails-and-beta-scope.md)
- Issue [#258](https://github.com/nplhse/collaborative-ivena-statistics/issues/258)
