# Closure-list import

**Audience:** Operators and developers importing IVENA closure lists.

The closure import is a beta. Only users with `ROLE_CLOSURE_BETA` can choose it, see those imports in the participant list, or open them. `ROLE_ADMIN` does not include that role. Assign it on the user in EasyAdmin. The EasyAdmin import screen still lists every import for operators.

Allocation imports are unchanged. The flag `departmentWasClosed` on allocations is not derived from these intervals.

## File

IVENA export `Schließungsliste`: semicolon-separated, often ISO-8859-1. Some exports quote every cell, others only cells that contain line breaks. The existing CSV reader detects the encoding and normalizes headers to snake_case. A quoted cell may span several physical lines; its line breaks are kept as LF (for example in remarks). One record may span up to 64 KiB. A quote that never closes keeps only its first line, and the following lines are read as records of their own.

One row is one interval for one department and one care level. The same closure is repeated across departments and care levels. Rows that share department, care level, and time but differ in closure unit, group id, reason, or remark are stored as separate intervals. There is no deduplication.

IVENA exports a closure in one of two layouts, depending on how the hospital set up its closure units:

- **Flat:** one record per department and care level. Each record is one row.
- **Grouped:** one record per closure unit. `Fachgebiet`, `Fachbereich` and `Behandlungsdringlichkeit` hold one value per line, and line *i* of each column belongs together. The record is split into one row per line; every other column applies to each row. A record whose three lists differ in length is rejected as one row (`GROUPED_LIST_MISMATCH`), and nothing from it is stored.

Both layouts give the same rows, so counts and durations stay comparable across hospitals. `row_count`, the stored and rejected counts, and the rejection ratio of the import status count rows, not source records. Rejects of a split record share the line number of that record. The line number counts records with the header as 1; it is not the physical line in the file when cells span several lines and show the single department and care level that failed.

Samples used in tests: `tests/Import/Fixtures/closure_import_sample.csv` (flat) and the grouped records built in `ClosureImportFeatureTest`. Do not commit a full production export; those files contain staff names.

## Stored columns

| CSV column | Stored as | Notes |
|------------|-----------|--------|
| `Krankenhaus-Kurzname` | Hospital of the upload | Plausibility only. The hospital selected at import start is stored. See below. |
| `Fachgebiet` | Speciality | Existing name lookup, including speciality aliases. Unknown names reject the row. |
| `Fachbereich` | Department | Existing name lookup, including department aliases. Unknown names reject the row. |
| `Behandlungsdringlichkeit` | `ClosureCareLevel` | Notfallversorgung, Stationäre Versorgung, and Ambulante Versorgung are SK1–SK3. `Sonstige` is stored and is not an SK. |
| `Datum` / `Uhrzeit` of start and end | `starts_at`, `ends_at` | Europe/Berlin wall clock. End must be after start. |
| `Schließungs-Dauer (Minuten)` | Checked, not stored | Must match either the elapsed minutes in Europe/Berlin or the difference of the local clock times. The two differ only across a DST switch, and IVENA reports both: a closure from 07:30 on the day before the spring switch to 08:30 the next day can be listed as 1440 (elapsed) or 1500 (clock). Any other value rejects the row (`DURATION_MISMATCH`). The stored duration is always derived from start and end, so it is the elapsed time. |
| `Grund` | `ClosureReason` | Shared catalog: Überlastung der Notaufnahme, keine Bettenkapazitäten, Technische Störung, OP-Meldung, `k.A.`. `k.A.` is its own category. Unknown text rejects the row. |
| `Schließungseinheit` | `closure_unit` | Optional hospital-local snapshot. Empty stays null. A later rename in IVENA does not rewrite old rows. Index: `(hospital_id, closure_unit)`. |
| `Gruppen-Schließungs-ID` | `source_group_id` | Optional IVENA action id, not a group catalog. Index: `source_group_id`. |
| `Bemerkung`, `Krankenhausinterne Bemerkung` | Optional text | Not categories. |
| `Eingetragen am`, `Geändert am` | Source timestamps | |
| `Typ` | `ClosureFacilityKind` | `Klinik` is accepted. Unknown values reject the row. |

## Ignored columns

Derived calendar fields (day, weekday, month, month name, year) are not stored.

Not read: the long `Krankenhaus` address, `KHS-Versorgungsgebiet`, `Art der Einrichtung`, practice columns, and accessibility columns.

`Eingetragen von` and `Geändert von` are not stored.

## Hospital assignment

The import is always stored on the hospital selected when the upload starts. That selection does not change, and the file cannot assign rows to a different hospital.

`Krankenhaus-Kurzname` is read once before writing. Comparison trims, case-folds, and collapses whitespace. Empty values are ignored for this check.

- One short name that exactly matches a different catalog hospital rejects every row (`HOSPITAL_CONFLICT`). Start the import for that hospital, or export the list for the selected one.
- One short name that matches the selected hospital, or that matches no catalog hospital exactly, is accepted. A different spelling is logged and does not reject the row. This covers IVENA short names that are not the catalog name.
- Several short names keep a row only when it exactly matches the selected hospital's catalog name. Other rows reject (`HOSPITAL_MISMATCH`). Split the file and import each hospital on its own. If none of the names match, nothing is stored.

A catalog name that normalizes to more than one hospital is not treated as a unique contradiction. There is no alias list and no fuzzy match. Hospitals have no external id. The long `Krankenhaus` column is an address line, not an id.

### Development-only target hospital override

For local development a real closure export can be loaded into a fictional demo hospital (#878). In the `dev` environment the upload form shows **Override target hospital (development only)**. When it is ticked, a single `Krankenhaus-Kurzname` that names a different catalog hospital no longer rejects the rows (`HOSPITAL_CONFLICT`); the rows are stored on the selected hospital.

- Only the hospital assignment changes. Parsing, the closure catalogs, speciality and department resolution, duplicate handling and rejects stay as without the override. Specialities and departments are one global catalog; there is no per-hospital list of departments, so a row with an unknown speciality or department is rejected as usual.
- A file with several hospital names is still checked row by row (`HOSPITAL_MISMATCH`). The override does not spread one file across hospitals.
- The import records `hospital_override = true`; when a conflicting name was bypassed, `hospital_override_source_label` keeps it. The import page shows a notice with that name.
- Outside `dev` the field does not exist (a posted value makes the form invalid) and a stored flag is ignored: the run applies the normal checks and logs `closure.import.hospital_override.ignored`. The check is `DevelopmentHospitalOverride`.
- The rows stay real operational data. Assigning them to a fictional hospital does not make them synthetic ([synthetic-data-generator.md](../../03-development/synthetic-data-generator.md)).

## Rejects

Invalid rows are rejected with a message and are not stored. The same reject writer, import status, and deletion cleanup as allocation imports apply. Deleting an import removes its closure intervals.

Adding a new shared reason, care level, or facility kind is a catalog class under `src/Import/Application/Mapping/`. Adding or renaming a column is `ClosureRowMapper`. The import loop stays the same.

## Analytics coverage limitation

The export period selected in IVENA is not part of the CSV. `import.export_starts_at`
and `import.export_ends_at` stay empty unless that period is known from somewhere
else. They are never filled from the earliest start or the latest end. Closure
Analytics still estimates a span per import for orientation. That span is not
proof of coverage and is not a denominator. A pause inside it is labelled as
unknown coverage. See [closure-architecture.md](../statistics/closure-architecture.md).

## Requeue

```bash
php bin/console app:import:start <IMPORT_ID>
```

This resolves the import type and, for a closure import, dispatches `ImportClosuresMessage` on `async_priority_high`. A worker must consume that transport. The source file stays on disk; a later run clears only data that belongs to this import and does not itself schedule a rebuild. A successful completion schedules one analysis rebuild for the hospital. If the run fails after that cleanup, the analysis is scheduled then. The volume projection follows asynchronously. Only completed and partial imports feed the analysis.
