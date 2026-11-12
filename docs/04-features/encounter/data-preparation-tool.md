# Data preparation tool

**Audience:** Developers of the tool, its profiles and the encounter import; pilot coordinators who
support hospitals.

**Status:** v1 with the ORBIS MTS profile (#1051), part of the encounter data pilot (#1043).

Decisions: [ADR 025](../../02-architecture/decisions/025-encounter-data-context-and-exchange-format.md)
(tool, CSP, profiles), [ADR 026](../../02-architecture/decisions/026-encounter-pseudonym-key.md) (key
file, check value, prefixes). Output format: [exchange-format.md](exchange-format.md).

## What it is

One self-contained HTML file. A hospital user downloads it from the encounter page (`/encounter`,
beta role required) and opens it from disk in a current browser. It reads the export of the hospital
information system (HIS) and the hospital's key file, keeps only the agreed fields and saves a
canonical `ed_visit` file for the upload. Names, dates of birth, staff, free text and the case number
never leave the computer.

| Part | Location |
|---|---|
| Core (parse, transform, HMAC, content check; no DOM, no network) | `src/Encounter/UI/DataPrep/js/data-prep-core.js` |
| Page logic | `src/Encounter/UI/DataPrep/js/data-prep-app.js` |
| Public profiles (declarative JSON) | `src/Encounter/UI/DataPrep/profiles/` |
| MTS catalog (German labels of diagrams and indicators, reusable for #1056) | `src/Encounter/UI/DataPrep/catalogs/mts-de.json` |
| `ed_visit` v1 field contract for the tool | `src/Encounter/UI/DataPrep/schema/ed_visit.v1.json` (must equal `EdVisitDimension`, checked by `DataPrepContractTest`) |
| Template | `src/Encounter/UI/Twig/templates/data_prep/tool.html.twig` |
| Renderer, version | `DataPrepToolRenderer`, `DataPrepRelease` in `src/Encounter/UI/DataPrep/` |
| Routes | `GET /encounter/data-prep/download` (`ROLE_ENCOUNTER_BETA`), `GET /data-prep/latest.json` (public) |
| Tests | `tests/Encounter/DataPrep/*.test.js` (`npm run test:js`), `tests/Encounter/Unit/UI/DataPrep/`, `tests/Encounter/Functional/Controller/DataPrepToolControllerTest.php` |

The two scripts are plain scripts without imports; the server inlines them into the file, and the
Node tests load the core as a side-effect module (`globalThis.EncounterDataPrep`). Translations come
from the `encounter` domain (`encounter.data_prep.*`) in the locale of the user who downloads the file.

## Versions and update check

`app.encounter.data_prep` in `config/packages/app.yaml`:

| Option | Meaning |
|---|---|
| `version` | version of the rendered tool, part of the file name (`encounter-data-prep-<version>.html`) and of the manifest (`tool=`) |
| `min_version` | tools below it block processing; the import (#1048) rejects their files |
| `released_at` | release date shown in the tool and in `latest.json` |

Raise `version` with every change to the scripts, the template, a bundled profile or the schema copy.
Raise `min_version` when older tools write wrong data.

`GET /data-prep/latest.json` returns `{version, minVersion, releasedAt, downloadUrl}` with
`Access-Control-Allow-Origin: *`. It has its own firewall without security (`data_prep` in
`config/packages/security.yaml`) and is a stateless route: no session, no consent or analytics
cookie. At start the tool sends one request to it (`credentials: 'omit'`, `cache: 'no-store'`, no
query):

| Answer | Tool |
|---|---|
| same version | "current version" |
| newer `version` | banner with the download link |
| own version below `minVersion` | processing blocked |
| no answer (offline, proxy) | notice; the tool keeps working, the server checks the version on upload |

### Content Security Policy

The file sets, as a `<meta>` tag:

```
default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:;
connect-src <absolute URL of /data-prep/latest.json>; base-uri 'none'; form-action 'none'
```

So no request other than the update check is possible, and no external script, style, font or image
is loaded. Checked in the browser for v1: the page made exactly one request (`latest.json`); fetches
to other URLs fail. CSP source matching ignores query strings; the tool sends none.

## Key file

Generated once per hospital in the tool (section "No key file yet? Create one") and kept in the team
folder. Format (JSON):

```json
{
    "format": "encounter-key",
    "version": 1,
    "label": "ZNA Musterklinik",
    "created": "2026-11-12",
    "key": "<64 hex characters, 256 random bits from crypto.getRandomValues>",
    "check": "<hex HMAC-SHA256(key, \"encounter-key-check\")>"
}
```

The tool recomputes the check value on load and rejects a file whose value does not match. The check
value goes into the manifest (`key_check`); the key itself never leaves the computer. "Remember on
this computer" stores the key file in IndexedDB (database `encounter-data-prep`); this is convenience
only, browsers may clear it, and the file stays the source of truth. "Forget key" deletes the entry.

## Local map (private, per hospital)

Hospital-specific codes are never part of the public profile or the server. Each hospital keeps a
JSON file next to the key file and loads it per run (optional):

```json
{
    "format": "encounter-local-map",
    "version": 1,
    "profile": "orbis-mts",
    "hospital": "ZNA Musterklinik",
    "departments": {
        "INN XYZ": "<department code of the reference catalog>"
    },
    "stations": {
        "ITS XYZ": "icu",
        "IMC XYZ": "monitoring",
        "S1 XYZ": "ward"
    },
    "dispositions": {
        "<hospital-specific Entlassung text>": { "disposition": "admitted", "careLevel": "ward" }
    }
}
```

| Key | Effect |
|---|---|
| `profile` | optional; a map for another profile is rejected |
| `departments` | source department code (`Stat_Fachrichtung`, else `Med. Fachabteilungskürzel`) → `admitting_department`; codes match `[A-Za-z0-9_.-]{1,64}`, the import checks them against the catalog |
| `stations` | `Stat_Station` → `care_level` (`ward`, `monitoring`, `icu`, `or`, `unknown`) for `admitted` |
| `dispositions` | additional or overriding `Entlassung` texts → `disposition` and optional `careLevel` (only with `admitted` or `transferred_external`) |

Keys match case-insensitively, with whitespace collapsed. Values that no map knows are written empty
and listed in the tool's report ("Unmapped values"); the report shows them only on screen.

## Flow

1. Key file: load (or remembered key), or create once.
2. Local map: optional.
3. Export: the profile is detected by its required header columns; UTF-8 if the bytes are valid
   UTF-8, otherwise Windows-1252; `;`, quoted cells. The column preview lists every source column as
   kept, transformed or dropped, with the target field or the reason.
4. Prepare: transformation, report, content check and preview of the first ten rows.
5. Save: `ed_visit_<profile>_<first anchor date>_<last anchor date>.csv`, UTF-8, LF, manifest line
   first ([exchange-format.md](exchange-format.md#file)).

## Transformation rules

The allow-list is the profile: a column the profile does not map is dropped. Rules of the format
(ranges, fingerprint, codes) are in [exchange-format.md](exchange-format.md); the tool implements:

| Topic | Rule |
|---|---|
| Rows | rows without case number or without both anchor timestamps are skipped and counted; so are rows without age (and without date of birth) or without a mappable sex, both required fields; a second row with the same fingerprint is skipped as duplicate |
| Keys | `visit_fingerprint` and `case_key` exactly per [`ed-visit:v1`](exchange-format.md#visit-fingerprint-ed-visitv1), WebCrypto HMAC-SHA256 with the key file |
| Age | leading number of `Alter`; without it, age at the anchor date from `Geburtsdatum`; then clamped to 1–99 |
| Timestamps | `dd.mm.yyyy HH:MM[:SS]` with any whitespace between date and time; output `YYYY-MM-DDTHH:MM`, seconds cut; invalid values are emptied and counted |
| Codes | profile map, case-insensitive; optional `prefixes`; empty markers (`-- keine --`) give empty; other values give empty and are listed as unmapped |
| MTS diagram, indicator | catalog code derived from the label: lowercase, umlauts transliterated, other characters `_` (`Abdominelle Schmerzen bei Erwachsenen` → `abdominelle_schmerzen_bei_erwachsenen`); provisional until the MTS catalog is fixed (#1048, #1056). Diagrams must be in `catalogs/mts-de.json`, otherwise empty and listed as unmapped; indicators not in the (open) list keep their code and are listed as "new catalog values" |
| Disposition | local map first, then the profile; `care_level` only for `admitted` (station map, else the level of the disposition text, else `unknown`) and `transferred_external` (level of the text, else `unknown`); an unmapped station is listed |
| Vitals | unit stripped, decimal comma accepted; integers except temperature (one decimal, half up) and glucose (rounded half up to whole mg/dl); out of range or unreadable → empty and counted per field; `vitals_reference = documented_initial` if any vital is set |
| Flags | `ja` → `1`; empty → empty; other values → empty and listed |
| Isolation | `Isolation` and `Infektiös` split at commas, mapped, sorted, joined; `-- keine --` ignored |
| Header | a repeated header (ORBIS writes `Zweiteinschätzung` twice) is read by position; the first occurrence feeds rules, the preview lists each column |

### ORBIS MTS value mapping (profile v1)

| Column | Source values → code |
|---|---|
| `Geschlecht` | `M` → `M`, `W` → `F`, `D` → `X` |
| `Transport` | `Ohne` → `self`, `RTW` → `ambulance`, `KTW` → `patient_transport`, `NAW/NEF/ITW` → `physician_vehicle`, `Anderes` → `other` |
| `Einweisung` | `Ohne` → `self`, `Rettungsdienst` → `ems`, `Notarzt` → `emergency_physician`, `Klinik/Verlegung` → `hospital_transfer`, `Vertragsarzt/Praxis` → `physician`, `KV-Notfallpraxis am Krankenhaus` → `kv_practice`, `Andere` → `other` |
| `Notfallpatient - Elektivpatient` | `Notfallpatient` → `emergency`, `Elektivpatient` → `elective` |
| `Fallstatus` | `ambulant` → `outpatient`, `stationär` → `inpatient`, `vorstationär` → `pre_inpatient`, `nachstationär` → `post_inpatient` |
| `MTS Einstufung`, `MTS Einstufung Zweiteinschätzung` | `rot`, `orange`, `gelb`, `grün`, `blau` → `red`, `orange`, `yellow`, `green`, `blue` (`mts_category`, `retriage_category`) |
| `MTS Diagramm`, `ZweiteinschaetzungDiagramm` | `mts_diagram`, `retriage_diagram` (catalog) |
| `Indikator`, `ZweiteinschaetzungIndikator` | `mts_indicator`, `retriage_indicator` (catalog, `-- keine --` → empty) |
| `Zweiteinschätzung` (twice), `spätester Arztkontakt Zweiteinschätzung` | dropped (meaning unknown, empty in the analysed export); `retriage_at` stays empty |
| `BegruendungHerabtriagierung` | dropped (free text) |

`Entlassung` → `disposition` / `care_level`:

| Text | Disposition | Care level |
|---|---|---|
| `Entlassung nach Hause` | `home` | |
| `Entlassung zu weiterbehandelnden Arzt` | `to_physician` | |
| `Weiterleitung an KV-Notdienstpraxis` | `kv_practice` | |
| `Entlassung gegen ärztlichen Rat` | `against_advice` | |
| `Behandlung durch Pat. abgebrochen` | `left_before_completion` | |
| `Verlegung intern: Normalstation` / `Überwachung` / `OP / Intervention` | `admitted` | `ward` / `monitoring` / `or`, overridden by the station map (for example ITS → `icu`) |
| `Verlegung extern: Normalstation` / `Überwachung` / `OP / Intervention` | `transferred_external` | `ward` / `monitoring` / `or` |
| `Verstorben` | `died` | |
| `Sonstiges` | `other` | |

`icu` only comes from the private station map; the public profile contains no station codes.

| Column | Source values → code |
|---|---|
| `Isolation`, `Infektiös` | `Gastroenteritis`, `Clostridien`, `COVID-19` / `COVID-19 Positiv`, `Multiresistente Erreger`, `MRSA`, `ESBL`, `Pseudomonaden`, `TBC`, `Sonstiges / Kommentar` → `gastroenteritis`, `clostridioides`, `covid19`, `mdro`, `mrsa`, `esbl`, `pseudomonas`, `tb`, `other` |

## Content check

Before saving, the tool checks every value that reaches the output, and the raw labels of the MTS
diagram and indicator columns before they become codes:

| Finding | Pattern |
|---|---|
| date-like value in a non-date field | `dd.mm.yy(yy)` or `yyyy-mm-dd` |
| name-like value | `Lastname, Firstname` at the start, or `Herr` / `Frau` followed by a name |
| long free text | more than 100 characters or at least 12 words |

Findings list field, source column, kind, count and first line. With findings the check fails, and
saving needs an explicit confirmation that the values are not personal data; the import checks the
content again. The check reduces the risk, it does not find every identifier (ADR 025).

## Adding a profile

1. Add `profiles/<id>.json` with `detect.requiredColumns`, `keys`, `age`, `fields` (rule types `code`,
   `timestamp`, `catalog`, `integer`, `vital`, `bloodPressure`, `flag`, `codeSet`, `disposition`,
   `department`) and `dropped`.
2. Register it in `DataPrepToolRenderer::PROFILES` (catalogs in `DataPrepToolRenderer::CATALOGS`).
3. Add a synthetic sample and tests; `DataPrepContractTest` checks the codes against the schema.
4. Raise `app.encounter.data_prep.version`.

Profiles are data only: a new rule type needs a change to the core and a new tool version.

## Tests

- `npm run test:js` (part of `npm run check:js` / `make lint-js`): Node's built-in test runner on the
  synthetic sample `tests/Encounter/DataPrep/fixtures/orbis-mts-sample.csv` (invented values,
  Windows-1252), with hand-checked expected values, the content check and stable fingerprints.
- `fixtures/fingerprint-vectors.json` holds fingerprint vectors; the JavaScript tests and
  `DataPrepContractTest` (PHP `hash_hmac`) both check them.
- Real exports are never used in tests or committed.
