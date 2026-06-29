# Fachglossar i18n (DE)

Verbindliche EN↔DE-Tabelle für UI-Übersetzungen in `translations/messages+intl-icu.{en,de}.xlf`.
Dient als Referenz für manuelles Review und MT-Entwürfe (Phase 0 der Übersetzungsstrategie).

**Ergänzend:** technische Projektbegriffe in [Glossary.md](Glossary.md) · klinische Referenzdaten in `fixtures/reference/*.yaml`

---

## So nutzt du dieses Dokument

1. **Entscheidungen** in der Tabelle unten sind verbindlich (Phase 0 abgeschlossen).
2. **Vorschläge mit `(?)`** prüfen — besonders wo bestehende DE-Übersetzungen widersprüchlich sind.
3. Glossar ist bindend für Bulk-MT und modulweises Review.
4. Neue Features: EN- und DE-Key im selben PR (siehe [analysis-explorer-v2.md](analysis-explorer-v2.md)).
5. Fortschritt prüfen: `make lint-trans-de` (siehe [Testing.md](Testing.md)).

---

## Übersetzungsregeln

| Regel | Beschreibung |
|---|---|
| ICU-Platzhalter | `{name}`, `{count}`, `{chart}`, `{dimension}`, `{grain}` usw. **unverändert** lassen |
| HTML/XML | Tags in `<target>` nicht verändern oder entfernen |
| Produktnamen | IVENA, Collaborative IVENA Statistics → **nicht übersetzen** |
| Dateiformate | CSV, PNG → **nicht übersetzen** |
| Abkürzungen in Daten | LST, RD, ZLST, k.A. aus Referenzdaten → in UI-Labels erklären oder laut Glossar |
| Du/Sie | **Sie** (formell) | durchgängig in UI-Texten |

### Allocation vs. Zuweisung vs. Fall (Kernregel)

Im Code heißt die Entität überall **Allocation**; in der UI heißt sie **Zuweisung/Zuweisungen** (Entscheidung #1).

| Kontext | EN (typisch) | DE | Anmerkung |
|---|---|---|---|
| Singular-Label (`label.allocation`) | Allocation | **Zuweisung** | ersetzt frühere „Verlegung“ |
| Plural in Charts/Explorer | Allocations | **Zuweisungen** | einheitlich in UI |
| KPI „cases“ / Zähler | cases | **Fälle** | Entscheidung #2 |
| Techn./Projection (Code/Docs) | allocation(s) | Allocation(en) | nur in techn. Kontext, nicht in UI |
| Export | Export allocations | **Zuweisungen exportieren** | Entscheidung Export-Abschnitt |

**Terminologie-Audit (Phase 2):** Bestehende DE-Datei enthält noch ~41 Stellen mit „Verlegung“, „Allocationen“, „Leitstellenbereich“ usw. — vor Bulk-Übersetzung bereinigen.

- `label.assignment` → **Zuweisungstyp** (Entscheidung #8)
- Explorer-Dimension `assignment` → konsistent zu #8 halten

---

## Nicht übersetzen

| Begriff | Grund |
|---|---|
| IVENA | Produktname |
| CSV | Dateiformat |
| CSV-Export / PNG-Export | Formatbezeichnung |
| EasyAdmin | Framework/Admin-UI |
| Symfony / Doctrine | Technologie (nur Admin/Docs) |
| GCS | Medizinische Abkürzung (Glasgow Coma Scale) — optional mit Erstnennung |
| CPR | üblich in Rettungsmedizin; alternativ „Reanimation“ — siehe klinische Merkmale |
| ECMO / ECLS | etablierte Abkürzungen |
| ROSC | etablierte Abkürzung |

---

## Plattform & Rollen

| EN | DE (Vorschlag)        | Status                                                             |
|---|-----------------------|--------------------------------------------------------------------|
| Participant | Teilnehmer            | Rolle `ROLE_PARTICIPANT`                                           |
| Participant onboarding | Onboarding            | an [participant-onboarding.md](participant-onboarding.md) angelehnt |
| Import (Vorgang) | Import                | Substantiv, etabliert in [Glossary.md](Glossary.md)                |
| Requeue | Erneut starten        | technisch; eher Admin/CLI                                          |
| Reject (Import-Zeile) | Zurückgewiesene Zeile | -                                                                  |
| Explore | Erkunden              |  — Nav `link.explore`                               |
| Statistics | Statistik / Statistiken | Kontextabhängig Singular/Plural                                    |
| Analysis Explorer | Analyse-Explorer      | bereits etabliert                                                  |
| Benchmark / Benchmarking | Benchmarking          |  — Lehnbegriff vs. „Vergleichsanalyse“              |
| Dashboard | Dashboard             | gängig im Klinik-IT-Kontext                                        |
| Settings | Einstellungen     | bereits übersetzt                                                  |
| Worklist | Arbeitsliste      | Indikations-Review; aktuell „Review-Worklist“                      |

---

## IVENA-Datenmodell (Allocation-Domain)

Entitäten und Filterlabels — überwiegend aus bestehenden DE-Labels in `messages+intl-icu.de.xlf`.

| EN | DE                | Anmerkung |
|---|-------------------|---|
| Hospital | Krankenhaus       | |
| Dispatch area | Leitstelle        | |
| State | Bundesland        | |
| Department | Abteilung         | |
| Speciality | Fachrichtung      | |
| Tier / care level | Versorgungsstufe  | `label.tier`, `hospital_tier` |
| Location (hospital) | Standort          | vs. „Lage“ bei urban/rural — siehe Kohorte |
| Size (hospital) | Größe             | KPI: „Größenklasse“ |
| Assignment | Zuweisungstyp | Entscheidung #8 |
| Occasion | Anlass            | Einsatzanlass (Ort/Situation) |
| Secondary transport | Sekundärtransport | Referenz: Sekundärverlegung, Kapazitätsengpass |
| Transport type | Transportart      | |
| Infection | Infektion         | |
| Indication | Indikation        | |
| Normalized indication | Indikation (normalisiert) | |
| Raw indication | Indikation (original) | aktuell teils Englisch „Raw-Indikation“ |
| Secondary indication | Sekundärindikation | |
| Indication group | Indikationsgruppe | siehe `fixtures/reference/indication_groups.yaml` |
| MCI case(s) | MANV-Fälle | — Massenanfall von Verletzten |
| Urgency | Dringlichkeit     | |
| Urgency 1 / emergency | Notfallversorgung | `allocation.urgency.1` |
| Urgency 2 / inpatient | Stationäre Versorgung | `allocation.urgency.2` |
| Urgency 3 / outpatient | Ambulante Versorgung | `allocation.urgency.3` |
| Department was closed | Fachbereich war abgemeldet | `field.departmentWasClosed` |
| Age / age group | Alter / Altersgruppe | Buckets numerisch belassen (0–18, …) |
| Gender | Geschlecht        | |
| Male / female / other | Männlich / Weiblich / Divers |  für `label.gender.*` |

### Zuweisungstypen (Referenz `fixtures/reference/assignments.yaml`)

| DE (Referenz) | Hinweis für UI                            |
|---|-------------------------------------------|
| Arzt/Arzt |                                           |
| Einweisung |                                           |
| LST | Leitstelle — ggf. Tooltip                 |
| Notzuweisung |                                           |
| Patient |                                           |
| RD | Rettungsdienst — ggf. Tooltip             |
| ZLST | Zentrale-Leitstelle —  |

---

## Klinische Merkmale & Ressourcen

Boolean-Flags und Explorer-Metriken — weitgehend etabliert.

| EN | DE                       | Anmerkung |
|---|--------------------------|---|
| Resus / requires resus | Schockraum angefordert   | |
| CPR | Reanimation              | Rate: „Reanimationsrate“ |
| Cath lab | Herzkatheter angefordert | |
| Ventilation / ventilated | Beatmung                 | |
| Shock | Schock                   | |
| Pregnancy | Schwangerschaft          | |
| Work accident | Arbeitsunfall            | |
| Infectious / infection | Infektiös / Infektion    | |
| With physician | Arztbegleitung           | |
| Clinical resources | Klinische Ressourcen     | |
| Clinical features | Klinische Merkmale       | |
| Clinical care (group) | Klinische Versorgung     | |

### ABCDE-Assessment (`label.assessment.*`) — **noch ohne DE**

| EN (Kategorie) | DE (Vorschlag) | Status                     |
|---|---|----------------------------|
| Assessment | Assessment |                            |
| Airway | Atemweg |                            |
| Airway — at risk | Atemweg gefährdet | Fachreview                 |
| Airway — critical | Atemweg kritisch | Fachreview                 |
| Airway — free | Atemweg frei | Fachreview                 |
| Airway — intubated | Intubiert | Fachreview                 |
| Breathing | Atmung |                            |
| Breathing — spontaneous | Spontanatmung | Fachreview                 |
| Breathing — insufficient | Atmung unzureichend | Fachreview                 |
| Breathing — NIV | NIV | Nichtinvasive Beatmung     |
| Breathing — invasive | Invasive Beatmung | Fachreview                 |
| Breathing — CPAP | CPAP | Abkürzung belassen         |
| Circulation | Kreislauf |                            |
| Circulation — stable | Kreislauf stabil | Fachreview                 |
| Circulation — unstable | Kreislauf instabil | Fachreview                 |
| Circulation — ongoing CPR | laufende Reanimation | Fachreview                 |
| Disability | Neurologie | ABCDE-D |
| GCS below 15 / below 9 | GCS &lt; 15 / GCS &lt; 9 | Abkürzung GCS belassen     |

---

## Statistik & Analytics

| EN | DE (Vorschlag)                      | Status                                                       |
|---|-------------------------------------|--------------------------------------------------------------|
| Overview | Übersicht                           |                                                              |
| Key findings | Wesentliches                        | Executive Dashboard                                          |
| Case volume | Fallzahl                            |                                                              |
| Cases per day | Fälle pro Tag                       |                                                              |
| Median age | Medianalter                         |                                                              |
| Night share | Nachtanteil                         |                                                              |
| Weekend share | Wochenendanteil                     |                                                              |
| Physician rate | Arztbegleitungsrate                 |                                                              |
| Median transport (time) | Median Transportzeit                |                                                              |
| Data completeness | Datenvollständigkeit                |                                                              |
| Distribution | Verteilung                          | `statistics.distribution.*`                                  |
| Box plot | Box-Plot                            | bereits „Box-Plot“ in DE                                     |
| Heatmap | Heatmap                             |                                                              |
| Cross-tab | Kreuztabelle                        |                                                              |
| Scope | Bereich                             | Filter-Kontext                                               |
| Period | Zeitraum                            |                                                              |
| Cohort | Kohorte                             |                                                              |
| Hospital cohort | Krankenhauskohorte                  |                                                              |
| Master cohort | Master-Kohorte                      | bereits etabliert                                            |
| Comparison cohort | Vergleichskohorte                   | Benchmarking                                                 |
| Projection | Projektion                          | techn. Tabelle `allocation_stats_projection`                 |
| Materialized view | Materialized view                   | eher Admin/Docs                                              |
| Generic analysis | Generische Analyse                  |                                                              |
| Case flow | **Patientenfluss**                  |  — Modul `stats.case_flow.*`                 |
| Centralization | Zentralisierung                     | Case Flow KPI                                                |
| Regional share | Regionalanteil                      |                                                              |
| Overregional share | Überregionaler Anteil               |                                                              |
| Indication insights | Indikationen                        |                                                              |
| Indication dashboard | Indikations-Dashboard               |                                                              |
| Reports | Berichte                            |                                                              |
| Hospital population | Krankenhaus-Population              | bereits teils etabliert                                      |
| Data quality indicator | Datenqualitätsindikator             | siehe [data-quality-indicator.md](data-quality-indicator.md) |
| Coverage | Abdeckung                           | Dimension DQ                                                 |
| Representativeness | Repräsentativität                   | Dimension DQ                                                 |
| Subgroup (support) | Untergruppen | Dimension DQ |
| Traffic light LOW/MEDIUM/HIGH | Niedrig / Mittel / Hoch | Entscheidung #13 |

### Explorer — Zeit & Aggregation

| EN | DE                                | Status |
|---|-----------------------------------|---|
| Month / year / weekday / hour | Monat / Jahr / Wochentag / Stunde | etabliert |
| Time grain | Zeiten                            | |
| Day time bucket | Tageszeit                         | etabliert |
| Shift bucket | Schicht                           | etabliert |
| Transport time bucket | Transportzeit-Gruppen             | ggf. „Transportzeit-Klasse“ |
| Percent of total | Anteil an Gesamt                  | |
| Prevalence rate | Prävalenzrate                     | |
| Top 5 / Top 10 | Top 5 / Top 10                    | belassen |

### Kohorten-Attribute (`hospital.location.*`, `hospital.size.*`) — **noch ohne DE**

| EN | DE (Vorschlag) |
|---|---|
| Urban | Städtisch |
| Rural | Ländlich |
| Mixed | Gemischt |
| Small / Medium / Large | Klein / Mittel / Groß |

---

## Import & Datenqualität (Workflow)

| EN | DE (Vorschlag)                                                                              | Status |
|---|---------------------------------------------------------------------------------------------|---|
| Upload | Hochladen                                                                                   | |
| Processing | Verarbeitung                                                                                | |
| Pending / running / completed / failed / cancelled / partial | Ausstehend / Läuft / Abgeschlossen / Fehlgeschlagen / Abgebrochen / Teilweise abgeschlossen | Status-Labels |
| Row count | Zeilenanzahl                                                                                | |
| Rows passed | Zeilen ohne Fehler                                                                          | |
| Rows rejected | Zeilen zurückgewiesen                                                                       | |
| Rejection rate | Rückweisungsrate                                                                            | |
| Deduplicated | Dedupliziert | Import-Bericht |
| Duplication rate | Duplikatsrate                                                                               | |
| Preview rows | Fall-Vorschau                                                                               | |
| Run time | Laufzeit                                                                                    | |
| Source file | Quelldatei                                                                                  | |

---

## Indikations-Review (Workflow)

| EN | DE (aktuell / Vorschlag)     | Status |
|---|------------------------------|---|
| Review worklist | Indikations-Review-Arbeitsliste | Entscheidung #11 |
| Start matching | Matching starten             | |
| Start reviewing | Review starten               | |
| Unreviewed | Ungeprüft                    | |
| Match proposed by | Match vorgeschlagen von      | |
| Reviewed by | Geprüft von                  | |
| Approved by | Freigegeben von              | |
| Match rejected by | Match abgelehnt von          | |
| Four-eyes principle | Vier-Augen-Prinzip           | bereits in Help-Text |
| Complete without normalized indication | Ohne Zuordnung … abschließen | etabliert |

---

## Export & Explore

| EN | DE (aktuell / Vorschlag)         | Status |
|---|----------------------------------|---|
| Export allocations | Zuweisungen exportieren          | — siehe Allocation-Regel |
| Export period | Exportzeitraum                   | teils etabliert |
| Include raw indication | Original Indikation mit exportieren | etabliert |
| Explore data | Daten erkunden                   | Onboarding-Text: „Rohdaten … im Explorer“ |

---

## Getroffene Entscheidungen (verbindlich)

| # | Frage | Vorschlag A | Vorschlag B | Entscheidung            |
|---|---|---|---|-------------------------|
| 1 | Singular/Plural Allocation in UI | Verlegung / Verlegungen | Allocation / Allocationen | Zuweisung / Zuweisungen |
| 2 | „cases“ in KPIs (`stats.overview.*`, Case Flow) | Fälle | Verlegungen | Fälle                   |
| 3 | Benchmarking | Benchmarking | Vergleichsanalyse | Benchmarking            |
| 4 | Explore (Navigation) | Explorer | Daten erkunden | Erkunden                |
| 5 | Participant | Teilnehmer | Mitwirkende/Klinikpartner (?) | Teilnehmer              |
| 6 | MCI cases | MANV-Fälle | MCI-Fälle | MANV-Fälle              |
| 7 | Raw indication | Roh-Indikation | Unverarbeitete Indikation | Indikation (original)   |
| 8 | Assignment (Label) | Zuweisungstyp | Verlegungsart | Zuweisungstyp           |
| 9 | Case flow (Modultitel) | Patientenfluss | Fallfluss | Patientenfluss          |
| 10 | Anrede Du/Sie | Sie (formell) | Du (informell) | Sie (formell)           |
| 11 | Worklist | Worklist | Arbeitsliste | Arbeitsliste            |
| 12 | Reject (Import) | Abweisung | Fehlerhafte Zeile | Zurückgewiesene Zeile   |
| 13 | Data quality traffic light labels | Niedrig/Mittel/Hoch | Schlecht/Mittel/Gut | Niedrig/Mittel/Hoch     |

### Freitext — weitere domänenspezifische Begriffe

<!-- Hier kannst du Begriffe ergänzen, die dir spontan einfallen oder die im Klinikalltag anders heißen. -->

```
Beispiel:
- „Schockraum“ vs. „Traumazimmer“ → ...
- „Notaufnahme“ vs. „ZNA“ → ...
```

---

## MT-Prompt-Kurzreferenz (Copy-Paste)

Beim Bulk-Übersetzen eines Key-Prefixes (z. B. `stats.benchmark.*`):

```
Übersetze Symfony-XLF-Targets EN→DE.
Regeln:
- ICU-Platzhalter {…} unverändert lassen
- IVENA, CSV, Benchmarking nicht übersetzen
- Allocation in UI-Kontext: Zuweisung / Zuweisungen
- cases in KPIs: Fälle
- Medizinische Begriffe konsistent zum Glossar docs/Glossary-i18n-de.md
- Sie-Form (formell)
Kontext-Modul: [z.B. stats.benchmark.*]
```

---

## Nächste Schritte (Übersetzungs-Rollout)

1. **Phase 1 (Tooling):** `make trans-de`, `make lint-trans-de` — siehe [Testing.md](Testing.md)
2. **Phase 2a — Terminologie-Audit:** bestehende DE-Einträge gegen dieses Glossar (Zuweisung statt Verlegung, Leitstelle, Schockraum, …)
3. **Phase 2b — Wellen:** Shared/User → Content → Statistics → Import/Allocation (je Prefix-Batch MT + Review)
