# Dimension Insights

**Audience:** Developers extending statistics dimension analysis (formerly Indication Insights).

Insights analyse one catalog value (or indication group) against the remaining caseload in the current **scope** and **period**. Top Lists remain frequency rankings; Insights remain comparative dashboards. Scope dimensions (hospital, state, dispatch area) are not Insight dimensions.

Canonical routes:

| Page | Route | Name |
|---|---|---|
| Overview | `/statistics/insights` | `app_stats_insights` |
| Search (JSON) | `/statistics/insights/search` | `app_stats_insights_search` |
| Dimension directory | `/statistics/insights/{dimension}` | `app_stats_insights_dimension` |
| Detail | `/statistics/insights/{dimension}/{id}` | `app_stats_insights_show` |
| Compare | `/statistics/insights/compare` | `app_stats_insights_compare` |

Legacy Indication Insights URLs redirect while keeping scope/period query parameters:

- `/statistics/indication-insights` → overview
- `/statistics/indication/{id}` → `indications/{id}`
- `/statistics/indication-group/{id}` → `indication-groups/{id}`
- `/statistics/indication/compare` → `/statistics/insights/compare` (`indication_a` / `subject_*_type` mapped to `subject_*_dimension`)
- `/statistics/insights/{dimension}/compare` → `/statistics/insights/compare` (path dimension used when `subject_*_dimension` is absent)

The overview does **not** open a default value. Detail pages require an explicit selection.

The overview uses a two-column layout (2/3 + 1/3): search and a tabbed Indications / Indication groups card on the left, stacked Top-5 teasers on the right. Teaser titles open the dimension directory. The Insights subnav lives on the overview and on dimension directories; the active tab uses a surface background and a primary underline. The Statistics subnav uses `tabler:chart-bar` as the Insights icon. Detail dashboards and compare show only the chosen subject; breadcrumbs return to Insights. Compare can be launched from a detail page: side A is the current subject plus the header scope/period; the dialog chooses side B (Insights search plus independent scope/period).

Directories keep their own card: search in a compact toolbar (Indications keep the groups tabs in the card header), sort next to page-size in the footer. The table body is [`RankingTable`](ranking-table.md) (rank, name, count, share, Explore action). Search, group tabs, and the footer stay outside that component. The Top List action lives in the page header with the standard `list-numbers` icon. Table rows keep the name as the Insight detail link and add a final icon-only action to the Explore catalog show page. The overview featured tables use the same ranking table; their empty state stays `EmptyState`. Case Flow segment tables stay custom: they have no rank column, and they already share the share bar and the delta indicator.

## Supported dimensions (wave 1)

| Dimension | Slug | Navigation | Projection filter |
|---|---|---|---|
| Indications | `indications` | primary, featured | `indication_normalized_id` |
| Indication groups | `indication-groups` | nested under Indications (`?view=groups`) | member `indication_normalized_id` values |
| Specialities | `specialities` | primary | `speciality_id` |
| Departments | `departments` | primary | `department_id` |
| Assignment | `assignments` | primary | `assignment_id` |
| Occasions | `occasions` | primary | `occasion_id` |
| Infections | `infections` | primary | `infection_id` |
| Secondary transports | `secondary-transports` | primary | `secondary_transport_id` |

Indication groups stay a provider that resolves member IDs. Compare is cross-dimensional: each side is resolved independently via `InsightDimensionRegistry`. Mixed Indication ↔ group still shares the indication projection column; other pairs (e.g. indication vs department) use their own population columns. The same catalog value may be compared across two periods or scopes (ACS 2025 vs ACS 2024). Identity is rejected only when subject **and** scope/period are the same.

Out of wave 1: Zuweiser (not in the model), assignment mode, secondary indication as its own Insight dimension, recents, and case counts in global search.

## Architecture

Tagged providers implement `InsightDimensionProviderInterface` (`#[AutoconfigureTag('app.statistics.insight_dimension')]`). `InsightDimensionRegistry` feeds overview, subnav, directory, search, and subject resolution.

`InsightSubject` carries dimension, id, label, optional code/publicId, and an `InsightPopulationFilter` (`column` + id list, whitelist in `ALLOWED_COLUMNS`). Metrics and slice queries accept `array|InsightPopulationFilter` and emit `column IN (:ids)` plus baseline `(column IS NULL OR column NOT IN (:ids))`.

`IndicationInsightEngine` can disable tautological insight ids per provider (infection dimension disables `infectious`). Drawer/overlay filters stay unused; all rankings and detail numbers use the same scope/period resolution as the rest of Statistics.

Hospital-scope detail pages embed the isochrone origin map below Age groups. The widget receives `dimension` + `id`, colours travel-time rings by the subject population, and offers the Case Flow origin choropleth as an off-by-default layer. See [isochrone-origin-heatmap.md](isochrone-origin-heatmap.md).

Search is provider `ILIKE` (name/code), minimum two characters, no projection table and no case counts. The Stimulus combobox (`insights-search`) debounces ~300 ms and preserves the current scope/period query. Hits are interleaved across dimensions (up to five per provider, **five** overall) so later catalogs are not crowded out. Compare uses the same widget in `select` mode with the same cap. Do not pass query `dimension=` on compare (that would restrict the search to the current Insight page). Narrow the query if the target is missing.

## Compare query

Canonical URL:

```
GET /statistics/insights/compare
  ?subject_a_dimension=indications
  &subject_a_id=123
  &subject_b_dimension=departments
  &subject_b_id=45
  &scope=...&period=...                 # side A (header pickers)
  &comparison_scope=...&comparison_period=...  # side B, only when set
```

- Do not use query `dimension=` on compare (it collides with the search API).
- Missing `comparison_*` falls back to the **primary** filter (not the Top Lists / `ComparisonScopeResolver` default cohort). After Apply, `comparison_*` is written explicitly so later header changes do not silently move B.
- Header chrome and data quality stay on the primary filter. Navigation preserves `subject_*` and `comparison_*`.
- Swap A/B exchanges subjects **and** primary ↔ `comparison_*` filters when B has its own filter; if B still followed primary, only the subjects swap.
- **Stop comparing** leaves the compare page for side A’s Insight dashboard with the primary filter; `subject_*` and `comparison_*` are dropped.
- Metrics/slice SQL builds an independent `BenchmarkSqlFilter` side predicate per side plus `InsightPopulationFilter`.
- `disabledInsightIds()` from both dimension providers are united before the compare insight engine runs.

## Compare UI

The compare dialog edits **side B only**. Side A stays the current Insight page (subject plus the header scope/period pickers).

- Search reuses the overview combobox in Stimulus `select` mode (`InsightCompareSelectionForm`). The search URL must not include `dimension`, `id`, or `subject_*`, otherwise later catalogs disappear behind the current page dimension. Results are capped at five interleaved hits (`limit=5`); refine the query if the needed object is missing.
- Scope/period for B reuse the Top Lists comparison side fields and write `comparison_*`.
- Indication-group member presets set B to the largest (or smallest) member; A stays the group.
- Apply navigates to the canonical compare URL. Missing B shows `stats.insights.compare.error.missing_subject_b`.
- Header actions: swap A/B (query rules above) and **Stop comparing**, which opens side A’s Insight dashboard.

## How to add a dimension

1. Add a case to `InsightDimensionKey` (slug, projection column, catalog/top-list mapping, compare family).
2. Implement a provider (usually extend `AbstractEntityInsightDimension`) with labels, icon, `navPlacement` / `navOrder`, optional `disabledInsightIds()`, and search/list queries.
3. Extend the route `requirements` for `{dimension}` (or use `InsightDimensionKey::routeRequirement()`).
4. Add `stats.insights.dimension.{slug}.label|description|all` translations (hyphens in the slug become underscores in the key).
5. Wire catalog/top-list links through `CatalogActionFactory` and `TopListCatalogCrossReference` when the dimension has a catalog entity.
6. Cover directory + detail with a functional test; add an integration assertion if the projection column is new.

Do not invent grouping models. Nested groups are only appropriate when membership already exists in the domain (as with indication groups).

## Cross-links

- Catalog actions: “Open insight” (`tabler:chart-bar`) for every Insight dimension. Allocation case files link only to Explorer entity details; Insights stay on catalog detail pages.
- Insights directories and the overview featured table: final icon-only Explore action (`tabler:book-2`, Details)
- Top Lists: Insight link in the final actions column (`tabler:chart-bar`, `insightRowTargets`); row labels stay Explore show links
- Top List header: Overview (`tabler:compass`) returns to the Explore catalog list
- Overview indication mix / Benchmark indication mix / Transport Time Profile: canonical show URLs

Analysis Explorer deep-links stay backlog except where a concrete catalog entity already exists; see [analysis-explorer-library-standards.md](analysis-explorer-library-standards.md).

## Related

- [indication-dashboard-performance.md](indication-dashboard-performance.md) — parameterized metrics/slice SQL
- [statistics-filter-and-scope.md](statistics-filter-and-scope.md)
- [data-quality-indicator.md](data-quality-indicator.md)
- [isochrone-origin-heatmap.md](isochrone-origin-heatmap.md)
