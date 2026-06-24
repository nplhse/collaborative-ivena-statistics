# Data quality indicator (statistics)

Statistics pages with **scope** and **period** filters show a **Data Quality** badge in the breadcrumb row. It answers:

> How trustworthy are the deployment statistics for the active scope and period?

On the indication dashboard, evaluation is additionally limited to the route **indication**. Everywhere else, all allocations in the scope and period are considered.

The badge uses a three-level traffic light (`LOW` / `MEDIUM` / `HIGH`). Clicking it opens an offcanvas drawer with per-dimension scores, short explanations, and optional detail panels.

This is a **read-side transparency feature**. It does not block access to statistics or change query results.

## Where it appears

| Page | Route | `indicationId` |
|---|---|---|
| Overview | `/statistics/` | — (scope-wide) |
| Indication Insights index | `/statistics/indication-insights` | — |
| Indication dashboard | `/statistics/indication/{id}` | route parameter |
| Case Flow | `/statistics/case-flow` | — |
| Reports | `/statistics/reports` | — |
| Analysis library / explorer | `/statistics/analysis/*` | — |
| Benchmarking | `/statistics/benchmarking` | — (primary scope) |

**Excluded:** Hospital Population (`/statistics/hospital-population`) — no scope/period filter context.

Pages with extra filters (comparison scope in benchmarking, analytics dimensions) still show **scope + period** quality only, not filter-specific quality.

## Overview progressive loading

The overview dashboard (`/statistics/`) does **not** compute the data-quality report in the initial HTML response. Instead:

1. The page renders a placeholder indicator button and an empty offcanvas drawer shell.
2. A Stimulus controller (`data-quality-indicator`) prefetches `/statistics/data-quality/drawer` in the background via `requestIdleCallback` (with a short `setTimeout` fallback).
3. When the response arrives, the overall badge (`HIGH` / `MEDIUM` / `LOW`) is filled in; the HTML is cached for the drawer.
4. Opening the drawer reuses the cached HTML when available, so the first open does not trigger a second request.

All other statistics pages still render the full report synchronously in the controller.

## Scope and inputs

Evaluation uses the same scope and period as the page header controls:

| Input | Source |
|---|---|
| Scope | `StatisticsFilter` (public, hospital, state, dispatch area, cohort, my hospitals) |
| Period | Overview/dashboard period resolver |
| Indication | Route parameter on indication dashboard only; omitted elsewhere |

Two hospital sets are derived:

| Set | Definition |
|---|---|
| **Population** | All hospitals in the organisational scope, loaded from the `Hospital` entity (`DataQualityPopulationResolver` → `DataQualityHospitalPopulationQuery`) |
| **Participants** | Hospitals with at least one matching row in `allocation_stats_projection` for scope + period (+ indication when set) (`DataQualityParticipantHospitalIdsQuery`) |

Population snapshots carry `size`, `careLevel` (tier), `urbanity` (location), and `landkreis` for representativeness and subgroup checks.

## Four dimensions

Each dimension is scored independently, then combined into an overall level.

### 1. Coverage

Share of population hospitals that contributed data in the selected period (and indication, when set).

| Level | Threshold |
|---|---|
| LOW | &lt; 20 % |
| MEDIUM | 20 % – 89.9 % |
| HIGH | ≥ 90 % |

### 2. Representativeness

Compares the **distribution** of participating hospitals to the population across four sub-dimensions:

- hospital size
- care level (tier)
- urbanity (location)
- Landkreis

For each sub-dimension the calculator measures **total variation distance (TVD)** between population and participant category shares (reported as `0.5 × Σ|Δshare|`).

| Level | TVD per sub-dimension |
|---|---|
| HIGH | &lt; 0.10 |
| MEDIUM | 0.10 – 0.24 |
| LOW | ≥ 0.25 |

**Cap rule:** if any sub-dimension is not `HIGH`, the representativeness dimension cannot be `HIGH` (maximum `MEDIUM`).

Populations with fewer than five hospitals are still evaluated, but individual sub-dimension rows are flagged as based on a small base.

### 3. Subgroup support

Checks whether enough participants exist in relevant hospital **profile cells**:

- size × care level (12 cells: Small/Medium/Large × Basic/Extended/Full/unknown)
- urbanity × care level (12 cells: Urban/Mixed/Rural × Basic/Extended/Full/unknown)

A cell is **supported** when participant count ≥ `min(5, population in cell)` (adaptive minimum).

The dimension rating uses a population-weighted share of supported hospitals by default. For **narrow scopes** with fewer than five populated cells, rating switches to the share of supported **cells** instead (so small scopes are not unfairly penalised).

| Level | Supported share |
|---|---|
| LOW | &lt; 50 % |
| MEDIUM | 50 % – 79 % |
| HIGH | ≥ 80 % |

Weak cells are listed in the drawer accordion.

### 4. Allocation volume

Among participating hospitals, how many have enough allocation rows to stabilise rates and comparisons?

| Level | Share of participants with ≥ 100 allocations |
|---|---|
| LOW | &lt; 50 % |
| MEDIUM | 50 % – 79 % |
| HIGH | ≥ 80 % |

The drawer shows mean/median/percentile statistics, a histogram (buckets 0–24, 25–99, 100–249, 250+), and hospitals below the threshold.

## Overall level

1. Map each dimension to a score: `LOW = 1`, `MEDIUM = 2`, `HIGH = 3`.
2. Average the four scores.
3. Map the average back to a level:

| Average score | Overall level |
|---|---|
| &lt; 1.5 | LOW |
| 1.5 – 2.49 | MEDIUM |
| ≥ 2.5 | HIGH |

**Cap rule:** if any dimension is `LOW`, the overall level cannot be `HIGH` (maximum `MEDIUM`).

A short explanation string is chosen from the weakest dimensions (`DataQualityExplanationBuilder`).

## Code map

Namespace: `App\Statistics\DataQuality\`

| Area | Location |
|---|---|
| Thresholds | `DataQualityThresholds.php` |
| Dimension calculators | `CoverageDataQualityCalculator`, `RepresentativenessDataQualityCalculator`, `SubgroupSupportDataQualityCalculator`, `AllocationVolumeDataQualityCalculator` |
| Overall score + cap | `DataQualityScoreCalculator` |
| Orchestration | `Application/DataQualityReportService.php` |
| Population by scope | `Application/DataQualityPopulationResolver.php` |
| SQL reads | `Infrastructure/Query/DataQuality*Query.php` |
| Controller wiring | `StatisticsDataQualityReportFactory` |
| Templates | `_data_quality_indicator.html.twig`, `_data_quality_drawer.html.twig` |
| Overview prefetch (JS) | `assets/controllers/data-quality-indicator_controller.js`, `assets/lib/data-quality-prefetch.js` |
| Drawer lazy load (JS) | `assets/controllers/data-quality-drawer_controller.js` |
| Styles | `assets/styles/app.css` (`.data-quality-*`) |
| Translations | `stats.data_quality.*` in `translations/messages+intl-icu.en.xlf` |

`DataQualityCriteria.indicationId` is `null` for scope-wide evaluation; set on the indication dashboard.

Service wiring: `DataQualityHospitalPopulationReaderInterface` → `DataQualityHospitalPopulationQuery` in `config/services.yaml`.

## Changing thresholds

All numeric thresholds live in `DataQualityThresholds.php`. After changing values:

1. Update the matching unit tests under `tests/Statistics/Unit/DataQuality/`.
2. Run `make ci`.

There is no runtime configuration or admin UI for thresholds yet.

## Tests

| Type | Location |
|---|---|
| Unit | `tests/Statistics/Unit/DataQuality/` — calculators, cap rules, population resolver, SQL filter |
| Functional | `IndicationDashboardControllerTest`, `DashboardControllerTest`, `IndicationInsightsIndexControllerTest` — assert `data-testid="stats-data-quality-indicator"`; overview also asserts `data-controller="data-quality-indicator"` |

## Related documentation

- [Statistics-projection-materialized-views.md](Statistics-projection-materialized-views.md) — projection table used for participant and allocation counts
- [indication-dashboard-performance.md](indication-dashboard-performance.md) — dashboard query optimisation (separate from data quality evaluation)
