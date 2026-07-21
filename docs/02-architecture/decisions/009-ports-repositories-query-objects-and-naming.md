# ADR 009: Ports, repositories, query objects, and naming

**Status:** accepted

## Context

The codebase uses concrete Doctrine `*Repository` classes extensively and has **no** repository interfaces. Cross-cutting extension points (import processors, report definitions, exporters) already use application contracts with tagged iterators. Analytical read paths have started to appear as dedicated query classes under `Infrastructure/Query/`, while large repositories such as `AllocationRepository` still accumulate many specialized read methods.

Statistics is one bounded context with internal submodules (`AnalysisExplorer`, `GenericAnalysis`, `Benchmarking`, …). Naming of DTO folders (`Dto` vs `DTO`) and contract folders (`Contract` vs `Contracts`) is inconsistent.

## Decision

### Repository interfaces

Do **not** introduce repository interfaces by default. Concrete Doctrine repositories are the standard persistence API inside a context.

Introduce interfaces / application contracts only when they provide clear value:

- Tagged extension points and registries
- Cross-context ports (e.g. projection rebuild)
- Swappable strategies (e.g. reject writers)

### Query objects for specialized reads

Keep repositories focused on aggregate persistence and simple lookups (find, save-related helpers, small scoped queries).

Specialized read paths—especially statistics, explore, dashboard, and reporting SQL—belong in dedicated query (or SQL builder) classes under the owning context’s `Infrastructure/Query/` (or an equivalent infrastructure query location already used by a submodule).

Rules of thumb:

- **New** analytical / multi-join / report-style queries should be added as query classes, not as additional public methods on a large repository
- Existing oversized repositories (notably `AllocationRepository`) are reduced **incrementally** on touchpoints or in dedicated complexity PRs—not as a single big-bang move before beta
- Query classes may use DBAL/ORM directly; they do not need a repository wrapper unless reuse inside the aggregate API is natural

### Statistics internal model

`Statistics` remains a **single** bounded context. Submodules are internal modules for organization and optional future Deptrac layers; they are not separate top-level contexts.

### Naming conventions (forward-looking)

For **new** code:

- Use `DTO` (uppercase) for DTO directories
- Use `Contract` (singular) for application contract directories

Bulk renames of existing `Dto` / `Contracts` trees are not required before beta; rename opportunistically when a file or folder is already being touched.

## Consequences

**Positive:**

- Matches existing successful patterns (contracts for extension points, query objects for analytics)
- Gives a durable strategy to shrink repository hotspots without mandating interface noise
- Clarifies Statistics as one context for dependency rules

**Negative:**

- Boundary between “simple repository query” and “query object” still needs judgment
- More classes for read paths
- Naming debt remains until touchpoint cleanups

## Alternatives

- **Repository interfaces everywhere** — rejected; little test/isolation benefit given Doctrine-centric design
- **Keep growing repositories** — rejected; `AllocationRepository` size already harms changeability
- **Full CQRS framework / separate read DB** — rejected as operational and conceptual overhead for current scale
- **Immediate global DTO/Contract rename** — rejected; noisy diff without behavioral gain

## References

- [../extension-points.md](../extension-points.md)
- [../bounded-contexts.md](../bounded-contexts.md)
- [001-projection-and-materialized-views.md](001-projection-and-materialized-views.md)
- [010-architecture-guardrails-and-beta-scope.md](010-architecture-guardrails-and-beta-scope.md)
- Issue [#258](https://github.com/nplhse/collaborative-ivena-statistics/issues/258)
