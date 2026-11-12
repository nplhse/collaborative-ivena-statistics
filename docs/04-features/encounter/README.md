# Encounter data (Behandlungsdaten)

**Audience:** Developers and domain experts working on the encounter data pilot.

**Status:** in development for beta v0.3 (umbrella #1043). This page describes the agreed design.
Sections are updated as the implementation steps land; nothing here is available in a release yet.

## Purpose

The pilot shows whether emergency departments (EDs) can link their routine data with IVENA
allocations and use them for local care improvement. IVENA allocations remain the primary data of
the project. The first source is the Manchester Triage System (MTS) export of ORBIS: triage, process
times, disposition and initial vitals.

Decisions: [ADR 025](../../02-architecture/decisions/025-encounter-data-context-and-exchange-format.md)
(context and exchange format), [ADR 026](../../02-architecture/decisions/026-encounter-pseudonym-key.md)
(pseudonym key), [ADR 027](../../02-architecture/decisions/027-encounter-access-grants.md) (access),
[ADR 028](../../02-architecture/decisions/028-encounter-import-lifecycle.md) (import lifecycle),
[ADR 029](../../02-architecture/decisions/029-encounter-allocation-matching.md) (matching).

## Flow

1. The hospital downloads the data preparation tool (one HTML file) from the encounter import page.
2. In the browser, the tool reads the vendor export and the hospital's key file, drops identifying
   columns, pseudonymises the case number and writes a canonical `ed_visit` file
   ([exchange-format.md](exchange-format.md)).
3. A user with the upload grant uploads that file and confirms that it was produced with the tool.
4. The server validates manifest, header, key check value and residual identifying content, imports
   the rows as a new version and recomputes the current state of the affected cases.
5. Matching links ED visits with IVENA allocations; people review proposals and conflicts.
6. Source files are deleted after 30 days (`app.encounter.source_file_retention_days`).

## Model

- `Encounter`: administrative case of a hospital (case number pseudonym).
- `EdVisit`: one ED visit within the case, with triage, process times, disposition, initial vitals,
  flags and an optional link to one allocation.
- No patient-level key: visits are grouped only under the same administrative case number.
- `Allocation` is not changed and has no reference to encounter data.

## Population groups

Self-presenters are part of the pilot population on purpose. Every ED visit is in exactly one group:

| Group | Rule |
|---|---|
| Linked to IVENA, confirmed | manually confirmed link |
| Linked to IVENA, automatic, unreviewed | auto-accepted link nobody has confirmed yet |
| EMS arrival without confirmed link | arrival mode from the export is EMS, no confirmed or automatic link |
| Self-presenter | arrival mode from the export is self |
| Arrival route unknown | arrival mode missing or other |

The arrival route always comes from the clinical export. A missing link says nothing about how a
patient came to the ED. EMS arrivals without an allocation are normal.

## Access

| Action | Needs |
|---|---|
| Pilot pages and navigation | `ROLE_ENCOUNTER_BETA` (inherited by `ROLE_ADMIN`) |
| Upload, key reset | beta role + encounter grant `UPLOAD` on the hospital |
| Match review | beta role + encounter grant `REVIEW` (implies `VIEW_CASES`) |
| Case-level views, case card on an allocation | beta role + encounter grant `VIEW_CASES` |
| Descriptive aggregates of the own hospital | beta role + any encounter grant + `STATISTICS` |
| Manage encounter grants of a hospital | hospital owner or `ROLE_ADMIN` |
| Import status, counts, match diagnostics without case details | `ROLE_ADMIN` |

Hospital ownership, hospital grants and `ROLE_ADMIN` do not imply an encounter grant. Details:
[ADR 027](../../02-architecture/decisions/027-encounter-access-grants.md),
[permission-model.md](../../02-architecture/permission-model.md#encounter-grants).

## Matching

Summary of [ADR 029](../../02-architecture/decisions/029-encounter-allocation-matching.md):

- Same hospital; pass 1 EMS arrivals, −20 … +45 min, age ±2; pass 2 the rest, −30 … +90 min, age ±5.
- Features: time difference, sex, age difference, arrival mode, physician vehicle, work accident.
  MTS category and admitting department are not used.
- Score 0.0–1.0 with competition between candidates, shown as agreement, with the feature breakdown.
- `auto_accepted` (≥ 0.90, no conflict) is shown as "automatisch, ungeprüft" until confirmed;
  `proposed` (≥ 0.50) goes to review; manual decisions are sticky; conflicts are never auto-accepted.

## Statistics in the pilot

Descriptive only, for the own hospital: coverage, visits by group, MTS categories and diagrams,
process times recomputed from timestamps (median, IQR), disposition and care level — each by group.
No cross-hospital aggregates. Each figure documents population, filters, numerator and denominator,
missing values and period boundaries when it is implemented (#1055).

## Governance

Before the first real upload the pilot hospital documents the data flow:
[governance.md](governance.md).

## Steps

| Issue | Step |
|---|---|
| #1044 | ADRs and documentation |
| #1045 | Per-visit identifier in the ORBIS export |
| #1046 | Context skeleton, beta role, encounter grants |
| #1047 | Data model and import lifecycle |
| #1048 | Encounter import |
| #1049 | Source file retention |
| #1050 | Key reset |
| #1051 | Data preparation tool v1 |
| #1052 | Synthetic encounter data |
| #1053 | Matcher v1 |
| #1054 | Match review |
| #1055 | Descriptive pages and case card |
| #1056 | MTS glossary |
| #1057 | Match validation |
| #1058 | IVENA case number in encounter sources (after the pilot) |
