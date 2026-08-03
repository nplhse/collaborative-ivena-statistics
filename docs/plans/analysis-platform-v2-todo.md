# Analysis Platform v2 — To-do-Checkliste

**Operatives Dokument** auf `refactor/analysis-platform-v2`. Vor Merge nach `main` **entfernen**; Rest nur noch dauerhafte Code-Doku/ADR.

Status: `[ ]` offen · `[x]` erledigt · `[-]` entfällt · `BLOCKED:` …

Verwandt: [Plan](analysis-platform-v2-plan.md) · [ADR 013](../02-architecture/decisions/013-analysis-platform-rewrite.md) · [Ersetzungsinventar](analysis-platform-v2-replacement-inventory.md)

---

## P0 — Planung auf Basis-Branch

Branch: `refactor/analysis-platform-v2`

- [x] Basis-Branch von `main` angelegt
- [x] ADR 013 erstellt + Index
- [x] Implementierungsplan erstellt
- [x] Diese To-do-Liste erstellt
- [x] Ersetzungsinventar angelegt
- [x] Links in Statistics-/Architecture-/Docs-READMEs
- [x] `Kernel::APP_VERSION` → `v0.0.6-alpha`
- [x] Planungs-Commit (Docs + Version) — erledigt mit initialem P0-Commit
- [x] Kein Analyse-Produktivcode in P0 (Prüfpunkt vor Commit)

---

## P1 — Zeitreihe (Monatliche Fallzahlen)

Branch: `refactor/analysis-v2-time-series` → Merge nur in Basis

### Vor Start

- [x] ADR + Plan + Todo gelesen
- [x] Bestehende Monatszeitreihen inventarisiert (Overview, `allocations-over-time`, Indication)
- [x] Nicht-Ziele bestätigt (keine Vorperiode, kein freier Designer, keine Complete-Months-Periode)

### Umsetzung

- [x] Scope-Auflösung (eigene Kliniken / bestehendes Filtermodell) angebunden
- [x] Zeitraum `Period::All` (Overview-Semantik) angebunden
- [x] Zusatzfilter möglich (mind. ResusRoom/`requires_resus`, Alter, Dringlichkeit)
- [x] Query-Pfad für Count-Zeitreihe festgelegt/vereinheitlicht
- [x] Gemeinsames Result für Linie und Tabelle
- [x] Liniendiagramm angebunden
- [x] Tabellenansicht angebunden (gleiche Query/Result)
- [x] System-View (Slug/`allocations-over-time` oder Nachfolger) + Family `time_series`
- [x] Library-Metadaten (Family, Topic) für die View
- [x] Overview-Drill-down mit Scope/Period-Kontext
- [x] Indication/Katalog-Overlay soweit machbar
- [x] Ersetzungsinventar aktualisiert

### Tests / Abschluss

- [x] Unit/Integration/Functional wie betroffen
- [ ] Security/Scope-Tests
- [ ] Golden/Ergebnisgleichheit Overview↔View wo angestrebt
- [ ] lint / static-analysis / migrate (falls Migration)
- [ ] Performance-Smoke notiert
- [x] Todo + Plan aktualisiert; Stop (keine P2-Vorarbeit)

---

## P2 — Ranking (Top-Indikationen)

Branch: `refactor/analysis-v2-ranking`

- [ ] Vor-Start-Checkliste (ADR/Plan/Todo, Nicht-Ziele)
- [ ] Ranking-Config: Dimension Indikation, Count, Limit 25, Sortierung
- [ ] Tabelle (± optional horizontaler Balken laut View-Policy)
- [ ] System-View + Topic `indications` + Family `ranking`
- [ ] Scope/Period/Filter
- [ ] Drill-down / Library-Auffindbarkeit
- [ ] Tests; Ersetzungsinventar (Reports/Top-Queries)
- [ ] Todo aktualisiert; Stop

---

## P2b — Weitere Top-Listen

Branch: `refactor/analysis-v2-ranking-variants`

- [ ] Varianten als Config (Indikationsgruppen, Anlässe, Infektionen, Assignments, Departments, Specialties)
- [ ] Nur Spezialklasse bei echten Sonderregeln
- [ ] System-Views + Topics
- [ ] Tests; Todo aktualisiert

---

## P3 — Verteilung (Dringlichkeit)

Branch: `refactor/analysis-v2-distributions`

- [ ] Dringlichkeitsverteilung Count (+ Anteil mit Nenner/Missing-Policy)
- [ ] Balken + Tabelle
- [ ] System-View + Family `distribution`
- [ ] Tests; Todo aktualisiert

---

## P4 — Kennzahlen / statistische Zusammenfassungen

Branch: `refactor/analysis-v2-statistical-metrics`

- [ ] Float-Audit Presenter/Relative/DescriptiveStats
- [ ] Transportzeit AVG/MIN/MAX (QB oder GA)
- [ ] Median/Quartile enablen (`PERCENTILE_CONT` vorhanden)
- [ ] Age in GA nachziehen (später in Phase oder Teilschritt)
- [ ] Five-Number-Summary dokumentiert; kein Tukey-Zwang
- [ ] Tests; Todo aktualisiert

---

## P5 — Matrix / Heatmap

Branch: `refactor/analysis-v2-matrix`

- [ ] Wochentag × Stunde; eine Metric
- [ ] Heatmap + Kreuztabelle
- [ ] System-View + Family `matrix`
- [ ] Kein Pivot-Produkt
- [ ] Tests; Todo aktualisiert

---

## Library-Taxonomie (mitwachsend)

Branch optional: `refactor/analysis-v2-library-taxonomy`

- [ ] Entity-Spalten Family + Topics
- [ ] Seeder befüllt System-Views
- [ ] Library-Filter Family + Topic + Search
- [ ] Card-Badges; dataSource-Chip statt category als Primärfilter
- [ ] Tests

---

## P6+ (später, eigene Aufträge)

- [ ] Vergleiche (Benchmarking bleibt Spezial)
- [ ] Tukey-Boxplots / erweiterte Stats
- [ ] Abgeleitete Quoten mit Nenner-Policy
- [ ] Freierer Designer
- [ ] Zusammengesetzte Berichte
- [ ] Explorer-UI progressiv (`refactor/analysis-v2-explorer-ui`)

---

## Abschluss vor Merge nach `main`

- [ ] ADR-Inhalt an den finalen Code-Stand angeglichen (Status bereits `accepted`)
- [ ] Dauerhafte Feature-Doku aktualisiert
- [ ] Diese Todo-Datei entfernen
- [ ] Implementierungsplan archivieren/kürzen/entfernen (bewusst)
- [ ] Migrationskette geprüft
- [ ] Alpha-Muss-Kriterien erfüllt
- [ ] Expliziter Auftrag zum Merge nach `main`
