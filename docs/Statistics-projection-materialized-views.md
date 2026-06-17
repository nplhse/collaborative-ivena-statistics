# Statistics projection and materialized views

This document explains how allocation statistics are stored, how PostgreSQL materialized views fit into the read path, and how database resets work in the **test** environment (Zenstruck Foundry).

## Data layers

Statistics reads are built on two PostgreSQL layers:

| Layer | Relation | Role |
|-------|----------|------|
| **Projection table** | `allocation_stats_projection` | Denormalized facts per allocation (hospital, state, dispatch area, urgency, etc.). Rebuilt from imports via `AllocationStatsProjectionRebuilder`. |
| **Materialized views** | `mv_projection_*` | Pre-aggregated snapshots derived from the projection table for fast overview and scope checks. |

The projection table is the **source of truth** for analytics queries that scan raw facts. Materialized views are **caches**: they must be refreshed after the projection changes, or reads will see stale counts and hospital lists.

### Overview materialized views

Registered under group `overview` in `StatisticsMaterializedViewGroups`:

| View | Purpose |
|------|---------|
| `mv_projection_state_hospital_count` | Distinct hospital count per `state_id` |
| `mv_projection_dispatch_area_hospital_count` | Distinct hospital count per `dispatch_area_id` |
| `mv_projection_hospital_dimensions` | One row per `hospital_id` with state, dispatch area, location, and tier codes |

Doctrine maps these views to read-only entities under `App\Statistics\Infrastructure\Entity\Projection*`.

They are created by migration `Version20260519125102` (with unique indexes required for `REFRESH MATERIALIZED VIEW CONCURRENTLY` in production).

## Read path in the application

Typical flow:

1. Controllers resolve filters (`StatisticsFilterFactory`, `ComparisonScopeResolver`).
2. Scope resolution may use **materialized views** (e.g. `CountDistinctHospitalsForStateQuery`, `GetDistinctHospitalIdsByStateQuery`) to enforce rules such as “state scope requires at least two hospitals”.
3. Dashboard and overview queries often read from the views for performance.
4. Deeper or legacy comparisons may still use `AllocationStatsProjectionScopeQuery`, which queries `allocation_stats_projection` directly.

Because of this split, **the projection table and the materialized views can disagree** until a refresh runs. That is expected; only the projection is updated automatically on import rebuild.

## Production and development operations

### Rebuild projection data

After imports or loading allocation fixtures:

```bash
php bin/console app:statistics:rebuild-projection
```

Or rely on the async handler that calls `AllocationStatsProjectionRebuilder::rebuildForImport()` per import.

Rebuilding the projection **does not** refresh materialized views.

### Refresh materialized views

After bulk projection changes:

```bash
# All registered groups (currently: overview)
php bin/console app:statistics:refresh-mviews

# Overview group only
php bin/console app:statistics:refresh-mviews --overview
```

Implementation: `MaterializedViewRefresher` (DBAL `Connection`). The console command uses **`REFRESH MATERIALIZED VIEW CONCURRENTLY`** by default (requires the unique indexes from the migration).

If views are missing after a fresh database, run Doctrine migrations first; the migration creates the views and runs an initial non-concurrent refresh.

### Install / repair views in test only

`OverviewMaterializedViewsInstaller::ensureInstalled()` recreates overview views when they are missing **only in `APP_ENV=test`**. In other environments it throws and directs you to migrations or `app:statistics:refresh-mviews --overview`.

## Why tests need special handling

### DAMA Doctrine Test Bundle (transaction rollback)

Tests use [DAMA Doctrine Test Bundle](https://github.com/dmaicher/doctrine-test-bundle) together with Foundry. Each test method runs inside a database transaction that is rolled back automatically when the test finishes. That replaces the previous per-test schema drop/recreate cycle and is the main reason the DB test suite runs much faster.

Implications for materialized views:

- **Schema reset** (drop + recreate + MV install) runs **once per PHPUnit process** when the first `#[ResetDatabase]` test class starts — not before every test method.
- **MV data** refreshed inside a test (via `RefreshesStatisticsMaterializedViewsTrait`) is rolled back with the test transaction; the view definitions persist.
- **MV refresh after projection rebuild remains mandatory** when assertions use MV-backed queries or `StatisticsFilterFactory` scope rules.

Configuration: `config/packages/dama_doctrine_test_bundle.yaml`, PHPUnit extension in `phpunit.dist.xml`.

### Foundry `ResetDatabase` and PostgreSQL

Tests use [Zenstruck Foundry](https://github.com/zenstruck/foundry) with ORM reset mode **`migrate`** in `test` (`config/packages/zenstruck_foundry.yaml`). Without DAMA, Foundry would run before almost every test method:

```text
doctrine:database:drop --force
doctrine:database:create
doctrine:migrations:migrate
```

With DAMA enabled, Foundry's `DamaDatabaseResetter` performs that migration reset only **once per PHPUnit/ParaTest worker** at the start of the run; subsequent tests rely on transaction rollback.

Overview materialized views are created by migration `Version20260519125102`. The test-only `MaterializedViewAwareOrmResetter` calls `OverviewMaterializedViewsInstaller::ensureInstalled()` after the migrate reset as a safety net when migrations did not run the view DDL.

### Test-only reset decorator

Registered only when `APP_ENV=test` (`config/services/foundry_test.yaml`):

**`MaterializedViewAwareOrmResetter`** decorates `Zenstruck\Foundry\ORM\ResetDatabase\OrmResetter` (inner to Foundry's `DamaDatabaseResetter`, `decoration_priority: 20`) and wraps each migrate reset:

1. **Before reset** — `OverviewMaterializedViewsInstaller::resetInstallationState()` clears the in-memory “already installed” flag.

2. **Foundry reset** — inner resetter drops the database, recreates it, and runs migrations (DAMA disables static connections before this step).

3. **After reset** — `OverviewMaterializedViewsInstaller::ensureInstalled()` recreates the overview views if they are absent (normally already created by migrations).

This wiring does **not** run in `prod` or `dev`; it does not change runtime behavior outside tests.

### Refresh after test fixtures

Foundry creates allocations and rebuilds the projection in many integration tests. That updates `allocation_stats_projection` but leaves materialized views at their **previous snapshot** (often empty right after reset).

Symptoms in tests:

- `AllocationStatsProjectionScopeQuery` returns correct counts (reads the table).
- `StatisticsFilterFactory` downgrades `state` scope to `public` because `CountDistinctHospitalsForStateQuery` reads the view and sees `0` hospitals.

**Fix in tests:** refresh views after rebuilding projection data.

Use the shared trait:

```php
use App\Tests\Support\MaterializedView\RefreshesStatisticsMaterializedViewsTrait;

final class MyStatisticsTest extends KernelTestCase
{
    use RefreshesStatisticsMaterializedViewsTrait;

    public function testSomething(): void
    {
        // ... create data, rebuild projection ...
        $this->refreshStatisticsMaterializedViews();
        // ... assertions using MV-backed queries or filter factory ...
    }
}
```

The trait calls `MaterializedViewRefresher` with `concurrently: false` (plain `REFRESH MATERIALIZED VIEW`), which is appropriate for the isolated test database.

Alternatively:

```php
self::getContainer()
    ->get(OverviewMaterializedViewsInstaller::class)
    ->refreshIfInstalled();
```

## Component reference

| Component | Location | Notes |
|-----------|----------|--------|
| View registry | `StatisticsMaterializedViewGroups` | Groups and view names |
| Drop before reset | `StatisticsMaterializedViewDropper` | Test reset hook (DBAL) |
| Foundry decorator | `App\Tests\Support\Foundry\MaterializedViewAwareOrmResetter` | Test env only |
| Install / refresh helper | `OverviewMaterializedViewsInstaller` | Test install + `refreshIfInstalled()` |
| Refresh service | `MaterializedViewRefresher` | Used by console command and test trait |
| Console command | `app:statistics:refresh-mviews` | Production refresh |
| Projection rebuilder | `AllocationStatsProjectionRebuilder` | Does not refresh MVs |
| Test trait | `RefreshesStatisticsMaterializedViewsTrait` | `tests/Support/MaterializedView/` |

## Related documentation

- [Architecture.md](Architecture.md) — controller and query overview
