# ADR 009: Cross-context dependency rules

**Status:** accepted

## Context

Bounded contexts under `src/` import types from other contexts in many places. Without documented rules, dependency direction is enforced only by convention and code review. Phase 1 mapped the strongest edges (Import→Allocation, Statistics→User, User→Hospital, etc.).

Automated checks (Deptrac, Phase 3) need an agreed target state, including explicit exceptions.

## Decision

Adopt the dependency rules documented in [../dependency-rules.md](../dependency-rules.md). Summary:

### Allowed dependency directions

- Any BC → **Shared**
- **Import** → Allocation (pipeline)
- **Allocation** → Import (restricted: `Import` entity reference on `Allocation` only)
- **Import** → Statistics (via `ImportCompleted` application event / Messenger)
- **Statistics** → Allocation, User (read path and security scope)
- **Allocation** → User (permissions, security)
- **User** → Allocation (restricted: `Hospital` entity only)
- **Engagement** → Allocation, Statistics
- **Kpi** → Import
- **Content**, **Onboarding**, **Feedback** → User
- **Statistics submodules** → each other and Statistics root (ADR 011)

### Forbidden

- Any BC → **Admin** or **Install**
- **Shared** → foreign BC **Infrastructure** (baseline: `SitemapProvider` → `ExportVoter`, to be fixed)
- **Statistics** writing to Allocation aggregate state (projection writes go to Statistics-owned tables)

### Exceptions

- **Admin** may import all contexts for CRUD — technical module only, not a pattern for features
- **DataFixtures** exempt from production dependency rules
- **Five UI controllers** with `EntityManagerInterface` — baseline until refactored
- **Domain `repositoryClass`** — see ADR 007

## Consequences

**Positive:**

- Deptrac rules can be derived directly from this ADR
- New features have a checklist for cross-context imports

**Negative:**

- Statistics→User coupling (60+ files) remains broad but explicitly allowed
- User→Hospital entity coupling remains until a future breaking change

## Alternatives

- **Strict isolation — no cross-context entity references** — rejected; impractical for hospital-scoped monolith
- **Shared event bus with integration events only** — rejected for now; current application events are sufficient

## References

- [../dependency-rules.md](../dependency-rules.md)
- [../data-flow.md](../data-flow.md)
- `src/Import/Infrastructure/EventSubscriber/ImportCompletedSubscriber.php`
- `src/User/Domain/Entity/User.php`
