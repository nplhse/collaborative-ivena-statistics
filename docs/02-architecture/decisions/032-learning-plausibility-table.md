# ADR 032: Learning plausibility table for PZC and MTS diagram

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
| learned table (PZC 2 digits × diagram, cross-fitted by day, bonus only, cap 3) | 443 (89.3 %) | 39 | 0 |
| all feature weights learned from the matcher's own links | 382 (77.0 %) | – | 0 |

- **The gain comes from compensation.** A plausible diagram lets an otherwise strong pair outweigh one
  contradicting feature: with +3, 10 pairs with a sex mismatch and 7 with a non-EMS route became automatic
  links, all agreeing with the reference.
- **No penalty.** A penalty for contradiction hits the true pairs in mapping gaps and costs more than it
  gains.
- **Learn only this table.** Learning all weights from the matcher's own links reinforces its blind spots:
  1.1.0 never links a sex mismatch, so a learner punishes it harder. The core feature points stay fixed and
  versioned (ADR 030); only the plausibility table learns.
- **Granularity.** Learned cells by the first two PZC digits beat the first digit and the full code (89.3 %
  vs. 87.9 % each): fine enough to separate problem areas, coarse enough to collect counts.
- Half a month of one hospital already gave most of the a-priori effect; a curated seed as starting point
  should close the gap.

The user decided (2026-11-13): the table learns from the start and is a tool of its own; one global table so
new hospitals profit right away; cells PZC (2 digits) × MTS diagram; cap +3; activation by administrators or
persons with a separate reviewer right; the user reviews the curated seed in the app.

## Decision

### Cells and points

- A cell is *PZC prefix × MTS diagram*: the first two digits of the allocation's three-digit PZC (`71` for
  711–719) and the canonical diagram code of the visit's initial triage ([ADR 031](031-mts-catalog-as-maintained-data.md);
  aliases resolve to their canonical code).
- A cell holds a bonus of 0 … +3 points. There are no negative points. A pair without PZC, without diagram,
  or in a cell without a row gets 0.
- Only the diagram is compared. MTS category, indicator and admitting department stay out of the score, and
  IVENA urgency is never compared with the MTS category.

### One global table

- One table for all hospitals; no per-hospital tables. A new hospital gets the bonus from its first import.
- The table holds points and aggregate counts only, no allocation, visit or hospital reference.

### Curated seed

- The seed is a set of domains (problem areas). Each domain lists PZC ranges and MTS diagrams and has points
  (+3 in the draft). A cell gets the highest points of the domains it belongs to on both sides. "Other" codes
  and unspecific diagrams (for example "Unwohlsein bei Erwachsenen") belong to no domain and stay at 0.
- The draft mapping of the measurement is the starting point; the user reviews and corrects it in the app
  (development environment) before the first snapshot is activated. Carrying the reviewed seed to production
  is the export/import follow-up of ADR 031.
- Domains are edited by administrators and reviewers; changes are audited. Editing creates or changes a
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
- **Estimate per cell:** log2 of the likelihood ratio (share of positives in the cell / share of negatives in
  the cell), with Laplace smoothing, shrunk towards the seed points of the cell in proportion to the cell's
  counts; rounded, clipped to 0 … +3.
- **Minimum counts:** a cell below the minimum number of positives keeps its seed points.
- **Cross-fitting by period:** the report splits the eligible data into periods (alternating days in the
  first version), learns on one part and replays on the other, and adds up the result. The snapshot itself is
  learned on all eligible data. The cross-fitted replay is the honest estimate of its effect.
- **Report** (aggregate only): replay of the draft against the active snapshot and against no bonus:
  automatic links, proposals, agreement with validation verdicts and confirmed pairs, and the cells that
  changed with their counts. Small counts (1–4) are shown as `< 5`.
- Smoothing strength, minimum counts and fold rule are configuration, set in #1155 with replays.

Human-confirmed pairs may themselves have been proposed because of the bonus. They are still eligible: a
person made the decision. The report shows how many eligible pairs came from such proposals.

### Reviewer right

- New role **`ROLE_ENCOUNTER_CATALOG_REVIEWER`**: edit the curated domains, run the learning job, read draft
  reports, activate and retire snapshots.
- It works only together with `ROLE_ENCOUNTER_BETA` (the pilot pages). It grants no case access: no encounter
  grant action, no visit lists, no case card; the pages show cells, counts and reports only.
- `ROLE_ADMIN` inherits it (administrators may activate, as decided). Like the beta role, it is assigned on
  the user in the back office.
- Maintenance of the MTS catalog itself stays with administrators (ADR 031); the role may cover it later.

### Cross-hospital aggregate

The table and its report are aggregates over the data of all hospitals. ADR 028 shows no cross-hospital
aggregates in the pilot. This table is a deliberate, narrow exception: it is a parameter of the matcher, not
a statistic; it shows points and global counts per cell with small-cell suppression, never counts per
hospital, and only to reviewers and administrators. No encounter page or analysis shows it to participants.

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
- A cross-hospital aggregate exists for reviewers, as a documented exception to ADR 028
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
- **Full PZC code or first digit as cell**: rejected; both performed worse in the measurement.

## References

- [028-encounter-access-grants.md](028-encounter-access-grants.md)
- [030-encounter-allocation-matching.md](030-encounter-allocation-matching.md)
- [031-mts-catalog-as-maintained-data.md](031-mts-catalog-as-maintained-data.md)
- [../permission-model.md](../permission-model.md)
- [../../04-features/encounter/README.md](../../04-features/encounter/README.md#plausibility-table)
- Issues #1136, #1152, #1153, #1154, #1155, #1156
