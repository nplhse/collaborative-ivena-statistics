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
| Pseudonymisation | Keyed visit fingerprint (and optionally a keyed pseudonym of the case number) with a key file held only by the hospital; only the hospital owner or a platform administrator can change the key ([ADR 026](../../02-architecture/decisions/026-encounter-pseudonym-key.md)). The data is pseudonymised, not anonymised. Local preparation and the checks reduce the risk of uploading identifiers but cannot rule it out. |
| Responsibilities | The hospital owns the data and the key file; the project operates the platform. To be confirmed by the hospital. |
| Recipients | Persons with an explicit encounter grant for the hospital see case-level data; grants are managed by the hospital owner and administrators and are audited. Platform administrators also have access to case-level data of every hospital without a grant ([ADR 027](../../02-architecture/decisions/027-encounter-access-grants.md)). No other hospital sees the data. |
| Retention | Uploaded files (accepted, or rejected for a possible processing error): kept 30 days for error analysis, then deleted. Files recognised as raw HIS exports or containing direct identifiers: deleted immediately, reason recorded. Imported encounters and links: until the owning import is deleted or the owner or an administrator deletes them with a key change; a separate retention period is still to be decided. |

## Data flow

1. Export from the HIS on a hospital computer.
2. Preparation in the browser tool on the same computer, offline except for one update check. The
   original export and the key file never leave the computer.
3. Upload of the canonical file by a person with the upload grant, with confirmation.
4. Storage of the file for at most 30 days; storage of the imported encounters on the platform.
5. Access in the application according to the encounter grants.

## Checklist before the first upload

- [ ] Purpose and population confirmed
- [ ] Field set reviewed against the export
- [ ] Responsibilities and recipients documented
- [ ] Key file created and stored in a team folder with restricted access
- [ ] Encounter grants assigned to the named persons
- [ ] Local map for departments and stations prepared
