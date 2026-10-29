# Development fixtures

This document describes how demo and reference data is loaded for local development and tests. All fixture data is synthetic and built in code ([ADR 020](../02-architecture/decisions/020-portable-reference-catalog.md)); no curated data is stored in the repository.

## Overview

| Layer | Location | Purpose |
|---|---|---|
| Faker providers | `src/Allocation/Infrastructure/Faker/Provider/` | Synthetic reference data: Hesse geography, official PZC indications, departments, lookups, hospitals |
| Synthetic catalog | `SyntheticReferenceCatalogBuilder` | Composes the providers into a reference catalog package (seed, size) |
| Distribution patterns | `src/DataFixtures/Pattern/` | Segment patterns, indication groups and coherence rules for synthetic allocations |
| Generator | `src/DataFixtures/Generator/` | One hospital and month (allocations, MCI cases, closures), hospital profiles, scenarios, processing pipeline, `app:generate:*` commands ([synthetic-data-generator.md](synthetic-data-generator.md), [ADR 022](../02-architecture/decisions/022-synthetic-data-generation.md)) |
| Fixture classes | `src/DataFixtures/` | Loaders and groups; the allocation fixture runs the generator for the active hospitals of the preset |
| Foundry factories | `src/*/Infrastructure/Factory/` | Ad-hoc test data; lookup factories align with reference names where possible |

Factories live under `src/` but are registered only in `dev`/`test`/`demo` (see `config/services/foundry.yaml`). Attribute route loading scans `src/` with glob excludes for dev-only directories (`**/Infrastructure/Factory`, `**/Infrastructure/Faker`, `DataFixtures`, `**/Domain/Factory`) so `composer install --no-dev` can boot the kernel in production. The main `App\` service scan in `config/services.yaml` excludes the same directories (paths relative to that file, `../src/...`); the CI job "Prod kernel smoke" fails when a service from `App\DataFixtures` or a factory or Faker directory shows up in the prod container (#914).

## Loading fixtures

### Full dev dataset (default)

```bash
make reset
# or
symfony composer load-fixtures
# equivalent:
php bin/console doctrine:fixtures:load --group=dev --no-interaction --no-debug
php bin/console app:statistics:rebuild-closure-volume-projection
php bin/console app:statistics:refresh-mviews
```

`make fixtures`, `make reset` and `make install-dev` run the load and the rebuilds with `XDEBUG_MODE=off` (the closure rebuild of `realistic` took 221 s instead of 383 s with `xdebug.mode=develop`). To debug a fixture run on purpose, set the mode yourself: `XDEBUG_MODE=debug make reset`. Calling `composer load-fixtures` directly keeps your PHP's Xdebug mode; tests and coverage targets are not affected.

`make install-dev` runs the same load after creating the database. The load writes the data, export periods, data coverage, the allocation projection and the daily KPIs (`kpi_daily`); the two commands after it run the closure analysis with the closure volume projection and refresh the materialized views. They cannot run inside the load, because fixtures run in one database transaction and the closure rebuild takes a lock outside it. 

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

| Profile | Hospitals with allocations | Period | Allocations | Closed department-hours | Scenarios |
|---|---|---|---|---|---|
| `single` | 1, drawn with the seed | 12 months + current | ~4,000 | 1 % | none |
| `minimal` | 3: one Large (preferably Full), one Medium (Extended), one Small (Basic) | 12 months + current | ~25,000 | 1 % | none |
| `medium` (default) | 6: two of each size | 12 months + current | ~50,000 | 1 % | 1 MCI |
| `realistic` | 15: 3 Large, 6 Medium, 6 Small | 12 months + current | ~97,000 | 5 / 8 / 10 % by size | 1 flu wave in winter, 1 MCI |
| `large` (demo) | all 20 participating hospitals: 3 Large, 8 Medium, 9 Small | 24 months + current | ~247,000 (1,000 imports) | 5 / 8 / 10 % by size | per year 1 flu wave and 1 MCI, plus 1 icy-road day |

**Closures per preset.** `closure_share` in a preset sets the share of closed department-hours for all its hospitals (a number from 0 to below 1). The small presets set 1 %, so they load fast; `realistic` and `large` leave it out, so each hospital gets the share of its size from the structural patterns. An explicit closure share of a `ProfileSelection` (generation service of #877, demo wizard) wins over the preset. The closure volume rebuild grows with the number of closure intervals, which is why the dev default is `medium`.

The allocation counts are those of the base data; scenarios add a few percent on top (a flu wave about 25 % for 2–4 weeks).

`make reset` takes about 1½ minutes with the default `medium` (load about 60 s, closure rebuild about 20 s, about 1,300 closure intervals) and about 7 minutes with `realistic` (load about 85 s, closure rebuild about 340 s for about 26,000 closure intervals at the full closure shares; measured with #876, `FIXTURES_UNTIL=2026-09-30`). `large` takes longer still, mostly in the closure rebuild. The KPI aggregation inside the load adds about 0.1 s per upload day: about 5 s with `realistic`, about 10 s with `large`. The load runs without debug mode: in debug mode Symfony keeps every SQL query in memory (`large` then needs over 800 MB instead of about 100 MB). A preset can set its own `months`; the synthetic catalog has 20 participating hospitals, so no preset can have more.

The demo user `foo` owns one of the active hospitals, drawn with the seed: the same seed gives the same hospital, another seed another one. In `single` it owns the only one.

```bash
FIXTURES_PROFILE=minimal make reset
FIXTURES_PROFILE=single FIXTURES_SCALE=0.2 make reset    # fastest
FIXTURES_UNTIL=2026-09-30 make reset                     # fixed period, same data on every day
FIXTURES_PROFILE=large make reset                        # two years, every participating hospital
FIXTURES_PROFILE=realistic make reset                    # 15 hospitals with the full closure shares
FIXTURES_MONTHS=36 make reset                            # three years with the default preset
```

### Parameters

Dev defaults live in `config/packages/dev/fixtures.yaml` (the test environment uses `minimal` with small rates):

| Parameter | Default | Effect |
|---|---|---|
| `FIXTURES_PROFILE` | `medium` | Profile from `fixtures.profiles` (hospitals per size; `any` = drawn from all sizes; optional `months`, `closure_share`, `scenarios`) |
| `FIXTURES_SCALE` | `1` | Factor on the allocations per day (0.01–10); `1` is close to real volumes |
| `FIXTURES_UNTIL` | empty | Last day of the period (`Y-m-d`); empty means yesterday |
| `FIXTURES_MONTHS` | empty | Whole months before the month of the last day; empty uses the months of the preset or `fixtures.baseline.months` |
| `FIXTURES_REFERENCE_PACKAGE` | empty | Reference package (JSON) whose catalog replaces the synthetic one; states, dispatch areas and hospitals stay synthetic ([Generator on another catalog](synthetic-data-generator.md#generator-on-another-catalog)) |
| `FIXTURES_CATALOG_MAPPING` | empty | Mapping file (JSON) that binds the generator's names to a catalog that spells them differently |
| `FIXTURES_PATTERNS` | `defaults` | Distribution patterns: `defaults` (code defaults) or a pattern package, as a file name in `var/patterns/` or a path ([Pattern packages](synthetic-data-generator.md#pattern-packages)) |
| `fixtures.baseline.months` | `12` | Whole months before the month of the last day: with yesterday in October 2026 the data runs from 1 October 2025 until yesterday |
| `fixtures.baseline.trend_per_year` | `0.02` | Yearly growth of the daily rate, relative to 1 January 2026 (fixed, so a month added later gets the same volume); `0` is off |
| `fixtures.baseline.allocations_per_day` | Small `10`, Medium `15`, Large `40` | Average allocations per hospital and day by hospital size, rounded from real volumes; each hospital's profile scales it ([hospital profiles](synthetic-data-generator.md#hospital-profiles)) |
| `fixtures.baseline.mci_cases_per_hospital_and_year` | `2` | Expected MCI cases per active hospital and year, Poisson distributed per month |
| `fixtures.baseline.seed` | `740` | Seed for the hospital choice, the daily counts, sampling and MCI cases |
| `fixtures.baseline.rebuild_projection` | `true` | Rebuild `allocation_stats_projection` after allocation fixtures |

Each active hospital gets one import per month. The number of allocations per day varies around its rate: fewer at the weekend, a little more in winter, plus random noise, so totals look like real data. Hospital profiles (structure, departments, volume and case mix per hospital), distribution patterns, closures, scenarios and the generator commands are described in [synthetic-data-generator.md](synthetic-data-generator.md).

## Reference data

`ReferenceCatalogFixture` builds the synthetic catalog with `SyntheticReferenceCatalogBuilder` (seed 740, 60 hospitals) and loads it through the same validation and import as a real package (`app:reference:validate`, `app:reference:import`):

- Hesse with exactly the 24 dispatch areas the maps know; hospitals have addresses and coordinates inside their district.
- The 208 official PZC indications with their official wording; raw labels are the official wording, generated variants (abbreviation, typo) and a few unmapped labels.
- Departments and specialities with generated aliases, lookups and a few indication groups.

The same seed gives the same package byte for byte (UUIDv5 ids). Participation is not part of the catalog: `HospitalParticipationFixture` makes every third hospital participate and gives the demo user `foo` one of the active hospitals of the profile.

Production and staging must not run `doctrine:fixtures:load`. The local demo environment loads the same presets into its own database `app_demo`, with `large` as default ([../05-operations/demo-environment.md](../05-operations/demo-environment.md)). Use [../04-features/import/reference-catalog.md](../04-features/import/reference-catalog.md) to move the real catalog between environments.

## Private catalog: reset and restore

To test real IVENA files locally, replace the synthetic catalog with a private package (never committed; see [reference-catalog.md](../04-features/import/reference-catalog.md)):

1. `make reset`: synthetic installation.
2. Copy the private package to `var/reference/catalog.json`.
3. Stop the workers, then `php bin/console app:dev:reset-operational-data` (dev/test only): deletes allocations, imports, closures, projections, access grants, hospital owners and other data that refers to catalog rows; users and the catalog stay.
4. `php bin/console app:reference:import --mode=replace --dry-run`, then without `--dry-run`.
5. `php bin/console app:geo:geocode-hospitals` for missing coordinates, optionally isochrones.
6. Upload a real IVENA file and import it.

`make reset` returns to the synthetic installation.

This brings in the real hospitals as well. To keep fictional hospitals, for example to generate synthetic data on the real catalog, use the [composed catalog](synthetic-data-generator.md#composed-catalog) instead.

## Projection rebuild

After bulk allocation fixture loads, the projection table is rebuilt automatically when `rebuild_projection` is enabled; `composer load-fixtures` then rebuilds the closure analysis, the closure volume projection and the materialized views (`SyntheticDataPipeline`). The fixture also aggregates the daily KPIs of every upload day; unlike the closure rebuild this works inside the fixture transaction. `app:generate:imports` and `app:generate:scenario` run all of this for the imports they add, including the KPIs of their upload days. Manual rebuild:

```bash
php bin/console app:statistics:rebuild-projection
```

See [../04-features/statistics/projection-and-materialized-views.md](../04-features/statistics/projection-and-materialized-views.md) for the relationship between the projection table and materialized views.

## Purging in development

`doctrine:fixtures:load` purges the database before loading. In `dev` and `test`, a materialized-view-aware purger drops overview MVs before truncate and recreates them afterward (`MaterializedViewAwareOrmPurger`).

## Tests

Reference fixture coverage lives under `tests/DataFixtures/`:

- `Reference/SyntheticReferenceCatalogBuilderTest.php` — valid package, same bytes per seed, stable ids, no curated labels
- `Reference/ReferenceFixtureLoadTest.php` — end-to-end reference group load; two loads in one process give the same catalog
- `Allocation/HospitalParticipationFixtureTest.php` — participation and demo owner

Generator, pattern and structure tests: [synthetic-data-generator.md](synthetic-data-generator.md#tests).

Provider tests live in `tests/Allocation/Unit/Infrastructure/Faker/`.

The Faker providers draw from the global `mt_rand` state, unlike the generator's seeded streams. Faker's `Generator::__destruct()` reseeds that state at random, and a discarded generator is only freed by the cycle collector, because it and its providers reference each other. Code that seeds a generator and expects repeatable values (`SyntheticReferenceCatalogBuilder`, the provider tests) therefore calls `gc_collect_cycles()` right before `seed()` (#891).

Run:

```bash
php bin/phpunit --no-coverage tests/DataFixtures/
```

## Related documentation

- [../01-getting-started/local-setup.md](../01-getting-started/local-setup.md) — `make install-dev`, `make reset`
- [development-workflow.md](development-workflow.md) — daily commands
- [testing.md](testing.md) — test environment and MV refresh
- [../02-architecture/overview.md](../02-architecture/overview.md) — bounded contexts and commands
- [../02-architecture/decisions/020-portable-reference-catalog.md](../02-architecture/decisions/020-portable-reference-catalog.md) — synthetic data, private packages, restore
- [synthetic-data-generator.md](synthetic-data-generator.md) — hospital profiles, patterns, closures, scenarios, generator commands
- [../02-architecture/decisions/022-synthetic-data-generation.md](../02-architecture/decisions/022-synthetic-data-generation.md) — generator, hospital profiles, pattern packages, closures, scenario overlays
