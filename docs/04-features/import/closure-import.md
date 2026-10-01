# Closure-list import

**Audience:** Operators and developers importing IVENA closure lists.

The closure import is a beta. Only users with `ROLE_CLOSURE_BETA` can choose it, see those imports in the participant list, or open them. `ROLE_ADMIN` does not include that role. Assign it on the user in EasyAdmin. The EasyAdmin import screen still lists every import for operators.

Allocation imports are unchanged. The flag `departmentWasClosed` on allocations is not derived from these intervals.

## File

IVENA export `Schließungsliste`: semicolon-separated, quoted, often ISO-8859-1. The existing CSV reader detects that encoding and normalizes headers to snake_case.

One row is one interval for one department and one care level. The same closure is repeated across departments and care levels. Rows that share department, care level, and time but differ in closure unit, group id, reason, or remark are stored as separate intervals. There is no deduplication.

Sample used in tests: `tests/Import/Fixtures/closure_import_sample.csv`. Do not commit a full production export; those files contain staff names.

## Stored columns

| CSV column | Stored as | Notes |
|------------|-----------|--------|
| `Krankenhaus-Kurzname` | Hospital of the upload | Plausibility only. The hospital selected at import start is stored. See below. |
| `Fachgebiet` | Speciality | Existing name lookup, including speciality aliases. Unknown names reject the row. |
| `Fachbereich` | Department | Existing name lookup, including department aliases. Unknown names reject the row. |
| `Behandlungsdringlichkeit` | `ClosureCareLevel` | Notfallversorgung, Stationäre Versorgung, and Ambulante Versorgung are SK1–SK3. `Sonstige` is stored and is not an SK. |
| `Datum` / `Uhrzeit` of start and end | `starts_at`, `ends_at` | Europe/Berlin wall clock. End must be after start. |
| `Schließungs-Dauer (Minuten)` | Checked, not stored | Compared with elapsed minutes in Europe/Berlin, so the spring-forward hour matches IVENA. A mismatch rejects the row. Duration is derived later from start and end. |
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

## Rejects

Invalid rows are rejected with a message and are not stored. The same reject writer, import status, and deletion cleanup as allocation imports apply. Deleting an import removes its closure intervals.

Adding a new shared reason, care level, or facility kind is a catalog class under `src/Import/Application/Mapping/`. Adding or renaming a column is `ClosureRowMapper`. The import loop stays the same.

## Analytics coverage limitation

The export period selected in IVENA is not part of the CSV and is therefore not
stored on the import. Closure Analytics estimates observation coverage per import
as the span from its earliest closure start to its latest closure end. This is not
proof that the export was complete and is shown only as orientation. Closure
Analytics does not use the span as a denominator or report open/closed
percentages; time outside it remains unknown.

## Requeue

```bash
php bin/console app:import:start <IMPORT_ID>
```

This resolves the import type and, for a closure import, dispatches `ImportClosuresMessage` on `async_priority_high`. A worker must consume that transport. The source file stays on disk; a later run clears only data that belongs to this import.
