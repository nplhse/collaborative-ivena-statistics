# ADR 033: Learning plausibility table for PZC and MTS diagram

**Status:** accepted

## Context

Matcher 1.1.0 ([ADR 030](030-encounter-allocation-matching.md)) links about 85 % of the EMS allocations of
the pilot hospital automatically. Most true pairs it leaves as proposals have one contradicting feature
(sex, route, age or time) or a close competitor. ADR 030 kept the MTS diagram out of the score so that
later comparisons of IVENA attributes with clinical triage stay unbiased.

A measurement on the pilot hospital (October 2026, aggregates only; #1136) compared the IVENA indication
(PZC) with the MTS diagram of the visit. Reference: 476 pairs, the automatic links of matcher 1.0.0 plus the
pairs a person confirmed; the validation found 0 wrong among 69 decisive pair verdicts. A draft a-priori
mapping of PZC ranges and diagrams to problem areas (domains) classified each pair as *match* (domains
overlap), *neutral* (one side unspecific) or *contradiction*:

| Plausibility | true pairs | other candidates in the same window | likelihood ratio |
|---|---:|---:|---:|
| match | 72 % | 21 % | 3.4 |
| neutral | 23 % | 23 % | 1.0 |
| contradiction | 5 % | 56 % | 0.09 |

Contradictions among true pairs were mostly gaps in the draft mapping (fever/sepsis with a urological
problem, dehydration with falls, "other" PZC codes with specific diagrams).

Effect on automatic links (496 allocations; matcher 1.1.0 re-implemented as a script):

| Variant | automatic | proposals | linked to another visit than the reference |
|---|---:|---:|---:|
| 1.1.0 | 421 (84.9 %) | 61 | 0 |
| a-priori match +3, pass 2 only | 453 (91.3 %) | 29 | 0 |
| a-priori match +3, both passes | 455 (91.7 %) | 27 | 0 |
| a-priori match +4, pass 2 only | 459 (92.5 %) | 23 | 0 |
| plus a penalty for contradiction | 84–88 % | more | 0 |
| learned table, first PZC digit × diagram (cross-fitted by day, bonus only, cap 3) | 436 (87.9 %) | 46 | 0 |
| learned table, first two PZC digits × diagram | 443 (89.3 %) | 39 | 0 |
| learned table, exact PZC × diagram, no fallback | 436 (87.9 %) | 46 | 0 |
| learned table, exact PZC × diagram with fallback for sparse cells | 89.5 % | – | 0 |
| plus PZC × MTS indicator (exact or two digits; minimum 5, 10 or 20 observations; replacing or max) | 89.5 % in every variant | – | 0 |
| all feature weights learned from the matcher's own links | 382 (77.0 %) | – | 0 |

- **The gain comes from compensation.** A plausible diagram lets an otherwise strong pair outweigh one
  contradicting feature: with +3, 10 pairs with a sex mismatch and 7 with a non-EMS route became automatic
  links, all agreeing with the reference.
- **No penalty.** A penalty for contradiction hits the true pairs in mapping gaps and costs more than it
  gains.
- **Learn only this table.** Learning all weights from the matcher's own links reinforces its blind spots:
  1.1.0 never links a sex mismatch, so a learner punishes it harder. The core feature points stay fixed and
  versioned (ADR 030); only the plausibility table learns.
- **Granularity.** The exact PZC alone has too few observations per cell (87.9 %); with a fallback for sparse
  cells it is as good as the best coarse variant (89.5 % vs. 89.3 % for the first two digits). The two-digit
  prefix is not a good fallback, because several prefixes mix unrelated problems: 32 (anaphylaxis, syncope,
  hypotension, thrombosis, fever, dehydration), 34 (arrhythmia, hypertensive crisis, cardiogenic shock,
  pulmonary embolism, electrical injury), 37 (CO poisoning, meningitis, sepsis, infections), 26 and 28. A
  curated problem area groups the codes that belong together clinically.
- **MTS indicator.** Adding cells by indicator changed nothing in any variant (89.5 %, no link against a
  known pair): it only raised the bonus of pairs that were linked anyway. 31 of the 33 true pairs still not
  linked automatically sit in indicator cells with fewer than 5 observations in the whole month.
- Half a month of one hospital already gave most of the a-priori effect; a curated seed as starting point
  should close the gap.

The user decided (2026-11-13): the table learns from the start and is a tool of its own; one global table so
new hospitals profit right away; cells by exact PZC × MTS diagram with a curated problem area as fallback
(replacing the first decision for the first two PZC digits), the indicator as a reserved finer level; cap +3; activation by administrators or
persons with a separate reviewer right; the user reviews the curated seed in the app.

## Decision

### Cells and points

The table has three levels. The MTS side is always the canonical diagram code of the visit's initial triage
([ADR 032](032-mts-catalog-as-maintained-data.md); aliases resolve to their canonical code).

| Level | Key | Use |
|---|---|---|
| exact cell | exact PZC of the allocation × diagram | the bonus, when the cell has enough observations |
| fallback cell | problem area of the PZC × diagram | the bonus, when the exact cell has too few observations |
| indicator cell | exact PZC × diagram × MTS indicator | reserved; not used for the bonus in matcher `1.2.0` |

- **Problem areas** are curated groups of PZC codes that belong together clinically (for example urology,
  chest and heart, syncope and circulation). Each PZC belongs to at most one problem area; "other" codes
  belong to none. Problem areas are not the PZC prefix: they come from the clinical review in the app.
- **Lookup:** the exact cell if it has at least the minimum number of observations; otherwise the fallback
  cell of the PZC's problem area; otherwise 0. A pair without PZC or without diagram gets 0.
- **Observations** of a cell are its eligible pairs and other candidates (see Learning). The minimum
  (10 as starting value) is configuration, set in #1155 with replays.
- A cell holds a bonus of 0 … +3 points. There are no negative points.
- **Indicator level:** the key has an optional, nullable indicator column. The learning job may fill
  indicator cells and its report shows their effect in the replay; the matcher does not read them. Using
  them later goes through a snapshot activated by a person like every other change to the table, with this
  ADR amended for the lookup rule.
- Only the diagram (and later possibly the indicator) is compared. MTS category and admitting department stay
  out of the score, and IVENA urgency is never compared with the MTS category.

### One global table

- One table for all hospitals; no per-hospital tables. A new hospital gets the bonus from its first import.
- The table holds points and aggregate counts per cell only, no allocation, visit or hospital reference.
  The counts are inputs of the learning job and the replay; who may see what is set under
  [Privacy](#privacy).

### Curated seed

- The seed is the set of problem areas: each lists its PZC codes and the MTS diagrams plausible with them,
  with points (+3 in the draft). It gives the seed points of the fallback cells (problem area × diagram) and,
  through each code's problem area, of the exact cells. "Other" codes and unspecific diagrams (for example
  "Unwohlsein bei Erwachsenen") belong to no problem area and stay at 0.
- The draft mapping of the measurement is the starting point; the user reviews and corrects it in the app
  (development environment) before the first snapshot is activated. Carrying the reviewed seed to production
  is the export/import follow-up of ADR 032.
- Problem areas are edited by administrators and reviewers; changes are audited. Editing creates or changes a
  draft snapshot, never the active one.

### Snapshots

- The matcher reads cells from a **snapshot**: an immutable set of cells with an id, its origin (seed or
  learned), the data it was learned from (period, number of eligible pairs) and a report.
- Status: `draft` → `active` → `retired`. Exactly one snapshot is active, or none; without an active snapshot
  the bonus is 0 everywhere.
- Administrators or reviewers activate a draft after reading its report. Activating retires the previous
  active snapshot. Activation and retirement are audited.
- Active and retired snapshots are never changed or deleted while pairs reference them, so every stored
  pair stays reproducible. Drafts can be deleted.
- A new active snapshot follows the rules of a new matcher version (ADR 030): links and manual decisions
  stay; open proposals are recomputed with it on the next run over their allocations.

### Learning

A learning job (#1155) builds a draft snapshot from all hospitals' data:

- **Eligible pairs** (positives): pairs a person confirmed in the review or judged correct (or chose
  instead) in the validation, and automatic links whose decision did not depend on the bonus (all pass-1
  links; pass-2 links whose bonus was not decisive, see ADR 030). Links decided by the bonus are excluded, so
  the table never learns from its own output (no feedback loop).
- **Other candidates** (negatives): the candidate visits of the same allocations in the pass-2 window that
  are not the partner.
- **Estimate per cell** (exact and fallback cells, indicator cells for the report): log2 of the likelihood
  ratio (share of positives in the cell / share of negatives in the cell), with Laplace smoothing, shrunk
  towards the seed points of the cell in proportion to the cell's counts; rounded, clipped to 0 … +3.
- **Minimum counts:** an exact cell below the minimum falls back to its problem area (lookup above); a
  fallback cell below the minimum keeps its seed points.
- **Cross-fitting by period:** the report splits the eligible data into periods (alternating days in the
  first version), learns on one part and replays on the other, and adds up the result. The snapshot itself is
  learned on all eligible data. The cross-fitted replay is the honest estimate of its effect.
- **Report**: the cells that changed against the active snapshot (points before and after, source), and a
  replay of the draft against the active snapshot and against no bonus: automatic links, proposals, agreement
  with validation verdicts and confirmed pairs. Replay figures are kept per hospital and shown only for the
  hospitals the viewer may see ([Privacy](#privacy)).
- Smoothing strength, minimum counts and fold rule are configuration, set in #1155 with replays.

Human-confirmed pairs may themselves have been proposed because of the bonus. They are still eligible: a
person made the decision. The report shows how many eligible pairs came from such proposals.

### Reviewer right

- New role **`ROLE_ENCOUNTER_CATALOG_REVIEWER`**: edit the curated problem areas, run the learning job, read draft
  reports, activate and retire snapshots.
- It works only together with `ROLE_ENCOUNTER_BETA` (the pilot pages). It grants no case access: no encounter
  grant action, no visit lists, no case card; the pages show points and reports as set under
  [Privacy](#privacy).
- `ROLE_ADMIN` inherits it (administrators may activate, as decided). Like the beta role, it is assigned on
  the user in the back office.
- Maintenance of the MTS catalog itself stays with administrators (ADR 032); the role may cover it later.

### Privacy

The table is learned from the data of all hospitals, but reviewers never see case counts across hospitals
(user decision, #1152). ADR 028 shows no cross-hospital aggregates in the pilot, and this stays true:

- **Review and activation pages** show per cell only the points, their source (curated, learned) and the
  change against the active snapshot. No counts per cell, no totals, no rates computed over all hospitals.
- **Replay figures** in a report (links, proposals, agreement with verdicts, and any other count or rate)
  use only the data of hospitals on which the viewer holds the encounter grant action `REVIEW`.
  Administrators see all hospitals, as ADR 028 gives them every grant action. A reviewer without a review
  grant sees the points and changes, but no replay figures.
- **Points are model parameters, not case counts.** A learned value appears only for cells that reach the
  minimum number of observations; below it, a cell shows the seed or the problem-area value.
- The stored counts per cell are inputs of the learning job; no participant, reviewer or encounter page
  shows them. No encounter page or analysis shows the table to participants.

### Bias in PZC ↔ MTS analyses

Using the diagram for linkage makes linked pairs agree more often in PZC and diagram than true pairs do. The
pair's contributions record the bonus and the snapshot (ADR 030), and whether the bonus was decisive. Analyses
that compare the IVENA indication with the MTS diagram (#1156) exclude links decided by the bonus or report
them apart, and state the share. Pass-1 links and manual decisions are unaffected.

## Consequences

**Positive:**

- About +6 percentage points of automatic links on the pilot hospital without a wrong link in the reference
- The table improves with the data and with the review work hospitals already do
- Every pair records the snapshot that scored it; a snapshot is reproducible and reversible by activating
  another one
- New hospitals profit from the global table at once
- No feedback loop: the table never learns from links it decided

**Negative:**

- A global table mixes hospital profiles (the pilot hospital has a urology focus); cells of rare specialties
  learn slowly
- Analyses of PZC against the MTS diagram must handle the linkage bias; links decided by the bonus are lost
  or reported apart there
- Reviewers decide on activation without seeing global counts; their replay figures cover only the
  hospitals they review, which may be few or none
- The measured gain comes from one hospital and one month; the bonus size must be checked on a second month
  or hospital (report of the learning job)
- A second role to assign and explain

## Alternatives

- **Keep MTS out of the matcher (ADR 030 as is)**: rejected; about one in seven allocations stays a proposal
  although the diagram makes the pair clear.
- **Penalty for contradiction**: rejected; it hits true pairs in mapping gaps and lowers the rate (84–88 %).
- **Fixed curated mapping without learning**: rejected by the user; gaps in the mapping would only close by
  hand, and the table could not adapt to new hospitals and diagrams.
- **Learn all feature weights**: rejected; learning from the matcher's own links reinforces its blind spots
  (77 % instead of 85 %), and too few human verdicts exist to learn them from people only.
- **Per-hospital tables**: rejected; new hospitals would start without a bonus and small hospitals would not
  reach minimum counts.
- **Bonus in both passes**: not chosen; it adds only 2 links on the pilot data, and pass 1 stays free of the
  diagram so its links remain unbiased training data and analysis basis.
- **Cap +4**: not chosen; +1.2 percentage points more, but more links that override a contradicting feature
  (14 sex mismatches instead of 10) before a second hospital confirms the size.
- **First one or two PZC digits as cell or fallback**: rejected; the first digit performed worse, and the
  two-digit prefixes mix unrelated problems, so a learned bonus would carry over between them.
- **Exact PZC without fallback**: rejected; too few observations per cell (87.9 %).
- **Indicator in the bonus now**: postponed; no effect in the measurement, and indicator cells are mostly too
  small. The level is reserved so that it can be enabled later without a new table.

## References

- [028-encounter-access-grants.md](028-encounter-access-grants.md)
- [030-encounter-allocation-matching.md](030-encounter-allocation-matching.md)
- [032-mts-catalog-as-maintained-data.md](032-mts-catalog-as-maintained-data.md)
- [../permission-model.md](../permission-model.md)
- [../../04-features/encounter/README.md](../../04-features/encounter/README.md#plausibility-table)
- Issues #1136, #1152, #1153, #1154, #1155, #1156
