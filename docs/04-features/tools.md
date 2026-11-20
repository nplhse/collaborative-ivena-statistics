# Tools

**Audience:** Developers and domain experts who add a tool or change one.

**Purpose:** Inventory of the Tools section (`/tools`): what each tool is for, who sees it, where it starts, and
the naming rules for tools. The registry and the `Tool` definition are described in
[extension-points.md](../02-architecture/extension-points.md#tools) and
[ADR 031](../02-architecture/decisions/031-tools-section-and-tool-registry.md).

## Inventory

State after #1201. Names are the same on the Tools card, the page title and the breadcrumb.

| Tool (EN / DE) | Category | Provider (context) | Visible for | Entry |
|---|---|---|---|---|
| Indication review / Indikations-Review | Matching and review | `IndicationReviewToolProvider` (Allocation) | `IndicationRawReviewVoter::VIEW` (participants) | `/tools/indication-review` |
| ED match review / Abgleichsprüfung Notaufnahme | Matching and review | `EncounterToolProvider` (ED visit plugin) | `ROLE_ENCOUNTER_BETA`; pages also need encounter grant `REVIEW` or `ROLE_ADMIN` | `/tools/encounter-review` (hospital list) |
| ED match validation / Abgleichsvalidierung Notaufnahme | Matching and review | `EncounterToolProvider` (ED visit plugin) | as the match review | `/tools/encounter-review?mode=validation` (hospital list) |
| PZC × MTS plausibility table / Plausibilitätstabelle PZC × MTS | Matching and review | `EncounterToolProvider` (ED visit plugin) | `ROLE_ENCOUNTER_BETA` and `ROLE_ENCOUNTER_CATALOG_REVIEWER` | `/tools/encounter-plausibility` |
| Assignment conflicts / Zuordnungskonflikte | Matching and review | `AssignmentConflictToolProvider` (Encounter) | `ROLE_ENCOUNTER_BETA`; pages also need encounter grant `REVIEW` or `ROLE_ADMIN` | `/tools/encounter-assignments` (hospital list) |
| Encounter data preparation / Aufbereitung der Behandlungsdaten | Data preparation | `DataPrepToolProvider` (Encounter) | `ROLE_ENCOUNTER_BETA`, only while a schema contributes to the tool | `/tools/data-prep` (guide and download) |

All encounter tools carry the beta badge. Details of each workflow:
[indication-normalization.md](allocation/indication-normalization.md),
[encounter README](encounter/README.md#tools-section) (match review, validation, plausibility table,
assignment conflicts) and [data-preparation-tool.md](encounter/data-preparation-tool.md).

## Naming rules

- A tool name says what users do with it, as a noun phrase, without a source system (no ORBIS, no product
  names). Add the subject ("Notaufnahme", "ED", "Behandlungsdaten", "Indikation") where two tools would
  otherwise read the same.
- The card title, the page title of the entry page and the breadcrumb item use the same text. Keep the
  card key (`tools.<key>.title`) and the page title key in step, or let both point to one key.
- The card description is one sentence that starts with the action and names the result. Domain terms follow the
  glossaries ([glossary.md](../06-reference/glossary.md),
  [glossary-i18n-de.md](../06-reference/glossary-i18n-de.md)), for example "Indikation (PZC)".
- Pages inside a tool keep the tool as the first breadcrumb item after "Tools", followed by the hospital
  where the tool works per hospital.

## Differences between the tools

Recorded in #1201 as input for #1402 (shared review components, v0.4-beta); not changed here.

- **Hospital selection:** the match review and the validation share one hospital list; the assignment
  conflicts have their own; the indication review and the plausibility table work across hospitals.
- **Lists:** the indication worklist and the match review use the DataTable; the validation samples, the
  assignment conflicts and the plausibility table use plain tables.
- **Page header:** every entry page uses `<twig:PageHeader>`, except the single raw indication page, which
  builds its header by hand.
- **Decisions:** each tool has its own set of actions and labels (indication review: match and approve,
  reject, not matchable, ignore, reopen; match review: confirm, reject, change, leave open, plus swap proposals; assignment conflicts: assign,
  defer; validation: a verdict per sample item).
- **Feedback:** most pages use `<twig:Alert>`; the allocation page of the match review writes its alerts
  by hand.
