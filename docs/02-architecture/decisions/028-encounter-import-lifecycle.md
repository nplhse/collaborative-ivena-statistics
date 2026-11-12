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
  with a versioned rule. Rule `ed-visit:v1` (#1045): normalised administrative case number, the time
  anchor in its original second resolution and the anchor used. The anchor is the first contact of
  the initial assessment, or the ED admission time when that is missing. The initial assessment comes
  first because ED admission times can be missing for whole periods and be entered later, which would
  change the fingerprint. The exchanged timestamps stay at minute precision. Triage, vitals and
  disposition are not part of the fingerprint. Exact normalisation and input format:
  [exchange-format.md](../../04-features/encounter/exchange-format.md#visit-fingerprint-ed-visitv1).
- The server enforces uniqueness of `(hospital, key generation, fingerprint)` with a database
  constraint, so parallel imports cannot create the same visit twice.
- Rows without any time anchor are skipped and counted in the import report; they get no encounter.
- The rule could not be tested across two real exports before the pilot. Its stability is measured in
  operation: every file also carries the case pseudonym, and the import reports a **suspected
  duplicate** when a new visit has the same case pseudonym as an existing visit and a time anchor
  within 15 minutes of it. Suspected duplicates are reported, not merged. A rule change becomes
  `ed-visit:v2`.

### Import

- Duplicate detection runs row by row over the whole file.
- A known visit is **skipped** and counted as duplicate in the import report; the existing record and
  the import it belongs to are unchanged.
- An unknown visit is inserted and **belongs to the import that created it**.
- Overlapping exports therefore work, also when known and new visits are mixed in one file.
- There are no historical row versions and no replacement of whole cases. Corrections of known visits
  in later exports are not applied.

### Reprocessing

Reprocessing an import (after a bug fix or for any other reason) works as for allocation and closure
imports: the run first removes all data the import created — its visits with their clinical data,
IVENA links and review decisions — and then processes the retained file completely again with the
same duplicate check. Rows that belong to other imports are skipped again; the import's own rows are
created anew. Reprocessing is only possible while the file is retained (ADR 025).

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
- Reprocessing, like deletion, removes the links and review decisions of the import's visits; they are
  rebuilt only by matching (automatic links) or by people (decisions)
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
