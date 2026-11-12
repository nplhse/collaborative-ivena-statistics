# ADR 028: Encounter import lifecycle with versioned rows and stable visit identity

**Status:** accepted

## Context

Hospitals export encounter data by period. Exports overlap, later exports correct earlier ones,
and imports can be deleted. Match decisions (ADR 029) are made by people and must survive these
changes.

Forces:

- The ORBIS MTS export has no per-visit identifier. Its second case id is 1:1 with the case number.
  Whether a visit id can be exported is open (#1045).
- One administrative case can contain several ED visits.
- Arrival times can be missing, corrected or rounded, so they are a fragile visit identity.
- Deleting a correcting import must bring back the previous state, not a gap.

## Decision

### Versioned rows

Every encounter import stores its own rows; all rows carry `import_id`. Rows are never updated in
place by another import.

### Administrative case as unit of replacement

For each `(hospital, case pseudonym)` the current state comes from the newest non-deleted import that
contains this case (newest = upload order). A newer import replaces all visits of the cases it
contains; cases it does not contain keep their state. After every import completion and deletion the
current state is recomputed for the affected cases, then matching reruns for the affected days.

### Stable visit identity

Each visit has a stable surrogate `visit_uid`. When a new version of a case arrives, its visits are
mapped to existing `visit_uid`s within the same case:

1. by visit pseudonym, if the source provides a visit id (#1045)
2. otherwise by arrival time within a tolerance (default ±15 min) and order within the case

Visits that cannot be mapped get a new `visit_uid`.

### Decisions bound to the visit

Allocation links and match decisions reference `visit_uid`, not a row:

- Visit present in the current version → decision stays. If match features changed (sex, age, arrival
  time beyond the tolerance), a confirmed decision stays but is flagged "source changed after
  decision" for re-review.
- Visit no longer present → decision becomes `orphaned` and is shown in the review. It is never moved
  to another visit silently.

### Required behaviour (test case)

Import A contains visit V. Import B corrects V's triage. B is deleted. Then A is current again, V
shows A's triage, keeps its `visit_uid` and keeps its match decision.

## Consequences

**Positive:**

- Deleting or reprocessing an import has a defined result
- Corrections arrive without manual merging
- Human decisions survive re-imports and are never reassigned silently

**Negative:**

- Storage grows with every overlapping import (old versions stay until their import is deleted)
- Current-state recomputation adds work after each import and deletion
- Visit mapping by arrival time can split or merge visits when times are corrected heavily; such
  cases surface as orphaned decisions

## Alternatives

- **Upsert by natural key (case pseudonym + arrival time)**: rejected; corrected or missing times
  create duplicates, and deleting the correcting import cannot restore the previous state.
- **Visit as unit of replacement**: rejected; without a visit id there is no reliable key across
  imports.
- **Newest import replaces everything of the hospital**: rejected; partial or period exports would
  delete data of other periods.

## References

- [025-encounter-data-context-and-exchange-format.md](025-encounter-data-context-and-exchange-format.md)
- [029-encounter-allocation-matching.md](029-encounter-allocation-matching.md)
- [../../04-features/import/import-runs.md](../../04-features/import/import-runs.md)
- Issues #1044, #1045, #1047
