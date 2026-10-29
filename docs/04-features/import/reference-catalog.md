# Reference catalog

**Audience:** Operators and developers who move allocation master data between environments.

The reference catalog is the master data that imports resolve against: states and dispatch areas, hospitals, departments and specialities with their aliases, assignments, occasions, infections, secondary transports, normalized and raw indications, and indication groups. The production database is its source of truth; it is curated there through the admin pages ([ADR 020](../../02-architecture/decisions/020-portable-reference-catalog.md)).

The catalog moves between environments as **one JSON package**. The package format and its validation are described in [reference-catalog-format.md](reference-catalog-format.md).

| Kind of data | Where it lives |
|---|---|
| Technical defaults (IVENA label rules, normalizers) | code |
| Synthetic reference data for development, tests and demos | built in code from Faker providers ([fixtures.md](../../03-development/fixtures.md)) |
| Real (private) reference data | production database; exports in `var/reference/` and in a private data repository |

No real reference data is stored in this repository.

## A package is not a backup

A package holds the catalog only. It holds no users, permissions, hospital owners or participation, allocations, imports, rejects, closures or projections. Use the [database backup](../../05-operations/backup-restore.md) for those.

Public ids are stable across environments; database ids are not. After a restore, rows get new local ids. Bookmarked statistics URLs with ids, saved Explorer views and hospital notifications refer to local ids; that is why the restore refuses while they exist (see below and the [inventory](reference-catalog-inventory.md)).

## Commands

| Command | Purpose |
|---|---|
| `app:reference:export` | Write the database catalog as a package (default `var/reference/catalog-<timestamp>.json`; `--types=` for a partial package, `--notes=`) |
| `app:reference:validate` | Check a package without importing it (default `var/reference/catalog.json`) |
| `app:reference:import` | Add a package to a database (`--mode=add`, default) or replace the whole catalog (`--mode=replace`); `--dry-run`, `--user=` |
| `app:dev:reset-operational-data` | dev/test only: delete the data that blocks a restore |
| `app:reference:propose-from-rejects` | Report catalog values that import rejects miss |

All commands except the development reset run in `prod`. The export reads its own package back before writing and writes nothing when the data does not form a valid package.

## Additive import (`--mode=add`)

The import validates the whole package first, then plans every row:

- A row is matched by its id, then by its natural key (name; state and name for dispatch areas; code and name for normalized indications; code and label for raw indications).
- Same id and same natural key: the row is skipped. Existing rows are never changed or deleted.
- Same id with another natural key, or same natural key with another id: **conflict**.
- A new row may only refer to rows in the package or in the database. Aliases conflict when they belong to another department or speciality, or match another one's name.

One conflict stops the import before anything is written; every conflict is listed with its path in the file. `--dry-run` prints the plan only. Otherwise all rows are written in one transaction with the ids from the package, and the reference data cache is cleared.

New rows get `createdBy` from `--user=` (default `admin`); reviewed raw indications also get it as `reviewedBy`. Imported hospitals do not participate and have no owner. An additive import is not a restore: it never removes or renames anything.

## Restore (`--mode=replace`)

A restore replaces the whole catalog with a **complete** package (`meta.complete = true`, exported without `--types`). It is meant for an installation without operational data, for example a fresh staging system.

1. It takes the shared maintenance lock and refuses while another restore or development reset runs.
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

## Runbooks

### Production → private repository

The private copies of the catalog live in a separate private Git repository outside this one. Run on the server from the current release ([paths on the server](../../05-operations/deployment.md#paths-on-the-server)):

```bash
cd <deploy_path>/current
php bin/console app:reference:export --notes="monthly export"
php bin/console app:reference:validate --source=var/reference/catalog-<timestamp>.json
```

`var/reference` is a Deployer shared directory, so the file survives the next deploy. Copy it into the private repository (for example with `scp`) and commit it there; the deterministic output keeps the diff readable.

### Private repository → staging (empty installation)

```bash
php bin/console doctrine:migrations:migrate -n
# create the admin user
cp /path/to/private-catalog/catalog.json var/reference/catalog.json
php bin/console app:reference:import --mode=replace --dry-run
php bin/console app:reference:import --mode=replace
php bin/console app:geo:geocode-hospitals   # only fills missing coordinates
```

Imported hospitals do not participate and have no owner; enable participation and assign owners in that environment.

### Local development with the private catalog

Locally the blockers come from development data. The development reset removes exactly that data and keeps users and the catalog:

```bash
make reset                                  # synthetic installation
cp /path/to/private-catalog/catalog.json var/reference/catalog.json
php bin/console app:dev:reset-operational-data --dry-run
php bin/console app:dev:reset-operational-data
php bin/console app:reference:import --mode=replace --dry-run
php bin/console app:reference:import --mode=replace
php bin/console app:geo:geocode-hospitals   # only fills missing coordinates
```

Then upload and import a real IVENA file. Stop the workers first; reset and restore refuse while queues hold messages, and they share one lock.

### Local development with the private catalog and fictional hospitals

A restore brings in the real hospitals. To keep the synthetic hospitals and use only the private catalog (departments, specialities, lookups, indications, groups), load a composed catalog instead; the generator then writes synthetic data against the private catalog:

```bash
cp /path/to/private-catalog/catalog.json var/reference/catalog.json
FIXTURES_REFERENCE_PACKAGE=var/reference/catalog.json FIXTURES_CATALOG_MAPPING=var/reference/generator-mapping.json make reset
php bin/console app:generate:check-catalog
```

The mapping file binds the generator's names to the private labels; see [fixtures.md](../../03-development/fixtures.md#generator-on-another-catalog). Both files stay in `var/reference/` and are never committed.

### Adding a few rows to an existing installation

Export a partial package where the rows exist (`--types=occasion,infection`) and import it with `--mode=add` where they are missing. Rows that exist with another id are reported as conflicts; add those through the admin pages instead.

## Report from import rejects

`app:reference:propose-from-rejects` is read-only on `import_reject`. It streams rejects, takes `REF_NOT_FOUND` values plus soft fields on the same row (`anlass`, `ansteckungsfaehig`, `sekundaeranlass`), applies the same normalization as the import, and skips values that already resolve against the current catalog, aliases included. Empty values, mojibake, URLs and denylisted leftovers are dropped. Pair it with [`app:import:analyze-rejects`](reject-analysis.md) for the full reject breakdown.

`--output=` is a directory:

| File | Contents |
|---|---|
| `report.md` | Value, type, counts, example file |
| `requeue-import-ids.txt` | Comma-separated import ids for `app:import:requeue-all --only-ids=` |
| `requeue-imports.md` | Hospital, file, matching reject counts |

Add the missing values through the admin pages, then requeue the listed imports:

```bash
php bin/console app:reference:propose-from-rejects --output=var/export/reference-from-rejects
# add the values from report.md through the admin pages
php bin/console app:import:requeue-all --only-ids="$(cat var/export/reference-from-rejects/requeue-import-ids.txt)" --dry-run
```

`app:import:requeue-all` dispatches allocation imports only; closure imports are restarted with `app:import:start <id>`.

## Dispatch-area names

`DispatchAreaNameNormalizer` (code) trims, maps NBSP to space, collapses whitespace, strips a leading `_`, parenthetical suffixes, longest-first prefixes (`Kommunale Regionalleitstelle`, `Integrierte Leitstelle`, `Zentrale Leitstelle`, `Regionalleitstelle`, `Leitstelle`, `Landkreis`, `Kreis`) and a trailing `Kreis` / `Führungsstab`, and fixes known encoding typos. Lookup stays name-based. The maps know the 24 dispatch areas of Hesse by their exact name (`config/case_flow/dispatch_area_geo_map.yaml`); an area with another name has no shape.

Department and speciality aliases live in the `department_alias` and `speciality_alias` tables and travel with the package. The explore department page lists the known spellings.

## Related

- [reference-catalog-format.md](reference-catalog-format.md)
- [reference-catalog-inventory.md](reference-catalog-inventory.md)
- [reject-analysis.md](reject-analysis.md)
- [batch-requeue.md](batch-requeue.md)
- [../allocation/indication-normalization.md](../allocation/indication-normalization.md)
- [../../03-development/fixtures.md](../../03-development/fixtures.md)
- [../../06-reference/console-commands.md](../../06-reference/console-commands.md)
