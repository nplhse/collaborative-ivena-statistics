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
| `Krankenhaus-Kurzname` | Hospital of the upload | Must match the selected hospital after trimming and case-folding. A mismatch rejects the row. |
| `Fachgebiet` | Speciality | Existing name lookup. Unknown names reject the row. |
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

## Rejects

Invalid rows are rejected with a message and are not stored. The same reject writer, import status, and deletion cleanup as allocation imports apply. Deleting an import removes its closure intervals.

Adding a new shared reason, care level, or facility kind is a catalog class under `src/Import/Application/Mapping/`. Adding or renaming a column is `ClosureRowMapper`. The import loop stays the same.
