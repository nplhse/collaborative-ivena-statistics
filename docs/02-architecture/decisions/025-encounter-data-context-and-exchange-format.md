# ADR 025: Encounter data context, source profiles and canonical exchange format

**Status:** accepted

## Context

Participating hospitals hold routine emergency department (ED) data next to the IVENA allocations:
triage (Manchester Triage System, MTS), process times, disposition and initial vitals, later also
controlling data (stays, diagnoses, procedures). Linking these data with allocations adds the
clinical side of an allocation. The pilot (#1043) starts with the MTS export of ORBIS.

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
- **One administrative case can contain several ED visits.** The ORBIS export shows several visits
  under one case number (return visits within the same case).
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

### Model

- `Encounter` = one administrative case of a hospital, identified by a pseudonym of the case number
  (ADR 026).
- `EdVisit` = one ED visit within that case. Triage, process times, disposition, initial vitals and
  the link to an IVENA allocation belong to the visit.
- There is no patient-level key. Visits are grouped only under the same administrative case number;
  nothing links different administrative cases of the same person.
- `Allocation` gets no reference to encounter data.

### Two kinds of extension

| Kind | Varies by | Lives where |
|---|---|---|
| **Source profile**: how a vendor export maps to a schema | vendor, version, hospital configuration | data preparation tool (declarative profile; hospital-specific maps as a private local file) |
| **Schema**: a kind of data with fixed meaning | stable domain concept | server (`EncounterDimensionInterface`, tag `encounter.dimension`) |

A new vendor format needs a new profile only. A new kind of data needs a new schema on the server and
at least one profile.

### Schemas are semantic contracts

A schema describes a kind of record, not a vendor format. `ed_visit` (one row = one ED visit) takes
ED data from any ED system. Data of another shape gets its own schema instead of widening `ed_visit`:
`inpatient_stay`, `diagnosis` and `procedure` are expected later and attach to `Encounter`. One source
export may produce several canonical files.

Versioning: additive optional columns are a minor version that the server accepts next to the
previous one; removed or redefined columns are a new major version with a transition period.

### Canonical exchange format

The server imports only canonical exchange files:

- UTF-8, `;`, first line a manifest:
  `#encounter-exchange;schema=ed_visit;schema_version=1;profile=…;profile_version=…;tool=…;key_check=…;rows=…`
- exact header of the schema version; any other column rejects the file
- values already in canonical codes (catalogs of the schema), timestamps in Europe/Berlin local time
  with minute precision, age 1–99 as in IVENA

Raw vendor exports therefore cannot be imported, even if the user confirms otherwise. Excel input is
handled by the tool if a source needs it; the server stays CSV-only.

### Data preparation tool

A single HTML file with inlined CSS and JavaScript, rendered and versioned by the server and
downloaded from the encounter import page. It runs in the browser without installation:

- Content Security Policy blocks every request except one fixed URL `/data-prep/latest.json`
  (public, CORS `*`, no credentials, no query) used once at start for an update check. A blocked
  request leaves the tool usable offline. The server enforces the minimum tool version again on
  upload.
- Allow-list transformation: every column not mapped by the profile is dropped. Names, dates of
  birth, staff columns and free text never reach the output. Date of birth is only used inside the
  tool to derive age when no age column exists.
- The case number is replaced by a keyed pseudonym (ADR 026).
- A residual scan for identifying content (date-like values in non-date fields, name-like values,
  long free text) blocks saving; the server repeats it.
- Profiles are declarative and cannot run code or make requests.

### Upload, retention and validation

- New `ImportType::ENCOUNTER` with its own handler, reusing import runs, rejects, coverage and
  deletion cleanup.
- The upload requires a confirmation that the file was produced with the tool and contains no direct
  identifiers; it is stored with user and time.
- Any manifest, header, key or identifying-content finding rejects the whole file.
- Encounter source files are deleted after `app.encounter.source_file_retention_days` (default 30)
  by a scheduled job. Unlike allocation and closure files they are not kept for later reprocessing:
  they contain no raw data that a better parser could exploit, and the window only covers processing
  bugs. Imported rows stay.

### IVENA case number

The ORBIS MTS export has no IVENA case number (ENR). The ENR is not part of `ed_visit` v1. How a
plain ENR may enter later sources (upload file retention, rejects, downloads, backups) is decided
separately (#1058).

## Consequences

**Positive:**

- Vendor and hospital specifics stay out of the server code; private mappings never need to be
  published
- Direct identifiers never reach the server; the server can verify the format strictly
- Allocations, projection and existing statistics are untouched
- New sources of an existing kind only need a profile
- Self-presenters and EMS arrivals without allocation are first-class visits

**Negative:**

- A second, browser-side code base (tool and profiles) with its own tests and releases
- Every schema change touches server, tool and profiles together
- Mapping errors in a hospital-specific profile are only visible through the tool's preview and the
  import report
- Pseudonymised data is still personal data; access control (ADR 027) and governance remain
  necessary

## Alternatives

- **Import vendor exports directly on the server with server-side plugins**: rejected; raw identifiers
  would reach the server, and private hospital mappings would become server code.
- **Desktop or command-line preparation tool**: rejected; hospital IT departments usually forbid
  installing or running programs.
- **One wide schema for all hospital data**: rejected; ED visits, stays and diagnoses have different
  shapes and cardinalities.
- **Store encounter fields on `Allocation`**: rejected; most ED visits have no allocation, and the
  allocation pipeline and statistics must not change.
- **Case number as visit identity**: rejected; one administrative case can contain several ED visits.
- **Keep source files like allocation files**: rejected; no reprocessing benefit, more exposure.

## References

- [007-bounded-contexts-and-dependency-directions.md](007-bounded-contexts-and-dependency-directions.md)
- [020-portable-reference-catalog.md](020-portable-reference-catalog.md)
- [026-encounter-pseudonym-key.md](026-encounter-pseudonym-key.md)
- [027-encounter-access-grants.md](027-encounter-access-grants.md)
- [028-encounter-import-lifecycle.md](028-encounter-import-lifecycle.md)
- [029-encounter-allocation-matching.md](029-encounter-allocation-matching.md)
- Issues #1043, #1044, #1058
