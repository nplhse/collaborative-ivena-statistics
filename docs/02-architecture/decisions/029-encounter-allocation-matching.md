# ADR 029: Matching ED visits with IVENA allocations

**Status:** accepted

## Context

Without a shared identifier (the ORBIS MTS export has no IVENA case number), an ED visit can only be
linked to an IVENA allocation by comparing its features. IVENA data can be wrong: the arrival time is
an expected time, the age an estimate. Linkage errors would distort every later analysis, so each link
needs a traceable quality and a human must be able to correct it.

A local feasibility check against real data showed that most EMS allocations have exactly one
plausible ED visit when time, sex and age are compared, and that the documented ED admission usually
follows the expected IVENA arrival by a few minutes. It says nothing about hit quality yet; there is
no ground truth.

## Decision

### Candidates and features

- Candidates come from the same hospital only.
- Pass 1: visits with an EMS arrival mode, ED admission within −20 … +45 min of the IVENA arrival,
  age ±2. Pass 2: remaining allocations, all arrival modes, −30 … +90 min, age ±5. Windows are
  configurable.
- Arrival anchor: documented ED admission, fallback first contact of the initial assessment. The
  anchor source is stored and shown.
- Features: time difference, sex, age difference, arrival mode, physician vehicle ↔
  `isWithPhysician`, work accident ↔ `isWorkAccident`.
- **Not features:** MTS category and diagram, admitting department. They are outcomes to be compared
  with IVENA urgency and department later; using them for linkage would bias those analyses.

### Score

- Score 0.0–1.0 from weighted features, with competition: all candidates of an allocation and a
  "no match" option share the score, so two similar candidates both score low.
- The feature breakdown and the matcher version are stored with every link.
- The UI shows the score as agreement ("Übereinstimmung"), not as a probability, until it is
  validated.

### Status

| Status | Rule |
|---|---|
| `auto_accepted` | score ≥ auto-accept threshold (default 0.90) and no conflict; shown as "automatisch, ungeprüft" everywhere until a person confirms it |
| `proposed` | score ≥ proposal threshold (default 0.50) |
| `confirmed` / `rejected` | manual decision; sticky across reruns; rejected pairs are never proposed again |
| `conflict` | a visit is claimed by several allocations; never auto-accepted |
| `orphaned` | the visit disappeared in a newer import (ADR 028) |

Thresholds live in the `app:` config tree. Links are one-to-one.

### Reruns and validation

- Matching reruns via Messenger for the affected hospital-days after encounter or allocation imports
  and deletions. Manual decisions are never changed by a rerun.
- Before the score is presented as a probability or auto-accepted links count as confirmed,
  match quality is validated with stratified review samples (auto-accepted, proposed, without
  proposal) and synthetic data with known links (#1057).

### Population groups

Every visit is shown in one group: linked (confirmed), linked (automatic, unreviewed), EMS arrival
without confirmed link, self-presenter, arrival route unknown. The arrival route comes from the
clinical export; a missing link says nothing about how someone came to the ED.

## Consequences

**Positive:**

- Every link is explainable and correctable
- Auto-accepted links are visible as such; statistics can include or exclude them
- Linkage does not use the variables that later analyses compare

**Negative:**

- Review work for the hospital
- Until validation, the score is a ranking, not a calibrated probability
- Wrong IVENA times or ages reduce linkage; such allocations remain unlinked

## Alternatives

- **Manual linkage only**: rejected; too much work per month for a hospital.
- **Auto-accept without marking**: rejected; unvalidated links would look like confirmed ones.
- **Include MTS category and department in the score**: rejected; it biases the comparison of IVENA
  urgency and department with clinical triage and admission.
- **Deterministic matching only (IVENA case number)**: not possible for the first source (#1058).

## References

- [025-encounter-data-context-and-exchange-format.md](025-encounter-data-context-and-exchange-format.md)
- [028-encounter-import-lifecycle.md](028-encounter-import-lifecycle.md)
- Issues #1053, #1054, #1057
