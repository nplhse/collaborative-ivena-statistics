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
| `FIXTURES_REFERENCE_PACKAGE` | empty | Reference package (JSON) whose catalog replaces the synthetic one; states, dispatch areas and hospitals stay synthetic ([Generator on another catalog](#generator-on-another-catalog)) |
| `FIXTURES_CATALOG_MAPPING` | empty | Mapping file (JSON) that binds the generator's names to a catalog that spells them differently |
| `FIXTURES_PATTERNS` | `defaults` | Distribution patterns: `defaults` (code defaults) or a pattern package, as a file name in `var/patterns/` or a path ([Pattern packages](#pattern-packages)) |
| `fixtures.baseline.months` | `12` | Whole months before the month of the last day: with yesterday in October 2026 the data runs from 1 October 2025 until yesterday |
| `fixtures.baseline.trend_per_year` | `0.02` | Yearly growth of the daily rate, relative to 1 January 2026 (fixed, so a month added later gets the same volume); `0` is off |
| `fixtures.baseline.allocations_per_day` | Small `10`, Medium `15`, Large `40` | Average allocations per hospital and day by hospital size, rounded from real volumes; each hospital's profile scales it (see below) |
| `fixtures.baseline.mci_cases_per_hospital_and_year` | `2` | Expected MCI cases per active hospital and year, Poisson distributed per month |
| `fixtures.baseline.seed` | `740` | Seed for the hospital choice, the daily counts, sampling and MCI cases |
| `fixtures.baseline.rebuild_projection` | `true` | Rebuild `allocation_stats_projection` after allocation fixtures |

Each active hospital gets one import per month. The number of allocations per day varies around its rate: fewer at the weekend, a little more in winter, plus random noise, so totals look like real data. Each hospital is sampled with the default pattern of its segment (`urban-full` for full care, `rural-basic` for rural, `urban-basic` otherwise), adjusted by its profile (`HospitalProfileResolver`):

Each hospital draws a **configuration** once, with the seed: a coherent bundle of resources (cath lab, neurosurgery, stroke unit, trauma level, obstetrics level, paediatrics) with a weight by tier and size (`DefaultStructurePatterns` in `src/DataFixtures/Structure/`). Resources are never drawn one by one, so a stroke unit always comes with neurology and a perinatal centre with paediatrics (`StructureRules`).

| Tier | Configurations (weight Small / Medium / Large) |
|---|---|
| Basic care | `basic-general` 55/40/30 %, `basic-obstetrics` 20 %, `basic-cardio` (cath lab) 10/20/25 %, `basic-stroke` 10/15/20 %, `basic-neurosurgery` 5 % |
| Extended care | `extended-general` (cath lab, stroke unit, regional trauma centre, obstetrics, paediatrics) 40/45/40 %, `extended-no-stroke` (no stroke unit, obstetrics or paediatrics) 25/20/10 %, `extended-perinatal` 5/15/25 %, `extended-neurosurgery` 5/10/20 %, `extended-no-cathlab` 25/10/5 % |
| Full care | `full-maximum` 70 %, `full-no-obstetrics` 30 % (both supraregional trauma centre, stroke unit, cath lab, neurosurgery, paediatrics) |

The configurations are manually curated, not sampled from data: coarse weights from general knowledge, rounded towards the aggregates of #874 where at least five hospitals stand behind a value. Uncommon combinations, such as a small basic-care hospital with neurosurgery or a cath lab, have small weights.

Departments follow from the configuration, in the department keys of the generator vocabulary (`GeneratorKeys`). Every hospital offers the core departments (`cardiology`, `gastroenterology`, `pulmonology`, `general-surgery`, `trauma-surgery`); a resource brings its departments (stroke unit → `stroke-unit` and `general-neurology`, neurosurgery → `neurosurgery`, obstetrics → `obstetrics`, perinatal centre → `prenatal-medicine` and `neonatology`, paediatrics → `general-paediatrics`). For every field (speciality key) the configuration then gives the number of further departments, drawn per hospital, and the hospital gets the most common ones of that field (`DepartmentStructure`). The resuscitation room share follows the trauma level (1 % none, 2 % basic, 5 % regional, 10 % supraregional).

**Capability gates.** An indication that needs a resource the hospital lacks is never drawn there; its share goes to the other codes: STEMI and cardiogenic shock (332, 346) need a cath lab, polytrauma (211, 212) a regional trauma centre, preterm births a perinatal centre. A hospital without a cath lab gets no cath lab cases. A hospital gets no allocations and no closures in a department it does not offer: an indication whose departments are all missing is drawn again (a hospital without obstetrics gets no births, one without neurology hardly any strokes), and after five tries the case goes to a core department. How much a hospital is closed is part of the profile too (see Closures). Allocations are written in batches through DBAL.

**Volume per hospital.** The preset rate of the size stays the anchor. Each hospital multiplies it by a bed factor, (beds ÷ reference beds of its size)^0.8 with reference beds Small 200, Medium 450, Large 950, and by a hospital factor drawn log-normally with mean 1 and spread 0.20. Hospitals of one size thus differ by about a fifth to a quarter, larger ones get more cases, and the preset totals stay about the same. Scenario extra cases are a share of the hospital's own rate.

**Case mix per hospital.** Each hospital also draws a tilt of its mix: one factor per indication category (first PZC digit, spread 0.25), one on the age bands from 65 on (spread 0.20) and one on urgency 1 (spread 0.35), all log-normal with mean 1. The factors multiply the weights of the segment pattern and the indication groups before a case is drawn, so hospitals of one tier differ in their share of trauma, internal medicine, old and urgent patients. Volume and tilt are drawn after configuration and departments, so they did not change the structures of step 1.

Spreads and the bed elasticity are coarse values rounded towards the aggregates of #874 (between-hospital variation within a size or tier, hospitals with at least five behind each value).

**Selecting a structure.** `HospitalProfileResolver::resolve()` and `HospitalMonthGenerator::plan()` / `generate()` take a `ProfileSelection` for targeted scenarios and the generation service of #877:

- a configuration by key (also one with weight 0 for the hospital's tier and size) and/or single resources on top of it, for example `new ProfileSelection(configuration: 'basic-neurosurgery')` or `new ProfileSelection(cathlab: true)`;
- tier, size and beds instead of those of the catalog hospital, for a new hospital of the #791 wizard; for an existing hospital they come from the catalog;
- an annual volume (allocations per year at the trend reference day): it **replaces** preset rate, bed factor, hospital factor and scale, it is not multiplied with them; weekday, month and trend factors still apply;
- a closure share (share of closed department-hours, default 5 / 8 / 10 % for Small / Medium / Large, general knowledge, not measured; see Closures).

An unknown key, an incoherent result (a supraregional trauma centre without neurosurgery) or figures out of range (beds or annual volume not above 0, a closure share outside 0 to below 1) are refused. Overrides by hospital public id in `fixtures.yaml` stay out (see below).

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

`PatternAllocationFixture` samples allocations from `DefaultDistributionPatterns` (`PatternSampler`, `SyntheticAllocationGenerator`), with the pattern of each hospital's segment. The defaults are coarse, plausible shares in the generator's vocabulary (the names of the synthetic catalog), not exported real data; real pattern packages follow with #797. Where to find the source of each table: [Sources of the allocation defaults](#sources-of-the-allocation-defaults). On another catalog the names are bound through [Generator on another catalog](#generator-on-another-catalog).

The indication of a case is drawn first; the rest follows from it (`IndicationGroups`): a stroke goes to the stroke unit, a STEMI to cardiology with a high cath lab share, a paediatric case gets a child, a hip fracture an older, mostly female patient, a pregnancy (PZC 52x–54x) a pregnant woman in obstetrics. Patterns and scenarios name indications by PZC code, not by wording, since the obstetric codes share theirs (`<16 SSW` is 521, 531 and 541). The speciality is the field of the department. `CoherenceRules` then make sure that pregnant patients are women aged 15 to 49, paediatric departments get patients under 18, and CPR and shock mean urgency 1 (CPR also the resuscitation room). `PatternCouplings` link the fields the segment tables keep flat to the case: traffic and work accident occasions only for injuries (PZC 2xx; a work accident occasion also sets the flag), influenza and COVID five times as often for respiratory codes (31x), weekend nights (0–5 h) 1.3 times as busy as weekday nights, 80 % of transport times rounded to five minutes and urgency 3 transport times × 0.8. Like the coherence rules they hold for every pattern source.

### Sources of the allocation defaults

Every value is a rounded default (whole percent or five percent steps, common values only), never derived from real data automatically. **General knowledge** means a plausible value without a measurement behind it. **Coarse aggregate** means a value nudged towards the all-hospital aggregates of the #874 assessment (more than 20 hospitals), never towards a value resting on fewer than five hospitals ([ADR 022](../02-architecture/decisions/022-synthetic-data-generation.md)). Real figures are not written down anywhere in the repository, only the defaults themselves (#909).

| Table | Where | Default | Source |
|---|---|---|---|
| Indication mix | `DefaultDistributionPatterns::indications` | about 40 common codes; injuries about 30 %, internal medicine about 45 %, neurology and psychiatry about 13 %, children, gynaecology and obstetrics a few percent; no 809; STEMI, polytrauma 1 %, ROSC, cardiogenic shock 0.5 % in every segment, kept away from hospitals without the capability by the gates | coarse aggregate (categories, which codes are common), general knowledge (weights per code) |
| Department per indication group | `IndicationGroups` | unchanged | general knowledge |
| Urgency per indication group | `IndicationGroups` | urgency 2 the common case (internal medicine 85 %, urgency 3 5–10 %); stroke and STEMI 90 % urgency 1, CPR 100 %; urgency 3 mainly for injuries | coarse aggregate (urgency 2 as default, urgency 3 rare in internal medicine), general knowledge (time-critical groups) |
| Age bands | `IndicationGroups` | 65–79, 80–89 and 90–99 instead of 65–99, fewer patients over 90 than in their 80s; children 1–17, a few in internal medicine | coarse aggregate |
| Sex | `PatternSampler::UNKNOWN_SEX_SHARE`, groups | "unknown" 0.1 %; female share per group unchanged | coarse aggregate |
| Flags | segment `flags`, group `flags` | ventilation 1 % base, shock and pregnancy only from their groups, physician 5 % base; chest pain cath lab 5 %, STEMI cath lab and physician 80 %; resus factor 0.5 for internal medicine, 5 for stroke | coarse aggregate (rounded), general knowledge (which groups set them) |
| Transport type | segment `transport_types` | air 1 % in full care, none in basic care | coarse aggregate |
| Secondary transport | segment `secondary_transports` | present in 2 % | coarse aggregate |
| Infection | segment `infections` | present in 5 %, "Sonstiges" 60 % of them; respiratory codes × 5 for influenza and COVID (`PatternCouplings`) | coarse aggregate (main value), general knowledge (other values, factor) |
| Assignment | segment `assignments` | RD 65, ZLST 15, Patient 8, Arzt/Arzt 5, Einweisung 5, Notzuweisung 2 % | coarse aggregate |
| Occasion | segment `occasions` | home 60, public 10, practice 5, other 3, secondary transfer 2, traffic accident 15, work accident 5 %; accidents only for injuries, so other cases come from home in three of four cases | coarse aggregate (home share), general knowledge (accidents) |
| Hour of day | segment `hours` | about 2 % per night hour, peak at 10–11 h, slow decline; weekend nights × 1.3 (`PatternCouplings`) | coarse aggregate |
| Transport time | segment `transport_time_minutes` | 15 / 20 / 25 / 35 minutes (p25–p90), rural p90 40; 80 % rounded to five minutes, urgency 3 × 0.8 | coarse aggregate |
| Seasonality | `ImportBatchPlanner` | unchanged, ±6 % | general knowledge |
| Structure, resuscitation room share, volume, closures | `DefaultStructurePatterns` | see [Parameters](#parameters) and [Closures](#closures) | see there |

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

Each hospital and month also gets a closure import (`synthetic/closures/…`) with `closure_interval` rows from `ClosurePlanner`. The hospital profile sets **how much** is closed: the share of closed department-hours of its offered departments, merged per department (default 5 / 8 / 10 % for small / medium / large hospitals, or the `closure_share` of the preset). The closure model of the structural patterns (`ClosureModel`) sets **how**:

| | Default |
|---|---|
| Departments per event (share of the offered ones) | one 45 %, few (10–25 %) 30 %, many (30–60 %) 15 %, most (70–100 %) 10 % |
| Duration | 30 min–2 h 40 %, 2–6 h 35 %, 6–24 h 15 %, a whole day from midnight 10 % |
| Care level per event | in-patient 45 %, emergency 30 %, out-patient 25 % |
| Weekday factor Mon … Sun | 1.15, 1.10, 1.0, 1.0, 1.10, 0.85, 0.80 |
| Field weight of the closed departments | internal medicine 1.5, neurology 1.2, paediatrics and obstetrics 0.7, others 1 |
| Multi-department events | 55 % IVENA groups, 45 % exact-time clusters |

All closure values are general knowledge, not measured: the reference data of #874 has closures of too few hospitals for values of their own. The number of events follows from share and model, `−ln(1 − share) × departments × hours ÷ (expected departments × expected hours of an event)`; the logarithm makes up for events that overlap on one department. Autumn and winter get somewhat more events, summer fewer, with the yearly mean unchanged. With 20 offered departments and 10 %, a hospital gets about 51 events and 230 closure intervals in a 30-day month (before: about 8 events and 10 intervals). A one-department event is a single closure, so the closure analysis still finds all three event types. Closures last at most 24 hours. Only departments the hospital offers are closed. Closures are planned before the allocations of the month: an allocation to a closed department during an interval gets `department_was_closed`, other allocations never do.

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

### Datasets for several hospitals and months

`app:generate:dataset` (dev, test and demo, #877) generates a shared period for one hospital, several hospitals or every participating hospital, each with its own volume and structure. It always records a generation run: allocations are written synchronously, projections follow in the Messenger worker as above.

```bash
php bin/console app:generate:dataset --all-participating --dry-run                    # plan only, writes nothing
php bin/console app:generate:dataset --hospital=<public id> --hospital=<public id>   # default period, see below
php bin/console app:generate:dataset --hospital=<public id> --from=2025-10 --to=2026-08 --annual-volume=24000 --closure-share=0.05
php bin/console app:generate:dataset --all-participating --profiles=var/demo/profiles.json --existing=replace --notify
php bin/console app:generate:dataset --retry=<run id>                                 # hospitals that failed during generation
```

- **Scope.** Exactly one of `--hospital` (repeatable) and `--all-participating`. "All" takes the hospitals marked as participating and nothing else.
- **Period.** Whole months. Without `--from`/`--to` it is `--months` (12) up to the month before last: on 28 October 2026 that is 2025-09 to 2026-08, and September stays free for the demo CSV upload (`app:demo:csv`). A month is generated up to yesterday at most.
- **Profiles.** Without a selection each hospital draws its profile from tier, size and seed (`HospitalProfileResolver`). `--annual-volume`, `--closure-share` and `--configuration` set a `ProfileSelection` for a single hospital; `--profiles` reads a JSON object `{"<public id>": {"annual_volume": 24000, "closure_share": 0.05, "cathlab": false}}` for several (fields: `configuration`, `cathlab`, `neurosurgery`, `stroke_unit`, `trauma_level`, `obstetrics`, `paediatrics`, `tier`, `size`, `beds`, `annual_volume`, `closure_share`). Hospital master data is never changed.
- **Checks before writing.** Environment, pattern package and catalog, the period, and per hospital: participating, an owner with participant access, dispatch area and state, a usable profile, no other run still writing it. A blocked hospital refuses the whole run and the plan names what is missing; `--skip-blocked` leaves blocked hospitals out instead, and the summary lists them with the reason.
- **Existing data (`--existing`).** Real imports (every import that is not a synthetic marker, such as uploads and `app:demo:csv`) are never touched: a hospital with real data in the period is blocked in every mode.

  | Mode | Generated months in the period | Scenario imports in the period |
  |---|---|---|
  | `refuse` (default) | block the hospital | block the hospital |
  | `fill` | complete months stay, missing and incomplete months are generated | stay if they lie on kept months, otherwise block |
  | `replace` | deleted and generated again (any seed) | deleted |

  Deleting uses the regular import deletion (rows, MCI cases, projection rows); the run then rebuilds the hospital's projections and the KPIs of the old and new upload days. Other hospitals are not touched. With the same seed a replaced month gets the same rows.
- **Reproducible.** The run records seed, period, last day, mode, pattern source, preset and scale, and the profile of each hospital (`app:generate:run:show`). `generate()` reads only these, and refuses a different `FIXTURES_PROFILE` or `FIXTURES_SCALE`.
- **Failures and retry.** Each month is written in one transaction. A hospital that fails is rolled back as a whole (its months of this run are deleted again) and reported with its reason; the other hospitals go on. `--retry=<run id>` starts a new run for those hospitals with the recorded settings in mode `fill`, so nothing is written twice. A run whose process stopped is retried the same way once it has been idle for 30 minutes; complete months it left behind are kept and projected by the new run. Hospitals that failed in the asynchronous stage keep their data: `app:generate:run:retry` projects them again.

The demo wizard (#791) uses the same service, `DatasetGenerator`: `prepare(DatasetRequest)` returns the plan, `start(DatasetPlan)` records the run, `generate(runId)` writes it; `GenerationRunTracker::get(runId)` shows the progress. See [ADR 023](../02-architecture/decisions/023-demo-environment.md#dataset-engine-877).

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

- closures driven by the share of closed department-hours (#876);
- structural pattern packages (`ivena-structure-patterns`, #906, with the structural export of #797); hospital structures come from the code defaults (`DefaultStructurePatterns`) until then;
- overrides of the profile by hospital public id in `fixtures.yaml` (cath lab, departments, volume, closure frequency). Left out on purpose (#863): public ids follow the catalog seed, so overrides break when the catalog changes, and the demo does not need them;
- the export of real patterns (#797), and case archetypes;
- the pattern source in scenario markers: `synthetic/scenario/<name>/seed-740/<hospital public id>/<start day>.csv` has no source slot, although scenario rows are sampled with the selected patterns. Refusing a scenario twice and `--remove` look at name, seed and start day only, so they work whatever the source.

`FIXTURES_PROFILE` keeps its name; the ADR calls its values *presets* to tell them apart from hospital profiles.

### Limitations

The data looks plausible in totals, weekly rhythm and the indication-driven fields, but it does not model everything that real exports show (assessment in #874). Structural hospital profiles (#876) and pattern packages (#883) address most of it:

- **Hospitals differ only coarsely.** Volume follows beds and a hospital factor, and the mix of each hospital is tilted by category, old age and urgency 1. Within a category the mix of codes still follows the segment, and the segment ignores size: a medium full-care hospital gets the mix of a large one, tilted.
- **About 40 PZC codes.** Urology, ENT, ophthalmology, dermatology (7xx) and transport codes (6xx) are not drawn: the generator vocabulary has no departments for them yet. Neither are allocations without a normalized indication or with a secondary indication.
- **Few dependencies outside the indication.** Department, urgency, age, sex and flags follow from the indication; occasion, infection, hour and transport time are linked to the case only by the few rules of `PatternCouplings`. Assignment, transport type and secondary transport stay independent, the hour does not depend on the case, and ages are uniform inside their bands.
- **Closures follow a coarse model.** Share, event size, durations, care levels and weekdays are general-knowledge defaults. Closures do not depend on the day's load, and one event closes one care level. `department_was_closed` is set only for allocations inside a generated interval.
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
- `Generator/ImportBatchPlannerTest.php` — daily counts, independent of other hospitals and months; the profile's rate (volume factor, explicit annual volume) replaces the preset rate
- `Generator/HospitalMonthGeneratorTest.php` — a month added later equals the month of a full load (upload time included), leaves other imports alone and is refused twice; `kpi_daily` matches the imports of every upload day
- `Generator/UploadTimeTest.php` — upload one to three days after the data, during office hours, never after the period
- `Generator/SyntheticImportMarkerTest.php` — marker path round trip
- `Generator/PresetScenarioTest.php` — scenario counts per period; the `medium` preset spreads one MCI over 2–4 hospitals, its rows are in the projection and the KPIs, same seed same placement
- `Generator/HospitalProfileResolverTest.php` — configurations by tier and size (cath lab shares, uncommon ones possible), coherent departments for every draw, field counts, explicit selection and refusal, capability gates, resuscitation room shares, same per seed, closure share; volume by beds (2 ^ 0.8 for twice the reference beds), explicit annual volume, spread of hospital factors and category tilt, tier and size of a new hospital, figures out of range
- `Structure/StructureRulesTest.php` — code defaults coherent and weights summing to 1, the structure uses the generator keys with fields = speciality keys, incoherent configurations and incomplete sets reported
- `Structure/VolumeAndTiltTest.php` — bed factor and tilt of categories, old age and urgency 1 with hand-checkable numbers
- `Generator/ClosurePlannerTest.php` — number of events from the share by hand (ten departments, 10 %, 30 days: 47.74 events), the merged closed share of a year matches 5 % and 10 %, no closures without a share, valid intervals of at most 24 hours, whole days from midnight, all three event types, sizes and care levels, more Monday than Sunday events, same per seed, only offered departments
- `Generator/ScenarioGeneratorTest.php`, `ScenarioCatalogTest.php` — overlays add only their rows, are uploaded after their days and counted in the KPIs, MCI patients, flu closures, intensity, removal restores counts, projection and KPIs, refused twice, same plan per seed
- `Generator/Catalog/InterchangeableCatalogTest.php` — a second synthetic catalog with other labels, public ids, insert order and two normalized indications for one code: the composed catalog keeps the synthetic hospitals, the check lists exactly the renamed names, the load stops before the first row without mapping, with mapping the rows use only the mapped catalog and are the same whatever the database ids; ambiguous aliases, pinned codes, unknown mapping entries, `app:generate:check-catalog`
- `Generator/Catalog/CatalogMappingTest.php`, `GeneratorVocabularyTest.php` — mapping file format and errors, the vocabulary of every source
- `Allocation/SyntheticAllocationGeneratorTest.php` — two generator runs on the same catalog give the same content, MCI cases in their month, cath lab cases only where the profile has one, allocations and closures only in offered departments, no indication the hospital lacks the capability for, `department_was_closed` exactly during a closure
- `Generator/*Test.php` — random streams, no global random functions
- `Pattern/Package/*Test.php` — the code defaults round-trip through writer, reader and validator unchanged, same bytes per package, departments by generator key, invalid packages name table and key, the loader (`defaults`, a file in `var/patterns/`, invalid and missing files), `app:patterns:*` commands with the catalog check
- `Generator/HospitalMonthGeneratorTest.php` also: the code defaults written as a package give the same rows, the markers record the checksum, an invalid package writes nothing
- `Pattern/*Test.php` — default patterns, indication groups (urgency 2 as the common case, age bands, polytrauma apart from other multiple injuries), coherence rules, no impossible combinations in a sample of 1,500 cases, codes with the same wording stay apart, only offered departments
- `Pattern/PatternCouplingsTest.php` — hand-checkable couplings: accident occasions only for injuries, influenza × 5 for respiratory codes, weekend nights × 1.3, rounding and urgency 3 transport times; `DefaultDistributionPatternsTest` checks them on sampled cases, with severe codes in basic care only where the capability allows them

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
