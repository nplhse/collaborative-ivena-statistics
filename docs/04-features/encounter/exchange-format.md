# Encounter exchange format `ed_visit` v1

**Audience:** Developers of the data preparation tool, the encounter import and source profiles.

**Status:** agreed design for the pilot (#1043); implemented in #1048 (import) and #1051 (tool).

Context: [ADR 025](../../02-architecture/decisions/025-encounter-data-context-and-exchange-format.md).

## File

- UTF-8, delimiter `;`, LF or CRLF.
- Line 1: manifest, line 2: header, then one row per ED visit.

```
#encounter-exchange;schema=ed_visit;schema_version=1;profile=orbis-mts;profile_version=1;tool=0.3.0;key_check=<hex>;fingerprint=ed-visit:v1;rows=<n>
```

| Manifest key | Meaning |
|---|---|
| `schema`, `schema_version` | contract of the file; unknown values reject the file |
| `profile`, `profile_version` | source profile that produced the file |
| `tool` | tool version; below the server's minimum version rejects the file |
| `key_check` | check value of the key file ([ADR 026](../../02-architecture/decisions/026-encounter-pseudonym-key.md)) |
| `fingerprint` | rule used for `visit_fingerprint`; unknown rules reject the file |
| `rows` | number of data rows; a mismatch rejects the file |

The header must match the schema version exactly. Empty cells are null.

## Fields

Timestamps: `YYYY-MM-DDTHH:MM`, Europe/Berlin local time, minute precision.

| Group | Field | Type / values | Required |
|---|---|---|---|
| keys | `visit_fingerprint` | hex HMAC of the fingerprint rule input, prefix `ed-visit:v1` ([ADR 028](../../02-architecture/decisions/028-encounter-import-lifecycle.md), rule [below](#visit-fingerprint-ed-visitv1)) | yes |
| | `case_key` | hex HMAC pseudonym of the administrative case number, prefix `case:v1`; used only for the suspected-duplicate check, not for grouping or as a key | yes |
| demographics | `age` | integer 1–99 (< 1 → 1, ≥ 100 → 99) | yes |
| | `sex` | `M`, `F`, `X` | yes |
| arrival | `arrival_mode` | `self`, `ambulance`, `patient_transport`, `physician_vehicle`, `other`; the server derives the arrival route from it ([mapping](README.md#population)) | no |
| | `referral_source` | `self`, `ems`, `emergency_physician`, `hospital_transfer`, `physician`, `kv_practice`, `other` | no |
| | `admission_type` | `emergency`, `elective` | no |
| | `case_status` | `outpatient`, `inpatient`, `pre_inpatient`, `post_inpatient` | no |
| times | `ed_admission_at` | timestamp | one of the two (rows without both are skipped by the tool) |
| | `first_assessment_contact_at` | timestamp | one of the two |
| | `assessment_start_at`, `assessment_end_at` | timestamp | no |
| | `physician_target_at` | timestamp (latest physician contact by MTS target time) | no |
| | `first_physician_at` | timestamp | no |
| | `treatment_end_at` | timestamp | no |
| | `ed_discharge_at` | timestamp | no |
| | `admin_admission_at`, `admin_discharge_at`, `pre_inpatient_admission_at` | timestamp | no |
| triage | `mts_category` | `red`, `orange`, `yellow`, `green`, `blue` | no |
| | `mts_diagram` | MTS diagram code (catalog) | no |
| | `mts_indicator` | MTS indicator code (catalog) | no |
| | `pain_score` | integer 0–10 | no |
| | `retriage_category`, `retriage_diagram`, `retriage_indicator`, `retriage_at` | as above | no |
| disposition | `disposition` | `home`, `to_physician`, `kv_practice`, `against_advice`, `left_before_completion`, `admitted`, `transferred_external`, `died`, `other` | no |
| | `care_level` | for `admitted` and `transferred_external`: `ward`, `monitoring`, `icu`, `or`, `unknown`; otherwise empty | no |
| | `admitting_department` | department code of the reference catalog | no |
| vitals | `vitals_reference` | `admission_measurement` (measurement with documented reference to the admission, `vitals_measured_at` set) or `documented_initial` (documented first value without assured time reference) | if any vital is set |
| | `vitals_measured_at` | timestamp; only with `admission_measurement` | no |
| | `bp_systolic`, `bp_diastolic` | mmHg, 40–300 / 20–200 | no |
| | `heart_rate` | /min, 20–300 | no |
| | `temperature` | °C, 25.0–45.0 | no |
| | `respiratory_rate` | /min, 3–80 | no |
| | `glucose` | mg/dl, integer, 10–1500 (decimals rounded by the tool, see rules) | no |
| | `spo2` | %, 30–100 | no |
| | `gcs` | 3–15 | no |
| flags | `work_accident` | `1` (documented yes) or empty (not documented, unknown); there is no value for "no" | no |
| | `tetanus_protected` | `1` (documented yes) or empty (not documented, unknown) | no |
| | `isolation` | comma-separated codes: `gastroenteritis`, `clostridioides`, `covid19`, `mdro`, `mrsa`, `esbl`, `pseudomonas`, `tb`, `other` | no |

Rules:

- The tool sets out-of-range vitals to empty and counts them in its report; the server rejects values
  outside the ranges. The ORBIS MTS export has no measurement time, so its profile writes
  `documented_initial`; the UI labels such values "documented first value" and does not place them on
  the admission timeline.
- Admission within the own hospital (`admitted`) and external transfer (`transferred_external`) are
  separate dispositions; an unmappable ward gives `care_level = unknown`.
- The arrival anchor for matching is `ed_admission_at`, fallback `first_assessment_contact_at`.
- Durations are not part of the format; they are computed from timestamps.
- Glucose is exchanged in whole mg/dl. Sources with decimals (ORBIS writes for example `999.99 mg/dl`)
  are rounded half up by the tool (`99.5` → `100`, `99.49` → `99`) before the range check; the server
  rejects a glucose value with decimals.
- The server checks every field against this table (#1048): ranges are inclusive, temperature has at most
  one decimal, integers have none, timestamps are exactly `YYYY-MM-DDTHH:MM`. A value outside its range
  or code list rejects the row, never the file.
- `mts_diagram`, `mts_indicator` and their retriage counterparts are interim slugs of the German label
  (`^[a-z0-9_]{1,64}$`: lowercase ASCII, ä→ae, ö→oe, ü→ue, ß→ss, other characters → `_`) until the MTS
  catalog is fixed (#1056); any other value rejects the row.
- `admitting_department` is the name of a department of the reference catalog (case-insensitive); an
  unknown or ambiguous name rejects the row.
- Unknown manifest keys are ignored; required are the eight keys of the example line.
- What the server rejects as a whole file and what it deletes at once:
  [README, Import](README.md#import).

## Visit fingerprint `ed-visit:v1`

Decided in #1045, see [ADR 028](../../02-architecture/decisions/028-encounter-import-lifecycle.md).

```
anchor_source = "assessment" if first assessment contact is set, else "admission"
anchor        = that timestamp in source resolution, formatted YYYY-MM-DDTHH:MM:SS (Europe/Berlin)
case_number   = case number with all non-digits removed, leading zeros kept
input         = "ed-visit:v1|" + case_number + "|" + anchor_source + "|" + anchor
visit_fingerprint = hex(HMAC-SHA256(key, input))
case_key          = hex(HMAC-SHA256(key, "case:v1|" + case_number))
```

- Rows without case number or without both timestamps are skipped and counted in the tool's report.
- If the source has no seconds, `:00` is used.
- Known limits: a visit whose first assessment contact is entered later (untriaged visits) changes
  its fingerprint; the import reports such cases as suspected duplicates (same `case_key`, anchor
  within 15 minutes). Two visits of one case with the same anchor second would collide; none was
  observed.
- ORBIS profile: first assessment contact = `Erstkontakt Ersteinschätzung`, ED admission =
  `Aufnahme ZNA`.

## ORBIS MTS profile (public part)

Source: ORBIS MTS export, cp1252, `;`, quoted cells, timestamps `dd.mm.yyyy  HH:MM:SS` (two spaces),
trailing empty column.

| ORBIS column | Treatment |
|---|---|
| `Fallnummer` | input of `visit_fingerprint` and `case_key` |
| `Patientenname`, `Geburtsdatum`, `Fall-ID` | dropped (date of birth only as age fallback inside the tool) |
| `Arzt des Erstkontakt`, `Ersteinschätzer`, `Zweiteinschätzer`, `VerantwortlicherArzt`, `VerantwortlichePflege` | dropped (staff) |
| `Grund des Kommens`, `Bemerkung`, `Allergien`, `GrundUeberschreitungAK`, `GrundManuelleAenderungAK`, `AnmerkungDauerAufenthalt`, `IsolationSonstiges` | dropped (free text) |
| `CEDIS Vorstellungsgrund` | empty in the analysed export; coded presenting complaint, candidate for a later schema version |
| `Wochentag`, duration columns (`… bis …`, `Gesamtaufenthaltsdauer`) | dropped (derived) |
| `Entlassende Fachrichtung`, `PrimaereFachrichtungCode`, `Ambulanzkürzel Schein`, `Stat_Station` | dropped (hospital-internal units); `Stat_Station` only feeds `care_level` via the local map |
| `GeriatrischesAssessment`, `BegruendungHerabtriagierung`, `weitere Stammdaten`, `Konsile`, `SchmerzBeiEntlassung`, `LeftWithoutBeingSeen`, `Schwanger` | dropped in v1 |
| `Alter` | `age` |
| `Geschlecht` (`M`, `W`) | `sex` (`M`, `F`) |
| `Transport` (`Ohne`, `RTW`, `KTW`, `NAW/NEF/ITW`, `Anderes`) | `arrival_mode` (`self`, `ambulance`, `patient_transport`, `physician_vehicle`, `other`) |
| `Einweisung` | `referral_source` |
| `Notfallpatient - Elektivpatient` | `admission_type` |
| `Fallstatus` | `case_status` |
| `Aufnahme ZNA`, `Erstkontakt Ersteinschätzung`, `Ersteinschätzung Beginn`/`Ende`, `spätester Arztkontakt`, `Erstkontakt Arzt`, `Behandlungsende`, `Entlassungszeit`, `admin. Fall-Aufnahmedatum`, `admin. Fall-Entlassdatum`, `vorstat. Aufnahmedatum` | time fields |
| `MTS Einstufung` (`rot` … `blau`), `MTS Diagramm`, `Indikator` (`-- keine --` → empty), `Schmerzskala-Einstufung` | triage fields |
| second-assessment columns | `retriage_*` |
| `Entlassung` | `disposition` and, with the local map, `care_level` |
| `Stat_Fachrichtung`, `Med. Fachabteilungskürzel` | `admitting_department` via the local map |
| `Blutdruck`, `Puls`, `Temperatur`, `Atemfrequenz`, `Blutzucker`, `Sauerstoffsaettigung`, `GCS` | vitals (units stripped, glucose rounded half up to whole mg/dl, ranges checked) |
| `BG`, `Tetanusschutz` (`ja` / empty) | `work_accident`, `tetanus_protected` (`ja` → `1`, anything else → empty) |
| `Isolation`, `Infektiös` | `isolation` |

### Local map (private, per hospital)

Hospital-specific unit codes are never part of the public profile. Each hospital keeps a local map
file for the tool:

- department codes → reference catalog departments (`admitting_department`)
- station codes → care level (`icu`, `monitoring`, `ward`)
- disposition texts specific to the hospital, if any

Unmapped values become `unknown` / empty and are listed in the tool's preview.
