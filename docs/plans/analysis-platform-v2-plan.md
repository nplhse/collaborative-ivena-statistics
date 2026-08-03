# Analysis Platform v2 — Implementierungsplan

**Branch:** `refactor/analysis-platform-v2`  
**Alpha:** `v0.0.6-alpha`  
**ADR:** [013-analysis-platform-rewrite.md](../02-architecture/decisions/013-analysis-platform-rewrite.md)  
**Todo:** [analysis-platform-v2-todo.md](analysis-platform-v2-todo.md)  
**Ersetzungsinventar:** [analysis-platform-v2-replacement-inventory.md](analysis-platform-v2-replacement-inventory.md)

Operatives Dokument (Deutsch). Änderungen während des Rewrite gemeinsam mit Code committen. Vor Merge nach `main` bewusst archivieren/kürzen/entfernen; ADR und Code-Doku bleiben.

---

## 1. Ziel

Bestehende Statistics-Analyseflächen (Overview, Explorer, Library, Reports, Katalog-Drill-downs) schrittweise auf gemeinsame Konzepte bringen:

- Analysefamilien (Zeitreihe → Ranking → Verteilung → Kennzahlen → Matrix)
- Measure × Aggregation (ohne Big-Bang-Key-Rename)
- Scope / Zeitraum / Filter gemeinsam und sicher
- Analysis Views + Library (Family + Topics)
- Vertikale Scheiben statt abstrakter Vorab-Engine

Kein Mini-BI, kein Query-Compiler. Benchmarking u. a. Spezialflächen bleiben eigene Produkte.

---

## 2. Ist-Kurzinventur

| Bereich | Route | Query-Stack | Familie (Soll) |
|---------|-------|-------------|----------------|
| Overview | `/statistics/` | Projection DBAL + QB, MVs | KPI / Zeitreihe / Verteilung / Matrix / Ranking |
| Reports | `/statistics/reports` | `Top*Query` (QB) | Ranking |
| Indication Insights / Dashboards | `/statistics/indication*` | Projection SQL/QB | Ranking → Spezial-Dashboard |
| Analysis Library / Explorer | `/statistics/analysis/*` | Generic Analysis SQL | alle Familien |
| Benchmarking / Compare | `/statistics/benchmarking`, compare | Projection SQL | Vergleich (spezialisiert) |
| Explore Catalog | `/explore/...` | Coverage + Deep Links | nicht-analytisch + Drill-down |
| Case Flow / Hospital Population / Data Quality | diverse | spezialisiert | Spezial |

Wiederverwendbar: `allocation_stats_projection`, `StatisticsFilter*` / Scope / Period, `SavedExplorerView`, Dimension-/Metric-Registries, Explorer-Presenters, Boxplot Five-Number-Summary.

Lücken: keine Family/Topics-Metadaten; Library-`category` = Datenquelle; Overview selten vertiefbar; parallele Query-Stacks; Metriken als Composite-Keys; Transport-Stats in Registry disabled.

---

## 3. Zielmodell (Kurz)

```text
AnalysisFamily + Measure + Aggregation + Dimensions
+ Scope-Strategie + Period + AnalysisFilter[]
→ Query → Result → Renderer
→ SavedExplorerView (+ library metadata)
```

Persistenz: weiterhin composite `metricKeys` / `visualMetric`; Domain-Mapping Measure×Agg. Family/Topics als Entity-Spalten. Period-Default: `StatisticsFilterPeriod::All`. ResusRoom = `requires_resus`; `is_shock` getrennt.

---

## 4. Branch-Strategie

| Branch | Inhalt | Merge nach |
|--------|--------|------------|
| `refactor/analysis-platform-v2` | Basis + Planungsdocs | später `main` (explizit) |
| `refactor/analysis-v2-time-series` | P1 Monatliche Fallzahlen | Basis |
| `refactor/analysis-v2-ranking` | P2 Top-Indikationen | Basis |
| `refactor/analysis-v2-ranking-variants` | P2b weitere Top-Listen | Basis |
| `refactor/analysis-v2-distributions` | P3 | Basis |
| `refactor/analysis-v2-statistical-metrics` | P4 | Basis |
| `refactor/analysis-v2-matrix` | P5 | Basis |
| `refactor/analysis-v2-library-taxonomy` | Family/Topics-UI (teilweise in P1/P2) | Basis |
| `refactor/analysis-v2-explorer-ui` | progressive UI später | Basis |

Sync `main` → Basis: Security/Schema zeitnah (Merge). Keine Cherry-Picks unfertiger Rewrite-Teile nach `main`. Andere historische Analysis-Branches ignorieren.

---

## 5. Phasen und Vertical Slices

| Phase | Branch | Slice | Nicht-Ziele |
|-------|--------|-------|-------------|
| P0 | Basis | ADR, Plan, Todo, Inventar, `v0.0.6-alpha` | Analyse-Produktivcode |
| P1 | `…-time-series` | Zuweisungen/Monat; Count; Filter (ResusRoom, Alter, Urgency); Linie+Tabelle gleiche Query; System-View; Overview-Drill-down | Vorperiode, alle Grains, freier Designer |
| P2 | `…-ranking` | Top-Indikationen Limit 25 | andere Top-Listen |
| P2b | `…-ranking-variants` | weitere Top-Dimensionen als Config | Spezial-Queries ohne Bedarf |
| P3 | `…-distributions` | Dringlichkeit; Count/Anteil | Kreisdiagramm-Zwang |
| P4 | `…-statistical-metrics` | Transport/Age Aggregate | Tukey-UI, CI, freie Formeln |
| P5 | `…-matrix` | Wochentag×Stunde; 1×1×1 | Pivot-Produkt |
| P6+ | eigene Childs | Vergleiche, Boxplots, Quoten, Designer, Berichte | alles auf einmal |

Vor jeder Phase die Planungsfragen im Todo abhaken (Inventar, Query, Result, Renderer, Views, Drill-downs, Tests, Nicht-Ziele).

---

## 6. Alpha-Mindestumfang (`v0.0.6-alpha`)

**Muss:** View-Grundstruktur; relative Scopes; Perioden; Filter; Zeitreihe; Ranking; Chart+Tabelle; Library Family+Topics; System-Views; Speichern neuer persönlicher Views; Overview-Drill-down; mind. ein Katalog/Indication-Overlay; Auth-Tests; Performance-Smoke.

**Soll:** Verteilungen; Grund-Matrix; zentrale Explorer-Flows ersetzt.

**Später:** volle Stats/Boxplots; komplexe Vergleiche; Berichte; freie Filtergruppen; voller Designer.

**Views:** bestehende persönliche Views verfallen; System-Views neu.

---

## 7. Teststrategie

Pro Child/Slice: betroffene Unit/Integration/Functional/Security; `make lint` / static-analysis; Migrationen; relevante Browser-Tests. Alte Tests nur mit dokumentiertem Ersatz entfernen (Ersetzungsinventar). Golden-Tests Overview↔View wo Semantik angeglichen wird.

---

## 8. Performance

Erst messen. Priorität: gemeinsame Query-Pfade → Lazy Loading → Indexes → Result-Cache → Voraggregation nur nach Bedarf. Percentile/Distribution-Raw-Pfade bewusst begrenzen.

---

## 9. Risiken

- Drift Basis↔`main`
- Migrationskonflikte zwischen Childs
- Scope Creep in Folgephasen
- Kommunikation: persönliche Views verfallen
- Zwei Query-Stacks divergieren bis bewusst vereinheitlicht

---

## 10. Definition of Done (Familie / Slice)

- Fachdefinition + Inventar dokumentiert
- Scope/Period/Filter sicher
- Query nachvollziehbar; Chart+Tabelle gleicher Result-Pfad
- ≥1 System-View; Library Family (+ Topic)
- ≥1 Drill-down
- Tests grün; Performance-Smoke
- Ersetzungsinventar + Todo aktualisiert
- Keine ungeplanten Folgephasen
- Basis nach Merge installierbar/migrierbar/testbar

---

## 11. Abschluss vor Merge nach `main`

1. ADR-Inhalt auf den implementierten Stand aktualisieren (Status bereits `accepted`).  
2. Feature-/Architektur-Doku beschreibt den Code.  
3. **Todo-Checkliste entfernen.**  
4. Diesen Plan archivieren/kürzen/entfernen (bewusst).  
5. Migrationskette prüfen; Alpha-Kriterien erfüllt.
