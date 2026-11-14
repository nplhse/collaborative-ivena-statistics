# ADR 026: Encounter data context, source profiles and canonical exchange format

**Status:** accepted

## Context

Participating hospitals hold routine emergency department (ED) data next to the IVENA allocations:
triage (Manchester Triage System, MTS), process times, disposition and initial vitals, later also
controlling data (stays, diagnoses, procedures). The pilot (#1043) shows whether EDs can link their
routine data with IVENA allocations and use them for local care improvement. It starts with the MTS
export of ORBIS.

Forces:

- **IVENA stays primary.** The project remains the IVENA statistics tool. Hospital data enriches
  allocations for the hospitals that supply it; it must not change existing statistics, the
  projection or allocation deduplication.
- **Many vendor formats, few kinds of data.** Each hospital information system (HIS) exports the
  same kind of data in its own layout, version and local configuration (ORBIS, Epias, Medico, …).
  Some layouts and mappings are hospital-specific and must not be public.
- **Sensitive raw data.** Vendor exports contain names, dates of birth, case numbers, staff names
  and free text. Those must not leave the hospital computer. Hospital IT usually forbids installing
  or running tools.
- **Not every ED visit has an allocation.** Self-presenters and EMS arrivals without IVENA
  allocation are part of the ED population and of the pilot.
- **No visit identifier in the source.** The ORBIS MTS export has no per-visit id; one administrative
  case number can cover several ED visits.
- The import pipeline accepts only CSV and rejects Excel on purpose.

## Decision

### Bounded context `Encounter`

A new context `src/Encounter/{Domain,Application,Infrastructure,UI}` with its own Deptrac layers owns
encounter data, its import handler, matching, access grants and pages. Product name: "Encounter data /
Behandlungsdaten". New identifiers avoid "clinical", which already names IVENA allocation features
(`ClinicalFeatures`, `clinical_indicators`).

Dependencies: `Encounter` may depend on `Allocation` (hospital, allocation, department catalog),
`Import` (runs, rejects, coverage) and `Shared`. No existing context depends on `Encounter`; links
from existing pages are contributed by `Encounter`.

### Model and population

- **One `Encounter` is exactly one ED visit** of a hospital, with triage, process times, disposition,
  initial vitals, flags and at most one link to an IVENA allocation.
- Its identity is a visit fingerprint computed in the tool (ADR 027, ADR 029). The administrative case
  number is carried as a pseudonym for the suspected-duplicate check only; it neither groups visits
  nor serves as a key.
- There is no patient-level key.
- `Allocation` gets no reference to encounter data.
- The pilot population is every ED visit of the export. Self-presenters and EMS arrivals without
  IVENA link are included on purpose. The **documented arrival route** (from the export) and the
  **IVENA link status** are separate attributes; a missing link says nothing about the arrival route.

### Two kinds of extension

| Kind | Varies by | Lives where |
|---|---|---|
| **Source profile**: how a vendor export maps to a schema | vendor, version, hospital configuration | data preparation tool (declarative profile; hospital-specific maps as a private local file) |
| **Schema**: a kind of data with fixed meaning | stable domain concept | server (`EncounterDimensionInterface`, tag `encounter.dimension`) |

A new vendor format needs a new profile only. A new kind of data needs a new schema on the server and
at least one profile.

### Schemas are semantic contracts

A schema describes a kind of record, not a vendor format. `ed_visit` (one row = one ED visit) takes
ED data from any ED system. Data of another shape gets its own schema instead of widening `ed_visit`
(`inpatient_stay`, `diagnosis`, `procedure` are expected later; how they attach is decided with them).
One source export may produce several canonical files.

Versioning: additive optional columns are a minor version that the server accepts next to the
previous one; removed or redefined columns are a new major version with a transition period.

The `ed_visit` contract in particular:

- **Disposition** distinguishes actual inpatient admission to a normal ward or intensive care,
  external transfer, and an unknown care level. Internal ward names and codes stay in the hospital's
  local map; only the derived care level is exchanged.
- **Vitals** keep the initial values as clinical context. The contract distinguishes a measurement
  with a documented reference to the admission from a documented first value without an assured time
  reference, fixes the units, and turns missing or implausible values into empty values that are
  counted in the import report.

### Canonical exchange format

The server imports only canonical exchange files:

- UTF-8, `;`, first line a manifest:
  `#encounter-exchange;schema=ed_visit;schema_version=1;profile=…;profile_version=…;tool=…;key_check=…;fingerprint=ed-visit:v1;rows=…`
- exact header of the schema version; any other column rejects the file
- typed fields with values in canonical codes, timestamps in Europe/Berlin local time with minute
  precision, age 1–99 as in IVENA

Raw vendor exports therefore cannot be imported, even if the user confirms otherwise. Excel input is
handled by the tool if a source needs it; the server stays CSV-only.

### Data preparation tool

A single HTML file with inlined CSS and JavaScript, rendered and versioned by the server and
downloaded from the encounter import page. It runs in the browser without installation:

- Content Security Policy blocks every request except one fixed URL `/data-prep/latest.json`
  (public, CORS `*`, no credentials, no query) used once at start for an update check. A blocked
  request leaves the tool usable offline. The server enforces the minimum tool version again on
  upload.
  *Amended for #1157 ([ADR 032](032-mts-catalog-as-maintained-data.md)):* a second fixed URL,
  `/data-prep/mts-catalog.json` (same rules: public, CORS `*`, no credentials, no query), serves the
  maintained MTS catalog at start; without an answer the tool uses its bundled copy.
- Allow-list transformation: every column not mapped by the profile is dropped. Names, dates of
  birth, staff columns and free text are not written to the output. Date of birth is only used inside
  the tool to derive age when no age column exists.
- Case number and visit fingerprint are keyed HMAC values (ADR 027).
- Profiles are declarative and cannot run code or make requests.

### Protection against identifying content

Local preparation, typed fields, the exact header check and content checks in the tool and on the
server **reduce the risk** that direct identifiers are uploaded. They do not detect every identifier;
the confirmation at upload and the governance of the pilot hospital remain necessary. The content
checks look for date-like values in non-date fields, name-like values and long free text.

### Upload, rejects and retention

- New `ImportType::ENCOUNTER` with its own handler, reusing import runs, coverage and deletion cleanup.
- The upload requires a confirmation that the file was produced with the tool and contains no direct
  identifiers; it is stored with user and time.
- A manifest, header or key finding rejects the whole file.
- **Row rejects** stay traceable for systematic error analysis: line number, field, error code and
  profile version. They never contain the full raw row or the suspicious cell value.
- **File retention in the beta:** accepted files and files rejected for a possible processing error
  are kept for `app.encounter.source_file_retention_days` (default 30) for error analysis and
  reprocessing after a bug fix, then deleted by a scheduled job.
- A file recognised as a raw HIS export or containing direct identifiers is deleted immediately; the
  rejection reason is recorded on the import.
- The rule for rejected files after the beta is open.
- The file retention applies to uploads only. Imported encounters and links serve local care
  improvement; they follow the deletion rule of ADR 029 and a retention decision of their own.

### IVENA case number

The ORBIS MTS export has no IVENA case number (ENR). The ENR is not part of `ed_visit` v1. For a later
source it is decided separately where the ENR becomes a matchable key and which copies arise at
upload, processing, rejects, downloads and backups (#1058).

## Consequences

**Positive:**

- Vendor and hospital specifics stay out of the server code; private mappings never need to be
  published
- Direct identifiers are unlikely to reach the server; the server verifies the format strictly
- Allocations, projection and existing statistics are untouched
- New sources of an existing kind only need a profile
- Self-presenters and EMS arrivals without allocation are first-class encounters

**Negative:**

- A second, browser-side code base (tool and profiles) with its own tests and releases
- Every schema change touches server, tool and profiles together
- Mapping errors in a hospital-specific profile are only visible through the tool's preview and the
  import report
- Content checks can miss identifiers; pseudonymised data is still personal data, so access control
  (ADR 028) and governance remain necessary
- Uploaded files stay on the server for up to 30 days

## Alternatives

- **Import vendor exports directly on the server with server-side plugins**: rejected; raw identifiers
  would reach the server, and private hospital mappings would become server code.
- **Desktop or command-line preparation tool**: rejected; hospital IT departments usually forbid
  installing or running programs.
- **One wide schema for all hospital data**: rejected; ED visits, stays and diagnoses have different
  shapes and cardinalities.
- **Store encounter fields on `Allocation`**: rejected; most ED visits have no allocation, and the
  allocation pipeline and statistics must not change.
- **Administrative case number as encounter identity**: rejected; one case number can cover several
  ED visits.
- **Delete files right after import**: rejected for the beta; processing bugs could not be analysed
  or fixed by reprocessing.

## References

- [007-bounded-contexts-and-dependency-directions.md](007-bounded-contexts-and-dependency-directions.md)
- [020-portable-reference-catalog.md](020-portable-reference-catalog.md)
- [027-encounter-pseudonym-key.md](027-encounter-pseudonym-key.md)
- [028-encounter-access-grants.md](028-encounter-access-grants.md)
- [029-encounter-import-lifecycle.md](029-encounter-import-lifecycle.md)
- [030-encounter-allocation-matching.md](030-encounter-allocation-matching.md)
- Issues #1043, #1044, #1045, #1058
