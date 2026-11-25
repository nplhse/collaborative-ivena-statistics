# Data preparation tool

**Audience:** Developers of the tool, its profiles and the encounter import; pilot coordinators who
support hospitals.

**Status:** v1 with the ORBIS MTS profile (#1051), 1.1.0 with the local map editor and the landing
page (#1130), 1.2.0 with the MTS catalog from the server (#1157), 1.4.0 with the department names as a selection (#1638), part of the encounter data pilot (#1043). What changed per version: `DataPrepChangelog`,
shown on the landing page.

Decisions: [ADR 026](../../02-architecture/decisions/026-encounter-data-context-and-exchange-format.md)
(tool, CSP, profiles), [ADR 027](../../02-architecture/decisions/027-encounter-pseudonym-key.md) (key
file, check value, prefixes). Output format: [exchange-format.md](exchange-format.md).

## What it is

One self-contained HTML file. A hospital user downloads it from its landing page
(`/tools/data-prep`, the Tools card "Encounter data preparation", beta role required) and opens it
from disk in a current browser. It reads the export of the hospital
information system (HIS) and the hospital's key file, keeps only the agreed fields and saves a
canonical exchange file of the matching schema (today `ed_visit`) for the upload. The tool is a generic
shell; schemas contribute what it needs to know about them (#1301, [schema contributions](#schema-contributions)). Names, dates of birth, staff, free text and the case number
never leave the computer.

| Part | Location |
|---|---|
| Core (parse, generic rule types, keys, export boundary, content check, local map storage; no DOM, no network) | `src/Encounter/UI/DataPrep/js/data-prep-core.js` |
| `ed_visit` schema module (age and sex, vitals, dispositions and care levels, departments, MTS catalog format) | `plugins/ed-visit/data-prep/js/ed-visit.js` |
| Page logic | `src/Encounter/UI/DataPrep/js/data-prep-app.js` |
| Contributions | `DataPrepContributionInterface` (`src/Encounter/Contract/DataPrep/`), registry `DataPrepContributions`; `EdVisitDataPrepContribution` (`plugins/ed-visit/src/UI/DataPrep/`), in tests `InpatientStayDataPrepContribution` |
| Public profiles (declarative JSON) | `plugins/ed-visit/data-prep/profiles/` (`ed_visit`) |
| MTS catalog: loaded at start from `GET /data-prep/mts-catalog.json` (the database catalog, ADR 032); bundled copy as offline fallback | endpoint `MtsCatalogForDataPrepController` (`MtsCatalog::toolCatalog()`); bundled `plugins/ed-visit/src/Application/Catalog/mts-de.json` (`MtsCatalog::bundledToolCatalog()`) |
| Department names the import resolves (#1638): bundled when the tool is downloaded, no public endpoint (private reference data, ADR 020) | `EdVisitDataPrepContribution::catalogs()` (catalog `department-names`, format `encounter-name-list`, `DepartmentCatalogInterface::uniqueNames()`) |
| `ed_visit` v1 field contract for the tool | `plugins/ed-visit/data-prep/schema/ed_visit.v1.json` (must equal `EdVisitDimension::fields(1)`, checked by `DataPrepContractTest`; no key rules, see [key rules](#key-rules)) |
| Template | `src/Encounter/UI/Twig/templates/data_prep/tool.html.twig` |
| Renderer, version, changelog | `DataPrepToolRenderer`, `DataPrepRelease`, `DataPrepChangelog` in `src/Encounter/UI/DataPrep/` |
| Local map template and example | `plugins/ed-visit/data-prep/local-map/{template,example}.json` (read by `LocalMapFiles` through the contribution) |
| Landing page and subpages | `ShowDataPrepGuideController`, `ShowDataPrepKeyController`, `ShowDataPrepLocalMapController`, `DataPrepHospitals`; templates `guide`, `key`, `local_map` in `src/Encounter/UI/Twig/templates/data_prep/` |
| Routes (404 without a contribution, and no Tools card) | `GET /tools/data-prep` (landing page), `GET /tools/data-prep/key`, `GET /tools/data-prep/local-map`, `GET /tools/data-prep/download`, `GET /tools/data-prep/local-map-{template,example}.json` (all `ROLE_ENCOUNTER_BETA`), `GET /data-prep/latest.json` and `GET /data-prep/mts-catalog.json` (public) |
| Tests | `tests/Encounter/DataPrep/*.test.js` (`npm run test:js`), `tests/Encounter/Unit/UI/DataPrep/`, `tests/Encounter/Functional/Controller/DataPrepToolControllerTest.php`, `DataPrepGuideControllerTest.php`, `tests/Encounter/Functional/DataPrep/ShippedDataPrepToolTest.php` |

The scripts are plain scripts without imports; the server inlines the core, the script modules of the
contributions and the app into the file, and the Node tests load them as side-effect modules
(`globalThis.EncounterDataPrep`). Translations come
from the `encounter` domain (`encounter.data_prep.*`) in the locale of the user who downloads the file.

## Versions and update check

`app.encounter.data_prep` in `config/packages/app.yaml`:

| Option | Meaning |
|---|---|
| `version` | version of the rendered tool, part of the file name (`encounter-data-prep-<version>.html`) and of the manifest (`tool=`) |
| `min_version` | tools below it block processing; the import (#1048) rejects their files |
| `released_at` | release date shown in the tool and in `latest.json` |

Raise `version` with every change to the scripts, the template, a bundled profile, catalog or the schema copy,
and add its entry (version, date, notes as `encounter.data_prep.changelog.*` keys in EN and DE) at the top of
`DataPrepChangelog`; `DataPrepChangelogTest` fails if the newest entry is not the configured version.
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

### MTS catalog

`GET /data-prep/mts-catalog.json` (#1157, [ADR 032](../../02-architecture/decisions/032-mts-catalog-as-maintained-data.md))
serves the maintained catalog in the tool's catalog format, with the same rules as `latest.json`: public,
stateless, `Access-Control-Allow-Origin: *`, `X-Content-Type-Options: nosniff`, `Cache-Control: public,
max-age=300` and an `ETag`. It only reads the catalog; entries are created by the import, never by this
endpoint.

```json
{
    "format": "encounter-mts-catalog",
    "version": 1,
    "id": "mts-de",
    "diagrams": [{ "code": "thoraxschmerz", "name": "Thoraxschmerz", "englishName": "Chest pain" }],
    "indicators": [{ "code": "heiss", "name": "Heiß", "englishName": null }],
    "aliases": { "diagrams": { "brust_schmerzen": "thoraxschmerz" }, "indicators": {} }
}
```

- `diagrams`, `indicators`: the `curated` entries ordered by code; `name` is the German name. Provisional
  entries are left out: their codes come from hospital exports, nobody has reviewed them yet and the endpoint
  needs no login. The tool lists such codes as new catalog values and writes them as usual. Merged entries are
  not listed; their codes are aliases.
- `aliases`: alias code → code of a curated target, only aliases that resolve (ADR 032).

At start the tool fetches it once (`credentials: 'omit'`, `cache: 'no-store'`, no query) and validates it
(`parseCatalog()`: format, version, codes `^[a-z0-9_]{1,64}$`). Without a valid answer it uses the bundled
copy, `mts-de.json` turned into the same format by the renderer (codes derived from the labels, no English
names, no aliases). A status line under the version says which catalog is in use and how many diagrams,
indicators and aliases it has. The catalog is the one in use when the user presses "Prepare".

### Content Security Policy

The file sets, as a `<meta>` tag:

```
default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:;
connect-src <absolute URL of /data-prep/latest.json> <absolute URL of each contributed catalog>; base-uri 'none'; form-action 'none'
```

With the `ed_visit` contribution the catalog URL is `/data-prep/mts-catalog.json`; the policy text is the same as
before #1301 (checked by `ShippedDataPrepToolTest`).

So no request other than the update check and the MTS catalog (1.2.0) is possible, and no external script,
style, font or image is loaded. Checked in the browser for v1: the page made exactly one request
(`latest.json`); fetches to other URLs fail. CSP source matching ignores query strings; the tool sends none.

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

Hospital-specific codes are never part of the public profile, the repository or the server; keep the
real local map next to the key file (for development under the git-ignored `var/demo/`). Each hospital keeps a
JSON file next to the key file and loads it per run (optional):

```json
{
    "format": "encounter-local-map",
    "version": 1,
    "profile": "orbis-mts",
    "hospital": "ZNA Musterklinik",
    "departments": {
        "INN XYZ": "<name of a department of the reference catalog>"
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
| `departments` | source department code (`Stat_Fachrichtung`, else `Med. Fachabteilungskürzel`) → `admitting_department`: the name of a department of the reference catalog as the import resolves it (case-insensitive); 1–64 characters, no `;`, `"`, control characters or surrounding spaces, and nothing the import's content check would take for a person's name (`Word, Word`, a title like `Dr.` or `Herr` before a capitalised word) or a date; such a value would reject the whole file on the server. Since 1.4.0 the tool carries the names the import resolves (`department-names`, as of the download): a loaded map lists entries with another name as a warning in the map status ("Not in the department catalog …") but stays usable; the import still rejects rows with an unknown or ambiguous name, also when the catalog changed after the download |
| `stations` | `Stat_Station` → `care_level` (`ward`, `monitoring`, `icu`, `or`, `unknown`) for `admitted` |
| `dispositions` | additional or overriding `Entlassung` texts → `disposition` and optional `careLevel` (only with `admitted` or `transferred_external`) |

Keys match case-insensitively, with whitespace collapsed. Values that no map knows are written empty
and listed in the tool's report ("Unmapped values"); the report shows them only on screen.

### Local map editor (1.1.0)

The hospital fills the map in the tool, offline; the server never sees the codes (ADR 026/027):

- After a run, the "Unmapped values" of `admitting_department` (map section `departments`), `care_level`
  (`stations`) and `disposition` (`dispositions`) get an input: a department chosen from the bundled names
  the import resolves (since 1.4.0, #1638; a free-text field marked while it breaks the name rules only when the
  tool has no name list), a care level, or a disposition with an optional care level (only for `admitted` and
  `transferred_external`). Which report fields are editable follows from the profile's `department` and
  `disposition` rules (`localMapSections`). Other unmapped fields need a new profile version.
- "Add to map and run again" adds the filled-in entries to the loaded map (or a new one for the
  profile, named after the key file's label), replacing entries with the same lookup key
  (`setLocalMapEntry`). The result goes through `loadLocalMap`, the same validation as a loaded file; if
  it fails, the invalid entries are listed and nothing changes. Otherwise the tool runs again on the
  export already loaded.
- "Save map" downloads the map file (`encounter-local-map-<label>-<date>.json`, the format above, four
  spaces). Until then the map status warns that the changes are not saved.
- "Remember on this computer" stores the map in the same IndexedDB database as the key (entry
  `local-map`); "Forget map" deletes it. Convenience only; the saved file is the source of truth.

No new request: the editor added nothing to the Content Security Policy.

## Landing page

`/tools/data-prep` (beta role), linked from the Tools landing page; the main navigation entry
"Tools" stays active on it and its subpages. Until v0.4-beta the old paths under `/encounter/data-prep`
redirect here (#1160). The pages show no case data and
ask for no codes. Layout: main column (2/3) and side column (1/3).

| Page | Main column | Side column |
|---|---|---|
| `/tools/data-prep` | Getting started: 1. grants (what upload, review and view cases allow, the user's hospitals from encounter grants and ownership with the user's own grant actions and, for `MANAGE_GRANTS`, a link to the grants page); 2. export (the profile's required columns); 3. key file; 4. local map; 5. run; 6. upload and review. Below, short summaries of the key file and the local map with links to their pages | current version (`version`, `released_at`, `min_version`, download), What's new (`DataPrepChangelog`, newest first) |
| `/tools/data-prep/key` | what the key is for, creating and keeping it, key generations, a lost key | key change: links to `app_encounter_key_change` only for hospitals with `CHANGE_KEY` (owner, administrator); others read who can change it |
| `/tools/data-prep/local-map` | purpose, format and sections, allowed care levels and dispositions (from `CareLevel`, `Disposition`), example | downloads of the empty template and the example; the department names the import resolves (`DepartmentCatalogInterface::uniqueNames()`: names of the reference catalog that no other entry shares, case-insensitive) |

Help for common problems is left for the help area.

## Flow

1. Key file: load (or remembered key), or create once.
2. Local map: optional.
3. Export: the profile is detected by its required header columns, and the profile decides the schema of
   the run. If several profiles match, the tool names them and stops: the order of the profiles never
   decides (there is no manual profile choice). UTF-8 if the bytes are valid
   UTF-8, otherwise Windows-1252; `;`, quoted cells. The column preview lists every source column as
   kept, transformed or dropped, with the target field or the reason.
4. Prepare: transformation, report, content check and preview of the first ten rows.
5. Save: `<schema>_<profile>_<first anchor date>_<last anchor date>.csv` (for ORBIS `ed_visit_orbis-mts_…`), UTF-8, LF, manifest line
   first ([exchange-format.md](exchange-format.md#file)).

## Transformation rules

The allow-list is the profile: a column the profile does not map is dropped. Rules of the format
(ranges, fingerprint, codes) are in [exchange-format.md](exchange-format.md); the tool implements:

| Topic | Rule |
|---|---|
| Rows | rows without case number or without both anchor timestamps are skipped and counted; so are rows without age (and without date of birth) or without a mappable sex, both required fields; a second row with the same fingerprint is skipped as duplicate |
| Keys | `visit_fingerprint` and `case_key` exactly per [`ed-visit:v1`](exchange-format.md#visit-fingerprint-ed-visitv1), WebCrypto HMAC-SHA256 with the key file, built from the [key rules](#key-rules) the server renders |
| Export boundary | only the fields of the active schema version reach the file (`exportFields()`); a schema module may read source values such as the case number or the date of birth, but never writes them |
| Age | leading number of `Alter`; without it, age at the anchor date from `Geburtsdatum`; then clamped to 1–99 |
| Timestamps | `dd.mm.yyyy HH:MM[:SS]` with any whitespace between date and time; output `YYYY-MM-DDTHH:MM`, seconds cut; invalid values are emptied and counted |
| Codes | profile map, case-insensitive; optional `prefixes`; empty markers (`-- keine --`) give empty; other values give empty and are listed as unmapped |
| MTS diagram, indicator | catalog code derived from the label: lowercase, umlauts transliterated, other characters `_`, cut at 64 characters, no leading or trailing `_` (`Abdominelle Schmerzen bei Erwachsenen` → `abdominelle_schmerzen_bei_erwachsenen`); the same rule as `MtsCatalog::code()` on the server (#1056). A label is known when its code is an entry or alias code of the catalog in use. The code of the label is written as it is, also for an alias (ADR 032: the source code is kept, the server resolves aliases when it reads); aliases are listed in the report as "Other spellings recognised" (value → catalog code). Unknown diagrams and indicators (1.2.0: diagrams too, before they were emptied) keep their code and are listed as "new catalog values"; the import adds them to the catalog as provisional entries (#1157) |
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
diagram and indicator columns before they become codes. `admitting_department` is not checked here: its
values come from the hospital's own local map, which is checked when it is loaded (see the local map
format).

| Finding | Pattern |
|---|---|
| date-like value in a non-date field | `dd.mm.yy(yy)` or `yyyy-mm-dd` |
| name-like value | `Lastname, Firstname` at the start, or `Herr` / `Frau` followed by a name |
| long free text | more than 100 characters or at least 12 words |

Findings list field, source column, kind, count and first line. With findings the check fails, and
saving needs an explicit confirmation that the values are not personal data; the import checks the
content again. The check reduces the risk, it does not find every identifier (ADR 026).

## Key rules

The fingerprint and the case key of a schema version come from its dimension
(`EncounterDimensionInterface::keyRules($version)`, #1301): field, rule or prefix and the parts
(`case_number` as digits, `anchor_source`, `anchor` with seconds). The renderer adds them to the schema as
`keys`; schema files must not carry their own copy. The tool's core, the manifest's `fingerprint=` and the
synthetic data (`SyntheticEncounterKey`) all use these rules. All schemas share the case key `case:v1`, so
records of one case find the same encounter; each schema has its own fingerprint rule
(`ed-visit:v1`, in tests `inpatient-stay:v1`). `fixtures/fingerprint-vectors.json` carries the rendered block of
`ed_visit` v1, pinned to the dimension by `DataPrepContractTest`.

## Schema contributions

A schema joins the tool through a service implementing `DataPrepContributionInterface` (tag
`encounter.data_prep_contribution`, autoconfigured), usually in its bundle:

| Method | Content |
|---|---|
| `schema()` | schema name, as in the manifest and in the profiles |
| `schemaFile()` | schema file (`schema`, `version`, `fields`; no `keys`) |
| `profileFiles()` | source profiles; each names the schema |
| `scriptFile()` | optional script module that calls `EncounterDataPrep.registerSchema()` with its rule types and hooks (`beforeKeys`, `afterRow`, `columnPlan`, local map sections, catalog format); null for a schema the generic rule types cover |
| `catalogs()` | `DataPrepCatalog`: id, bundled payload (offline fallback) and the public route the tool refreshes it from (allowed in the CSP); without a route the payload is only bundled at download (#1638). A payload in the core's format `encounter-name-list` (`id`, `names`) is parsed by the core; a local map section with `catalog: '<id>'` then offers those names in the editor and warns about other names in a loaded map |
| `localMap()` | `DataPrepLocalMap`: template and example files and the values of the local map page; at most one contribution may have one |

Paths are absolute and must resolve from the installed package (bundle path or project directory).
`DataPrepContributions` orders contributions by schema name and checks them when they are read (files,
profile schema, unique profile and catalog ids, no key rules in schema files). Without any contribution the
tool pages, the download and the local map files answer 404 and the Tools card is hidden; `latest.json`
stays for tools already downloaded. The test bundle `inpatient_stay` contributes a schema file and one profile
and runs with the generic core alone.

## Adding a profile

1. Add `profiles/<id>.json` with `schema`, `detect.requiredColumns`, `keys`, `fields` (generic rule types
   `code`, `timestamp`, `catalog`, `integer`, `flag`, `codeSet`; with the `ed_visit` module also `age`,
   `vital`, `bloodPressure`, `disposition`, `department`) and `dropped`. Its required columns must not match
   the exports of another profile: several matches stop the tool.
2. List it in its contribution's `profileFiles()`. The `ed_visit` contribution bundles the MTS catalog file
   (`MtsCatalog::bundledToolCatalog()`) as offline fallback; a change to `plugins/ed-visit/src/Application/Catalog/mts-de.json`
   changes the tool only. The tool, the MTS glossary and the import read the catalog in the database, maintained
   in the back office (ADR 032).
3. Add a synthetic sample and tests; `DataPrepContractTest` checks the codes against the schema.
4. Raise `app.encounter.data_prep.version`.

Profiles are data only: a new rule type needs a change to the core and a new tool version.

## Tests

- `npm run test:js` (part of `npm run check:js` / `make lint-js`): Node's built-in test runner on the
  synthetic sample `tests/Encounter/DataPrep/fixtures/orbis-mts-sample.csv` (invented values,
  Windows-1252), with hand-checked expected values, the content check and stable fingerprints.
- `fixtures/fingerprint-vectors.json` holds fingerprint vectors and the rendered key rules of `ed_visit` v1;
  the JavaScript tests and `DataPrepContractTest` (PHP, `EdVisitDimension::keyRules(1)`) both check them;
  `EncounterKeyRulesTest` shows the shared case key of `ed_visit` and `inpatient_stay`.
- `ShippedDataPrepToolTest` downloads the tool, runs that HTML file offline with Node
  (`tests/Encounter/DataPrep/run-shipped-tool.js`: its own inline scripts, the bundled catalog, no network),
  once with the ORBIS sample and once with the `inpatient_stay` PoC, checks the CSP and imports both files
  through the regular import; the ORBIS file must equal `orbis-mts-sample.ed_visit.csv`. Skipped without `node`.
- An export-boundary test feeds the ORBIS sample with an unknown column and a row whose age comes from the
  date of birth; case numbers, names, dates of birth and the unknown column must not reach the file.
- Real exports are never used in tests or committed.
