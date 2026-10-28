# ADR 022: Synthetic data generation with hospital profiles, pattern packages and scenario overlays

**Status:** accepted

## Context

Development, tests and demonstrations need a database with plausible IVENA data that contains no real hospitals, patients or curated reference data (issue #792). ADR 020 provides the synthetic reference catalog: Hesse with its dispatch areas, 60 synthetic hospitals, the official PZC list, departments, specialities and lookups, all deterministic per seed. It assigns the pattern system, hospital profiles and scenarios to #792.

After #740 the fixtures already generate realistic volumes: presets choose how many hospitals of each size take part (`FIXTURES_PROFILE`: `single`, `minimal`, `medium`, `realistic`), each active hospital gets one import per month, and the daily count varies by size, weekday, month and random noise. Allocations are written in DBAL batches. Several things are missing:

- Every feature is sampled independently from three segment patterns, which produces impossible combinations (a pregnant 80-year-old man, CPR with urgency 3, a paediatric case aged 70).
- Volume depends on hospital size only, departments and resources on the care tier only. A resuscitation room or a catheterization laboratory cannot be configured per hospital.
- There are no closures. `department_was_closed` is a random flag without any relation to a closure.
- The generator is tied to the fixture: it reads the fixture configuration, requires the demo user, generates everything in one run and cannot add one month for one hospital later.
- All randomness comes from one global `mt_srand()` sequence. The same configuration reproduces the same data, but adding a hospital or a month shifts every later value.
- Generated imports have no export period and no data coverage, and only the allocation projection is rebuilt. Closure analysis, the closure volume projection and the materialized views are not.

The offline demo environment (#791) will reuse the generator, and the export of real patterns (#797) will produce pattern packages for it.

## Decision

### Placement

The generator lives in the existing dev/test module `src/DataFixtures/`. It is registered only in `dev` and `test` (`config/services/foundry.yaml`) and is excluded from the main service scan and the route scan, so a production install without dev dependencies never loads it.

| Directory | Content |
|-----------|---------|
| `DataFixtures/Generator/Application/` | settings, presets, hospital profiles, planners, the month and dataset generators, scenarios |
| `DataFixtures/Generator/Infrastructure/` | DBAL writers, random streams |
| `DataFixtures/Generator/UI/Console/` | `app:generate:imports`, `app:generate:scenario` |
| `DataFixtures/Pattern/` | pattern package format, validation, chain sampler, coherence rules, code defaults |
| `DataFixtures/Structure/` | structural patterns of hospitals: configurations, resources, department structure over the generator keys, structure rules, code defaults (#876) |
| `DataFixtures/Reference/` | synthetic reference catalog (ADR 020) |
| `DataFixtures/Allocation/`, `Scenario/`, `Content/`, … | Doctrine fixtures; they call the generator and hold only fixture concerns such as the demo user |

It is not a bounded context of its own. It is a development tool that writes to the tables of other modules, like the fixtures before. #791 registers the same module for the `demo` environment.

### Terms

- A **preset** chooses the size of a dataset: how many hospitals of each size, how many months, the scale factor and the scenarios to apply. The existing `FIXTURES_PROFILE` variable and the `fixtures.profiles` parameter keep their names for compatibility. Code and documentation call them presets.
- A **hospital profile** describes how one synthetic hospital behaves (see below).
- A **segment** is a hospital type that a pattern package is keyed by: care tier and location. It is never a single hospital.

### Settings and the generator API

The settings are seed, `until`, number of months, scale, the volume rates and a yearly trend. They come from the fixture configuration (`FixtureVolume`); further settings such as the pattern source are added with the steps that need them. A preset selects the hospitals and may set its own number of months and its scenarios; the closure share belongs to the hospital profile. The trend is relative to a fixed day (1 January 2026), so a month added later gets the same volume as inside a full load.

- `HospitalMonthGenerator` generates one hospital and one month: one allocation import (and, once closures exist, one closure import). It refuses a month after the last day of the period and a month that already has a synthetic import of that type or allocations of any import for the hospital.
- The allocation fixture runs the month generator for all selected hospitals and months, then rebuilds the projection once.
- `app:generate:imports` (dev and test) adds months later: hospitals by public id, one month, an optional seed and a dry run that only reports counts. It rebuilds the projection of each new import. The interactive wizard of #791 is not part of it; other generators join the `app:generate:` namespace (`app:generate:scenario`, `app:generate:encoding-csv`).

### Deterministic random streams

The generator never uses global random state (`mt_srand()`, `mt_rand()`, `random_int()`). Each unit of work gets its own `Random\Randomizer` with the `Mt19937` engine, seeded from a hash of the seed, the hospital's public id, the month and the kind of data (`volume`, `allocations`, `closures`, `mci`, `upload`, `profile`, `scenario:<name>`; preset scenarios are placed from a stream of their own). Hospital X in month M therefore gets the same rows whether it is generated alone, together with other hospitals or as part of a longer period. This is what makes adding a month later reproducible.

Time comes from `ClockInterface` (ADR 021). The generated period ends at `until`, which defaults to the end of yesterday; a fixed `until` gives a fixed dataset. The same settings, pattern package and seed give the same data, compared by content, since database ids change after every purge.

### Hospital profiles

A `HospitalProfile` belongs to the generator configuration, not to the reference package (ADR 020). It holds:

- the segment,
- the volume, as allocations per day or per bed and year,
- the departments and specialities the hospital offers,
- the resources it offers, each independent of the care tier: resuscitation room, catheterization laboratory and others,
- the closure share: the share of closed department-hours.

Default profiles are derived deterministically from tier, size and location; the defaults keep the per-size daily rates of the presets.

- The structure is drawn once per hospital when the data is generated, from its own stream (`profile`), as one **configuration** of the structural patterns (#876): a coherent bundle of resources (catheterization laboratory, neurosurgery, stroke unit, trauma level, obstetrics level, paediatrics) with a weight by tier and size. Tier and size influence the weights but exclude nothing: uncommon configurations, such as a small basic-care hospital with neurosurgery, have small weights. A hospital without a catheterization laboratory gets no cath lab cases.
- Resuscitation room cases happen in every hospital; the share follows the trauma level and is small without a trauma centre.
- Every hospital offers the core departments (cardiology, gastroenterology, pulmonology, general and trauma surgery). A resource brings its departments (a stroke unit brings stroke medicine and general neurology). For each field the configuration gives the number of further departments, drawn per hospital, filled with the most common departments of the field.
- **Capability gates:** an indication that needs a resource the hospital lacks is not drawn there (STEMI without cath lab, polytrauma without regional trauma centre, preterm birth without perinatal centre); its share goes to the other codes. Gates replace "severe codes only in the full-care segment" as the rule; the segments themselves are unchanged.
- The closure share is part of the profile; by default it follows the size (5, 8 or 10 % of the department-hours for small, medium and large hospitals, general knowledge, not measured).

- **Volume per hospital (#876):** the preset rate of the size stays the anchor, multiplied by a bed factor ((beds ÷ reference beds of the size)^elasticity) and a log-normal hospital factor with mean 1, so hospitals of one size differ and the preset totals stay about the same.
- **Case mix per hospital (#876):** a tilt drawn once per hospital multiplies the weights of each indication category, of the age bands from 65 on and of urgency 1. Volume and tilt are drawn after the configuration and the departments.

A `ProfileSelection` chooses a configuration by key (also one with weight 0 for the hospital's tier and size), single resources, tier, size, beds, an annual volume and a closure share for targeted scenarios and the generation service of #877; an explicit annual volume replaces the volume model instead of being multiplied with it. The result must keep the structure rules (a supraregional trauma centre has neurosurgery, a perinatal centre paediatrics) and figures in range, otherwise it is refused. Overrides of the profile by hospital public id in the configuration are not built (#863): public ids follow the catalog seed, so overrides would break when the catalog changes, and neither tests nor the demo need them.

### Pattern packages

A pattern package describes the distributions of the generated allocations as a chain of conditional tables, in the direction set by ADR 020:

1. time: month index, weekday, hour by day type (weekday or weekend),
2. indication (PZC code) by segment,
3. age band and sex by indication category (first PZC digit),
4. urgency by indication category,
5. department and speciality by indication category,
6. flags (resuscitation room, catheterization laboratory, CPR, ventilated, shock, pregnant, physician), occasion, assignment, transport type, infection and secondary transport by category and urgency,
7. transport time percentiles by urgency,

plus a volume block per segment and an `archetypes` section. A **case archetype** is a weighted template that fixes several features together, for example a resuscitation room case, a stroke, a STEMI, a birth, a paediatric infection, a geriatric fall, a psychiatric emergency or an interhospital transfer. Archetypes are mixed into the normal sampling per segment.

- Format `ivena-distribution-patterns`, version 1, as JSON with a SHA-256 checksum. Files live in `var/patterns/` and are never tracked.
- The package is validated with the Symfony Validator, like the reference package: known keys, shares that sum to 1, values that exist in the catalog, valid checksum. Errors name the table and the key.
- Without a file the generator uses the **code defaults**: a package built in PHP from coarse, hand-written shares (whole or five percent steps, common values only). It passes the same validation. Code defaults are never derived from real data.
- Real patterns come only from the export of #797, under the privacy rules of ADR 020.

**First version (#792):** the chain lives in code, without a package file. A segment pattern sets the mix of indications, occasions, assignments, infections, transport times and base flags. The indication is drawn first; department, urgency, age range, sex and the indication-specific flags follow from it (`IndicationGroups`, keyed by the longest matching PZC code prefix, for example `332` STEMI before `33` chest pain before `3` internal medicine). The speciality is the field of the drawn department. The package format, archetypes and a pattern source setting come with the export of #797, which is the first producer of a package. Patterns and scenarios key indications by PZC code, since several obstetric codes share their wording; the obstetric codes 52x–54x have a group of their own (#864).

### Coherence rules

Rules in code apply after sampling, whatever the pattern source:

- pregnant patients are female and aged 15 to 49,
- paediatric departments get patients younger than 18,
- CPR implies urgency 1 and a resuscitation room,
- shock implies urgency 1,
- a cath lab case needs a hospital with a cath lab (hospital profile),
- a hospital gets no case in a department it does not offer: an indication whose departments are all missing is drawn again (up to five times, so a hospital without obstetrics gets no births), then the case goes to a core department.

### Closures

Each hospital gets one closure import per month with `ClosureInterval` rows:

- department and speciality, care level, reason and facility kind; only departments the hospital offers are closed, specialised ones (stroke unit, neurosurgery, paediatrics) wherever they are offered,
- durations from half an hour up to 24 hours; a closure never spans several days and never ends after the last generated day,
- the closed share of department-hours of the hospital profile; a closure model of the structural patterns sets event size (share of the offered departments), duration (whole days from midnight included), care level, weekday and field weights, and the number of events follows from share and model; a modest autumn and winter increase keeps the yearly mean; drawn from the hospital-month stream `closures`,
- some intervals share an IVENA group id and some share exact start and end times, so all three event types of the closure analysis appear (source group, cluster, single).

Closures of a month are planned before its allocations. An allocation to a closed department during an interval gets `department_was_closed = true`; the flag is no longer random.

### Processing pipeline

The generator writes rows directly with DBAL batches, but processing uses the regular services, so synthetic data goes through the same projections as imported data:

- per import: export period (ADR 015) and data coverage; a month without closures has nothing to derive a period from, so the generator stores the known window of the month as a manual period, like an empty IVENA export,
- after generation: allocation statistics projection, closure analysis, closure volume projection and refresh of the materialized views (`SyntheticDataPipeline`).

There is no separate projection code for synthetic data. Doctrine fixtures run in one database transaction, and the closure volume projection takes a lock that cannot be acquired inside it; the fixture therefore rebuilds only the allocation projection, and `composer load-fixtures` runs the closure rebuild and the view refresh as the existing commands after the load. `app:generate:imports` runs outside a transaction and processes its months completely. The daily KPI aggregation (`kpi_daily`) runs for the upload days of the generated imports, inside the fixture as well, since it needs no lock (#866). It counts imports by `created_at`, so a synthetic import gets an upload time one to three days after the data it covers instead of the load time.

### Scenario overlays

Event scenarios such as a mass casualty incident, an icy-road day, a flu wave or heat days are **overlays** applied to existing data, by command or by a preset:

- An overlay only adds rows and never changes base data. For each affected hospital it writes its own allocation import, a closure import when the scenario closes departments, and `MciCase` rows for a mass casualty incident.
- Because overlays are ordinary imports, the regular import deletion removes them together with their allocations, closures and projections, and the projections are rebuilt per import.
- An overlay draws from its own random stream. The same scenario, parameters and seed give the same rows, and applying the same overlay twice is refused.
- Base allocations that fall into a closure added by a scenario keep their `department_was_closed` value. The closure volume analysis reads the intervals, not this flag.

**First version (#792):** `app:generate:scenario` applies `mci`, `icy-road`, `flu-wave` and `heat` to the participating hospitals of a dispatch area or to selected hospitals, from a start day (`ScenarioCatalog`, `ScenarioGenerator`). Extra cases draw their indication from the scenario mix; department, age and urgency follow from the indication as for base data. A mass casualty incident writes `MciCase` patients with one MCI id across the hospitals, all urgency 1 with resuscitation room and physician, within 60 to 90 minutes. The flu wave also closes internal medicine departments. After writing, the imports run through the processing pipeline.

**Extended (#865):**

- MCI patients are spread by tier and size (full care and large hospitals take more), every hospital at least one.
- `--intensity` (0.5–2.0) scales extra cases, MCI patients and closures.
- `--remove` deletes every import of a scenario with a seed and start day through the regular import deletion, then rebuilds the closure volume, the views and the KPIs of the upload days.
- Presets apply scenarios (`scenarios` in `fixtures.profiles`, applications per year): `medium` one MCI, `realistic` a winter flu wave and an MCI, `large` per year a flu wave and an MCI plus one icy-road day. `PresetScenarioPlanner` places them with the seed: one part of the period per application, winter or summer days for seasonal scenarios. An MCI takes 2–4 hospitals, those of one dispatch area first, then the nearest of other areas; a flu wave takes every active hospital. The fixture applies them after the base data and before the projection and KPI rebuild.

### Recognising synthetic imports

Synthetic imports have no source file. Their `filePath` holds a structured marker, built and parsed by `SyntheticImportMarker`:

```text
synthetic/allocations/seed-740/defaults/<hospital public id>/2026-09.csv
synthetic/closures/seed-740/defaults/<hospital public id>/2026-09.csv
synthetic/scenario/icy-road/seed-740/<hospital public id>/2026-01-14.csv
```

The marker tells the generator which months already exist, keeps overlays from being applied twice, lets them be removed, and records seed and pattern source (`defaults` or the package checksum) as ADR 020 requires. No new column is needed. Since no file exists at that path, the import voter already hides download and reprocessing, so a reprocessing run can never replace synthetic rows.

## Amendment (October 2026, #863–#866)

The follow-ups of #792 changed the first version as described above:

- **KPIs (#866):** the pipeline aggregates `kpi_daily` for the upload days of generated imports; synthetic imports get an upload time after their data.
- **Indications by PZC code (#864):** patterns, scenario mixes and the lookup use the code, not the wording; the obstetric codes 52x–54x have a group of their own (obstetrics, pregnant women aged 15 to 49).
- **Hospital profile (#863, part 1):** offered departments and closure frequency; allocations and closures only in offered departments. Overrides by public id are rejected for now (see Hospital profiles).
- **Scenarios (#865):** presets apply scenarios, MCI patients are spread by tier and size, and the command gets intensity and removal.

The default dataset of `make reset` changes with it: department mix, obstetric cases and, from `medium` on, scenario data. Tests use `minimal` without scenarios.

## Amendment (October 2026, #875): interchangeable catalogs

The generator no longer depends on the synthetic catalog. It has a vocabulary of its own, independent of any catalog: departments and specialities by stable English keys (`GeneratorKeys`, for example `stroke-unit` and `neurology`; each key has a default label, its name in the synthetic catalog, and a department key names the speciality key it is written with), lookups by their IVENA value and indications by PZC code. `GeneratorVocabulary` collects what the patterns, indication groups, hospital profiles, closure planner and scenarios use, and `GeneratorCatalog` binds that vocabulary to whatever catalog is installed. Pattern packages and structural profiles address departments by key, never by catalog name; keys are renamed never, new departments get new keys. The code defaults still name departments by their default label and are translated to keys in one place.

- A key resolves through the mapping file if it binds it, else by its default label. Names resolve like in the import: by the normalized name key and the department and speciality aliases. A name that leads to more than one row is ambiguous.
- A local mapping file (`FIXTURES_CATALOG_MAPPING`, format `ivena-generator-catalog-mapping` version 1) binds a key to a catalog name, sets the speciality a department is written with, and pins the normalized or raw indication of a PZC code. Keys are generator keys, values catalog names. For a real catalog the file is private: it lives in `var/reference/` and is never tracked.
- Where the catalog offers several rows, the choice follows name and public id, never the database id: the normalized indication of a code by name, the raw indication with the official wording first. A code with several normalized indications is a warning until the mapping pins one; a code without a mapped raw indication is an error instead of an arbitrary raw label.
- The speciality of an allocation or closure is the mapping's choice for its department, otherwise the field of the unit (`MedicalSpecialities`), resolved through the catalog.
- `CatalogCompatibility` checks every name and code before anything is generated. The allocation fixture, `app:generate:imports` and `app:generate:scenario` stop before their first row with the full list of missing and ambiguous names; `app:generate:check-catalog` prints the same report.

The binding makes rows valid for another catalog; it does not widen what the generator knows. A real catalog has many more departments, specialities, occasions and infections; they get no generated rows until patterns and profiles use more keys and values (#883, #797, #876).

Together with the composed catalog of ADR 020 (`FIXTURES_REFERENCE_PACKAGE`), the same generation workflow runs on the synthetic catalog or on a locally supplied real one. Hospital identities and locations stay synthetic in both cases, so the random streams keyed by hospital public id are the same. With another catalog the content of the rows changes, but the same catalog content, mapping and seed give the same rows, whatever database ids the rows got.

## Amendment (October 2026, #876, step 1)

- **Structural patterns:** hospital profiles are drawn from configurations (see Hospital profiles) in `DataFixtures/Structure/`, keyed by the department and speciality keys of the generator vocabulary (#875), not by catalog names or ids. The patterns hold no hospital names, ids or locations.
- **Code defaults of the structure** are manually curated configurations. Their weights are coarse general knowledge, rounded in five percent steps towards the aggregates of the generator assessment (#874) where at least five hospitals stand behind a value; they are never generated from real data. A structural package (`ivena-structure-patterns`) follows in a later step of #876.
- **Capability gates** decide where severe indications may go. The default allocation patterns still draw them only in the full-care segment; adding them to the other segments is part of the allocation default tuning.
- The draw order of the `profile` stream changed (configuration, then departments per field), so every hospital gets a new structure once: a new generator version.

## Amendment (October 2026, #876, step 2)

- **Per-hospital volume and mix:** see Hospital profiles. Bed elasticity and the spreads of hospital factor and tilt are coarse values rounded towards the between-hospital variation measured in #874, only where at least five hospitals stand behind a value.
- **Closure share in the profile:** the share of closed department-hours (default by size, general knowledge, not measured) and its selection exist from this step; the closure planner switches to it in the next step.
- New draws were appended to the `profile` stream, so configurations and departments of step 1 stay; volume and mix of every hospital change once.

## Amendment (October 2026, #876, step 3)

- **Closures from the share of department-hours** (roadmap decision for #791): the profile gives the share, the closure model of the structural patterns how closures look, and `ClosurePlanner` derives the number of events, `−ln(1 − share) × departments × hours ÷ (expected departments × expected hours)`. Events per month are no longer an input.
- All closure defaults are general knowledge, not measured, since the reference data has closures of too few hospitals.
- A dataset has many times more closure intervals than before, so `department_was_closed` is set far more often and the closure rebuild takes longer.
- **Closure share per preset:** a preset may set `closure_share` for all its hospitals; `single`, `minimal` and `medium` close 1 % and load fast, `realistic` and `large` (the demo preset) keep the shares by size. An explicit share of a `ProfileSelection` wins over the preset, the preset over the structural default. The dev default preset becomes `medium` (6 hospitals, 12 months), since the full shares make the closure volume rebuild of `realistic` several minutes long; the test environment stays on `minimal`.

## Consequences

- A fresh clone gets a multi-hospital dataset with closures and coherent allocations from one `make reset`, and every statistics page works on it.
- The generator can add one month for one hospital, or one scenario, without touching existing records.
- The demo environment (#791) reuses the generator, the hospital profiles and the code defaults; with a private pattern package from #797 it shows real distributions without real records.
- Synthetic data no longer depends on the order in which hospitals and months are generated. Data generated before this ADR cannot be reproduced exactly; a new `make reset` replaces it.
- Changes to the code defaults, the coherence rules or the stream keys change the generated data. They are a new generator version, like a changed provider in ADR 020.
- Writing with DBAL bypasses Doctrine lifecycle callbacks and entity validation. The generator therefore sets every required column itself, and tests check that generated rows pass the regular import validation rules.
- Processing synthetic data takes longer than before, because closure analysis and the volume projection now run as well.
- The pattern package format is a second package format next to the reference package. Both use the same validation approach, but they are versioned independently.

## Alternatives

- **A bounded context of its own for the generator.** Rejected: it is a development tool without its own data. A separate context would need ports into Allocation, Import and Statistics only to write synthetic rows.
- **Generate CSV files and run them through the real import.** Rejected as the default path: it tests the import pipeline but is many times slower for a year of data and needs a writable upload directory. The synthetic CSV test of #740 keeps covering the import path, and #791 can use CSV for its demo upload.
- **Foundry factories for every allocation.** Rejected for bulk data: one ORM entity per row costs too much memory and time for more than 100,000 allocations. Factories stay in use for tests and small data.
- **A new column or table for generator metadata.** Rejected: the marker in `filePath` covers every need without a migration, and synthetic data never reaches production.
- **Scenarios that modify base data.** Rejected: they could not be removed cleanly, and repeated application would change data step by step.
- **Hospital profiles in the reference package.** Rejected: offered departments and resources are not curated reference data today (ADR 020). If hospitals get a real offer list later, the profile can read it from there.

## Related

- ADR 001 (projections and materialized views)
- ADR 015 (closure volume metrics, export periods)
- ADR 020 (portable reference catalog, synthetic catalog, pattern direction and privacy rules)
- ADR 021 (wall clock access)
- Issues #740, #791, #792, #797, #863, #864, #865, #866, #875
