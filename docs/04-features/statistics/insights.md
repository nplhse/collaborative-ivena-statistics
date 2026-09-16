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
| Compare | `/statistics/insights/{dimension}/compare` | `app_stats_insights_compare` |

Legacy Indication Insights URLs redirect while keeping scope/period query parameters:

- `/statistics/indication-insights` → overview
- `/statistics/indication/{id}` → `indications/{id}`
- `/statistics/indication-group/{id}` → `indication-groups/{id}`
- `/statistics/indication/compare` → `indications/compare`

The overview does **not** open a default value. Detail pages require an explicit selection.

The overview uses a two-column layout (2/3 + 1/3): search and a tabbed Indications / Indication groups card on the left, stacked Top-5 teasers on the right. Teaser titles open the dimension directory. The Insights subnav lives on the overview and on dimension directories. Detail dashboards and compare show only the chosen subject; breadcrumbs return to Insights. Compare can be launched from a detail page.

Directories are a DataTable-style card: search and optional Top List button in the card header (top right), sort next to page-size in the footer. Indications use a tabbed card to switch to indication groups.

## Supported dimensions (wave 1)

| Dimension | Slug | Navigation | Projection filter |
|---|---|---|---|
| Indications | `indications` | primary, featured | `indication_normalized_id` |
| Indication groups | `indication-groups` | nested under Indications (`?view=groups`) | member `indication_normalized_id` values |
| Specialities | `specialities` | primary | `speciality_id` |
| Assignment | `assignments` | primary | `assignment_id` |
| Departments | `departments` | primary | `department_id` |
| Occasions | `occasions` | primary | `occasion_id` |
| Infections | `infections` | primary | `infection_id` |
| Secondary transports | `secondary-transports` | primary | `secondary_transport_id` |

Indication groups stay a provider that resolves member IDs. Mixed compare Indication ↔ group remains indication-specific because both use the same projection column. Other dimensions compare only within the same dimension.

Out of wave 1: Zuweiser (not in the model), assignment mode, secondary indication as its own Insight dimension, recents, and case counts in global search.

## Architecture

Tagged providers implement `InsightDimensionProviderInterface` (`#[AutoconfigureTag('app.statistics.insight_dimension')]`). `InsightDimensionRegistry` feeds overview, subnav, directory, search, and subject resolution.

`InsightSubject` carries dimension, id, label, optional code/publicId, and an `InsightPopulationFilter` (`column` + id list, whitelist in `ALLOWED_COLUMNS`). Metrics and slice queries accept `array|InsightPopulationFilter` and emit `column IN (:ids)` plus baseline `(column IS NULL OR column NOT IN (:ids))`.

`IndicationInsightEngine` can disable tautological insight ids per provider (infection dimension disables `infectious`). Drawer/overlay filters stay unused; all rankings and detail numbers use the same scope/period resolution as the rest of Statistics.

Hospital-scope detail pages embed the isochrone origin map below Age groups. The widget receives `dimension` + `id`, colours travel-time rings by the subject population, and offers the Case Flow origin choropleth as an off-by-default layer. See [isochrone-origin-heatmap.md](isochrone-origin-heatmap.md).

Search is provider `ILIKE` (name/code), minimum two characters, no projection table and no case counts. The Stimulus combobox (`insights-search`) debounces ~300 ms and preserves the current scope/period query.

## How to add a dimension

1. Add a case to `InsightDimensionKey` (slug, projection column, catalog/top-list mapping, compare family).
2. Implement a provider (usually extend `AbstractEntityInsightDimension`) with labels, icon, `navPlacement` / `navOrder`, optional `disabledInsightIds()`, and search/list queries.
3. Extend the route `requirements` for `{dimension}` (or use `InsightDimensionKey::routeRequirement()`).
4. Add `stats.insights.dimension.{slug}.label|description|all` translations (hyphens in the slug become underscores in the key).
5. Wire catalog/top-list/allocation show links through `CatalogActionFactory`, `TopListCatalogCrossReference`, and `InsightEntityUrlResolver` when the dimension has a catalog entity.
6. Cover directory + detail with a functional test; add an integration assertion if the projection column is new.

Do not invent grouping models. Nested groups are only appropriate when membership already exists in the domain (as with indication groups).

## Cross-links

- Catalog actions: “Open insight” for every Insight dimension
- Top Lists: compact chart-bar link beside catalog labels (`insightRowTargets`)
- Overview indication mix / Benchmark indication mix / Transport Time Profile: canonical show URLs
- Allocation show: compact Insight icon next to indication, speciality, department, assignment, occasion, infection, and secondary transport

Analysis Explorer deep-links stay backlog except where a concrete catalog entity already exists; see [analysis-explorer-library-standards.md](analysis-explorer-library-standards.md).

## Related

- [indication-dashboard-performance.md](indication-dashboard-performance.md) — parameterized metrics/slice SQL
- [statistics-filter-and-scope.md](statistics-filter-and-scope.md)
- [data-quality-indicator.md](data-quality-indicator.md)
- [isochrone-origin-heatmap.md](isochrone-origin-heatmap.md)
