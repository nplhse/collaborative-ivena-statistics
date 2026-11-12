# Encounter data: material for the pilot hospital

**Audience:** The pilot hospital (data protection officer, ED management, IT) and the project team.

**Status:** draft for the pilot (#1043). The project assumes the data flow is permitted; the hospital
clarifies and documents it before the first real upload.

## What the hospital decides and documents

| Topic | Project proposal |
|---|---|
| Purpose | Local care improvement: describe triage, process times and disposition of ED visits by arrival route, and link them with IVENA allocations. |
| Population | All ED visits of the export, including self-presenters. Self-presenters are part of the real ED case mix; without them, arrival routes cannot be compared. |
| Field set | `ed_visit` v1 ([exchange-format.md](exchange-format.md)). No names, dates of birth, staff names or free text; case number only as a pseudonym. |
| Pseudonymisation | Keyed pseudonym of the case number with a key file held only by the hospital ([ADR 026](../../02-architecture/decisions/026-encounter-pseudonym-key.md)). The data is pseudonymised, not anonymised. |
| Responsibilities | The hospital owns the data and the key file; the project operates the platform. To be confirmed by the hospital. |
| Recipients | Only persons with an explicit encounter grant for the hospital see case-level data; grants are managed by the hospital owner and administrators and are audited ([ADR 027](../../02-architecture/decisions/027-encounter-access-grants.md)). No other hospital sees the data. |
| Retention | Uploaded files: deleted after 30 days. Imported data: until the hospital deletes the import or resets the key. |

## Data flow

1. Export from the HIS on a hospital computer.
2. Preparation in the browser tool on the same computer, offline except for one update check. The
   original export and the key file never leave the computer.
3. Upload of the canonical file by a person with the upload grant, with confirmation.
4. Storage of the file for at most 30 days; storage of the imported rows on the platform.
5. Access in the application according to the encounter grants.

## Checklist before the first upload

- [ ] Purpose and population confirmed
- [ ] Field set reviewed against the export
- [ ] Responsibilities and recipients documented
- [ ] Key file created and stored in a team folder with restricted access
- [ ] Encounter grants assigned to the named persons
- [ ] Local map for departments and stations prepared
