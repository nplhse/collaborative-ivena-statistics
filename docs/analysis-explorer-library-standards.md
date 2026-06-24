# Analysis Explorer — Library standards & chart alignment

This document records product decisions from the chart inventory (Overview, Indication Insights, Benchmarking, Hospitals) and defines how predefined library views relate to legacy dashboard charts.

## Priority decision (phased)

| Phase | Focus | Scope | Rationale |
|-------|--------|--------|-----------|
| **1 — now** | **Close library seed gaps** | Add system views for heatmaps, clinical features, top-report dimensions, hospital location/size variants | Low effort, high coverage; Explorer engine already supports these — only seeds were missing |
| **2 — next** | **Dashboard deduplication** | Replace redundant Overview / Indication charts with library deep-links or embedded explorer charts | Medium effort; removes duplicate `IndicationDashboardAssembler` pipelines for views that already exist in the library |
| **3 — later** | **Compare & benchmarking** | Allocations compare mode in Explorer (like hospitals `compare`), delta heatmaps | High effort; Benchmarking and Indication Compare are differentiated products — **not** replaced by standard library views |
| **Out of scope** | KPI decks, insight text cards, choropleth maps, moving averages | Keep bespoke dashboard widgets | Explorer is for exploratory breakdowns, not executive summary tiles |

**Decision:** Phase 1 is implemented via extended system views in `ExplorerSystemViewSeeder`. Phases 2–3 are documented below as a refactor backlog, not implemented in this pass.

## New system views (phase 1)

### Allocations — heatmaps (Overview / Indication occurrence charts)

| Slug | Chart | Rows × Columns | Replaces / complements |
|------|-------|----------------|------------------------|
| `allocations-weekday-by-day-time-heatmap` | heatmap | `weekday` × `day_time_bucket` | Overview heatmap (day/time mode); complements `allocations-by-weekday` + `day-time-bucket-distribution` |
| `allocations-weekday-by-shift-heatmap` | heatmap | `weekday` × `shift_bucket` | Overview heatmap (shift mode); complements `shift-bucket-distribution` |

### Allocations — clinical features (Overview sidebar progress bars)

| Slug | Dimension | Replaces / complements |
|------|-----------|------------------------|
| `resus-distribution` | `resus` | Overview clinical resources |
| `cathlab-distribution` | `cathlab` | Overview clinical resources |
| `cpr-distribution` | `cpr` | Overview clinical features |
| `ventilation-distribution` | `ventilation` | Overview clinical features |
| `shock-distribution` | `shock` | Overview clinical features |

(`with-physician-distribution` already existed.)

### Allocations — top-report dimensions

| Slug | Dimension | Replaces / complements |
|------|-----------|------------------------|
| `allocations-by-speciality` | `speciality` | Overview top reports |
| `allocations-by-occasion` | `occasion` | Overview top reports |
| `allocations-by-infection` | `infection` | Overview top reports |

(`allocations-by-department` already existed.)

### Hospitals — location / size variants (Hospital Population page)

| Slug | Chart | Dimension | Replaces / complements |
|------|-------|-----------|------------------------|
| `hospitals-by-location` | bar | `hospital_location` | Hospital population counts by urbanicity |
| `beds-distribution-by-location` | box_plot | `hospital_location` | Hospital population beds box plots (location) |
| `allocations-per-hospital-size` | bar | `hospital_size` | Hospital population allocation bars (size) |
| `allocations-per-hospital-location` | bar | `hospital_location` | Hospital population allocation bars (location) |

Tier-based equivalents (`hospitals-by-tier`, `beds-distribution-by-tier`, `allocations-per-hospital-tier`) already existed.

## Dashboard ↔ library redundancy matrix

Legend: **Link** = replace chart with library deep-link; **Keep** = stay bespoke; **Seed** = now covered by new system view.

### Statistics Overview (`/statistics/`)

| Dashboard visual | Library slug(s) | Phase 2 action |
|------------------|-----------------|----------------|
| Time series (line + MA) | `allocations-over-time`, `allocations-by-year` | **Link** — MA stays dashboard-only until Explorer supports overlays |
| Occurrence heatmap | `allocations-weekday-by-day-time-heatmap`, `allocations-weekday-by-shift-heatmap` | **Link** after phase 1 seeds |
| Age groups bar | `age-group-distribution` | **Link** |
| Transport time buckets bar | — (gap: no `transport_time_bucket` dimension) | **Keep** until bucket dimension or stat metric |
| Gender / urgency progress | `gender-distribution`, `urgency-distribution` | **Link** (optional embed) |
| Clinical resources / features | `resus-distribution`, `cathlab-distribution`, `cpr-distribution`, … | **Link** after phase 1 seeds |
| Transport type progress | `transport-type-distribution` | **Link** |
| Top reports tables | `allocations-by-department`, `allocations-by-speciality`, … | **Link** with `chartRowLimit=top_10` where useful |
| Executive KPIs, hospital insights | — | **Keep** |
| Hospital summary (own hospital) | — | **Keep** until allocations compare mode |

### Indication Insights

| Dashboard visual | Library approach | Phase 2 action |
|------------------|------------------|----------------|
| Same 4 ApexCharts as Overview | Generic library slug + `indication` filter (URL/query) | **Link** — requires indication filter on explorer routes |
| Compare delta heatmap / %-bars | — | **Keep** (Benchmarking family) |

### Benchmarking

| Visual | Library relation | Action |
|--------|------------------|--------|
| Delta heatmap, %-grouped bars, KPI tiles, indication mix | Thematic overlap only | **Keep** — add optional “view absolute breakdown” links to related library views |

### Hospital Population (`/statistics/hospital-population`)

| Visual | Library slug(s) | Phase 2 action |
|--------|-----------------|----------------|
| Beds box plot (tier) | `beds-distribution-by-tier` | **Link** / embed |
| Beds box plot (location) | `beds-distribution-by-location` | **Link** after phase 1 |
| Allocation bars (tier/size/location) | `allocations-per-hospital-*`, `hospitals-by-location` | **Link** after phase 1 |
| Regional map (choropleth) | — | **Keep** |
| Cross tables / beds matrix | `hospital-tier-by-location`, multi-metric table views | **Link** optional |

## Phase 2 refactor story (backlog)

1. Add `libraryView` (slug) + optional query params to dashboard chart cards in Twig.
2. For Indication Dashboard / Group: pass `indicationId` (or group) as explorer filter overlay when opening library view.
3. Remove duplicated chart payload from `OverviewChartsFactory` / `IndicationDashboardChartPayloadFactory` where a library slug exists.
4. Keep `IndicationDashboardAssembler` for transport-time buckets and moving-average time series until Explorer gaps close.
5. Run `bin/console statistics:explorer-views:sync` after seed changes in each environment.

## Known Explorer gaps (unchanged)

- Transport time **bucket** bar chart (Overview, Indication, Benchmarking %-bars use different semantics)
- **Delta** / two-scope **%-compare** (Benchmarking, Indication Compare)
- Moving average on time series
- Indication-filtered saved views (product decision)
- Geo / choropleth
- KPI decks and narrative insights

See also [analysis-explorer-v2.md](analysis-explorer-v2.md) for technical architecture.
