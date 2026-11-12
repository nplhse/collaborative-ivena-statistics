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
(context, exchange format, retention), [ADR 026](../../02-architecture/decisions/026-encounter-pseudonym-key.md)
(pseudonym key), [ADR 027](../../02-architecture/decisions/027-encounter-access-grants.md) (access),
[ADR 028](../../02-architecture/decisions/028-encounter-import-lifecycle.md) (import lifecycle),
[ADR 029](../../02-architecture/decisions/029-encounter-allocation-matching.md) (matching).

## Flow

1. The hospital downloads the data preparation tool (one HTML file) from the encounter import page.
2. In the browser, the tool reads the vendor export and the hospital's key file, drops identifying
   columns, computes the visit fingerprint and writes a canonical `ed_visit` file
   ([exchange-format.md](exchange-format.md)).
3. A user with the upload grant uploads that file and confirms that it was produced with the tool.
4. The server validates manifest, header, key check value and content, skips known visits (counted
   as duplicates) and inserts new ones.
5. Matching links encounters with IVENA allocations; automatic links are effective, people review
   proposals and conflicts.
6. Uploaded files are kept for 30 days for error analysis (`app.encounter.source_file_retention_days`);
   files recognised as raw HIS exports are deleted immediately.

## Model

- One `Encounter` = one ED visit of a hospital, with triage, process times, disposition, initial
  vitals, flags and at most one link to an allocation.
- Identity: internal `visit_uid`; duplicate detection by a keyed visit fingerprint, unique per
  hospital and key generation.
- Each encounter belongs to the import that created it. Deleting that import removes it, with its
  links and review decisions.
- The administrative case number is carried as a pseudonym only to report suspected duplicates; it
  does not group encounters. No patient-level key.
- `Allocation` is not changed and has no reference to encounter data.

## Population

Self-presenters and EMS arrivals without IVENA link are part of the pilot population on purpose.
Two separate attributes describe each encounter:

| Attribute | Values | Source |
|---|---|---|
| Arrival route | EMS, self-presenter, unknown | documented in the clinical export |
| IVENA link | linked (automatic), linked (confirmed), not linked | matching |

A missing link says nothing about the arrival route. EMS arrivals without an allocation are normal.
Overviews cross both attributes, for example "EMS arrival, not linked".

## Access

| Action | Needs |
|---|---|
| Pilot pages and navigation | `ROLE_ENCOUNTER_BETA` (inherited by `ROLE_ADMIN`) |
| Upload | beta role + encounter grant `UPLOAD` on the hospital |
| Key change | hospital owner only |
| Match review | beta role + encounter grant `REVIEW` (implies `VIEW_CASES`) |
| Case-level views, case card on an allocation | beta role + encounter grant `VIEW_CASES` |
| Descriptive aggregates of the own hospital | beta role + any encounter grant + `STATISTICS` |
| View or download a retained upload file | beta role + encounter grant `UPLOAD` on the hospital |
| Reprocess a retained upload file | beta role + `UPLOAD`, or `ROLE_ADMIN` (shows no file content) |
| Manage encounter grants of a hospital | hospital owner or `ROLE_ADMIN` |
| Import status, counts, reject codes, match diagnostics without case details or file content | `ROLE_ADMIN` |

Hospital ownership, hospital grants and `ROLE_ADMIN` do not imply an encounter grant. Details:
[ADR 027](../../02-architecture/decisions/027-encounter-access-grants.md),
[permission-model.md](../../02-architecture/permission-model.md#encounter-grants).

## Matching

Summary of [ADR 029](../../02-architecture/decisions/029-encounter-allocation-matching.md):

- Same hospital; pass 1 EMS arrivals, −20 … +45 min, age ±2; pass 2 the rest, −30 … +90 min, age ±5.
- Features: time difference, sex, age difference, arrival route, physician vehicle, work accident.
  MTS category, MTS diagram and admitting department are not used.
- Score 0.0–1.0 with competition between candidates, shown as agreement with feature contributions.
- `auto_accepted` (≥ 0.90, no conflict: no other allocation reaches 0.90 for the same visit in the
  same pass) is an effective link with visible automatic origin;
  `proposed` (≥ 0.50) needs a decision; manual decisions are sticky.
- Pass 1 reserves both sides; later runs only fill gaps and never reassign links. The review may
  propose a targeted swap.

## Statistics in the pilot

Descriptive only, for the own hospital: coverage, encounters by arrival route and link status, MTS
categories and diagrams, process times recomputed from timestamps (median, IQR), disposition and
care level. No cross-hospital aggregates. Each figure documents population, filters, numerator and
denominator, missing values and period boundaries when it is implemented (#1055).

## Governance

Before the first real upload the pilot hospital documents the data flow:
[governance.md](governance.md).

## Steps

| Issue | Step |
|---|---|
| #1044 | ADRs and documentation |
| #1045 | ORBIS visit fingerprint |
| #1046 | Context skeleton, beta role, encounter grants |
| #1047 | Data model and import lifecycle |
| #1048 | Encounter import |
| #1049 | Source file retention |
| #1050 | Key change |
| #1051 | Data preparation tool v1 |
| #1052 | Synthetic encounter data |
| #1053 | Matcher v1 |
| #1054 | Match review |
| #1055 | Descriptive pages and case card |
| #1056 | MTS glossary |
| #1057 | Match validation |
| #1058 | IVENA case number in encounter sources (after the pilot) |
