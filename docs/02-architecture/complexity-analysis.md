# Complexity analysis (Phase 4)

**Related:** [target-architecture.md](target-architecture.md), [deptrac.md](deptrac.md), [refactoring-backlog.md](refactoring-backlog.md), [GitHub Issue #258](https://github.com/nplhse/collaborative-ivena-statistics/issues/258)

Phase 4 measures **objective complexity and test risk** from PHPUnit coverage reports, correlates with Deptrac baseline and Git churn, and defines **Phase 5 refactoring strategies**. No production code was changed in this phase.

## Executive summary

| Rank | Hotspot | Why it matters |
|------|---------|----------------|
| 1 | `AllocationRepository` | ARCH-001: 1 862 LOC, ~80 methods, **sum CRAP 1 098**, **10.2 % line coverage** — CRUD, analytics, and hospital-scoped duplicates in one class |
| 2 | `AnalysisExplorerShell` | ARCH-002: 897 LOC, **sum CRAP 242**, 67 % coverage — Live Component mixes UI state, forms, analysis runs, charts, saved views |
| 3 | `ProjectionTimeSeriesQuery` | 482 LOC, **sum CRAP 271**, 43 % coverage — parallel aggregation API to Allocation analytics |
| 4 | `ExplorerConfigMapper` | 657 LOC, sum CRAP 123 — mapping + schema upgrades + legacy paths |
| 5 | `ExplorerEditFormType` | 722 LOC, sum CRAP 92 — large Symfony form (high coverage, structural debt) |

**Statistics** remains ~47 % of production code (ADR 011). Complexity is concentrated in **AnalysisExplorer** and **Allocation** analytics paths.

**Recommended first Phase 5 PR:** extract the first query object from `AllocationRepository` (e.g. time-series counts) with integration tests — smallest step with highest ARCH-001 impact.

## Methodology

| Input | Tool | Notes |
|-------|------|-------|
| CRAP, cyclomatic complexity, coverage | PHPUnit 12.5 + `php-code-coverage` (`sebastian/complexity`) | `make coverage` → `var/coverage/logs/crap4j.xml`, Clover, HTML, PHPUnit XML |
| Class summary | `bin/complexity-summarize` | Aggregates per class → `var/complexity/summary.csv` |
| Change frequency | `bin/complexity-churn` | Git log, last 12 months, top 50 from summary |
| Architecture debt | `deptrac.baseline.yaml` | Per-class skip count + known layer-pair categories (see [deptrac.md](deptrac.md)) |
| Qualitative review | Code + Phase 1 ARCH-* | Business vs unnecessary technical complexity |

**No new Composer packages.** Metrics reuse the existing PHPUnit dev stack.

**Exclusions:** `src/Admin` is excluded from PHPUnit `<source>` (Admin hotspots assessed by LOC only). `DataFixtures` and test code are out of scope.

**CRAP caveat:** Classes with **0 % coverage** and low complexity can show **misleadingly high max CRAP** (e.g. small DTOs). Prioritization uses **sum CRAP**, **LOC**, **line coverage**, and **ARCH relevance** — not max CRAP alone.

### Commands

```bash
make complexity    # coverage + summarize + churn
make coverage      # reports only
bin/complexity-summarize 30
bin/complexity-churn 50
```

Reports under `var/coverage/` and `var/complexity/` are gitignored (`var/`).

## Coverage snapshot (full suite, Phase 4 run)

| Metric | Value |
|--------|-------|
| Tests | 2 360 |
| Classes | 55.57 % (569/1 024) |
| Methods | 75.13 % (3 141/4 181) |
| Lines | 88.07 % (27 257/30 949) |
| Classes in summary | 1 024 |

## Top classes by risk score (LOC ≥ 400, sorted by sum CRAP)

| Class | max CRAP | sum CRAP | max ccn | LOC | line % | Deptrac skips | Churn (12 mo commits) |
|-------|----------|----------|---------|-----|--------|---------------|------------------------|
| `AllocationRepository` | 110 | 1 098 | 10 | 1 862 | 10.2 | 0 | 14 |
| `AnalysisExplorerShell` | 73 | 242 | 19 | 897 | 67.4 | 0 | 14 |
| `ProjectionTimeSeriesQuery` | 72 | 271 | 8 | 482 | 42.9 | 0 | 7 |
| `ExplorerConfigMapper` | 26 | 123 | 12 | 657 | 88.3 | 0 | — |
| `ExplorerChartPresenter` | 34 | 113 | 18 | 422 | 74.1 | 0 | — |
| `StatisticsPageViewModelFactory` | 30 | 111 | 30 | 584 | 94.1 | 0 | — |
| `BenchmarkComparisonPageViewModelFactory` | 30 | 106 | 30 | 586 | 99.2 | 0 | — |
| `ExplorerEditFormType` | 17 | 92 | 17 | 722 | 97.4 | 0 | — |
| `ExplorerResultsTablePresenter` | 17 | 104 | — | 566 | — | 0 | — |
| `ExplorerSystemViewSeeder` | 6 | 20 | — | 594 | — | 0 | — |
| `BenchmarkMetricBuilder` | 7 | 60 | — | 490 | — | 0 | — |
| `DimensionRegistry` | 4 | 29 | 4 | 504 | 98.5 | 0 | — |
| `Allocation` (entity) | 3 | 69 | — | 531 | — | 0 | — |

Full machine-readable list: run `make complexity` → `var/complexity/summary.csv`.

## Deptrac baseline correlation (layer pairs)

Individual classes mostly have 0–2 baseline skips. The **dominant architectural debt** is at layer level (390 skips total):

| Count | Pattern |
|-------|---------|
| 69 | `Statistics_Application` → `Statistics_Infrastructure` |
| 52 | `Allocation_UI` → `Allocation_Infrastructure` |
| 26 | `Allocation_Application` → `Allocation_Infrastructure` |
| 16 | `Allocation_Domain` → `Allocation_Infrastructure` (ARCH-004 `repositoryClass`) |
| 7 | `Kpi_Application` → `Admin_UI` (forbidden cross-BC) |

Refactoring P1/P2 hotspots should reduce Application→Infrastructure and UI→Infrastructure edges in Statistics and Allocation.

## Business vs technical complexity

| Class | Dominant type | Notes |
|-------|---------------|-------|
| `DimensionRegistry`, `MetricRegistry` | **Business** | Large config-as-code; domain rules for analysis dimensions |
| `ExplorerConfigMapper`, `ExplorerSystemViewSeeder` | **Mixed** | Business mapping + legacy upgrade paths (technical) |
| `AllocationRepository` duplicate `…ForHospitals` methods | **Technical** | Same queries with hospital scope — extract scope trait/query objects |
| `ViewModelFactories` (Statistics, Benchmarking) | **Technical** | Duplicated scope/period/URL building |
| `ExplorerEditFormType` | **Mixed** | Many fields are business; structure could be partial forms |
| EasyAdmin CRUD controllers | **Low priority** | Boilerplate configuration, not domain logic |
| `Allocation` entity size | **Business** | Rich domain model; acceptable per ADR 007 |

## Statistics submodules (ARCH-002)

| Submodule | Large classes (LOC ≥ 400) | Profile |
|-----------|---------------------------|---------|
| **AnalysisExplorer** | Shell, EditFormType, ConfigMapper, ChartPresenter, ResultsTablePresenter, SystemViewSeeder | Highest UI + application coupling; Explorer is the main complexity driver |
| **Benchmarking** | ViewModelFactories, BenchmarkMetricBuilder | Duplication with core Statistics UI |
| **GenericAnalysis** | DimensionRegistry | Registry/config weight |
| **Core Statistics** | ProjectionTimeSeriesQuery, ProjectionDeduplicator, Overview providers | Read-model / SQL aggregation |
| **Engagement** | MonthlyReminderContentBuilder | Cross-BC content building (483 LOC) |

## Hotspot strategy cards (Phase 5 input)

### 1. `AllocationRepository` (ARCH-001) — P1

| | |
|---|---|
| **Ist** | Single Doctrine repository: CRUD/delete-by-import, detail fetches, time-series counts, bucket aggregations, top reports — global and `ForHospitals` variants (~80 methods). |
| **Problem** | **Technical:** god object, duplicated hospital-scoping, 10 % line coverage on 1 862 LOC. |
| **Ziel** | `AllocationRepository` ~200 LOC (CRUD/find); queries in `Infrastructure/Query/` (`AllocationTimeSeriesQuery`, `AllocationBucketQuery`, `AllocationTopReportQuery`, shared scope helper). |
| **Kleinster Schritt** | Extract one query cluster (e.g. monthly/daily counts) + `ScopedQueryTrait`; keep public method signatures delegating to new class. |
| **Tests** | Extend `AllocationRepositoryMonthAggregationTest`; add unit tests per query class. |
| **Deptrac** | May reduce `Allocation_Application` → `Allocation_Infrastructure` if callers move to application services. |
| **Nicht jetzt** | Full split in one PR; changing statistics projection pipeline. |

### 2. `AnalysisExplorerShell` (ARCH-002) — P1

| | |
|---|---|
| **Ist** | Live Component with 15+ injected services; LiveProps for config, edit drawer, analysis revision; LiveActions for apply/save/swap axis. |
| **Problem** | **Technical:** orchestration + UI state in one class (897 LOC, ccn up to 19). |
| **Ziel** | Shell &lt; 300 LOC; `ExplorerEditStateManager`, `ExplorerAnalysisRunner`, `ExplorerSaveViewHandler` in Application. |
| **Kleinster Schritt** | Extract `ExplorerAnalysisRunner` (run analysis + normalize result) — already has clear dependency cluster. |
| **Tests** | `AnalysisExplorerShellTest` (13 references); add application service unit tests first. |
| **Deptrac** | Should improve `Statistics_UI` vs `Statistics_Application` boundaries. |
| **Nicht jetzt** | Dashboard deduplication ([analysis-explorer-library-standards.md](../04-features/statistics/analysis-explorer-library-standards.md) Phase 2). |

### 3. `ProjectionTimeSeriesQuery` — P2

| | |
|---|---|
| **Ist** | SQL aggregation for projection time series (482 LOC, sum CRAP 271, 43 % coverage). |
| **Problem** | **Technical:** overlaps AllocationRepository analytics; hard to test. |
| **Ziel** | Shared aggregation patterns or documented boundary between allocation DB vs projection DB queries. |
| **Kleinster Schritt** | Document call graph; extract one private method group into `ProjectionTimeSeriesBucketQuery`. |
| **Tests** | Integration tests against test DB with fixture projections. |

### 4. `ExplorerConfigMapper` — P2

| | |
|---|---|
| **Ist** | Config array ↔ domain DTO; schema upgrades; legacy field migration (657 LOC). |
| **Problem** | **Mixed:** business mapping + technical legacy upgrades. |
| **Ziel** | `ExplorerConfigUpgrader` for version steps; mapper only maps current schema. |
| **Kleinster Schritt** | Move `upgradeFromV*` methods to dedicated upgrader class. |
| **Tests** | Existing high coverage (88 %); characterization tests for upgrade paths. |

### 5. ViewModel factories (Statistics + Benchmarking) — P2

| | |
|---|---|
| **Ist** | `StatisticsPageViewModelFactory` and `BenchmarkComparisonPageViewModelFactory` (~584–586 LOC each, ccn 30). |
| **Problem** | **Technical:** duplicated scope/period/filter URL logic. |
| **Ziel** | `StatisticsScopeViewModelBuilder` shared service (~300 LOC each factory). |
| **Kleinster Schritt** | Extract period/scope URL builder used by both. |
| **Tests** | Functional smoke tests; unit tests for builder. |

### 6. ARCH-008 controllers — architecture debt (not size)

Five UI controllers still use `EntityManagerInterface` (baseline). Smaller than P1 hotspots but **clear layer violation** — good quick Phase 5 win after first P1 step.

## Deliberately not prioritized

- Small DTOs with high max CRAP due to 0 % coverage (metric artifact)
- EasyAdmin CRUD size (`AuditLogCrudController`, etc.)
- ARCH-004 `repositoryClass` on entities (accepted per ADR 007)
- `src/Admin` (excluded from coverage source)

## Reading order

1. **This document** — metrics and hotspot cards  
2. [refactoring-backlog.md](refactoring-backlog.md) — ordered Phase 5 PR list  
3. [deptrac.md](deptrac.md) — baseline strategy  
4. [target-architecture.md](target-architecture.md) — layer rules
