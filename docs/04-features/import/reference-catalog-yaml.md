# Reference catalog YAML

**Audience:** Anyone editing or reviewing `fixtures/reference/catalog.yaml`.

The catalog is **one UTF-8 YAML mapping**. Doctrine fixtures, `app:reference:import`, `app:reference:export`, and `app:reference:propose-from-rejects` share this schema. Commands, add/replace rules, and the production runbook live in [reference-catalog.md](reference-catalog.md).

Default path: `fixtures/reference/catalog.yaml`. `--source=` / `--output=` always point at **one file**, not a directory.

## Conventions

- Missing top-level keys are empty lists (`[]`). You may omit unused sections.
- Name lists are sequences of strings. Quote values that contain `:`, `#`, `{`, `}`, or leading/trailing spaces (`'Rheingau Taunus'`, `'aus Klinik'`).
- Indication **codes are strings**, quoted, three digits (`'000'`, `'143'`). Unquoted `000` becomes integer `0`.
- YAML `null` / `~` means “empty”. For dispatch areas that is “state not filled yet”.
- The file does **not** contain users, allocations, imports, rejects, CMS, or audit data.
- Import does not rename, delete, or update existing rows (`--mode=add`), except indication groups with `--update`.

## Sections

| YAML key | `--types=` | Row shape | Identity |
|---|---|---|---|
| `states` | `state` | string | `name` |
| `dispatch_areas` | `dispatch-area` | mapping | `state` + `name` |
| `departments` | `department` | string | `name` |
| `specialities` | `speciality` | string | `name` |
| `assignments` | `assignment` | string | `name` |
| `occasions` | `occasion` | string | `name` |
| `infections` | `infection` | string | `name` |
| `secondary_transports` | `secondary-transport` | string | `name` |
| `indications_normalized` | `indication-normalized` | mapping | `code` + `name` |
| `indications_raw` | `indication-raw` | mapping | hash of code + text |
| `indication_groups` | `indication-group` | mapping | `name` |
| `hospitals` | `hospital` | mapping | `name` |

Load order (and `--types=` filter order) follows the table top to bottom: states before areas, areas before hospitals, normalized indications before groups.

## Name lists

```yaml
states:
  - Bayern
  - Hessen
  - Niedersachsen
  - Thüringen
departments:
  - Kardiologie
  - 'Chir. Überwachung'
specialities:
  - 'Innere Medizin'
  - ECMO-Therapie
assignments:
  - Patient
  - ZLST
occasions:
  - 'aus Klinik'
  - Krankentransport
infections:
  - MRSA
  - 'V.a. COVID'
secondary_transports:
  - Diagnostik
  - Kapazitätsengpass
```

Empty list: `assignments: []`.

## Dispatch areas

| Field | Required | Notes |
|---|---|---|
| `name` | yes | Canonical lookup name after import normalization (e.g. `Göttingen`, not `_Kommunale Regionalleitstelle Göttingen`) |
| `state` | for import | Must match a `states` entry or an existing DB state. `~` / omitted / `''` → import **skips** the row with a warning (propose stubs) |

```yaml
dispatch_areas:
  - name: Frankfurt
    state: Hessen
  - name: Göttingen
    state: Niedersachsen
  - name: Göttingen
    state: ~          # review stub; not imported until state is set
```

Do not guess Bundesländer in the importer. Fill `state` during review.

## Indications

Normalized catalog (PZC codebook):

```yaml
indications_normalized:
  - code: '000'
    name: 'Kein Patient vorhanden'
  - code: '143'
    name: 'vaECMO Abholung'
```

Raw IVENA labels (hash = `IndicationKey` of code + text). `name` may be empty. Existing hashes are never overwritten.

```yaml
indications_raw:
  - code: '111'
    name: 'primäre Todesfeststellung'
  - code: '332'
    name: 'STEMI / "OMI"'
```

Groups reference **normalized codes**. Several normalized rows may share a code; all of them are attached. Unknown codes become import warnings.

```yaml
indication_groups:
  - name: 'ECMO & ECLS Transport'
    category: ~                    # optional string
    codes:
      - '143'
      - '144'
```

`--mode=add` skips an existing group name. `--update` refreshes `category` and membership.

## Hospitals

Skipped entirely in add mode if `name` already exists (owner, participating flag, and coordinates stay untouched). Import does **not** geocode; use `app:geo:geocode-hospitals`.

| Field | Required | Values / notes |
|---|---|---|
| `name` | yes | Unique identity |
| `state` | yes | Must exist (YAML or DB) |
| `area` | yes | Dispatch area **name** in that state |
| `participating` | no | Boolean, default `false` |
| `tier` | no | `Basic`, `Extended`, `Full`, or `~` |
| `size` | yes | `Small`, `Medium`, `Large` |
| `beds` | yes | Integer |
| `location` | yes | `Urban`, `Mixed`, `Rural` |
| `address.street` | yes | |
| `address.city` | yes | |
| `address.state` | yes | Address Bundesland (often same as `state`) |
| `address.postalCode` | yes | Keep quoted (`'60389'`) |
| `address.country` | yes | e.g. `Deutschland` |

```yaml
hospitals:
  - name: 'Agaplesion Bethanien Krankenhaus'
    state: Hessen
    area: Frankfurt
    participating: false
    tier: Basic
    size: Medium
    beds: 204
    location: Urban
    address:
      street: 'Im Prüfling 21-25'
      city: 'Frankfurt am Main'
      state: Hessen
      postalCode: '60389'
      country: Deutschland
```

## Propose output

`app:reference:propose-from-rejects` writes the **same keys**, but only sections that have missing values. Dispatch areas come with `state: ~`. After review, merge into this file or import the proposal with `--source=…/catalog.yaml`.

Not written as catalog rows (handled in PHP or dropped): ILS/`Führungsstab` prefixes, `Perinatalzentrum Level 2` → `Geburtshilfe`, URLs, mojibake, `Erhängen`.

## Related

- [reference-catalog.md](reference-catalog.md) — import/export/propose, add vs replace, production
- [../../03-development/fixtures.md](../../03-development/fixtures.md) — local `doctrine:fixtures:load`
- [../../06-reference/console-commands.md](../../06-reference/console-commands.md)
