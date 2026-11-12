# ADR 027: Encounter access grants independent of hospital permissions

**Status:** accepted

## Context

Encounter data (ADR 025) is pseudonymised, but still personal health data. The existing access model
does not fit it:

- Explore shows allocations of all hospitals to every participant (ADR 011).
- Hospital grants are a bitmask (ADR 002), and `HospitalPermissionAccess` gives owners and
  administrators every permission on a hospital implicitly.
- Hospitals grant VIEW to people who need the IVENA data, not necessarily the clinical case data.

The pilot needs case-level access that is granted explicitly per person and hospital, and separate
actions for uploading, reviewing matches and viewing cases.

## Decision

### Beta role

`ROLE_ENCOUNTER_BETA` controls participation in the pilot: navigation and encounter pages. It is
inherited by `ROLE_ADMIN`. It grants no access to case data.

### Encounter grants

A separate grant in the `Encounter` context (user, hospital, actions), checked by `EncounterVoter`:

| Action | Allows |
|---|---|
| `UPLOAD` | encounter imports for the hospital, viewing and downloading its retained upload files, reprocessing them; does **not** include the key change |
| `REVIEW` | match review decisions; implies `VIEW_CASES` |
| `VIEW_CASES` | case-level views: visit lists, the case card on an allocation, direct URLs |

- Hospital ownership, VIEW or other hospital grants and `ROLE_ADMIN` do **not** imply an encounter
  grant. This deliberately deviates from `HospitalPermissionAccess` for this data.
- Only users with access to the hospital (owner or hospital grant) can receive an encounter grant.
  Removing that access removes the encounter grant.
- Grants are managed by the hospital owner and by administrators, the same people who manage hospital
  access grants. An owner can grant themselves case access explicitly. Every change is audited.
- The key change (ADR 026) is reserved for the hospital owner; no grant and no administrator role
  allows it.
- Administrators without a grant see import status, counts, reject codes and match diagnostics
  without case details. They may trigger reprocessing of a retained file after a bug fix, which shows
  no content. Viewing or downloading a retained upload file and case-level access need a grant like
  for anyone else; import diagnostics alone never open file content.
- Descriptive aggregates of the own hospital need the beta role, any encounter grant on the hospital
  and the existing `STATISTICS` permission.

### Boundaries

- Every case-level request resolves the hospital of the requested object and checks the grant
  there. A visit of another hospital is not found, including direct URLs.
- The allocation case file in Explore shows encounter data only with `VIEW_CASES` on the
  allocation's hospital. Encounter fields, filters or cards never appear for other hospitals in
  cross-hospital Explore.
- No cross-hospital aggregates in the pilot. A later decision may show aggregates to hospitals that
  contribute the same kind of data themselves.

## Consequences

**Positive:**

- Case access is a conscious, per-person, per-hospital decision, visible in the audit log
- Uploading, reviewing and viewing can be given to different people
- IVENA access and encounter access stay independent

**Negative:**

- A second grant model next to ADR 002 that owners and administrators must maintain
- Administrators need an explicit grant for support cases that require case details
- The hospital pages need a link to encounter grant management without a dependency from
  `Allocation` on `Encounter`

## Alternatives

- **Reuse VIEW/IMPORT grants plus the beta role**: rejected; everyone with VIEW on a hospital would
  see case data once they get the role.
- **New bit in the hospital bitmask**: rejected; owners and administrators get all bits implicitly,
  and the bitmask has no room for an exception per bit.
- **Collaborative visibility as in ADR 011**: rejected; case-level clinical data is not shared across
  hospitals.

## References

- [002-hospital-permission-bitmask.md](002-hospital-permission-bitmask.md)
- [011-collaborative-explore-allocation-visibility.md](011-collaborative-explore-allocation-visibility.md)
- [025-encounter-data-context-and-exchange-format.md](025-encounter-data-context-and-exchange-format.md)
- [026-encounter-pseudonym-key.md](026-encounter-pseudonym-key.md)
- [../permission-model.md](../permission-model.md)
- Issues #1044, #1046
