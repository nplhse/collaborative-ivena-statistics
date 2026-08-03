# ADR 013: Analysis platform rewrite (v2)

**Status:** proposed *(final status after review of this initial documentation set)*

**Note:** Operational rewrite planning documents under `docs/plans/` may be written in German. This ADR remains in English to match existing ADRs.

## Context

Statistics analytics grew into several surfaces that answer similar questions with duplicated query paths and inconsistent UX:

- Overview dashboard (KPIs, time series, heatmaps, top lists)
- Analysis Explorer + saved views (`SavedExplorerView`, schema v3) + library
- Reports / Indication Insights rankings
- Indication / group dashboards and compare flows
- Explore catalog (coverage + deep links; not an analysis designer)
- Benchmarking and specialized tools (case flow, hospital population, data quality)

Reusable building blocks already exist (`allocation_stats_projection`, `StatisticsFilter` / scope / period resolution, Generic Analysis SQL registries, Explorer presenters). Gaps include: no explicit analysis **families**, library taxonomy tied to data source rather than analysis form/topic, Measure×Aggregation only as composite metric keys, Overview/catalog rarely drillable into reproducible views, and parallel QueryBuilder vs DBAL vs Generic Analysis SQL stacks.

The work is a deliberate **refactor of an existing alpha analysis platform**, not a greenfield feature. It will be developed on an isolated long-lived branch and shipped as an additional alpha (`v0.0.6-alpha`) before any merge to `main`.

## Decision

### 1. Isolated rewrite branch and alpha

1. Base branch: **`refactor/analysis-platform-v2`** (from `main`). Other historical analysis feature branches are ignored.
2. Child branches (e.g. `refactor/analysis-v2-time-series`) merge **only** into the base branch, never directly to `main` without an explicit decision.
3. App version on this track: **`v0.0.6-alpha`** (set from the first planning commit).
4. Sync from `main`: merge security and data-integrity fixes promptly; rebase of the long-lived base is not preferred.
5. Backward compatibility with the previous alpha internals is **not a goal**. Prefer clean replacement with a documented replacement inventory over permanent adapters, dual query paths, or legacy modes.
6. Existing **personal** saved explorer views **expire** (no migration). System views are re-seeded/replaced.

### 2. Analysis families (ordered vertical slices)

Introduce families as controlled UX/query shapes, not a free BI engine:

1. Time series  
2. Ranking (top lists)  
3. Distribution  
4. Statistical measures / summaries  
5. Matrix / heatmap  

Later: comparison, boxplots (beyond five-number), derived rates, freer designer, composite reports.

Each phase is a **vertical slice**: definition → scope/period/filter → query → result → chart+table → system view → library metadata → drill-down → tests. Do not start the next family until the previous slice is done, tested, and reviewed.

### 3. Views, scope, period, filters

1. Keep **`SavedExplorerView`** + schema v3 config for execution parameters (axes, metrics, filters, presentation).
2. Add library metadata on the entity (not inside `configJson`): **`analysisFamily`** (exactly one) and **`topics`** (controlled vocabulary, multi). Optional tags later and sparingly. Today’s `category` remains a data-source proxy, not a family.
3. Relative scopes stay the existing enums; resolution always goes through `StatisticsFilterFactory` + hospital access. Authorization must not be widenable via user filters.
4. Default relative period for Overview-aligned views: **`StatisticsFilterPeriod::All`** (rolling ~12 months from first day of current month minus 11 months, **including** the current incomplete month). Do **not** add a “complete months only” preset (hard for users to predict). `all_time` remains full history.
5. Evolve **`AnalysisFilter`** (whitelist dimensions; Equals/In first; later NOT). Optional Projection QueryBuilder adapter mirroring Generic Analysis SQL filter semantics. No custom query language or filter AST engine.

### 4. Query technology

1. Prefer Doctrine QueryBuilder on the projection for standard aggregates on the Projection stack.
2. Keep Generic Analysis **raw SQL** for Explorer aggregations (whitelisted dimensions/metrics).
3. Use DBAL / explicit PostgreSQL SQL for percentiles and similar (`PERCENTILE_CONT`, etc.).
4. No universal SQL compiler, free joins, or multi-engine query abstraction.

### 5. Measure × aggregation

1. Domain concepts: **Measure** (what) + **Aggregation/Metric** (how). Persist existing **composite metric keys** (`allocation_count`, `median_transport_time`, …) with an alias mapping; no big-bang key rename.
2. First iteration centers on explicit **allocation count**; later enable transport-time avg/min/max then median/quartiles (SQL already registered but disabled in Explorer); age into GA later.
3. Derived rates (`*_rate`) stay concrete metrics with documented numerator/denominator/missing policy.
4. **ResusRoom** = resuscitation room requested (`requires_resus` / topic `resus_room`). **`is_shock`** = patient in shock — separate, low priority. Do not name topics `shock_room`.

### 6. Results and renderers

1. Chart and table for the same analysis share one query/result path.
2. Keep `AnalysisMatrix` / existing presenters; allow float values. Box plots keep **five-number summary** first; Tukey whiskers/outliers later.
3. Renderers are constrained by family + result shape + metric — a metric does not force a single chart type.

### 7. Library findability

1. Primary filters: family AND topic(s) AND search (title/description/topic labels).
2. Tabs keep ownership: Overview / Favorites / My views.
3. Ranking variants = one family + different dimension/topic/limit configs (not one query class per dimension unless special rules require it).

### 8. Surfaces

1. Overview and Explore catalog remain distinct UX; analytical widgets should deep-link into analysis views with scope/period/filter context.
2. Benchmarking / indication compare stay specialized products; may deep-link absolute breakdowns later.
3. Freer Analysis Designer only after families are stable in production use on the alpha track.

### 9. Planning documents on the rewrite branch

Version on the base branch:

- This ADR (durable)
- `docs/plans/analysis-platform-v2-plan.md` (living migration plan)
- `docs/plans/analysis-platform-v2-todo.md` (operational checklist)
- Replacement inventory under `docs/plans/`

Before merge to `main`: update ADR to match shipped code; remove the operational todo checklist; decide to archive/shorten/remove the implementation plan; keep durable feature/architecture docs of what the code contains.

## Consequences

**Positive:**

- Shared vocabulary (family, measure, topic) without a mini-BI platform
- Isolated alpha allows replacing Explorer/view internals without destabilizing `main`
- Vertical slices deliver usable drill-downs early
- Reuses projection, filter/scope, Generic Analysis, and saved views

**Negative / follow-ups:**

- Base branch may temporarily diverge from `main` (requires disciplined merges)
- Personal views are discarded for the alpha reset
- Dual query stacks remain until slices deliberately converge semantics
- Library entity columns and seeder updates required for family/topics

## Alternatives

- **Continue evolving Explorer only on `main`** — rejected; incomplete intermediate states would disrupt the current alpha
- **Build a generic query/filter AST engine** — rejected; over-abstraction for a known domain
- **Force all aggregations through QueryBuilder only** — rejected; Explorer Generic Analysis SQL is mature and whitelisted
- **Migrate all personal views automatically** — rejected; unstable alpha payloads; explicit expire is simpler
- **“Complete months only” default period** — rejected; poor user predictability; keep `Period::All`

## References

- [006-analysis-explorer-saved-views.md](006-analysis-explorer-saved-views.md)
- [001-projection-and-materialized-views.md](001-projection-and-materialized-views.md)
- [../../04-features/statistics/analysis-explorer.md](../../04-features/statistics/analysis-explorer.md)
- [../../04-features/statistics/analysis-explorer-library-standards.md](../../04-features/statistics/analysis-explorer-library-standards.md)
- [../../04-features/statistics/statistics-filter-and-scope.md](../../04-features/statistics/statistics-filter-and-scope.md)
- [../../plans/analysis-platform-v2-plan.md](../../plans/analysis-platform-v2-plan.md)
- [../../plans/analysis-platform-v2-todo.md](../../plans/analysis-platform-v2-todo.md)
- [../../plans/analysis-platform-v2-replacement-inventory.md](../../plans/analysis-platform-v2-replacement-inventory.md)
