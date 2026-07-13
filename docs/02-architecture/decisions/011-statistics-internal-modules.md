# ADR 011: Statistics internal modules

**Status:** accepted

## Context

The `Statistics` bounded context contains roughly 47% of production PHP files under `src/`. It is subdivided into six submodules:

| Submodule | Purpose |
|-----------|---------|
| `AnalysisExplorer` | Interactive saved-view analytics |
| `GenericAnalysis` | Shared SQL kernel for tabular reports |
| `Benchmarking` | Hospital comparison |
| `DataQuality` | Data quality indicator |
| `CaseFlow` | Regional flow metrics and maps |
| `HospitalPopulation` | Hospital population overview |

Submodules have **uneven layer depth**: AnalysisExplorer and GenericAnalysis have Domain layers; Benchmarking, CaseFlow, DataQuality, and HospitalPopulation do not. Phase 2 must decide whether submodules are separate bounded contexts or internal modules.

## Decision

Statistics submodules are **internal modules within the Statistics bounded context**, not separate bounded contexts.

### Rules

1. **Namespace:** `App\Statistics\{Submodule}\{Layer}\…` — folder convention only.
2. **Deptrac (Phase 3):** Treat `src/Statistics/**` as **one bounded context group**. Optional internal layer collectors; no per-submodule BC boundaries.
3. **Internal coupling:** Submodules may import each other and Statistics root (`Application/`, `Domain/`, `Infrastructure/`, `UI/`) **directly**.
4. **External coupling:** Submodules follow the same cross-context rules as Statistics root — may use Allocation, User, and Shared; must not add dependencies on Import, Admin, etc. beyond what Statistics root already allows.
5. **Domain layer:** Not required in every submodule. Add Domain only where entities or domain rules exist (e.g. `SavedExplorerView` in Statistics Domain; explorer DTOs in AnalysisExplorer Domain).
6. **Extension points:** Submodule-specific registries (e.g. `ExplorerQueryMapperRegistry`, `ReportDefinitionRegistry`) stay inside Statistics.

### Submodule communication

Prefer Statistics root application services as facades when a submodule needs a stable API for UI or other BCs. Direct submodule-to-submodule imports are allowed for internal analytics composition (e.g. GenericAnalysis used by AnalysisExplorer).

## Consequences

**Positive:**

- Statistics remains one cohesive "Analytics" business capability
- Simpler Deptrac configuration than six separate contexts
- Submodule layer inconsistency is explicitly accepted

**Negative:**

- High internal complexity stays bundled in one BC
- No hard module boundaries between Explorer and Benchmarking — discipline via review and future Deptrac layer rules within Statistics

## Alternatives

- **Separate bounded context per submodule** — rejected; no independent lifecycle or ubiquitous language per submodule; would multiply Deptrac rules without business benefit
- **Flatten submodules into Statistics root** — rejected; would harm navigability of a large codebase

## References

- [../bounded-contexts.md](../bounded-contexts.md)
- [008-bounded-context-taxonomy.md](008-bounded-context-taxonomy.md)
- [009-cross-context-dependency-rules.md](009-cross-context-dependency-rules.md)
- [../04-features/statistics/README.md](../../04-features/statistics/README.md)
