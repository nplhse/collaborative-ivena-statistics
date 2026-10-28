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

Factories live under `src/` but are registered only in `dev`/`test`/`demo` (see `config/services/foundry.yaml`). Attribute route loading scans `src/` with glob excludes for dev-only directories (`**/Infrastructure/Factory`, `**/Infrastructure/Faker`, `DataFixtures`, `**/Domain/Factory`) so `composer install --no-dev` can boot the kernel in production.

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

| Profile | Hospitals with allocations | Period | Allocations | Scenarios |
|---|---|---|---|---|
| `single` | 1, drawn with the seed | 12 months + current | ~4,000 | none |
| `minimal` | 3: one Large (preferably Full), one Medium (Extended), one Small (Basic) | 12 months + current | ~25,000 | none |
| `medium` | 6: two of each size | 12 months + current | ~50,000 | 1 MCI |
| `realistic` (default) | 15: 3 Large, 6 Medium, 6 Small | 12 months + current | ~106,000 | 1 flu wave in winter, 1 MCI |
| `large` | all 20 participating hospitals: 3 Large, 8 Medium, 9 Small | 24 months + current | ~247,000 (1,000 imports, ~4,800 closure intervals) | per year 1 flu wave and 1 MCI, plus 1 icy-road day |

The allocation counts are those of the base data; scenarios add a few percent on top (a flu wave about 25 % for 2–4 weeks).

`make reset` takes about 2 minutes with `realistic` (load about 80 s, closure rebuild about 45 s) and about 4½ minutes with `large` (load about 130 s, closure rebuild about 130 s). These times were measured before presets applied scenarios (#865); the scenarios add a few percent of rows. The KPI aggregation inside the load adds about 0.1 s per upload day: about 5 s with `realistic`, about 10 s with `large`. The load runs without debug mode: in debug mode Symfony keeps every SQL query in memory (`large` then needs over 800 MB instead of about 100 MB). A preset can set its own `months`; the synthetic catalog has 20 participating hospitals, so no preset can have more.

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
| `FIXTURES_REFERENCE_PACKAGE` | empty | Reference package (JSON) whose catalog replaces the synthetic one; states, dispatch areas and hospitals stay synthetic ([Generator on another catalog](#generator-on-another-catalog)) |
| `FIXTURES_CATALOG_MAPPING` | empty | Mapping file (JSON) that binds the generator's names to a catalog that spells them differently |
| `FIXTURES_PATTERNS` | `defaults` | Distribution patterns: `defaults` (code defaults) or a pattern package, as a file name in `var/patterns/` or a path ([Pattern packages](#pattern-packages)) |
| `fixtures.baseline.months` | `12` | Whole months before the month of the last day: with yesterday in October 2026 the data runs from 1 October 2025 until yesterday |
| `fixtures.baseline.trend_per_year` | `0.02` | Yearly growth of the daily rate, relative to 1 January 2026 (fixed, so a month added later gets the same volume); `0` is off |
| `fixtures.baseline.allocations_per_day` | Small `10`, Medium `15`, Large `40` | Average allocations per hospital and day by hospital size, rounded from real volumes |
| `fixtures.baseline.mci_cases_per_hospital_and_year` | `2` | Expected MCI cases per active hospital and year, Poisson distributed per month |
| `fixtures.baseline.seed` | `740` | Seed for the hospital choice, the daily counts, sampling and MCI cases |
| `fixtures.baseline.rebuild_projection` | `true` | Rebuild `allocation_stats_projection` after allocation fixtures |

Each active hospital gets one import per month. The number of allocations per day varies around its rate: fewer at the weekend, a little more in winter, plus random noise, so totals look like real data. Each hospital is sampled with the default pattern of its segment (`urban-full` for full care, `rural-basic` for rural, `urban-basic` otherwise), adjusted by its profile (`HospitalProfileResolver`):

| | Cath lab | Resuscitation room cases | Offered departments |
|---|---|---|---|
| Full care | always | about 10 % | nearly all |
| Extended care | Large 80 %, Medium 50 %, Small 30 % of the hospitals | 4–6 % | most; stroke unit, neurosurgery, neonatology only in some |
| Basic care | Large 15 %, Medium 10 %, Small 5 % of the hospitals | 1–3 % | the core and a few more |

The cath lab and the offered departments are drawn once per hospital with the seed; a hospital without a cath lab gets no cath lab cases. Every hospital offers the core departments (cardiology, gastroenterology, pulmonology, general and trauma surgery); each further department has a chance by tier, larger hospitals a higher one. A hospital gets no allocations and no closures in a department it does not offer: an indication whose departments are all missing is drawn again (a hospital without obstetrics gets no births, one without neurology hardly any strokes), and after five tries the case goes to a core department. The closure frequency is part of the profile too (8, 5 or 3 events per month by size). Allocations are written in batches through DBAL.

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

This brings in the real hospitals as well. To keep fictional hospitals, for example to generate synthetic data on the real catalog, use the composed catalog below instead.

## Generator on another catalog

The generator writes its own vocabulary, independent of any catalog ([ADR 022](../02-architecture/decisions/022-synthetic-data-generation.md)):

| Type | Key | Example | Where |
|---|---|---|---|
| Department | stable English key | `stroke-unit`, `cardiology`, `obstetrics` | `GeneratorKeys::DEPARTMENTS` (25 keys, each with its default label and speciality key) |
| Speciality | stable English key | `neurology`, `paediatrics` | `GeneratorKeys::SPECIALITIES` (7 keys) |
| Assignment, occasion, infection, secondary transport | the IVENA value | `RD`, `Häuslicher Einsatz`, `MRSA` | `DefaultDistributionPatterns` |
| Indication | PZC code | `421` | patterns, `IndicationGroups`, scenarios |

Patterns and profiles should address departments by key. `GeneratorCatalog` binds each key to the installed catalog: through the mapping file if it binds the key, else by the default label (the name in the synthetic catalog), resolved the way the import resolves names: by name and alias, case and spacing ignored. Where the catalog has several rows for one code, the choice follows the name, never the database id. The generator's sources still name departments by their default label; they are translated to keys in one place (`GeneratorVocabulary`, `PatternLookupResolver`).

Before the first row, the allocation fixture, `app:generate:imports` and `app:generate:scenario` check every name and code and stop with the full list of missing and ambiguous ones. The same report, read-only:

```bash
php bin/console app:generate:check-catalog
php bin/console app:generate:check-catalog --mapping=var/reference/generator-mapping.json --all
```

### Composed catalog

`FIXTURES_REFERENCE_PACKAGE` loads the synthetic states, dispatch areas and hospitals together with every other type from a package, for example a private export ([ADR 020](../02-architecture/decisions/020-portable-reference-catalog.md)). Hospital identities and locations stay synthetic, real files resolve their departments, indications and lookups, and the generator runs on the real catalog:

1. Copy the private package to `var/reference/catalog.json` (never committed; a complete export, or one with every catalog type except state, dispatch area and hospital).
2. Find the names the catalog spells differently: load only the catalog, then check it.

   ```bash
   FIXTURES_REFERENCE_PACKAGE=var/reference/catalog.json php bin/console doctrine:fixtures:load --group=reference --no-interaction
   php bin/console app:generate:check-catalog
   ```

   Bind each missing name in `var/reference/generator-mapping.json` (below) and check again with `--mapping=var/reference/generator-mapping.json` until it passes.
3. Load the fixtures with both files; the load stops before the first allocation if a name is still missing:

```bash
FIXTURES_REFERENCE_PACKAGE=var/reference/catalog.json FIXTURES_CATALOG_MAPPING=var/reference/generator-mapping.json make reset
```

`make reset` without the variables returns to the synthetic catalog. Every load replaces the database, as always.

### Mapping file

Keys are keys of the generator (department and speciality keys, lookup values, PZC codes), values names in the installed catalog. Every part is optional; an entry for a key the generator does not use is an error, so typos show up.

```json
{
    "format": "ivena-generator-catalog-mapping",
    "version": 1,
    "names": {
        "department": { "stroke-unit": "<catalog department>" },
        "speciality": { "paediatrics": "<catalog speciality>" },
        "assignment": {}, "occasion": {}, "infection": {}, "secondary_transport": {}
    },
    "speciality_of_department": { "stroke-unit": "<catalog speciality>" },
    "indications": { "211": { "normalized": "<catalog name>", "raw": "<catalog label>" } }
}
```

- `names` binds a key to a catalog name, per type. Without an entry, a key resolves by its default label.
- `speciality_of_department` sets the speciality a department is written with. Without it, it is the speciality key of the department (`paediatrics` for `neonatology`), resolved through `names.speciality`.
- `indications` pins the normalized indication of a code when the catalog has several (otherwise the first by name is used, with a warning), and the raw label (otherwise the one with the official wording, else the first by label).

A mapping for a real catalog holds real labels: keep it in `var/reference/` next to the package and never commit it.

### Limits on a real catalog

The binding makes the generator write valid rows of another catalog; it does not make the data look like that catalog. A real catalog is finer: more departments and specialities (urology, ENT, ophthalmology, dermatology and others of their own) and more occasions and infections than the generator knows. The generator still uses only its 25 department keys and the lookup values of its patterns, so a real catalog's other departments and values get no generated rows. Wider coverage belongs to the pattern packages (#883, #797) and structural profiles (#876).

## Pattern-based synthetic allocations

`PatternAllocationFixture` samples allocations from `DefaultDistributionPatterns` (`PatternSampler`, `SyntheticAllocationGenerator`), with the pattern of each hospital's segment. The defaults are coarse, plausible shares in the generator's vocabulary (the names of the synthetic catalog), not exported real data; real pattern packages follow with #797. On another catalog the names are bound through [Generator on another catalog](#generator-on-another-catalog).

The indication of a case is drawn first; the rest follows from it (`IndicationGroups`): a stroke goes to the stroke unit, a STEMI to cardiology with a high cath lab share, a paediatric case gets a child, a hip fracture an older, mostly female patient, a pregnancy (PZC 52x–54x) a pregnant woman in obstetrics. Patterns and scenarios name indications by PZC code, not by wording, since the obstetric codes share theirs (`<16 SSW` is 521, 531 and 541). The speciality is the field of the department. `CoherenceRules` then make sure that pregnant patients are women aged 15 to 49, paediatric departments get patients under 18, and CPR and shock mean urgency 1 (CPR also the resuscitation room).

`HospitalMonthGenerator` (`src/DataFixtures/Generator/`) writes one hospital and one month; the fixture runs it for every active hospital and month. Each import carries a marker in `filePath`, for example `synthetic/allocations/seed-740/defaults/<hospital public id>/2026-09.csv` (`SyntheticImportMarker`); no file exists at that path.

An import is "uploaded" one to three days after the last day it covers, during office hours, and never after the last day of the period (`UploadTime`, drawn with the seed). Its `created_at` is that upload time, so the import list and the KPI aggregation, which counts imports by upload day, show a monthly upload rhythm instead of one day with every import. The running month is uploaded on the last day.

### Pattern packages

A pattern package is the same distributions as a JSON file: format `ivena-distribution-patterns`, version 1 (`src/DataFixtures/Pattern/Package/`, [ADR 022](../02-architecture/decisions/022-synthetic-data-generation.md#pattern-packages)). Packages live in `var/patterns/` and are never tracked; tests write synthetic packages only.

```text
{
    "meta": {
        "format": "ivena-distribution-patterns", "version": 1,
        "created_at": "2026-10-28T12:00:00+01:00", "source": "defaults", "catalog": "synthetic",
        "counts": { "segments": 3, "indication_groups": 25, "indications": 29 },
        "checksum": { "algorithm": "sha256", "value": "…" }
    },
    "segments": {
        "urban-full": {
            "indications": { "present": 1.0, "shares": { "322": 0.0885, "…": 0.0 } },
            "occasions": { "Häuslicher Einsatz": 0.5, "…": 0.0 },
            "assignments": { … }, "infections": { "present": 0.05, "shares": { … } },
            "secondary_transports": { "present": 0.1, "shares": { … } },
            "transport_types": { "G": 0.95, "A": 0.05 }, "hours": { "0": 0.0225, "…": 0.0 },
            "flags": { "requires_resus": 0.1, … },
            "transport_time_minutes": { "p25": 10.0, "p50": 15.0, "p75": 22.0, "p90": 30.0 }
        },
        "urban-basic": { … }, "rural-basic": { … }
    },
    "indication_groups": {
        "332": { "departments": { "cardiology": 1.0 }, "urgency": { "1": 0.9, "2": 0.1 },
                 "ages": { "18-39": 0.05, "40-64": 0.45, "65-99": 0.5 }, "female": 0.3,
                 "resus_factor": 3.0, "flags": { "requires_cathlab": 0.9, "is_with_physician": 0.7 } }
    },
    "fallback_group": "3"
}
```

`segments` holds what `DefaultDistributionPatterns` sets per segment, `indication_groups` what `IndicationGroups` sets per PZC code prefix (the longest matching prefix wins, `fallback_group` takes the rest). Departments are keyed by the generator's catalog-independent keys (`GeneratorKeys`, for example `cardiology`, `stroke-unit`), lookups by their IVENA value and indications by PZC code, so a package works with any catalog the generator can bind. The checksum is SHA-256 over everything except `meta`.

`PatternPackageReader` validates a package completely, in this order, and every error names the table and the key (`indication_groups.332.departments`):

1. format and version;
2. structure: known keys only, every key present, numbers where numbers belong, shares and probabilities between 0 and 1;
3. package rules: the three segments the generator uses, department keys of `GeneratorKeys` (a catalog name such as `Kardiologie` is refused with the key to use), three-digit PZC codes, transport types `G`/`A`, hours 0–23, urgency 1–3, age ranges `min-max` up to 120, known flags (`requires_resus` of a group is its `resus_factor`), every table summing to 1 (±0.002, for shares rounded to four places), percentiles that do not decrease, counts and checksum;

The code defaults pass the same validation; the PHP classes stay their source. Whether the installed catalog has every name and PZC code of a package is the catalog check of the generator ([Generator on another catalog](#generator-on-another-catalog)): it runs with the vocabulary of the selected pattern set, through the mapping file.

```bash
php bin/console app:patterns:write-defaults                  # var/patterns/defaults.json (--output, --force)
php bin/console app:patterns:validate defaults.json          # a file name in var/patterns/ or a path
php bin/console app:patterns:validate defaults.json --no-catalog   # format only, no database
```

Both commands exist in `dev` and `test` only. Without `--no-catalog`, `app:patterns:validate` also runs the catalog check of the generator with the package's patterns.

**Selecting a package.** `FIXTURES_PATTERNS` selects the patterns of the fixture, `--patterns` overrides it for `app:generate:imports` and `app:generate:scenario`:

```bash
FIXTURES_PATTERNS=tuned.json make reset
php bin/console app:generate:imports --hospital=<public id> --month=2026-09 --patterns=tuned.json
```

The package is read and validated, and the catalog check runs with its names and PZC codes, before the first row is written; an invalid package or a missing name stops the run with every problem. Coherence rules, hospital profiles, closures and the random streams are the same whatever the source, so the same package and seed give the same rows, and the code defaults written as a package give exactly the rows of `defaults`. The allocation and closure markers record the source: `defaults`, or the package checksum (`synthetic/allocations/seed-740/<checksum>/<hospital public id>/2026-09.csv`). The slot is built by `PatternSource`; structural patterns (#876) will add a part after `+` when they are not the defaults. A month that exists with any source is refused.

### Closures

Each hospital and month also gets a closure import (`synthetic/closures/…`) with `closure_interval` rows from `ClosurePlanner`: on average 8, 5 or 3 closure events per month for large, medium and small hospitals, a little more in autumn and winter. An event is an IVENA group (two to four departments with one group id), an exact-time cluster (two or three departments with the same start and end) or a single closure, so the closure analysis finds all three event types. Durations run from half an hour to at most 24 hours. Only departments the hospital offers are closed. Closures are planned before the allocations of the month: an allocation to a closed department during an interval gets `department_was_closed`, other allocations never do.

### Adding a month later

```bash
php bin/console app:generate:imports --hospital=<public id> --month=2026-10 --dry-run
php bin/console app:generate:imports --hospital=<public id> --hospital=<public id> --month=2026-10
```

The month gets the same rows as inside a full load with the same seed. A month after the last day (`FIXTURES_UNTIL` or yesterday) and a month that already has allocations, closures or a synthetic import for the hospital are refused; existing imports are never changed. The new imports run through the processing pipeline.

### Generation runs

With `--async` the command records a generation run and leaves the projections to a Messenger worker instead of waiting for them; `--notify` also notifies the owner of each hospital in-app once its data is fully processed. Without these options nothing is tracked and the pipeline runs synchronously as before.

```bash
php bin/console app:generate:imports --hospital=<public id> --month=2026-10 --async --notify
php bin/console messenger:consume async_priority_low       # projects the run
php bin/console app:generate:run:list
php bin/console app:generate:run:show <run id>             # newest run without an id
php bin/console app:generate:run:retry <run id>            # failed or stuck hospitals, no new data
```

Per hospital the worker rebuilds the allocation projection of its imports and then its closure analysis and closure volume; once no hospital is open, it refreshes the materialized views and KPIs and ends the run as `completed` or `failed`. Model, ordering and retries: [ADR 023](../02-architecture/decisions/023-demo-environment.md#generation-runs-791-step-3).

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

An MCI spreads its patients by tier and size: full care and large hospitals take more, every hospital at least one. `--intensity` (0.5–2.0, default 1) scales extra cases, MCI patients and closures. `--remove` deletes every import of the scenario that starts on `--at` (with the configured seed or `--seed`) through the regular import deletion, then rebuilds the closure volume, the views and the KPIs of the upload days:

```bash
php bin/console app:generate:scenario flu-wave --dispatch-area=Kassel --at=2026-01-05 --intensity=1.5
php bin/console app:generate:scenario flu-wave --at=2026-01-05 --remove
```

The same scenario, start day, seed and intensity give the same rows; applying a scenario twice to a hospital and start day is refused. The imports run through the processing pipeline.

**Scenarios in presets.** `scenarios` in `fixtures.profiles` lays scenarios over the base data of a preset: name → applications per year, scaled to the period and at least one. `PresetScenarioPlanner` places them with the seed: the period is split into one part per application, a flu wave or icy road starts on a winter day of its part (heat on a summer day) if the part has one. An MCI takes 2–4 hospitals, those of one dispatch area with at least two active hospitals first, then the nearest of other areas; a flu wave takes every active hospital; other scenarios the active hospitals of one dispatch area. Projections and KPIs follow with the rest of the load. To remove a preset scenario, look up its start day in the import list (`Scenario <name> <start day>`) and use `--remove`.

### Not built yet

[ADR 022](../02-architecture/decisions/022-synthetic-data-generation.md) also describes parts that follow when they are needed:

- volume per bed in the hospital profile;
- overrides of the profile by hospital public id in `fixtures.yaml` (cath lab, departments, volume, closure frequency). Left out on purpose (#863): public ids follow the catalog seed, so overrides break when the catalog changes, and the demo does not need them;
- the export of real patterns (#797), and case archetypes;
- the pattern source in scenario markers: `synthetic/scenario/<name>/seed-740/<hospital public id>/<start day>.csv` has no source slot, although scenario rows are sampled with the selected patterns. Refusing a scenario twice and `--remove` look at name, seed and start day only, so they work whatever the source.

`FIXTURES_PROFILE` keeps its name; the ADR calls its values *presets* to tell them apart from hospital profiles.

### Limitations

The data looks plausible in totals, weekly rhythm and the indication-driven fields, but it does not model everything that real exports show (assessment in #874). Structural hospital profiles (#876) and pattern packages (#883) address most of it:

- **Hospitals look alike.** Every hospital of one size gets the same daily rate, whatever its beds. Hospitals of one tier and location share one segment pattern, so apart from their offered departments and cath lab their indication, age and urgency mix hardly differ. The segment ignores size: a medium full-care hospital gets the mix of a large one.
- **Severity follows the segment, not the capabilities.** STEMI, polytrauma, resuscitation and cardiogenic shock codes appear only in full-care hospitals, even when another hospital has a cath lab.
- **About 30 PZC codes.** Urology, ENT, ophthalmology, dermatology (7xx) and transport codes (6xx) are not drawn, so the departments of those fields never get allocations; neither do allocations without a normalized indication or with a secondary indication.
- **Few dependencies outside the indication.** Department, urgency, age, sex and flags follow from the indication. Occasion, infection, assignment, transport type, secondary transport and transport time do not (a traffic accident can bring a chest pain patient). The hour of day depends neither on the weekday nor on the case; transport time depends on the hospital's location only. Ages are uniform inside wide bands, for example 65–99.
- **Closures are small and rare.** A closure event closes one to four departments from a fixed list. Events that close most departments of a hospital at once, closures by care level for the same department, and a closure frequency given as the share of closed department-hours are not modelled. `department_was_closed` is set only for allocations inside a generated interval.
- **Fixed lookups.** Assignment, occasion and infection use one flat distribution per segment and only part of the catalog's values.

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
- `Generator/ImportBatchPlannerTest.php` — daily counts, independent of other hospitals and months
- `Generator/HospitalMonthGeneratorTest.php` — a month added later equals the month of a full load (upload time included), leaves other imports alone and is refused twice; `kpi_daily` matches the imports of every upload day
- `Generator/UploadTimeTest.php` — upload one to three days after the data, during office hours, never after the period
- `Generator/SyntheticImportMarkerTest.php` — marker path round trip
- `Generator/PresetScenarioTest.php` — scenario counts per period; the `medium` preset spreads one MCI over 2–4 hospitals, its rows are in the projection and the KPIs, same seed same placement
- `Generator/HospitalProfileResolverTest.php` — cath lab and offered departments by tier and size, same per seed, resuscitation room shares, closure frequency
- `Generator/ClosurePlannerTest.php` — valid intervals of at most 24 hours, all three event types, same per seed, only offered departments
- `Generator/ScenarioGeneratorTest.php`, `ScenarioCatalogTest.php` — overlays add only their rows, are uploaded after their days and counted in the KPIs, MCI patients, flu closures, intensity, removal restores counts, projection and KPIs, refused twice, same plan per seed
- `Generator/Catalog/InterchangeableCatalogTest.php` — a second synthetic catalog with other labels, public ids, insert order and two normalized indications for one code: the composed catalog keeps the synthetic hospitals, the check lists exactly the renamed names, the load stops before the first row without mapping, with mapping the rows use only the mapped catalog and are the same whatever the database ids; ambiguous aliases, pinned codes, unknown mapping entries, `app:generate:check-catalog`
- `Generator/Catalog/CatalogMappingTest.php`, `GeneratorVocabularyTest.php` — mapping file format and errors, the vocabulary of every source
- `Allocation/SyntheticAllocationGeneratorTest.php` — two generator runs on the same catalog give the same content, MCI cases in their month, cath lab cases only where the profile has one, allocations and closures only in offered departments, `department_was_closed` exactly during a closure
- `Generator/*Test.php` — random streams, no global random functions
- `Pattern/Package/*Test.php` — the code defaults round-trip through writer, reader and validator unchanged, same bytes per package, departments by generator key, invalid packages name table and key, the loader (`defaults`, a file in `var/patterns/`, invalid and missing files), `app:patterns:*` commands with the catalog check
- `Generator/HospitalMonthGeneratorTest.php` also: the code defaults written as a package give the same rows, the markers record the checksum, an invalid package writes nothing
- `Pattern/*Test.php` — default patterns, indication groups, coherence rules, no impossible combinations in a sample of 1,500 cases, codes with the same wording stay apart, only offered departments

Provider tests live in `tests/Allocation/Unit/Infrastructure/Faker/`.

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
- [../02-architecture/decisions/022-synthetic-data-generation.md](../02-architecture/decisions/022-synthetic-data-generation.md) — generator, hospital profiles, pattern packages, closures, scenario overlays
