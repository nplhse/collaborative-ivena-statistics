# ADR 032: MTS catalog as maintained data

**Status:** accepted

## Context

The Manchester Triage System (MTS) catalog is a JSON file in the code
(`src/Encounter/Application/Catalog/mts-de.json`, read by `MtsCatalog`, #1056). The data preparation tool
bundles it, the MTS glossary and the encounter pages read it. It holds the diagrams and indicators seen in
one analysed ORBIS export (40 diagrams, about 70 indicators); the full MTS has more.

- Codes are slugs of the German name (`MtsCatalog::code()`, identical to the tool's `catalogCode()`). Another
  spelling of the same name, such as a vendor wording, gives a second code.
- The `encounter` table stores diagram and indicator as codes. The server accepts any code (the list is
  open); a code not in the catalog is shown as a code.
- Every change to the catalog is a code release and a new tool version.
- The plausibility table of [ADR 033](033-learning-plausibility-table.md) keys its cells by MTS diagram and
  needs a catalog that grows with the data instead of with releases.

The user decided (#1082, #1136, 2026-11-13): the catalog becomes data in the database. Diagrams and
indicators seen for the first time are created automatically as provisional entries; grouping, names and
other curation happen later in the app. The user adds the full MTS list later through the same curation.

## Decision

### Catalog in the database

The `Encounter` context owns the catalog tables:

| Table | Content |
|---|---|
| diagram | stable code, German name, English name (optional), source, status |
| indicator | stable code, German name, English name (optional), source, status |
| alias | kind (diagram or indicator), alias code and the label it came from → code of an active entry of the same kind |
| diagram–indicator assignment | which indicators belong to which diagram (definition, not counts) |

- **Source** says where an entry came from: `orbis_export` (the seed), `mts_edition`, `manual`,
  `first_seen` (created automatically, below).
- **Status**: `curated` (reviewed by a person) or `provisional` (created automatically, not reviewed yet),
  both called *active*, plus `merged` for an entry that became an alias of another one (below).
- Codes are unique per kind across entries and aliases: a code is either an entry's code or an alias code,
  and an alias code is only shared with the `merged` entry it stands for.
- The database is the source of truth from step 2 (#1082). `MtsCatalog` reads it; its public API (codes,
  entries by code) stays, so the glossary and the encounter pages keep working.

### Stable codes and source codes

- A code is derived once, when the entry is created, with the existing rule (`MtsCatalog::code()`). It never
  changes afterwards, even when the German name is corrected.
- **The source code is kept.** The data preparation tool writes the code derived from the original ORBIS
  label into the exchange file and never replaces it with a canonical code, even when it knows an alias.
  The server stores it unchanged in `encounter`, also when the catalog already has an alias for it. Display,
  grouping and the plausibility table resolve it to the canonical code when they read it. So the variant a
  hospital sent can always be reconstructed, and undoing a merge also applies to encounters imported after
  the merge. This rule holds for #1157 (tool download, alias handling in tool and import).
- `encounter` keeps storing plain codes, with no foreign key to the catalog. Catalog changes never touch
  encounter rows. Every code stored today stays valid.
- **Slug collisions are a limit of the exchange format.** Two different labels that give the same code (the
  rule drops case, umlauts, punctuation and anything past 64 characters) cannot be told apart by the server.
  Catalog maintenance reports a collision when an entry or alias is created whose code already exists with
  another label, so curators can clear it up; the stored encounters cannot be split afterwards.

### Resolution, aliases and merging

- **Resolution:** an active entry resolves to itself; a `merged` entry and any other alias code resolve
  through their alias to its target. Aliases point directly to an active entry of the same kind, never to
  another alias, so resolution takes at most one step. A code that resolves to nothing is shown as a code,
  as today.
- An alias maps another spelling to a canonical code. The alias code is derived from its label with the same
  rule, so the tool and the import can recognise labels and codes the same way.
- **Merge:** a provisional entry is merged into an active entry of the same kind. In one database
  transaction its status becomes `merged` and an alias from its code to the target is created. The entry
  row stays. Stored encounters keep the provisional code; readers resolve it to the target. No encounter row
  is rewritten: a known visit is never updated ([import lifecycle](029-encounter-import-lifecycle.md)).
- **Undo:** in one database transaction the alias is removed and the entry's status goes back to
  `provisional` (only provisional entries are merged in this ADR). Encounters with that code, older or newer
  than the merge, resolve to the entry again.
- **Blocked merges (first version):** a merge is refused while the provisional entry is itself the target
  of aliases, has diagram–indicator assignments, or is referenced by rules of the plausibility table
  (ADR 033), until the handling of these dependencies is decided. This also refuses a merge of two diagrams
  that both have curated rules.
- Merge and undo are audited.

### Interface to the plausibility table

- The plausibility table ([ADR 033](033-learning-plausibility-table.md)) groups by the canonical diagram code
  at the time it reads the catalog.
- Learned values are computed and stored in snapshots. After a merge or an undo, the values of the
  diagrams involved are out of date: the next learning job recomputes them into a new draft snapshot. The
  active snapshot is immutable and stays until a person activates the draft. Stored pairs keep the bonus
  they were scored with (ADR 030).

### Provisional entries

- **Created by the successful import only.** New entries come from the codes of encounter rows the import
  actually stores (initial triage and retriage, diagram and indicator); a rejected file or row creates
  nothing. The public catalog endpoint of #1157 only reads the catalog and never creates entries. It serves
  curated entries and aliases to them only (user decision, #1157): provisional entries come from hospital
  exports nobody has reviewed yet, and the endpoint needs no login.
- A code that resolves (active entry or alias) creates nothing. Otherwise an entry with status
  `provisional` and source `first_seen` is created. A unique constraint on kind and code makes parallel
  imports of the same code create exactly one entry (insert that ignores a conflict).
- Its German name is the code until a person sets the name: the exchange file carries codes, not labels.
- A provisional entry is a normal catalog entry for all readers: it has a code and appears in the glossary
  and in the plausibility table (as a diagram without curated rules, ADR 033).
- When it was first seen is kept for curation; which hospital sent it is not stored in the catalog.
- Auto-creation lands in #1157, with a one-time backfill from the codes already stored in `encounter`, so
  older unknown codes appear in curation too. Until then unknown codes behave as today.

### Curation

- Administrators (`ROLE_ADMIN`) maintain the catalog in the back office: names, English names, source,
  status, aliases, merges and diagram–indicator assignments. Every change is audited.
- The plausibility reviewer right of ADR 033 does not include catalog maintenance in this scope; it can be
  extended later without a new role.
- Names only: no discriminator texts and no flowcharts (copyright, as decided in #1056).

### Seed

The migration of step 2 seeds the tables with the current content of `mts-de.json` (status `curated`,
source `orbis_export`), carried in the migration itself so a later change to the file cannot change it.
The file stays as the tool's bundled copy until #1157 gives the tool a public catalog endpoint with the
bundled copy as offline fallback.

### Out of scope

- **Completeness** against the full MTS (German edition) and the licence check of the names. The user adds
  the missing entries later through curation.
- **Export and import to production.** The catalog and the plausibility table are reviewed in the
  development environment first (ADR 033); carrying them to production needs an export/import like the
  reference catalog package of [ADR 020](020-portable-reference-catalog.md). Follow-up issue at the end of
  #1136.

## Consequences

**Positive:**

- The catalog grows with the data; no release per new diagram or spelling
- Stored encounter codes stay valid; merging and correcting never touch clinical rows
- One resolution rule (active entry → itself, alias → its target) for the glossary, the pages and the
  plausibility table
- The source code of every visit stays as sent, so merges can be undone for all encounters
- Provisional entries make unknown codes visible to curators instead of hiding them

**Negative:**

- Every reader must resolve aliases; a reader that compares raw codes counts a merged code apart
- Merges with dependencies (aliases, assignments, plausibility rules) are refused until their handling is
  decided
- Two labels with the same slug cannot be separated; curation can only report the collision
- A merge or undo leaves learned plausibility values out of date until the next learning job
- Provisional entries show a code as name until someone curates them
- The development and production catalogs diverge until the export/import follow-up exists
- The tool's bundled copy can lag behind the database until #1157

## Alternatives

- **Keep the JSON file in the code**: rejected; every new diagram or spelling needs a release and a new tool
  version, and the plausibility table could not grow with the data.
- **Foreign key from `encounter` to the catalog**: rejected; an encounter with an unknown code must be
  importable without an existing catalog entry. (A foreign key would not force rewriting encounter rows on a
  merge, since the merged entry stays.)
- **Store the canonical code in `encounter`** (tool or import replaces a known alias): rejected; the variant
  the hospital sent could no longer be reconstructed, and undoing a merge would not reach encounters imported
  after it.
- **Rewrite stored codes when merging**: rejected; it updates known visits, which the import lifecycle rules
  out, and cannot be undone cleanly.
- **Import the full MTS list once from an official source**: postponed; licence and source are open, and the
  provisional entries already cover what the data needs.
- **Numeric ids instead of name-derived codes**: rejected; the tool derives codes offline from labels, and
  stored encounters already use the slugs.

## References

- [026-encounter-data-context-and-exchange-format.md](026-encounter-data-context-and-exchange-format.md)
- [029-encounter-import-lifecycle.md](029-encounter-import-lifecycle.md)
- [020-portable-reference-catalog.md](020-portable-reference-catalog.md)
- [033-learning-plausibility-table.md](033-learning-plausibility-table.md)
- [../../04-features/encounter/README.md](../../04-features/encounter/README.md#mts-glossary)
- Issues #1056, #1082, #1136, #1157
