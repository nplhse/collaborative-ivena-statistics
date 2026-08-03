# Analysis Platform v2 — Ersetzungsinventar

Pflegen während der Slices. Vor Entfernung alter Komponenten: Alt → Neu → Status dokumentieren.

| Alte Funktion | Neue Entsprechung | Status |
|---------------|-------------------|--------|
| Overview Monatszeitreihe (Chart-Pipeline) | Time-series Analysis View + Drill-down | geplant (P1) |
| System-View `allocations-over-time` | Time-series Family-View (ggf. gleicher Slug, neue Metadaten) | geplant (P1) |
| Reports `top_diagnoses` / TopDiagnosesQuery | Ranking Analysis View (Indikation) | geplant (P2) |
| Overview Top-Report-Cards / Indication Insights Top-Listen | Ranking System-Views + Library | geplant (P2/P2b) |
| Overview Gender/Urgency/Age/Transport-Verteilungen | Distribution Views + Drill-down | geplant (P3+) |
| Overview Heatmaps weekday×daytime/shift | Matrix Family Views (bestehende Heatmap-Seeds) | geplant (P5) |
| Library `category` Allocations/Hospitals als Primärnavigation | Family + Topics; dataSource als Chip | geplant |
| Explorer Edit-Drawer (Monolith) | View-Modus / progressive Konfiguration | geplant (später) |
| Persönliche SavedExplorerViews (Alpha alt) | — (verfallen, keine Migration) | entschieden |
| Benchmarking / Indication Compare | bleiben Spezialprodukte; optional Deep-Links | behalten |
| Executive KPI-Deck / Insights-Texte | bleiben bespoke | behalten |
| Case Flow / Hospital Population Map / Data Quality | bleiben Spezial | behalten |
| Admin `kpi_daily` | außerhalb User-Analytics | behalten |
| Transport-time statistical Explorer metrics (registered, disabled) | Enablement in P4 | geplant |
| Moving average on Overview time series | dashboard-only bis Explorer-Overlay existiert | bewusst Spezial |

## Hinweise

- Keine Dauer-Adapter zwischen Alt und Neu ohne späteren Nutzen.
- Beim Löschen von Tests: fachliche Zusicherung und Ersatztest hier oder im PR vermerken.
- System-Views über `app:statistics:explorer-views:sync` neu kuratieren.
