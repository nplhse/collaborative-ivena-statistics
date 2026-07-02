# Development fixtures

This document describes how demo and reference data is loaded for local development and tests. The legacy `App\Seed` module and its `app:seed:*` commands have been removed in favour of Doctrine fixtures with versioned YAML files.

## Overview

| Layer | Location | Purpose |
|---|---|---|
| Reference YAML | `fixtures/reference/*.yaml` | Curated master data (areas, hospitals, lookups, indications) |
| Distribution patterns | `fixtures/patterns/*.yaml` | Statistical profiles for synthetic allocations |
| Fixture classes | `src/DataFixtures/` | Loaders, groups, synthetic allocation generator |
| Foundry factories | `src/*/Infrastructure/Factory/` | Ad-hoc test data; lookup factories align with reference names where possible |

Factories live under `src/` but are registered only in `dev`/`test` (see `config/services/foundry.yaml`). Attribute route loading scans `src/` with glob excludes for dev-only directories (`**/Infrastructure/Factory`, `**/Infrastructure/Faker`, `DataFixtures`, `**/Domain/Factory`) so `composer install --no-dev` can boot the kernel in production.

## Loading fixtures

### Full dev dataset (default)

```bash
make reset
# or
symfony composer load-fixtures
# equivalent:
php bin/console doctrine:fixtures:load --group=dev --no-interaction
```

`make setup-dev` runs the same load after creating the database.

### Partial groups

| Group | Contents |
|---|---|
| `reference` | Users (dependency), areas, hospitals, lookups, indications |
| `geo` | Areas only (subset of reference) |
| `hospitals` | Hospitals (requires areas) |
| `lookups` | Departments, specialities, assignments, occasions, infections, secondary transports |
| `indications` | Normalized and raw indications with automatic linking |
| `allocations` | Pattern-based synthetic allocations and imports |
| `content` | English demo CMS pages |
| `minimal` | Small scenario for fast smoke tests |
| `dev` | Reference + allocations + content (full local demo) |
| `statistics` | Reference data used by statistics integration tests |

Examples:

```bash
php bin/console doctrine:fixtures:load --group=reference --no-interaction
php bin/console doctrine:fixtures:load --group=minimal --no-interaction
```

## Volume and pattern configuration

Dev defaults live in `config/packages/dev/fixtures.yaml`:

| Parameter | Default | Effect |
|---|---|---|
| `FIXTURES_SCALE` | `1` | Multiplier for imports, allocations, MCI cases (1–10) |
| `fixtures.baseline.hospitals_active` | `15` | Participating hospitals used for synthetic data |
| `fixtures.baseline.imports` | `20` | Number of import batches |
| `fixtures.baseline.allocations` | `5000` | Total synthetic allocations |
| `fixtures.baseline.mci_cases` | `25` | MCI demo cases |
| `fixtures.baseline.pattern` | `urban-full` | Distribution pattern from `fixtures/patterns/` |
| `fixtures.baseline.rebuild_projection` | `true` | Rebuild `allocation_stats_projection` after allocation fixtures |

Scale is resolved by `FixtureVolumeResolver` and applied in `PatternAllocationFixture`.

## Reference data

YAML files under `fixtures/reference/` are the single source of truth for master data. They are loaded by:

- `AreaReferenceFixture`
- `HospitalReferenceFixture`
- `LookupReferenceFixture`
- `IndicationReferenceFixture`

`ReferenceDataLoader` persists entities and maintains a `ReferenceRegistry` for hospital/area resolution during synthetic data generation.

Indication raw rows are linked to normalized entries by hash after load (`IndicationKey`).

## Pattern-based synthetic allocations

`PatternAllocationFixture` uses committed pattern files (`fixtures/patterns/manifest.yaml` lists available patterns). Sampling is handled by `PatternSampler` and `SyntheticAllocationGenerator`.

### Maintainer commands

```bash
# Validate pattern YAML (sums, required distributions)
php bin/console app:fixtures:validate-patterns

# Export patterns from production or staging data (requires DB with allocations)
php bin/console app:fixtures:export-patterns --pattern=urban-full
```

## Projection rebuild

After bulk allocation fixture loads, the projection table is rebuilt automatically when `rebuild_projection` is enabled. Manual rebuild:

```bash
php bin/console app:statistics:rebuild-projection
```

See [../04-features/statistics/projection-and-materialized-views.md](../04-features/statistics/projection-and-materialized-views.md) for the relationship between the projection table and materialized views.

## Purging in development

`doctrine:fixtures:load` purges the database before loading. In `dev` and `test`, a materialized-view-aware purger drops overview MVs before truncate and recreates them afterward (`MaterializedViewAwareOrmPurger`).

## Tests

Reference fixture coverage lives under `tests/DataFixtures/`:

- `Reference/ReferenceYamlLoaderTest.php` — YAML content and counts
- `Reference/ReferenceRegistryTest.php` — area/hospital registry
- `Reference/ReferenceDataLoaderTest.php` — persistence and indication linking
- `Reference/ReferenceFixtureLoadTest.php` — end-to-end reference group load
- `Pattern/*Test.php` — pattern validation and sampling

Run:

```bash
php bin/phpunit --no-coverage tests/DataFixtures/
```

## Related documentation

- [../01-getting-started/local-setup.md](../01-getting-started/local-setup.md) — `make setup-dev`, `make reset`
- [development-workflow.md](development-workflow.md) — daily commands
- [testing.md](testing.md) — test environment and MV refresh
- [../02-architecture/overview.md](../02-architecture/overview.md) — bounded contexts and commands
