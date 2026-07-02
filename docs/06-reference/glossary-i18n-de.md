# i18n glossary (German targets)

Authoritative EN↔DE table for UI translations. Domain-specific keys live in context domains (`statistics`, `allocation`, `import`, …); generic UI strings and cross-cutting entity labels in `messages+intl-icu.{en,de}.xlf`. Domain overview: [../03-development/translations.md](../03-development/translations.md).

Used as a reference for manual review and machine-translation drafts (phase 0 of the translation strategy).

**See also:** technical project terms in [glossary.md](glossary.md) · clinical reference data in `fixtures/reference/*.yaml`

---

## How to use this document

1. **Decisions** in the table below are binding (phase 0 complete).
2. Review **suggestions marked with `(?)`** — especially where existing DE translations are inconsistent.
3. This glossary is binding for bulk MT and module-by-module review.
4. New features: add EN and DE keys in the same PR (see [../04-features/statistics/analysis-explorer.md](../04-features/statistics/analysis-explorer.md)).
5. Check progress: `make lint-trans-de` (see [../03-development/testing.md](../03-development/testing.md)).
6. **Domain choice and form conventions:** [../03-development/translations.md](../03-development/translations.md) (sections *Recommended conventions* and *Domain decision matrix*).

---

## Translation rules

| Rule | Description |
|---|---|
| ICU placeholders | Keep `{name}`, `{count}`, `{chart}`, `{dimension}`, `{grain}`, etc. **unchanged** |
| HTML/XML | Do not alter or remove tags in `<target>` |
| Product names | IVENA, Collaborative IVENA Statistics → **do not translate** |
| File formats | CSV, PNG → **do not translate** |
| Abbreviations in data | LST, RD, ZLST, k.A. from reference data → explain in UI labels or follow this glossary |
| Address form | **Sie** (formal) | consistently in UI copy |

### Allocation vs. Zuweisung vs. Fall (core rule)

The entity is **Allocation** everywhere in code; in the UI it is **Zuweisung / Zuweisungen** (decision #1).

| Context | EN (typical) | DE | Note |
|---|---|---|---|
| Singular label (`label.allocation`) | Allocation | **Zuweisung** | replaces former „Verlegung“ |
| Plural in charts/explorer | Allocations | **Zuweisungen** | consistent in UI |
| KPI „cases“ / counters | cases | **Fälle** | decision #2 |
| Technical/projection (code/docs) | allocation(s) | Allocation(en) | technical context only, not UI |
| Export | Export allocations | **Zuweisungen exportieren** | see Export section |

**Terminology audit (phase 2):** Existing DE catalogue still has ~41 occurrences of „Verlegung“, „Allocationen“, „Leitstellenbereich“, etc. — clean up before bulk translation.

- `label.assignment` → **Zuweisungstyp** (decision #8)
- Explorer dimension `assignment` → align with #8

---

## Do not translate

| Term | Reason |
|---|---|
| IVENA | Product name |
| CSV | File format |
| CSV export / PNG export | Format label |
| EasyAdmin | Framework/admin UI |
| Symfony / Doctrine | Technology (admin/docs only) |
| GCS | Medical abbreviation (Glasgow Coma Scale) — optional expansion on first use |
| CPR | common in emergency medicine; alternative „Reanimation“ — see clinical features |
| ECMO / ECLS | established abbreviations |
| ROSC | established abbreviation |

---

## Platform & roles

| EN | DE | Status |
|---|---|---|
| Participant | Teilnehmer | role `ROLE_PARTICIPANT` |
| Participant onboarding | Onboarding | aligned with [../04-features/onboarding/participant-onboarding.md](../04-features/onboarding/participant-onboarding.md) |
| Import (process) | Import | noun; established in [glossary.md](glossary.md) |
| Requeue | Erneut starten | technical; mostly admin/CLI |
| Reject (import row) | Zurückgewiesene Zeile | — |
| Explore | Erkunden | nav `link.explore` |
| Statistics | Statistik / Statistiken | singular/plural by context |
| Analysis Explorer | Analyse-Explorer | already established |
| Benchmark / Benchmarking | Benchmarking | loanword vs. „Vergleichsanalyse“ |
| Dashboard | Dashboard | common in hospital IT context |
| Settings | Einstellungen | already translated |
| Worklist | Arbeitsliste | indication review; currently „Review-Worklist“ |

---

## IVENA data model (Allocation domain)

Entities and filter labels — mostly from existing DE labels in `messages+intl-icu.de.xlf`.

| EN | DE | Note |
|---|---|---|
| Hospital | Krankenhaus | |
| Dispatch area | Leitstelle | |
| State | Bundesland | |
| Department | Abteilung | |
| Speciality | Fachrichtung | |
| Tier / care level | Versorgungsstufe | `label.tier`, `hospital_tier` |
| Location (hospital) | Standort | vs. „Lage“ for urban/rural — see cohort |
| Size (hospital) | Größe | KPI: „Größenklasse“ |
| Assignment | Zuweisungstyp | decision #8 |
| Occasion | Anlass | incident context (place/situation) |
| Secondary transport | Sekundärtransport | reference: Sekundärverlegung, Kapazitätsengpass |
| Transport type | Transportart | |
| Infection | Infektion | |
| Indication | Indikation | |
| Normalized indication | Indikation (normalisiert) | |
| Raw indication | Indikation (original) | currently partly English „Raw-Indikation“ |
| Secondary indication | Sekundärindikation | |
| Indication group | Indikationsgruppe | see `fixtures/reference/indication_groups.yaml` |
| MCI case(s) | MANV-Fälle | mass-casualty incident |
| Urgency | Dringlichkeit | |
| Urgency 1 / emergency | Notfallversorgung | `allocation.urgency.1` |
| Urgency 2 / inpatient | Stationäre Versorgung | `allocation.urgency.2` |
| Urgency 3 / outpatient | Ambulante Versorgung | `allocation.urgency.3` |
| Department was closed | Fachbereich war abgemeldet | `field.departmentWasClosed` |
| Age / age group | Alter / Altersgruppe | keep numeric buckets (0–18, …) |
| Gender | Geschlecht | |
| Male / female / other | Männlich / Weiblich / Divers | for `label.gender.*` |

### Assignment types (reference `fixtures/reference/assignments.yaml`)

| DE (reference) | UI note |
|---|---|
| Arzt/Arzt | |
| Einweisung | |
| LST | dispatch centre — optional tooltip |
| Notzuweisung | |
| Patient | |
| RD | emergency medical service — optional tooltip |
| ZLST | central dispatch |

---

## Clinical features & resources

Boolean flags and explorer metrics — largely established.

| EN | DE | Note |
|---|---|---|
| Resus / requires resus | Schockraum angefordert | |
| CPR | Reanimation | rate: „Reanimationsrate“ |
| Cath lab | Herzkatheter angefordert | |
| Ventilation / ventilated | Beatmung | |
| Shock | Schock | |
| Pregnancy | Schwangerschaft | |
| Work accident | Arbeitsunfall | |
| Infectious / infection | Infektiös / Infektion | |
| With physician | Arztbegleitung | |
| Clinical resources | Klinische Ressourcen | |
| Clinical features | Klinische Merkmale | |
| Clinical care (group) | Klinische Versorgung | |

### ABCDE assessment (`label.assessment.*`) — **no DE yet**

| EN (category) | DE | Status |
|---|---|---|
| Assessment | Assessment | |
| Airway | Atemweg | |
| Airway — at risk | Atemweg gefährdet | clinical review |
| Airway — critical | Atemweg kritisch | clinical review |
| Airway — free | Atemweg frei | clinical review |
| Airway — intubated | Intubiert | clinical review |
| Breathing | Atmung | |
| Breathing — spontaneous | Spontanatmung | clinical review |
| Breathing — insufficient | Atmung unzureichend | clinical review |
| Breathing — NIV | NIV | non-invasive ventilation |
| Breathing — invasive | Invasive Beatmung | clinical review |
| Breathing — CPAP | CPAP | keep abbreviation |
| Circulation | Kreislauf | |
| Circulation — stable | Kreislauf stabil | clinical review |
| Circulation — unstable | Kreislauf instabil | clinical review |
| Circulation — ongoing CPR | laufende Reanimation | clinical review |
| Disability | Neurologie | ABCDE-D |
| GCS below 15 / below 9 | GCS &lt; 15 / GCS &lt; 9 | keep GCS abbreviation |

---

## Statistics & analytics

| EN | DE | Status |
|---|---|---|
| Overview | Übersicht | |
| Key findings | Wesentliches | executive dashboard |
| Case volume | Fallzahl | |
| Cases per day | Fälle pro Tag | |
| Median age | Medianalter | |
| Night share | Nachtanteil | |
| Weekend share | Wochenendanteil | |
| Physician rate | Arztbegleitungsrate | |
| Median transport (time) | Median Transportzeit | |
| Data completeness | Datenvollständigkeit | |
| Distribution | Verteilung | `statistics.distribution.*` |
| Box plot | Box-Plot | already „Box-Plot“ in DE |
| Heatmap | Heatmap | |
| Cross-tab | Kreuztabelle | |
| Scope | Bereich | filter context |
| Period | Zeitraum | |
| Cohort | Kohorte | |
| Hospital cohort | Krankenhauskohorte | |
| Master cohort | Master-Kohorte | already established |
| Comparison cohort | Vergleichskohorte | benchmarking |
| Projection | Projektion | table `allocation_stats_projection` |
| Materialized view | Materialized view | mostly admin/docs |
| Generic analysis | Generische Analyse | |
| Case flow | **Patientenfluss** | module `stats.case_flow.*` |
| Centralization | Zentralisierung | case flow KPI |
| Regional share | Regionalanteil | |
| Overregional share | Überregionaler Anteil | |
| Indication insights | Indikationen | |
| Indication dashboard | Indikations-Dashboard | |
| Reports | Berichte | |
| Hospital population | Krankenhaus-Population | partly established |
| Data quality indicator | Datenqualitätsindikator | see [../04-features/statistics/data-quality-indicator.md](../04-features/statistics/data-quality-indicator.md) |
| Coverage | Abdeckung | DQ dimension |
| Representativeness | Repräsentativität | DQ dimension |
| Subgroup (support) | Untergruppen | DQ dimension |
| Traffic light LOW/MEDIUM/HIGH | Niedrig / Mittel / Hoch | decision #13 |

### Explorer — time & aggregation

| EN | DE | Status |
|---|---|---|
| Month / year / weekday / hour | Monat / Jahr / Wochentag / Stunde | established |
| Time grain | Zeiten | |
| Day time bucket | Tageszeit | established |
| Shift bucket | Schicht | established |
| Transport time bucket | Transportzeit-Gruppen | possibly „Transportzeit-Klasse“ |
| Percent of total | Anteil an Gesamt | |
| Prevalence rate | Prävalenzrate | |
| Top 5 / Top 10 | Top 5 / Top 10 | keep as-is |

### Cohort attributes (`hospital.location.*`, `hospital.size.*`) — **no DE yet**

| EN | DE |
|---|---|
| Urban | Städtisch |
| Rural | Ländlich |
| Mixed | Gemischt |
| Small / Medium / Large | Klein / Mittel / Groß |

---

## Import & data quality (workflow)

| EN | DE | Status |
|---|---|---|
| Upload | Hochladen | |
| Processing | Verarbeitung | |
| Pending / running / completed / failed / cancelled / partial | Ausstehend / Läuft / Abgeschlossen / Fehlgeschlagen / Abgebrochen / Teilweise abgeschlossen | status labels |
| Row count | Zeilenanzahl | |
| Rows passed | Zeilen ohne Fehler | |
| Rows rejected | Zeilen zurückgewiesen | |
| Rejection rate | Rückweisungsrate | |
| Deduplicated | Dedupliziert | import report |
| Duplication rate | Duplikatsrate | |
| Preview rows | Fall-Vorschau | |
| Run time | Laufzeit | |
| Source file | Quelldatei | |

---

## Indication review (workflow)

| EN | DE | Status |
|---|---|---|
| Review worklist | Indikations-Review-Arbeitsliste | decision #11 |
| Start matching | Matching starten | |
| Start reviewing | Review starten | |
| Unreviewed | Ungeprüft | |
| Match proposed by | Match vorgeschlagen von | |
| Reviewed by | Geprüft von | |
| Approved by | Freigegeben von | |
| Match rejected by | Match abgelehnt von | |
| Four-eyes principle | Vier-Augen-Prinzip | already in help text |
| Complete without normalized indication | Ohne Zuordnung … abschließen | established |

---

## Export & explore

| EN | DE | Status |
|---|---|---|
| Export allocations | Zuweisungen exportieren | see Allocation rule |
| Export period | Exportzeitraum | partly established |
| Include raw indication | Original Indikation mit exportieren | established |
| Explore data | Daten erkunden | onboarding text: „Rohdaten … im Explorer“ |

---

## Resolved decisions (binding)

| # | Question | Option A | Option B | **Decision (DE)** |
|---|---|---|---|---|
| 1 | Singular/plural Allocation in UI | Verlegung / Verlegungen | Allocation / Allocationen | Zuweisung / Zuweisungen |
| 2 | „cases“ in KPIs (`stats.overview.*`, Case Flow) | Fälle | Verlegungen | Fälle |
| 3 | Benchmarking | Benchmarking | Vergleichsanalyse | Benchmarking |
| 4 | Explore (navigation) | Explorer | Daten erkunden | Erkunden |
| 5 | Participant | Teilnehmer | Mitwirkende/Klinikpartner (?) | Teilnehmer |
| 6 | MCI cases | MANV-Fälle | MCI-Fälle | MANV-Fälle |
| 7 | Raw indication | Roh-Indikation | Unverarbeitete Indikation | Indikation (original) |
| 8 | Assignment (label) | Zuweisungstyp | Verlegungsart | Zuweisungstyp |
| 9 | Case flow (module title) | Patientenfluss | Fallfluss | Patientenfluss |
| 10 | Address form Du/Sie | Sie (formal) | Du (informal) | Sie (formal) |
| 11 | Worklist | Worklist | Arbeitsliste | Arbeitsliste |
| 12 | Reject (import) | Abweisung | Fehlerhafte Zeile | Zurückgewiesene Zeile |
| 13 | Data quality traffic light labels | Niedrig/Mittel/Hoch | Schlecht/Mittel/Gut | Niedrig/Mittel/Hoch |

### Free text — additional domain terms

<!-- Add terms that come up in clinical practice or need local review. -->

```
Example:
- „Schockraum“ vs. „Traumazimmer“ → ...
- „Notaufnahme“ vs. „ZNA“ → ...
```

---

## MT prompt quick reference (copy-paste)

When bulk-translating a key prefix (e.g. `stats.benchmark.*`), use a German prompt like:

```
Übersetze Symfony-XLF-Targets EN→DE.
Regeln:
- ICU-Platzhalter {…} unverändert lassen
- IVENA, CSV, Benchmarking nicht übersetzen
- Allocation in UI-Kontext: Zuweisung / Zuweisungen
- cases in KPIs: Fälle
- Medizinische Begriffe konsistent zum Glossar glossary-i18n-de.md
- Sie-Form (formell)
Kontext-Modul: [z.B. stats.benchmark.*]
```

---

## Next steps (translation rollout)

1. **Phase 1 (tooling):** `make trans-de`, `make lint-trans-de` — see [../03-development/testing.md](../03-development/testing.md)
2. **Phase 2a — terminology audit:** existing DE entries against this glossary (Zuweisung instead of Verlegung, Leitstelle, Schockraum, …)
3. **Phase 2b — waves:** Shared/User → Content → Statistics → Import/Allocation (prefix batch MT + review per module)
