# Reference catalog

**Audience:** Operators and developers who need to move allocation master data between environments without `doctrine:fixtures:load`.

Fixtures purge the whole database and must not run in production. The catalog is **one YAML file** (`fixtures/reference/catalog.yaml`) plus three console commands that are available in `prod`.

## What is in the catalog

| Type | Identity | `--mode=add` |
|---|---|---|
| State | `name` | skip if the name exists |
| Dispatch area | `state` + `name` | skip if the pair exists; **rows without `state` are skipped** (warning) |
| Department, speciality, assignment, occasion, infection, secondary transport | `name` | skip if the name exists |
| Indication normalized | `code` + `name` | skip if the pair exists |
| Indication raw | hash of code + text | skip if the hash exists; never overwrite |
| Indication group | `name` | skip; `--update` refreshes category and membership |
| Hospital | `name` | skip entirely if the name exists (owner, participating, coordinates stay untouched) |

**Not in the catalog:** users, allocations, imports, rejects, CMS, audit.

Doctrine fixtures (`AreaReferenceFixture`, lookups, hospitals, indications) read the same file.

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
  - { name: 'ECMO & ECLS Transport', category: ~, codes: ['143'] }
hospitals:
  - { name: Klinikum Kassel, state: Hessen, area: Kassel, participating: true }
```

Hospital fields (`tier`, `size`, `beds`, `location`, `address`) are documented in the YAML schema. Import does not geocode; use `app:geo:geocode-hospitals` in production.

## Commands

```bash
php bin/console app:reference:export --output=fixtures/reference/catalog.yaml
php bin/console app:reference:export --output=var/export/catalog.yaml --types=occasion,infection

php bin/console app:reference:import --source=fixtures/reference/catalog.yaml --dry-run
php bin/console app:reference:import --mode=add --user=admin
php bin/console app:reference:import --mode=replace --user=admin

php bin/console app:reference:propose-from-rejects --output=var/export/reference-from-rejects
```

`app:reference:load-indication-groups` still exists and reads the `indication_groups` section of the same file (create-missing, optional `--update`).

### Add vs replace

- **`--mode=add` (default):** insert missing rows only. No rename, delete, or field update (except indication groups with `--update`).
- **`--mode=replace`:** delete catalog tables (reverse dependency order) and reload. **Aborts** when `allocation`, `mci_case`, or `import` rows exist. Omit `--types` (full catalog only). For a blank install after migrate + first user. Not a substitute for `doctrine:fixtures:load`.

Dispatch-area rows without `state` are never guessed (no Niedersachsen/Thüringen/Bayern inference). Fill `state`, then import.

`createdBy` comes from `--user=` (default `admin`).

## Propose from import rejects

`app:reference:propose-from-rejects` is read-only on `import_reject`. It does not write stammdaten and does not delete rejects. Pair it with [`app:import:analyze-rejects`](reject-analysis.md) when you need the full reject breakdown.

It streams rejects, takes `REF_NOT_FOUND` values plus soft fields on the same row (`anlass`, `ansteckungsfaehig`, `sekundaeranlass`), applies the same normalization as import, and skips values that already resolve against the current DB catalog (including aliases such as `Frankfurt Führungsstab` → Frankfurt and `Perinatalzentrum Level 2` → Geburtshilfe). Empty values, mojibake, URLs, and denylisted leftovers (`Erhängen`) are dropped.

Default `--output=` is a **directory**:

| File | Contents |
|---|---|
| `catalog.yaml` | Same schema, only missing entries; `dispatch_areas[].state` is empty/`~` |
| `report.md` | Value, type, counts, example file |
| `requeue-import-ids.txt` | Comma-separated import IDs for `app:import:requeue-all --only-ids=` |
| `requeue-imports.md` | Hospital, file, matching reject counts |

Review the YAML, fill area states, merge into `fixtures/reference/catalog.yaml` or import the proposal file directly (`--source=…/catalog.yaml`). Re-run propose after a partial requeue if the ID list is stale.

## Production runbook

No Deployer hook. After the code deploy (normalizer/aliases live, catalog **not** yet in the prod DB):

```bash
cd ~/www/current
php bin/console app:reference:propose-from-rejects --output=var/export/reference-from-rejects
# Fill dispatch_areas[].state in catalog.yaml, drop junk, merge if needed
php bin/console app:reference:import --mode=add --source=var/export/reference-from-rejects/catalog.yaml --dry-run
php bin/console app:reference:import --mode=add --user=admin --source=var/export/reference-from-rejects/catalog.yaml
php bin/console cache:pool:clear cache.allocation.reference_data
php bin/console app:import:requeue-all --only-ids="$(cat var/export/reference-from-rejects/requeue-import-ids.txt)" --dry-run
```

Alternative: export locally, commit YAML, deploy, then `import --mode=add` from `fixtures/reference/catalog.yaml`.

Empty instance: migrate, create the admin user, `import --mode=replace`. Never replace a database that already has allocations.

## Dispatch-area normalization (code, not YAML)

`DispatchAreaNameNormalizer` trims, maps NBSP to space, collapses whitespace, strips leading `_`, parenthetical suffixes, longest-first prefixes (`Kommunale Regionalleitstelle`, `Integrierte Leitstelle`, `Zentrale Leitstelle`, `Regionalleitstelle`, `Leitstelle`, `Landkreis`, `Kreis`), trailing `Kreis` / `Führungsstab`, and keeps the `Groá-Gerau` typo map. Lookup stays name-based. Orientation maps remain Hessen-only (`disabled` for Göttingen, Bayerischer Untermain, Niedersachsen, …).

Department alias: `Perinatalzentrum Level 2` → `Geburtshilfe` (same family as Level 1 / Schwerpunkt / Geburtsklinik). No extra department row.

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
