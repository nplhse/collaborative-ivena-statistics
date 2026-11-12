# ADR 028: Encounter import lifecycle — one visit, one owning import

**Status:** accepted

## Context

Hospitals export encounter data by period. Exports overlap, and one file can mix visits that are
already known with new ones. Imports can be deleted. Links and review decisions (ADR 029) must stay
consistent with that.

Forces:

- The ORBIS MTS export has no per-visit identifier, and one administrative case number can cover
  several ED visits (ADR 025).
- Duplicates must be detected per row, not per file.
- Deleting an import must have a simple, predictable result.

## Decision

### Visit identity

- Each encounter (= one ED visit) has an internal `visit_uid`.
- Duplicate detection uses a **visit fingerprint** computed in the tool as a keyed HMAC (ADR 026)
  with a versioned rule (`ed-visit:v1`). The rule is determined from the source fields in #1045;
  the first candidate is the normalised administrative case number together with the ED admission time
  in its original second resolution, with the time anchor used (and its fallback) part of the input.
  Seconds may enter the fingerprint while the exchanged timestamps stay at minute precision. Triage,
  vitals and disposition are not part of the fingerprint.
- The server enforces uniqueness of `(hospital, key generation, fingerprint)` with a database
  constraint, so parallel imports cannot create the same visit twice.
- How rows without a reliable time anchor are handled (skipped, or imported with a visible notice and
  without duplicate detection) is decided in #1045.

### Import

- Duplicate detection runs row by row over the whole file.
- A known visit is **skipped** and counted as duplicate in the import report; the existing record and
  the import it belongs to are unchanged.
- An unknown visit is inserted and **belongs to the import that created it**.
- Overlapping exports therefore work, also when known and new visits are mixed in one file.
- There are no historical row versions and no replacement of whole cases. Corrections of known visits
  in later exports are not applied.

### Deletion

Deleting an import removes the visits it created, with their clinical data, IVENA links and review
decisions. IVENA allocations and visits created by other imports stay.

Example: import A covers 1–15 October, import B covers 10–31 October. B's rows for 10–15 October are
skipped as duplicates. When B is deleted, all visits created by A stay; the visits of 16–31 October
created by B disappear.

## Consequences

**Positive:**

- Simple and predictable: each visit has one owner, deletion removes exactly what an import added
- Overlapping exports and mixed files need no special handling
- Parallel imports cannot create duplicates

**Negative:**

- Corrections in later exports are ignored; adopting them requires deleting the owning import and
  importing again (which removes its links and decisions)
- Reprocessing a retained file after a bug fix only adds visits that were not created before (for
  example after a failed run). If the bug stored wrong values, reprocessing does not correct them;
  the rule is again: delete the import and import again, losing its links and review decisions
- Deleting an import can remove visits that a later overlapping export also contained; that export
  must be reprocessed to restore them
- A changed fingerprint input (for example an ED admission time entered later) makes the same visit
  look new; #1045 measures how often this happens

## Alternatives

- **Versioned rows with the administrative case as unit of replacement**: rejected; complex, and the
  case number does not identify visits.
- **Upsert of known visits with the newest values**: rejected; deletion of the newer import could not
  restore the previous values, and review decisions would rest on changing data.
- **Natural key in clear (case number + time)**: rejected; the case number must not reach the server.

## References

- [025-encounter-data-context-and-exchange-format.md](025-encounter-data-context-and-exchange-format.md)
- [026-encounter-pseudonym-key.md](026-encounter-pseudonym-key.md)
- [029-encounter-allocation-matching.md](029-encounter-allocation-matching.md)
- Issues #1044, #1045, #1047, #1048
