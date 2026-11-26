# Curation package (MTS catalog and plausibility rules)

**Audience:** Administrators and operators who move the curated MTS catalog and the plausibility problem areas
between instances (#1198).

The MTS catalog ([ADR 032](../../02-architecture/decisions/032-mts-catalog-as-maintained-data.md)) and the
problem areas of the plausibility table ([ADR 033](../../02-architecture/decisions/033-learning-plausibility-table.md))
are curated in the app, and the clinical review usually happens on a development or staging instance. A **curation
package** carries that reviewed state to another instance as one JSON file:

- to set up a **new instance** with the curated catalog and rules;
- now and then, to **add** new diagrams, aliases or rules to an existing instance.

The import **only adds**. It adds what the target lacks, skips what is identical and reports every difference
as a conflict. It never changes, moves or deletes anything on the target. Changes to existing entries stay with
the curation in the back office and on the plausibility page.

The pattern follows the reference catalog package
([ADR 020](../../02-architecture/decisions/020-portable-reference-catalog.md)): a versioned file with a checksum,
full validation before any write, and a dry run.

## Commands

| Command | Purpose |
|---|---|
| `app:encounter:curation:export` | Write the curated state into one package. `--output=` sets the file (default `var/encounter/curation-<Ymd-His>.json`); `--notes=` adds a note. The package is validated before it is written. The command lists how many provisional rows it left out. |
| `app:encounter:curation:import` | Add a package to this instance (`--source=`, required). `--dry-run` shows the additions and the conflicts without writing. `--user=` (default `admin`, username or email) is recorded as actor in the audit log. Exits 1 on an invalid file or any conflict. |

Both are console-only and therefore admin-only.

## What the package holds

| Section | Content |
|---|---|
| `diagrams`, `indicators` | Curated entries only: code, German name, English name, source |
| `aliases` | Every alias whose target is a curated entry: kind, code, label, target code. A **former merge travels as its alias alone**, so the old code resolves the same way on the target; the merged entry row and the merge history do not travel |
| `assignments` | Diagram–indicator assignments between curated entries |
| `problem_areas` | All problem areas: code, German and English name, PZC codes, and the rules (diagram code, points 0 … +3) on curated diagrams |

**Never in a package:**
- provisional and merged entries;
- snapshots, cells, observation counts, learning parameters and reports;
- first-seen timestamps, database ids, users;
- anything tied to hospitals, allocations or visits.

No snapshot travels. The target derives its seed draft from the problem areas (below).

## Format

```json
{
  "meta": {
    "format": "ivena-encounter-curation",
    "version": 1,
    "exported_at": "2026-11-14T12:00:00+00:00",
    "counts": { "diagrams": 40, "indicators": 70, "aliases": 1, "assignments": 0, "problem_areas": 24 },
    "checksum": { "algorithm": "sha256", "value": "…" },
    "app_version": "…",
    "source_environment": "staging",
    "notes": null
  },
  "diagrams": [
    { "code": "allergie", "german_name": "Allergie", "english_name": "Allergy", "source": "orbis_export" }
  ],
  "indicators": [],
  "aliases": [
    { "kind": "diagram", "code": "allergische_reaktion", "label": "Allergische Reaktion", "target_code": "allergie" }
  ],
  "assignments": [],
  "problem_areas": [
    {
      "code": "anaphylaxie", "german_name": "Anaphylaxie", "english_name": "Anaphylaxis",
      "pzc": [321], "diagrams": [{ "code": "allergie", "points": 3 }]
    }
  ]
}
```

- **Deterministic.** Fixed key order. Entries and areas are sorted by code, aliases by kind and code,
  assignments by diagram and indicator, PZC codes ascending. Two exports of the same data differ only in
  `meta.exported_at`.
- **Checksum.** SHA-256 over the canonical JSON of everything except `meta`. Whitespace and layout of the file
  do not matter; any change of content or order does.
- **Version.** Only version 1 is read. A file of another format or version is rejected before any other check;
  export it again with the current application.

### Validation

Every check runs before anything is written, and all problems are listed with their path in the file
(`problem_areas[2].pzc[0]`):

1. Format and version.
2. Structure: strict field types; unknown fields are rejected.
3. Field rules:
   - codes `a–z`, `0–9`, `_`, at most 64 characters;
   - names not empty;
   - source one of `orbis_export`, `mts_edition`, `manual`, `first_seen`;
   - PZC 0 … 999, not listed twice;
   - points 0 … 3.
4. Package rules:
   - counts and checksum match the content;
   - codes are unique per kind across entries and aliases, and area codes are unique;
   - alias targets, assignments and area rules name entries of the package;
   - a PZC belongs to at most one area.

## Import rules

Rows are matched by their business key. Each package row is **new**, **unchanged** or a **conflict**. Rows that
only the target has are kept and not reported.

**Entry** (kind, code), the first matching rule applies:

1. A curated entry with the same German name, English name and source → unchanged.
2. A curated entry with other values → conflict. A name difference under a stable code is a conflict, not an
   update: the import never changes an existing entry.
3. A provisional entry → conflict. The dry run states the values to set when curating it.
4. A merged entry, or an alias with this code → conflict.
5. Unknown code → new.

**Alias** (kind, code), the first matching rule applies:

1. An alias with the same code, label and target → unchanged. This holds even when the merged entry of that merge
   has the same code, so an instance's own export imports back without changes.
2. An alias with another label or target → conflict.
3. A provisional entry with this code → conflict. Merge it in the back office into the alias target, with the
   alias label as its German name, then import again.
4. A curated entry with this code, or a merged entry without a matching alias → conflict.
5. Unknown code → new.

**Assignments:** a missing one is added; assignments only the target has stay.

**Problem areas** are collections the import can add to:

| Package row | New (added) | Unchanged | Conflict |
|---|---|---|---|
| area (code) | missing: created with its PZC codes and rules | same German and English name | other names |
| PZC of an area | free on the target: added to the area | already in this area | belongs to another area on the target (the import does not move PZC codes) |
| rule (area, diagram) | missing: added | same points | other points |

**Any conflict stops the import before anything is written.** The dry run and the import compute the same plan.
The conflict table names the path, the package value, the value on this instance and how to resolve it. Resolve
conflicts in the back office (MTS catalog) or on the plausibility page, or correct the source and export again.
Then run the import again.

### Writing, audit and the seed draft

- All additions run in one database transaction.
- Entries and aliases are created with the code from the package. The code is never derived again from a name
  that may have been corrected since (codes are stable, ADR 032).
- Every addition is flushed under its own audit intent, with the package checksum, source environment and export
  time as intent metadata, and the `--user` as actor:

  | Intent | Addition |
  |---|---|
  | `encounter.curation.entry_created` | diagram or indicator |
  | `encounter.curation.alias_created` | alias |
  | `encounter.curation.assignment_created` | diagram–indicator assignment |
  | `encounter.curation.area_created` | problem area with its PZC codes and rules |
  | `encounter.curation.area_extended` | PZC codes and rules added to an existing area |
  | `encounter.curation.seed_draft_rebuilt` | seed draft created (the cells are derived, see below) |

- **Seed draft.** When the import added areas, PZC codes or rules, the seed draft snapshot is rebuilt once from
  all areas, as after every area edit (`PlausibilityCuration::rebuildSeedDraft()`). If there is no seed draft, a
  new one is created. A seed draft holds only cells derived from the areas, so rebuilding it loses no other work.
  Active, retired and learned snapshots stay as they are. **Activation stays a manual step** on the target
  (`/tools/encounter-plausibility`).
- A second import of the same package finds everything unchanged. It writes nothing, adds no audit entries and
  does not rebuild the draft.
- If an encounter import creates a first-seen entry with the same code at the same moment, the unique
  constraint fails the import transaction. Nothing is written; run the import again (the code is then a
  provisional conflict).

## Runbooks

### Set up a new instance

1. On the source (staging): `php bin/console app:encounter:curation:export --notes="reviewed 2026-11"`.
2. Copy the file to the target, outside the release directory.
3. On the target: `php bin/console app:encounter:curation:import --source=<file> --dry-run`, and read the
   additions and conflicts.
4. Resolve any conflicts through the curation, then repeat the dry run.
5. Run the import without `--dry-run`, with `--user=<your username>`.
6. Review the seed draft under `/tools/encounter-plausibility` and activate it.

### Add new diagrams or rules to an existing instance

The steps are the same. Expect conflicts where the target diverged: provisional entries from encounter imports,
names corrected on one side only, other points for the same rule. The import never overrides them. Decide each
one in the curation and run the import again.

## Related

- [MTS glossary](README.md#mts-glossary) and [plausibility table](README.md#plausibility-table)
- [ADR 032](../../02-architecture/decisions/032-mts-catalog-as-maintained-data.md),
  [ADR 033](../../02-architecture/decisions/033-learning-plausibility-table.md),
  [ADR 020](../../02-architecture/decisions/020-portable-reference-catalog.md)
- [Backup and restore](../../05-operations/backup-restore.md): a package is not a backup
- [Console commands](../../06-reference/console-commands.md#encounter-curation)
