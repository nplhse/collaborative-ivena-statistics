# Development fixtures

This document describes how demo and reference data is loaded for local development and tests. All fixture data is synthetic and built in code ([ADR 020](../02-architecture/decisions/020-portable-reference-catalog.md)); no curated data is stored in the repository.

## Overview

| Layer | Location | Purpose |
|---|---|---|
| Faker providers | `src/Allocation/Infrastructure/Faker/Provider/` | Synthetic reference data: Hesse geography, official PZC indications, departments, lookups, hospitals |
| Synthetic catalog | `SyntheticReferenceCatalogBuilder` | Composes the providers into a reference catalog package (seed, size) |
| Distribution patterns | `src/DataFixtures/Pattern/` | Segment patterns, indication groups and coherence rules for synthetic allocations |
| Generator | `src/DataFixtures/Generator/` | One hospital and month (allocations, MCI cases, closures), hospital profiles, scenarios, processing pipeline, `app:generate:*` commands ([ADR 022](../02-architecture/decisions/022-synthetic-data-generation.md)) |
| Fixture classes | `src/DataFixtures/` | Loaders and groups; the allocation fixture runs the generator for the active hospitals of the preset |
| Foundry factories | `src/*/Infrastructure/Factory/` | Ad-hoc test data; lookup factories align with reference names where possible |

Factories live under `src/` but are registered only in `dev`/`test` (see `config/services/foundry.yaml`). Attribute route loading scans `src/` with glob excludes for dev-only directories (`**/Infrastructure/Factory`, `**/Infrastructure/Faker`, `DataFixtures`, `**/Domain/Factory`) so `composer install --no-dev` can boot the kernel in production.

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

`make setup-dev` runs the same load after creating the database. The load writes the data, export periods, data coverage and the allocation projection; the two commands after it run the closure analysis with the closure volume projection and refresh the materialized views. They cannot run inside the load, because fixtures run in one database transaction and the closure rebuild takes a lock outside it. 

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

| Profile | Hospitals with allocations | Period | Allocations |
|---|---|---|---|
| `single` | 1, drawn with the seed | 12 months + current | ~4,000 |
| `minimal` | 3: one Large (preferably Full), one Medium (Extended), one Small (Basic) | 12 months + current | ~25,000 |
| `medium` | 6: two of each size | 12 months + current | ~50,000 |
| `realistic` (default) | 15: 3 Large, 6 Medium, 6 Small | 12 months + current | ~106,000 |
| `large` | all 20 participating hospitals: 3 Large, 8 Medium, 9 Small | 24 months + current | ~247,000 (1,000 imports, ~4,800 closure intervals) |

`make reset` takes about 2 minutes with `realistic` (load about 80 s, closure rebuild about 45 s) and about 4½ minutes with `large` (load about 130 s, closure rebuild about 130 s). The load runs without debug mode: in debug mode Symfony keeps every SQL query in memory (`large` then needs over 800 MB instead of about 100 MB). A preset can set its own `months`; the synthetic catalog has 20 participating hospitals, so no preset can have more.

The demo user `foo` owns one of the active hospitals, drawn with the seed: the same seed gives the same hospital, another seed another one. In `single` it owns the only one.

```bash
FIXTURES_PROFILE=minimal make reset
FIXTURES_PROFILE=single FIXTURES_SCALE=0.2 make reset    # fastest
FIXTURES_UNTIL=2026-09-30 make reset                     # fixed period, same data on every day
FIXTURES_PROFILE=large make reset                        # two years, every participating hospital
FIXTURES_MONTHS=36 make reset                            # three years with the default preset
```

### Parameters

Dev defaults live in `config/packages/dev/fixtures.yaml` (the test environment uses `minimal` with small rates):

| Parameter | Default | Effect |
|---|---|---|
| `FIXTURES_PROFILE` | `realistic` | Profile from `fixtures.profiles` (hospitals per size; `any` = drawn from all sizes) |
| `FIXTURES_SCALE` | `1` | Factor on the allocations per day (0.01–10); `1` is close to real volumes |
| `FIXTURES_UNTIL` | empty | Last day of the period (`Y-m-d`); empty means yesterday |
| `FIXTURES_MONTHS` | empty | Whole months before the month of the last day; empty uses the months of the preset or `fixtures.baseline.months` |
| `fixtures.baseline.months` | `12` | Whole months before the month of the last day: with yesterday in October 2026 the data runs from 1 October 2025 until yesterday |
| `fixtures.baseline.trend_per_year` | `0.02` | Yearly growth of the daily rate, relative to 1 January 2026 (fixed, so a month added later gets the same volume); `0` is off |
| `fixtures.baseline.allocations_per_day` | Small `10`, Medium `15`, Large `40` | Average allocations per hospital and day by hospital size, rounded from real volumes |
| `fixtures.baseline.mci_cases_per_hospital_and_year` | `2` | Expected MCI cases per active hospital and year, Poisson distributed per month |
| `fixtures.baseline.seed` | `740` | Seed for the hospital choice, the daily counts, sampling and MCI cases |
| `fixtures.baseline.rebuild_projection` | `true` | Rebuild `allocation_stats_projection` after allocation fixtures |

Each active hospital gets one import per month. The number of allocations per day varies around its rate: fewer at the weekend, a little more in winter, plus random noise, so totals look like real data. Each hospital is sampled with the default pattern of its segment (`urban-full` for full care, `rural-basic` for rural, `urban-basic` otherwise), adjusted by its profile (`HospitalProfileResolver`):

| | Cath lab | Resuscitation room cases |
|---|---|---|
| Full care | always | about 10 % |
| Extended care | Large 80 %, Medium 50 %, Small 30 % of the hospitals | 4–6 % |
| Basic care | Large 15 %, Medium 10 %, Small 5 % of the hospitals | 1–3 % |

The cath lab is drawn once per hospital with the seed; a hospital without one gets no cath lab cases. Allocations are written in batches through DBAL.

### Reproducible data

The generator draws from `RandomStreams` (`src/DataFixtures/Generator/Infrastructure/Random/`), never from `mt_rand()` or `random_int()`. Every hospital, month and kind of data (`volume`, `allocations`, `mci`) gets its own stream, seeded from the seed, the hospital's public id and the month ([ADR 022](../02-architecture/decisions/022-synthetic-data-generation.md)). The hospitals of the synthetic catalog have name-based public ids, so:

- the same seed and the same last day give the same data, compared by content (database ids change after every purge);
- a hospital gets the same rows whether other hospitals take part or not, and a month gets the same rows alone or inside a longer period.

MCI cases lie in the month of their hospital's import. `NoGlobalRandomnessTest` fails when code in `DataFixtures/Allocation`, `Generator` or `Pattern` calls a global random function.

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

`PatternAllocationFixture` samples allocations from `DefaultDistributionPatterns` (`PatternSampler`, `SyntheticAllocationGenerator`), with the pattern of each hospital's segment. The defaults are coarse, plausible shares keyed by the synthetic catalog, not exported real data; real pattern packages follow with #797.

The indication of a case is drawn first; the rest follows from it (`IndicationGroups`): a stroke goes to the stroke unit, a STEMI to cardiology with a high cath lab share, a paediatric case gets a child, a hip fracture an older, mostly female patient. The speciality is the field of the department. `CoherenceRules` then make sure that pregnant patients are women aged 15 to 49, paediatric departments get patients under 18, and CPR and shock mean urgency 1 (CPR also the resuscitation room).

`HospitalMonthGenerator` (`src/DataFixtures/Generator/`) writes one hospital and one month; the fixture runs it for every active hospital and month. Each import carries a marker in `filePath`, for example `synthetic/allocations/seed-740/defaults/<hospital public id>/2026-09.csv` (`SyntheticImportMarker`); no file exists at that path.

### Closures

Each hospital and month also gets a closure import (`synthetic/closures/…`) with `closure_interval` rows from `ClosurePlanner`: on average 8, 5 or 3 closure events per month for large, medium and small hospitals, a little more in autumn and winter. An event is an IVENA group (two to four departments with one group id), an exact-time cluster (two or three departments with the same start and end) or a single closure, so the closure analysis finds all three event types. Durations run from half an hour to at most 24 hours. Closures are planned before the allocations of the month: an allocation to a closed department during an interval gets `department_was_closed`, other allocations never do.

### Adding a month later

```bash
php bin/console app:generate:imports --hospital=<public id> --month=2026-10 --dry-run
php bin/console app:generate:imports --hospital=<public id> --hospital=<public id> --month=2026-10
```

The month gets the same rows as inside a full load with the same seed. A month after the last day (`FIXTURES_UNTIL` or yesterday) and a month that already has allocations, closures or a synthetic import for the hospital are refused; existing imports are never changed. The new imports run through the processing pipeline.

### Scenarios

Scenarios are laid over existing data and only add rows: per affected hospital their own import (`synthetic/scenario/<name>/seed-740/<hospital public id>/<start day>.csv`), plus a closure import when the scenario closes departments.

```bash
php bin/console app:generate:scenario                                    # list the scenarios
php bin/console app:generate:scenario icy-road --dispatch-area=Kassel --at=2026-01-14 --dry-run
php bin/console app:generate:scenario mci --hospital=<public id> --at=2026-02-03
```

| Scenario | Effect |
|---|---|
| `mci` | 8–20 MCI patients within 60–90 minutes, spread over the hospitals; urgency 1, resuscitation room, physician; one MCI id |
| `icy-road` | one day with about 45 % more allocations, mostly falls and fractures of older patients |
| `flu-wave` | 2–4 weeks with about 25 % more respiratory cases and extra internal medicine closures |
| `heat` | 3–5 days with about 30 % more collapses and internal emergencies |

The same scenario, start day and seed give the same rows; applying a scenario twice to a hospital and start day is refused. The imports run through the processing pipeline. To remove a scenario, delete its imports.

### Not built yet

[ADR 022](../02-architecture/decisions/022-synthetic-data-generation.md) also describes parts that follow when they are needed:

- offered departments, volume per bed and overrides by public id in the hospital profile;
- pattern packages as JSON files (with the export of real patterns, #797), case archetypes and obstetric indications;
- presets that apply scenarios, a configurable closure frequency and the KPI aggregation after a load.

`FIXTURES_PROFILE` keeps its name; the ADR calls its values *presets* to tell them apart from hospital profiles.

## Projection rebuild

After bulk allocation fixture loads, the projection table is rebuilt automatically when `rebuild_projection` is enabled; `composer load-fixtures` then rebuilds the closure analysis, the closure volume projection and the materialized views (`SyntheticDataPipeline`). `app:generate:imports` runs all of this for the months it adds. Manual rebuild:

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
- `Generator/ImportBatchPlannerTest.php` — daily counts, independent of other hospitals and months
- `Generator/HospitalMonthGeneratorTest.php` — a month added later equals the month of a full load, leaves other imports alone and is refused twice
- `Generator/SyntheticImportMarkerTest.php` — marker path round trip
- `Generator/HospitalProfileResolverTest.php` — cath lab by tier and size, same per seed, resuscitation room shares
- `Generator/ClosurePlannerTest.php` — valid intervals of at most 24 hours, all three event types, same per seed
- `Generator/ScenarioGeneratorTest.php`, `ScenarioCatalogTest.php` — overlays add only their rows, MCI patients, flu closures, refused twice, same plan per seed
- `Allocation/SyntheticAllocationGeneratorTest.php` — two generator runs on the same catalog give the same content, MCI cases in their month, cath lab cases only where the profile has one, `department_was_closed` exactly during a closure
- `Generator/*Test.php` — random streams, no global random functions
- `Pattern/*Test.php` — default patterns, indication groups, coherence rules, no impossible combinations in a sample of 1,500 cases

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
- [../02-architecture/decisions/022-synthetic-data-generation.md](../02-architecture/decisions/022-synthetic-data-generation.md) — generator, hospital profiles, pattern packages, closures, scenario overlays
