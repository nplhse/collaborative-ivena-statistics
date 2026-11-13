# ADR 031: MTS catalog as maintained data

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
- The plausibility table of [ADR 032](032-learning-plausibility-table.md) keys its cells by MTS diagram and
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
| alias | kind (diagram or indicator), alias code and the label it came from → canonical code |
| diagram–indicator assignment | which indicators belong to which diagram (definition, not counts) |

- **Source** says where an entry came from: `orbis_export` (the seed), `mts_edition`, `manual`,
  `first_seen` (created automatically, below).
- **Status**: `curated` (reviewed by a person) or `provisional` (created automatically, not reviewed yet),
  plus `merged` for an entry that became an alias of another one (below).
- The database is the source of truth from step 2 (#1082). `MtsCatalog` reads it; its public API (codes,
  entries by code) stays, so the glossary and the encounter pages keep working.

### Stable codes

- A code is derived once, when the entry is created, with the existing rule (`MtsCatalog::code()`). It never
  changes afterwards, even when the German name is corrected.
- `encounter` keeps storing plain codes, with no foreign key to the catalog. An import never fails on an
  unknown code, and catalog changes never touch encounter rows. Every code stored today stays valid.
- Readers resolve a stored code through the catalog: the entry with that code, or the canonical entry of an
  alias with that code. A code that resolves to nothing is shown as a code, as today.

### Aliases and merging

- An alias maps another spelling to a canonical code. The alias code is derived from its label with the same
  rule, so the tool and the import can resolve labels and codes the same way.
- **Merging** a provisional entry into a canonical one turns the provisional code into an alias of the
  canonical code and marks the entry `merged`. Stored encounters keep the provisional code; readers resolve
  it to the canonical entry. No encounter row is rewritten: a known visit is never updated
  ([import lifecycle](029-encounter-import-lifecycle.md)).
- A merge can be undone by removing the alias; the stored codes were never changed.
- How the tool and the import use aliases (download of the catalog, label resolution) is decided in #1157
  within these rules.

### Provisional entries

- When the import or the catalog endpoint sees a diagram or indicator code that resolves to nothing, an
  entry with status `provisional` and source `first_seen` is created. Its German name is the code until a
  person sets the name (the exchange file carries codes, not labels).
- A provisional entry is a normal catalog entry for all readers: it has a code and appears in the glossary
  and in the plausibility table (as a diagram without curated rules, ADR 032).
- When it was first seen is kept for curation; which hospital sent it is not stored in the catalog.
- Auto-creation lands in #1157; until then unknown codes behave as today.

### Curation

- Administrators (`ROLE_ADMIN`) maintain the catalog in the back office: names, English names, source,
  status, aliases, merges and diagram–indicator assignments. Every change is audited.
- The plausibility reviewer right of ADR 032 does not include catalog maintenance in this scope; it can be
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
  development environment first (ADR 032); carrying them to production needs an export/import like the
  reference catalog package of [ADR 020](020-portable-reference-catalog.md). Follow-up issue at the end of
  #1136.

## Consequences

**Positive:**

- The catalog grows with the data; no release per new diagram or spelling
- Stored encounter codes stay valid; merging and correcting never touch clinical rows
- One resolution rule (code or alias → canonical entry) for the glossary, the pages and the plausibility
  table
- Provisional entries make unknown codes visible to curators instead of hiding them

**Negative:**

- Every reader must resolve aliases; a reader that compares raw codes counts a merged code apart
- Provisional entries show a code as name until someone curates them
- The development and production catalogs diverge until the export/import follow-up exists
- The tool's bundled copy can lag behind the database until #1157

## Alternatives

- **Keep the JSON file in the code**: rejected; every new diagram or spelling needs a release and a new tool
  version, and the plausibility table could not grow with the data.
- **Foreign key from `encounter` to the catalog**: rejected; an unknown code would reject a row or need a
  catalog write inside the import, and merging would rewrite encounter rows.
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
- [032-learning-plausibility-table.md](032-learning-plausibility-table.md)
- [../../04-features/encounter/README.md](../../04-features/encounter/README.md#mts-glossary)
- Issues #1056, #1082, #1136, #1157
