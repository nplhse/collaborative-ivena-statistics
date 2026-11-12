# ADR 029: Matching ED visits with IVENA allocations

**Status:** accepted

## Context

Without a shared identifier (the ORBIS MTS export has no IVENA case number), an ED visit can only be
linked to an IVENA allocation by comparing its features. IVENA data can be wrong: the arrival time is
an expected time, the age an estimate. Linkage errors would distort later analyses, so each link needs
a traceable origin and quality, and people must be able to correct it. Confirming every link by hand
is too much work for a hospital.

A local feasibility check against real data showed that most EMS allocations have exactly one
plausible ED visit when time, sex and age are compared, and that the documented ED admission usually
follows the expected IVENA arrival by a few minutes. It says nothing about hit quality yet; there is
no ground truth.

## Decision

### Candidates and features

- Candidates come from the same hospital only.
- Pass 1: visits with an EMS arrival route, ED admission within −20 … +45 min of the IVENA arrival,
  age ±2. Pass 2: remaining allocations, all arrival routes, −30 … +90 min, age ±5. Windows are
  configurable.
- Time anchor: documented ED admission, otherwise the first contact of the initial assessment as a
  marked substitute. The anchor used is stored and shown.
- Features: time difference, sex, age difference, arrival route, physician vehicle ↔
  `isWithPhysician`, work accident ↔ `isWorkAccident`.
- **Not features:** MTS category, MTS diagram and admitting department. They will be compared with the
  IVENA attributes later; using them for linkage would bias those comparisons.

### Score

- Score 0.0–1.0 from weighted features, with competition: all candidates of an allocation and a
  "no match" option share the score, so two similar candidates both score low.
- The feature contributions and the matcher version are stored with every link.
- The weights of matcher v1 (feature points and the "no match" option) are set by judgement, not
  estimated; the match validation (#1057) calibrates them.
- The score describes agreement ("Übereinstimmung"). It is shown as a hit probability only after
  calibration.

### Link status

| Status | Rule |
|---|---|
| `auto_accepted` | score ≥ auto-accept threshold (default 0.90) and no conflict (below); **an effective link** for the case card and linked analyses; its automatic origin stays visible |
| `proposed` | score ≥ proposal threshold (default 0.50); not effective until confirmed |
| `confirmed` | manual decision, effective |
| `rejected` | manual decision; the pair is never proposed again |

Thresholds live in the `app:` config tree. Links are one-to-one.

**Conflict:** within one pass, the competing claims are checked **before** any reservation. If two or
more allocations reach the auto-accept threshold for the same visit in the same pass, none of them
becomes `auto_accepted`; the claims are marked as conflict and go to the review as proposals. (Two
visits cannot both reach the threshold for one allocation, because candidates share the score.)
Allocations and visits of an unresolved conflict are not reserved, so later passes may fill them
only with the same check.
 Automatic links do not need manual
confirmation in the pilot.

### Runs

- Pass 1 reserves both sides of an effective link. Later passes and reruns only fill gaps among
  unlinked allocations and unlinked visits.
- A regular rerun never reassigns an existing link and never changes a manual decision.
- If an unlinked visit suggests a better overall assignment, the review can propose a targeted swap;
  it is applied only by a person.
- Reruns run via Messenger for the affected hospital-days after encounter or allocation imports and
  deletions.
- Open proposals are not kept: each run recomputes the proposals of the allocations it covers. A pair
  proposed in pass 1 keeps its pass-1 score when pass 2 sees it again; proposals touching a side that
  is linked at the end of the run are removed.
- Deleting, reprocessing or requeueing an allocation import removes the links and manual decisions of
  its allocations (as ADR 028 does for the visits of an encounter import). A reprocessed import gets new
  allocations; the next run links them again automatically, manual decisions must be made again.

### Review

The review shows the expected IVENA arrival, the documented ED admission or the marked substitute,
the time difference, the contribution of each feature and competing candidates. Users can confirm,
reject, change or leave open a link; decisions are audited.

### Quality check

Samples from automatic, proposed and unlinked cases check the quality; synthetic data with known
links tests the thresholds (#1057). Calibration of the score into a probability follows from these
results.

## Consequences

**Positive:**

- Most links work without manual work; every link is explainable and correctable
- Automatic links are recognisable, so analyses can show their share
- Existing links are stable across reruns
- Linkage does not use the variables that later analyses compare

**Negative:**

- Wrong automatic links are effective until someone finds them in a sample or the review
- Until calibration, the score is a ranking, not a probability
- Wrong IVENA times or ages reduce linkage; such allocations remain unlinked
- Greedy reservation in pass 1 can miss a better global assignment; only a person can swap
- Reprocessing or requeueing an allocation import loses the manual decisions on its allocations; they
  have to be made again

## Alternatives

- **Manual confirmation of every link**: rejected; too much work per month for a hospital.
- **Automatic links only as proposals**: rejected; the pilot would show almost no linked data.
- **Global reassignment on every run**: rejected; links would change under people's decisions.
- **Include MTS category and department in the score**: rejected; it biases the comparison of IVENA
  urgency and department with clinical triage and admission.
- **Deterministic matching only (IVENA case number)**: not possible for the first source (#1058).

## References

- [025-encounter-data-context-and-exchange-format.md](025-encounter-data-context-and-exchange-format.md)
- [028-encounter-import-lifecycle.md](028-encounter-import-lifecycle.md)
- Issues #1053, #1054, #1057
