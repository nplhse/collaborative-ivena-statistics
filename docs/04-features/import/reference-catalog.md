# Reference catalog

**Audience:** Operators and developers who need to move allocation master data between environments without `doctrine:fixtures:load`.

Fixtures purge the whole database and must not run in production. The catalog moves as **one JSON package** ([ADR 020](../../02-architecture/decisions/020-portable-reference-catalog.md)): `app:reference:export` writes it, `app:reference:validate` checks it and `app:reference:import` adds it to a database. All three are available in `prod`.

## What is in the catalog

Every row carries its public id; rows refer to each other by id. The import matches a row by id first, then by its natural key:

| Type | Natural key | Import (add) |
|---|---|---|
| State | `name` | skip if id and name match |
| Dispatch area | `state` + `name` | skip if id and key match |
| Department, speciality, assignment, occasion, infection, secondary transport | `name` | skip if id and name match; aliases are added under their owner |
| Indication normalized | `code` + `name` | skip if id and key match |
| Indication raw | code + label | skip if id and key match; `target` and review data come from the package |
| Indication group | `name` | skip if id and name match; members are exact normalized ids |
| Hospital | `name` | skip if id and name match; owner and participation are never imported |

A match by id with another key, or by key with another id, is a conflict and stops the import.

**Not in the catalog:** users, allocations, imports, rejects, CMS, audit.

Doctrine fixtures (`AreaReferenceFixture`, lookups, hospitals, indications) still read the YAML file below until they are built from code.

## File format

Full field reference: [reference-catalog-yaml.md](reference-catalog-yaml.md).

Default path: `fixtures/reference/catalog.yaml`. Missing sections are empty. `--source=` / `--output=` point at **one file**. `--types=` filters sections (comma-separated CLI names: `state`, `dispatch-area`, `department`, `speciality`, `assignment`, `occasion`, `infection`, `secondary-transport`, `indication-normalized`, `indication-raw`, `indication-group`, `hospital`).

```yaml
states:
  - Hessen
  - Bayern
dispatch_areas:
  - { name: Frankfurt, state: Hessen }
  - { name: Göttingen, state: ~ }   # propose stub; import skips until state is filled
departments:
  - Chir. Überwachung
occasions:
  - aus Klinik
indication_groups:
  - { name: 'ECMO / eCPR', category: ~, codes: ['143'] }
hospitals:
  - { name: Klinikum Weststadt, state: Hessen, area: Kassel, participating: true }
```

Hospital fields (`tier`, `size`, `beds`, `location`, `address`) are documented in the YAML schema. Import does not geocode; use `app:geo:geocode-hospitals` in production.

## Commands

```bash
php bin/console app:reference:export
php bin/console app:reference:export --output=var/reference/catalog.json --types=occasion,infection
php bin/console app:reference:validate --source=var/reference/catalog.json

php bin/console app:reference:import --source=var/reference/catalog.json --dry-run
php bin/console app:reference:import --source=var/reference/catalog.json --user=admin

php bin/console app:reference:propose-from-rejects --output=var/export/reference-from-rejects
```

`app:reference:export` writes the JSON package of [ADR 020](../../02-architecture/decisions/020-portable-reference-catalog.md) (default `var/reference/catalog-<timestamp>.json`, never a tracked file). `app:reference:import` reads such a package (default `var/reference/catalog.json`). The Doctrine fixtures still read the YAML file below until they are built from code.

`app:reference:load-indication-groups` still exists and reads the `indication_groups` section of the same file (create-missing, optional `--update`).

### Additive import

`app:reference:import` validates the whole package first (same checks as `app:reference:validate`), then plans every row:

- A row is matched by its id, then by its natural key (name; state and name for dispatch areas; code and name for normalized indications; code and label for raw indications).
- Same id and same natural key: skipped. Existing rows are never changed or deleted.
- Same id with another natural key, or same natural key with another id: conflict.
- A new row must only refer to rows in the package or in the database. Aliases conflict when they belong to another department or speciality, or match another one's name.

One conflict stops the import before anything is written; the command lists every conflict with its path in the file. `--dry-run` prints the plan only. Otherwise all rows are written in one transaction with the ids from the package, and the reference data cache is cleared.

New rows get `createdBy` from `--user=` (default `admin`); reviewed raw indications also get it as `reviewedBy`. Imported hospitals do not participate and have no owner. The additive import is not a restore.

### Restore (`--mode=replace`)

A restore replaces the whole catalog with a **complete** package (`meta.complete = true`, exported without `--types`). It is meant for an installation without operational data, for example a fresh staging system.

1. It takes the shared maintenance lock (the development reset uses the same lock) and refuses while another restore or reset runs.
2. It refuses while the Doctrine queues hold messages that are waiting or being handled. Stop the workers and let the queues drain first; the failure queue does not count.
3. It lists every **blocker**, a table outside the catalog that refers to catalog rows, and stops when there is one:
   - operational data: `allocation`, `mci_case`, `import`, `closure_interval`, `monthly_reminder_dispatch`
   - permissions: `hospital_access_grant`, hospitals with an owner
   - projections: `allocation_stats_projection`, `closure_event`, `closure_analysis_interval`, `closure_volume_hour`, `closure_rebuild_request`, `closure_relation_candidate`, `kpi_daily`
   - monitoring: `job_run` rows with a hospital
   - user data: saved Explorer views of users, hospital notifications

   Several of these foreign keys cascade; the restore never lets them delete rows silently. Access grants are a blocker, never deleted.
4. Without blockers it deletes the catalog (including aliases and the group join table) and inserts the package in **one transaction**, keeping the package ids. A failure rolls back and leaves the old catalog in place.
5. Afterwards it refreshes the materialized views and clears the reference data cache.

`--dry-run` shows rows to delete and create per type and the blockers, and writes nothing.

#### Local workflow with a private package

Locally the blockers come from development data. `app:dev:reset-operational-data` (dev and test only) removes exactly that data and keeps users and the catalog:

```bash
cp ~/private-catalog/catalog.json var/reference/catalog.json
php bin/console app:dev:reset-operational-data --dry-run
php bin/console app:dev:reset-operational-data
php bin/console app:reference:import --mode=replace --dry-run
php bin/console app:reference:import --mode=replace
php bin/console app:geo:geocode-hospitals   # only fills missing coordinates
```

Stop the workers first; both commands refuse while queues hold messages, and they share one lock.

## Propose from import rejects

`app:reference:propose-from-rejects` is read-only on `import_reject`. It does not write stammdaten and does not delete rejects. Pair it with [`app:import:analyze-rejects`](reject-analysis.md) when you need the full reject breakdown.

It streams rejects, takes `REF_NOT_FOUND` values plus soft fields on the same row (`anlass`, `ansteckungsfaehig`, `sekundaeranlass`), applies the same normalization as import, and skips values that already resolve against the current DB catalog (including aliases such as `Frankfurt Führungsstab` → Frankfurt and `Geburtszentrum B` → Geburtshilfe). Empty values, mojibake, URLs, and denylisted leftovers (`Erhängen`) are dropped.

Default `--output=` is a **directory**:

| File | Contents |
|---|---|
| `report.md` | Value, type, counts, example file |
| `requeue-import-ids.txt` | Comma-separated import IDs for `app:import:requeue-all --only-ids=` |
| `requeue-imports.md` | Hospital, file, matching reject counts |

Add the missing values through the admin pages (the production database is the source of the catalog), then requeue the listed imports. Re-run propose after a partial requeue if the ID list is stale.

## Production runbook

No Deployer hook. After the code deploy (normalizer/aliases live, catalog **not** yet in the prod DB):

```bash
cd ~/www/current
php bin/console app:reference:propose-from-rejects --output=var/export/reference-from-rejects
# Add the values from report.md through the admin pages
php bin/console cache:pool:clear cache.allocation.reference_data
php bin/console app:import:requeue-all --only-ids="$(cat var/export/reference-from-rejects/requeue-import-ids.txt)" --dry-run
```

Empty instance: migrate, create the admin user, then `app:reference:import --mode=replace --source=var/reference/<package>.json --dry-run` and the same without `--dry-run`, with a complete package exported from production.

## Specialty and department name correction

> Historical: this correction ran with the YAML import (`previous_names`), which no longer exists. The JSON import does not rename rows; a rename comes from the admin pages or a restore.

Deploy ships the migration (empty alias tables) and the reviewed `fixtures/reference/catalog.yaml`. It does not import the catalog and it does not requeue imports. There is no Deployer hook for this correction. An operator runs the commands below by hand after the release.

Do not use `app:reference:propose-from-rejects` as the source. That command would add rejected spellings as new rows and split statistics. Do not use `--mode=replace` on a database that already has allocations. `previous_names` renames the existing department or speciality and keeps its id, so allocations and closure intervals stay attached. The old spelling is stored as an alias.

`app:import:requeue-all` dispatches allocation imports only. Closure imports 1089 and 1090 must use `app:import:start`, which reads the import type and dispatches the closure job.

```bash
cd ~/www/current
php bin/console app:reference:import --mode=add --source=fixtures/reference/catalog.yaml --dry-run
php bin/console app:reference:import --mode=add --user=admin --source=fixtures/reference/catalog.yaml
php bin/console cache:pool:clear cache.allocation.reference_data
php bin/console app:import:start 1089
php bin/console app:import:start 1090
php bin/console app:import:requeue-all --only-ids=904,925,926,927,928,929,930,931,932,933,934,935,936,1050 --dry-run
php bin/console app:import:requeue-all --only-ids=904,925,926,927,928,929,930,931,932,933,934,935,936,1050
```

The allocation id list is the local snapshot whose reference misses are only `Chir. Überwachung`, `ECMO-Therapie`, or `Nuklearmedizin`. Dispatch-area rejects stay rejected. After the closure jobs finish, the 4 680 reference-only closure rejects should be imported and the 52 structural closure rejects should remain (40 duration mismatches and incomplete rows). Confirm renamed departments kept their ids and that the explore page shows the old spelling as an alternate name. The classification and source of an alias are visible on the admin department detail page.

## Dispatch-area normalization (code, not YAML)

`DispatchAreaNameNormalizer` trims, maps NBSP to space, collapses whitespace, strips leading `_`, parenthetical suffixes, longest-first prefixes (`Kommunale Regionalleitstelle`, `Integrierte Leitstelle`, `Zentrale Leitstelle`, `Regionalleitstelle`, `Leitstelle`, `Landkreis`, `Kreis`), trailing `Kreis` / `Führungsstab`, and keeps the `Groá-Gerau` typo map. Lookup stays name-based. Orientation maps remain Hessen-only (`disabled` for Göttingen, Bayerischer Untermain, Niedersachsen, …).

Department aliases live in `catalog.yaml` and the `department_alias` table, not in PHP. `Geburtszentrum B` → `Geburtshilfe` (same family as Level 1 / Schwerpunkt / Geburtsabteilung). No extra department row. Speciality aliases use `speciality_alias` the same way. The explore department page lists known spellings and says the catalog is only a matching guide.

## Reject audit (catalog gaps)

Hard `REF_NOT_FOUND` in the audited local DB was 2 072 rows: dispatch area 1 653, department 401, speciality 18, assignment 0.

Added to `catalog.yaml` (high volume):

- States: Niedersachsen, Thüringen
- Areas: Göttingen, Northeim, Eichsfeld, Schweinfurt (Bayern already existed)
- Departments: `Chir. Überwachung`, `vvECMO Zuverlegung`, `vaECMO Zuverlegung`, `eCPR Zuverlegung`
- Specialities: `ECMO-Therapie`, `Nuklearmedizin` (new row, not an alias of the combination name)
- Occasions from original CSVs (soft miss, no reject): `aus Klinik`, `Krankentransport`

Not catalogued: `Erhängen` (n=1), encoding mojibake, secondary-transport values from broken CSV lines, Nuklearmedizin → combination alias, Berlin / Test Area, extra `ILS`/`LS` prefixes.

After catalog import + targeted requeue + normalizer, those `REF_NOT_FOUND` rows should import unless a different line error applies. Occasion names above are set instead of silently left `null`. Blank / quote-broken rejects are unchanged.

## Related

- [reject-analysis.md](reject-analysis.md)
- [batch-requeue.md](batch-requeue.md)
- [../allocation/indication-normalization.md](../allocation/indication-normalization.md)
- [../../03-development/fixtures.md](../../03-development/fixtures.md)
- [../../06-reference/console-commands.md](../../06-reference/console-commands.md)
