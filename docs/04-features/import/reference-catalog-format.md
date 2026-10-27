# Reference catalog package format

**Audience:** Anyone who exports, checks, imports or builds a reference catalog package.

A reference catalog package is one UTF-8 JSON file ([ADR 020](../../02-architecture/decisions/020-portable-reference-catalog.md)). It holds the master data that imports resolve against: states, dispatch areas, departments and specialities with their aliases, assignments, occasions, infections, secondary transports, normalized and raw indications, indication groups and hospitals. How to move it between environments is described in [reference-catalog.md](reference-catalog.md).

A package is a catalog, not a database backup. It holds no users, permissions, allocations, imports or other operational data.

## Structure

```json
{
  "meta": {
    "format": "ivena-reference-catalog",
    "version": 1,
    "exported_at": "2026-10-27T10:00:00+01:00",
    "complete": true,
    "types": ["state", "dispatch-area", "department", "…"],
    "counts": { "state": 1, "dispatch-area": 24, "…": 0 },
    "checksum": { "algorithm": "sha256", "value": "f93f9ca2…" },
    "app_version": "1.4.0",
    "source_environment": "prod",
    "sources": [{ "name": "PZC", "version": "1.3.11", "date": "2025-06-13" }],
    "notes": null
  },
  "states": [{ "id": "9fdd440e-…", "name": "Hessen" }],
  "dispatch_areas": [{ "id": "…", "name": "Kassel", "state": "9fdd440e-…" }],
  "departments": [{
    "id": "…", "name": "Allgemeinchirurgie",
    "aliases": [{ "name": "Allgemeinchir.", "classification": "unambiguous_alias", "source": "synthetic", "note": null, "valid_from": null, "valid_to": null }]
  }],
  "specialities": [{ "id": "…", "name": "Chirurgie", "aliases": [] }],
  "assignments": [{ "id": "…", "name": "RD" }],
  "occasions": [{ "id": "…", "name": "Häuslicher Einsatz" }],
  "infections": [{ "id": "…", "name": "MRSA" }],
  "secondary_transports": [{ "id": "…", "name": "Diagnostik" }],
  "indications_normalized": [{ "id": "…", "code": "322", "name": "Synkope / Kollaps", "note": null }],
  "indications_raw": [{
    "id": "…", "code": "322", "name": "Synkope / Kollaps",
    "review": { "status": "matched", "comment": null, "reviewed_at": "2026-01-01T00:00:00+00:00" },
    "target": "<id of the normalized indication>",
    "first_matched_at": "2026-01-01T00:00:00+00:00"
  }],
  "indication_groups": [{ "id": "…", "name": "Schlaganfall", "members": ["<normalized id>"], "category": "Neurologie / Psychiatrie", "description": null, "sort_order": 6 }],
  "hospitals": [{
    "id": "…", "name": "Katharinen Kassel", "state": "<state id>", "area": "<dispatch area id>",
    "size": "Medium", "beds": 420, "location": "Urban", "tier": "Extended",
    "address": { "street": "Musterweg 1", "postal_code": "34117", "city": "Kassel", "state": "Hessen", "country": "Deutschland" },
    "coordinates": { "lat": 51.3127, "lng": 9.4797 }
  }]
}
```

Keys are snake_case. A section that a partial package does not list may be missing or empty.

## Identity and references

- Every row has an `id`: the public id of the row (UUID). It is stable across environments; database ids are not.
- Rows refer to each other by id: dispatch areas to their state, hospitals to state and dispatch area, raw indications to their `target`, groups to their `members`.
- Names stay in the file for readability and for matching rows that exist with another id.
- Aliases are nested under their department or speciality; their identity is the normalized name, unique per alias type.
- Codes and postal codes are strings.

Natural keys, used for duplicates and matching:

| Type | Natural key |
|---|---|
| Dispatch area | state id + normalized name |
| Normalized indication | code + normalized name |
| Raw indication | code + normalized label (same hash as the import) |
| Every other type | normalized name |

## Fields

### `meta`

| Field | Rule |
|---|---|
| `format` | `ivena-reference-catalog` |
| `version` | `1`; another version is rejected before any other check |
| `exported_at` | ISO 8601 date and time |
| `complete` | `true` exactly when all twelve types are listed |
| `types` | listed types, at least one, each once: `state`, `dispatch-area`, `department`, `speciality`, `assignment`, `occasion`, `infection`, `secondary-transport`, `indication-normalized`, `indication-raw`, `indication-group`, `hospital` |
| `counts` | entries per listed type; must match the sections |
| `checksum` | `algorithm` `sha256`, `value` the lowercase SHA-256 over the canonical encoding of all sections (without `meta`) |
| `app_version`, `source_environment` | optional, at most 255 / 64 characters |
| `sources` | optional list of `{name, version, date}` |
| `notes` | optional free text |

### Sections

| Section | Fields |
|---|---|
| `states`, `assignments`, `occasions`, `infections`, `secondary_transports` | `id`, `name` (required, at most 255 characters) |
| `dispatch_areas` | `id`, `name`, `state` (state id) |
| `departments`, `specialities` | `id`, `name`, `aliases` |
| alias | `name`, `classification` (`historical`, `faulty_catalog`, `unambiguous_alias`, `hospital_local`), `source` (at most 64 characters), `note`, `valid_from`, `valid_to` (dates) |
| `indications_normalized` | `id`, `code` (three digits), `name`, `note` |
| `indications_raw` | `id`, `code` (three digits), `name` (may be empty: some source rows carry only a code), `review` (`status`: `unreviewed`, `matched`, `not_matchable`, `ignored`, `needs_review`; `comment`; `reviewed_at`), `target` (normalized id or `null` for an unmapped label), `first_matched_at` |
| `indication_groups` | `id`, `name`, `members` (normalized ids, each once), `category` (at most 120 characters), `description`, `sort_order` |
| `hospitals` | `id`, `name`, `state`, `area`, `size` (`Small`, `Medium`, `Large`), `beds` (positive), `location` (`Urban`, `Mixed`, `Rural`), `tier` (`Basic`, `Extended`, `Full` or `null`), `address` (`street`, `postal_code`, `city`, `state`, `country`), `coordinates` (`lat`, `lng` or `null`) |

Not part of a package: users and actors (`createdBy`, `reviewedBy`; the import sets the importing user), technical timestamps, the `normalized` link of raw indications (the import sets it to `target`), hospital owner and participation.

## Validation

A package is checked completely before anything is written, by `app:reference:validate` and by every import:

1. **Format and version.** Another `format` or `version` gives one message with the expected and the found version; the file is not read further.
2. **Structure and types.** The JSON is decoded into typed objects. Every type error and every unknown field is reported.
3. **Field rules.** Required fields, lengths, UUIDs, codes, enums, dates and coordinates.
4. **Package rules.** Listed types match the sections, `counts` and `complete` are right, the checksum matches, ids and natural keys are unique per section, every reference resolves (when the referenced type is in the package), a hospital's dispatch area belongs to its state, and aliases do not collide with each other or with canonical names.

Every problem is reported with its path in the file, for example `hospitals[3].address.postal_code`. A package that was changed after export fails on the checksum.

## Deterministic output

`app:reference:export` and the synthetic catalog builder write the same bytes for the same content: fixed key order, entries sorted by natural key, name and id, pretty-printed, unescaped UTF-8 and a trailing newline. Two exports of the same database differ only in `exported_at`, so diffs in a private data repository stay readable.

## Related

- [reference-catalog.md](reference-catalog.md): export, import, restore and runbooks
- [reference-catalog-inventory.md](reference-catalog-inventory.md): what is portable and what depends on one installation
- [ADR 020](../../02-architecture/decisions/020-portable-reference-catalog.md)
