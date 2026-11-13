# ADR 030: Matching ED visits with IVENA allocations

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
  *Amended for matcher 1.2.0:* the MTS diagram enters pass 2 as a bonus only, with the bias recorded and
  handled ([matcher 1.2.0](#amendment-matcher-120)). MTS category and admitting department stay out; the
  MTS indicator is a reserved level of the plausibility table that `1.2.0` does not read.

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
  its allocations (as ADR 029 does for the visits of an encounter import). A reprocessed import gets new
  allocations; the next run links them again automatically, manual decisions must be made again.

### Review

The review shows the expected IVENA arrival, the documented ED admission or the marked substitute,
the time difference, the contribution of each feature and competing candidates. Users can confirm,
reject, change or leave open a link; decisions are audited.

### Quality check

Samples from automatic, proposed and unlinked cases check the quality; synthetic data with known
links tests the thresholds (#1057). Calibration of the score into a probability follows from these
results. Validation verdicts act on the links as review decisions do: "correct" confirms
the pair, "wrong" rejects it, and a partner chosen instead is linked (a link elsewhere only through a swap
the review proposes).

### Matcher versions

The points and the "no match" weight form a named matcher version (`MatchWeights`). Every pair stores
the version that scored it; earlier versions stay defined so that stored pairs remain reproducible and
replays (`app:encounter:evaluate-matcher --weights=<version>`) can start from them. Thresholds (0.90 /
0.50), passes and windows are configuration, not part of the version.

| Version | Change | Status |
|---|---|---|
| `1.0.0` | points set by judgement (feature table in the [encounter docs](../../04-features/encounter/README.md#matching)) | replaced by `1.1.0` |
| `1.1.0` | `time.near` +2 → +3; `sex.different` −4 → −2; everything else as in `1.0.0` | production until `1.2.0` |
| `1.2.0` | plausibility bonus in pass 2 from the active snapshot (cap +3); ambulance (RTW) with IVENA "with physician" +1 instead of −1 ([amendment](#amendment-matcher-120)) | planned (#1154) |

**Why 1.1.0.** The match validation of one pilot hospital (October 2026) found:

- 0 wrong among 69 decisive verdicts on pairs, across all score bands: the score of v1 is conservative.
- 51 of 84 proposals of v1 were single-candidate pairs that failed only the time core (−5 … +15 min). With
  `time.near` +3, such a pair with everything else matching scores 3 + 2 + 2 + 1 = 8 points,
  2^8 / (2^8 + 2^4) = 0.941, and is linked automatically (v1: 7 points, 0.889, a proposal).
- 14 of 15 partners the matcher missed differed only in sex (13 of them female in IVENA, male in the
  source), all with exact age, a time difference of 0–11 min and EMS arrival. With `sex.different` −2 such
  a pair scores 4 − 2 + 2 + 1 = 5 points, 2^5 / (2^5 + 2^4) = 0.667, and becomes a proposal (v1: 3 points,
  0.333, not stored). A smaller penalty was not chosen: a sex mismatch stays a case for a person and should
  not approach the auto-accept threshold.

**Effect on stored pairs.** A new version follows the rules for runs above; nothing is rescored in bulk:

- Automatic links and manual decisions of `1.0.0` stay as they are, with their score, contributions and
  version. They are effective links and a rerun never changes them.
- Open proposals are not kept: the next run over their allocations (after an import, or
  `app:encounter:match` for a hospital and period) deletes them and plans them again with `1.1.0`. A former
  v1 proposal may then become an automatic link.
- Allocations and visits without a link are matched with `1.1.0` on that run, so pairs with different sex
  can appear as new proposals.

Analyses and validation results that compare link rates or shares correct keep the matcher version apart
(the validation overview adds up verdicts per matcher version).

## Amendment: matcher 1.2.0

Accepted with [ADR 032](032-learning-plausibility-table.md) (#1136, #1152); implemented in #1154.

### Why the diagram enters after all

The original reason stands: a variable used for linkage agrees more often in linked pairs than in true
pairs, which biases any comparison of that variable. It was weighed against the link rate on real data. On
the pilot hospital (October 2026) the IVENA indication (PZC) and the MTS diagram are plausible together in
72 % of the true pairs but in 21 % of the other candidates; a bonus for that lets otherwise strong pairs with
one contradicting feature become automatic links (85 % → about 91 %, no link to another visit than the
reference). Measurements and alternatives: ADR 032.

The bias is limited instead of avoided:

- **Diagram only.** Only the MTS diagram is compared, with the exact PZC (a curated problem area of PZC
  codes as fallback for sparse cells). The MTS indicator is a reserved level of the table, not read by
  `1.2.0`; MTS category and admitting department stay out; IVENA urgency is never compared with the MTS category.
- **Pass 2 only.** Pass 1 stays as it is, so its links carry no linkage bias from the diagram.
- **Bonus only.** A plausible cell adds 0 … +3 points; an implausible one subtracts nothing. A penalty hit
  true pairs in gaps of the mapping and lowered the rate.
- **Recorded.** Every pair scored by 1.2.0 records the bonus and the snapshot; analyses comparing PZC with
  the MTS diagram exclude or separate links the bonus decided (ADR 032).

### Rules

- Pass 2 adds the plausibility bonus of the pair's cell (exact PZC × MTS diagram, or the
  PZC's problem area × diagram when the exact cell has too few observations) from the **active
  snapshot** (ADR 032) to the points, capped at +3. Without PZC, diagram, cell or active snapshot the bonus
  is 0. The bonus enters the competition like any other point; candidates, windows, thresholds and the
  conflict rule are unchanged.
- **Contributions** get the key `plausibility` (the bonus, 0 included in pass 2). The pair also stores the
  snapshot id (none in pass 1) and whether the bonus was **decisive**: the pair would not have reached its
  status with the bonus removed from every candidate of the allocation in that pass.
- **Physician vehicle.** An ambulance (RTW) on the visit with IVENA "with physician" is no mismatch: +1
  instead of −1 (a new level, e.g. `physician.rendezvous`). In the rendezvous system the physician comes in a
  separate vehicle and the patient arrives by ambulance; among the true pairs with IVENA "with physician",
  the source shows an ambulance about as often as a physician vehicle (16 vs. 15). The combination is the
  normal pattern of a physician-staffed mission, so it counts as weak agreement: +1, below the +2 of a
  physician vehicle, which is the stronger signal. The user chose +1 over 0 (#1152); on the pilot data
  either value changed about one automatic link. A patient transport (KTW) with IVENA "with physician" stays
  a mismatch (−1); a physician vehicle without IVENA "with physician" too.
- **Arrival route** unchanged: a patient transport (KTW) is an EMS arrival (+1).
- All other points are those of `1.1.0`.

### Matcher version and snapshot

`1.2.0` names the fixed points and the bonus rule; the snapshot is data and is recorded separately. Changing
the active snapshot is not a new matcher version, but follows the same rules for stored pairs: links and
manual decisions stay, open proposals are recomputed by the next run over their allocations.
`app:encounter:evaluate-matcher` replays with a chosen snapshot (or none) as well as a chosen version.

## Consequences

**Positive:**

- Most links work without manual work; every link is explainable and correctable
- Automatic links are recognisable, so analyses can show their share
- Existing links are stable across reruns
- Linkage does not use the variables that later analyses compare (since `1.2.0`: except the MTS diagram in
  pass 2, recorded per pair, ADR 032)

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
  urgency and department with clinical triage and admission. The MTS diagram was rejected for the same
  reason in `1.0.0` and admitted as a pass-2 bonus in `1.2.0` (amendment above).
- **Deterministic matching only (IVENA case number)**: not possible for the first source (#1058).

## References

- [026-encounter-data-context-and-exchange-format.md](026-encounter-data-context-and-exchange-format.md)
- [029-encounter-import-lifecycle.md](029-encounter-import-lifecycle.md)
- [032-learning-plausibility-table.md](032-learning-plausibility-table.md)
- Issues #1053, #1054, #1057, #1136, #1152, #1154
