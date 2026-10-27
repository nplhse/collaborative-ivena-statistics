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

## Volume configuration

### Profiles

`FIXTURES_PROFILE` chooses which participating hospitals receive synthetic allocations:

| Profile | Hospitals with allocations | Allocations (12 months + current) | `make reset` |
|---|---|---|---|
| `single` | 1, drawn with the seed | ~4,000 | ~25 s |
| `minimal` | 3: one Large (preferably Full), one Medium (Extended), one Small (Basic) | ~25,000 | ~35 s |
| `medium` | 6: two of each size | ~50,000 | ~45 s |
| `realistic` (default) | 15: 3 Large, 6 Medium, 6 Small | ~105,000 | ~70 s |

The demo user `foo` owns one of the active hospitals, drawn with the seed: the same seed gives the same hospital, another seed another one. In `single` it owns the only one.

```bash
FIXTURES_PROFILE=minimal make reset
FIXTURES_PROFILE=single FIXTURES_SCALE=0.2 make reset    # fastest
```

### Parameters

Dev defaults live in `config/packages/dev/fixtures.yaml` (the test environment uses `minimal` with small rates):

| Parameter | Default | Effect |
|---|---|---|
| `FIXTURES_PROFILE` | `realistic` | Profile from `fixtures.profiles` (hospitals per size; `any` = drawn from all sizes) |
| `FIXTURES_SCALE` | `1` | Factor on the allocations per day (0.01–10); `1` is close to real volumes |
| `fixtures.baseline.months` | `12` | Whole months before the current one: in October 2026 the data runs from 1 October 2025 until yesterday |
| `fixtures.baseline.allocations_per_day` | Small `10`, Medium `15`, Large `40` | Average allocations per hospital and day by hospital size, rounded from real volumes |
| `fixtures.baseline.mci_cases` | `25` | MCI demo cases |
| `fixtures.baseline.seed` | `740` | Seed for the hospital choice, the daily counts and sampling |
| `fixtures.baseline.rebuild_projection` | `true` | Rebuild `allocation_stats_projection` after allocation fixtures |

Each active hospital gets one import per month. The number of allocations per day varies around its rate: fewer at the weekend, a little more in winter, plus random noise, so totals look like real data. Each hospital is sampled with the default pattern of its segment (`urban-full` for full care, `rural-basic` for rural, `urban-basic` otherwise). Allocations are written in batches through DBAL.

## Reference data

`ReferenceCatalogFixture` builds the synthetic catalog with `SyntheticReferenceCatalogBuilder` (seed 740, 60 hospitals) and loads it through the same validation and import as a real package (`app:reference:validate`, `app:reference:import`):

- Hesse with exactly the 24 dispatch areas the maps know; hospitals have addresses and coordinates inside their district.
- The 208 official PZC indications with their official wording; raw labels are the official wording, generated variants (abbreviation, typo) and a few unmapped labels.
- Departments and specialities with generated aliases, lookups and a few indication groups.

The same seed gives the same package byte for byte (UUIDv5 ids). Participation is not part of the catalog: `HospitalParticipationFixture` makes every third hospital participate and gives the demo user `foo` one of the active hospitals of the profile.

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

`PatternAllocationFixture` samples allocations from `DefaultDistributionPatterns` (`PatternSampler`, `SyntheticAllocationGenerator`), with the pattern of each hospital's segment. The defaults are coarse, plausible shares keyed by the synthetic catalog, not exported real data; conditional patterns and real pattern packages follow with #792 and #797.

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
