# Development fixtures

This document describes how demo and reference data is loaded for local development and tests. All fixture data is synthetic and built in code ([ADR 020](../02-architecture/decisions/020-portable-reference-catalog.md)); no curated data is stored in the repository.

## Overview

| Layer | Location | Purpose |
|---|---|---|
| Faker providers | `src/Allocation/Infrastructure/Faker/Provider/` | Synthetic reference data: Hesse geography, official PZC indications, departments, lookups, hospitals |
| Synthetic catalog | `SyntheticReferenceCatalogBuilder` | Composes the providers into a reference catalog package (seed, size) |
| Distribution patterns | `DefaultDistributionPatterns` | Plain default distributions for synthetic allocations |
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
| `reference` | Users (dependency) and the complete synthetic reference catalog |
| `participation` | Reference catalog plus participating hospitals, owners and the demo grant |
| `allocations` | Participation plus pattern-based synthetic allocations and imports |
| `content` | English demo CMS pages |
| `minimal` | Small scenario for fast smoke tests |
| `dev` | Reference + allocations + content (full local demo) |
| `statistics` | Reference data, participation and allocations used by statistics integration tests |

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
| `fixtures.baseline.pattern` | `urban-full` | Default distribution pattern (`urban-full`, `urban-basic`, `rural-basic`) |
| `fixtures.baseline.rebuild_projection` | `true` | Rebuild `allocation_stats_projection` after allocation fixtures |

Scale is resolved by `FixtureVolumeResolver` and applied in `PatternAllocationFixture`.

## Reference data

`ReferenceCatalogFixture` builds the synthetic catalog with `SyntheticReferenceCatalogBuilder` (seed 740, 60 hospitals) and loads it through the same validation and import as a real package (`app:reference:validate`, `app:reference:import`):

- Hesse with exactly the 24 dispatch areas the maps know; hospitals have addresses and coordinates inside their district.
- The 208 official PZC indications with their official wording; raw labels are the official wording, generated variants (abbreviation, typo) and a few unmapped labels.
- Departments and specialities with generated aliases, lookups and a few indication groups.

The same seed gives the same package byte for byte (UUIDv5 ids). Participation is not part of the catalog: `HospitalParticipationFixture` makes every third hospital participate, plus one Full Urban hospital owned by the demo user `foo`.

Production and staging must not run `doctrine:fixtures:load`. Use [../04-features/import/reference-catalog.md](../04-features/import/reference-catalog.md) to move the real catalog between environments.

## Private catalog: reset and restore

To test real IVENA files locally, replace the synthetic catalog with a private package (never committed; see [reference-catalog.md](../04-features/import/reference-catalog.md)):

1. `make reset`: synthetic installation.
2. Copy the private package to `var/reference/catalog.json`.
3. Stop the workers, then `php bin/console app:dev:reset-operational-data` (dev/test only): deletes allocations, imports, closures, projections, access grants, hospital owners and other data that refers to catalog rows; users and the catalog stay.
4. `php bin/console app:reference:import --mode=replace --dry-run`, then without `--dry-run`.
5. `php bin/console app:geo:geocode-hospitals` for missing coordinates, optionally isochrones.
6. Upload a real IVENA file and import it.

`make reset` returns to the synthetic installation.

## Pattern-based synthetic allocations

`PatternAllocationFixture` samples allocations from `DefaultDistributionPatterns` (`PatternSampler`, `SyntheticAllocationGenerator`). The defaults are coarse, plausible shares keyed by the synthetic catalog, not exported real data; conditional patterns and real pattern packages follow with #792 and #797.

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

- `Reference/SyntheticReferenceCatalogBuilderTest.php` — valid package, same bytes per seed, stable ids, no curated labels
- `Reference/ReferenceFixtureLoadTest.php` — end-to-end reference group load
- `Allocation/HospitalParticipationFixtureTest.php` — participation and demo owner
- `Pattern/*Test.php` — default patterns and sampling

Provider tests live in `tests/Allocation/Unit/Infrastructure/Faker/`.

Run:

```bash
php bin/phpunit --no-coverage tests/DataFixtures/
```

## Related documentation

- [../01-getting-started/local-setup.md](../01-getting-started/local-setup.md) — `make setup-dev`, `make reset`
- [development-workflow.md](development-workflow.md) — daily commands
- [testing.md](testing.md) — test environment and MV refresh
- [../02-architecture/overview.md](../02-architecture/overview.md) — bounded contexts and commands
- [../02-architecture/decisions/020-portable-reference-catalog.md](../02-architecture/decisions/020-portable-reference-catalog.md) — synthetic data, private packages, restore
