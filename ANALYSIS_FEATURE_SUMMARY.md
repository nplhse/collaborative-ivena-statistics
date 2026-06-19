# Analysis Feature — Implementierungszusammenfassung

> Temporäre Notiz zum Stand der Arbeiten an der GenericAnalysis-Erweiterung.

## Ausgangslage

Der Statistikbereich hatte bereits ein **GenericAnalysis**-Modul mit Preset-Views, Analytics Library, Builder und Customize-Drawer. Charts waren weitgehend fest verdrahtet (überwiegend Bar/Line), Pivot-Tabellen liefen parallel als Legacy-Feature, und Hospital-Daten waren nur teilweise angebunden.

## Ziele

1. **Chart-Typen konfigurierbar** — Line, Bar, Pie, Heatmap (sowie Horizontal Bar, Stacked Varianten)
2. **Serienmodi** — `by_dimension` vs. `by_metric` (Multi-Metric-Charts)
3. **Anzeigemodi** — Chart, Tabelle, Pivot-Tabelle
4. **Hospital-Data-Source** in dieselbe GenericAnalysis-Pipeline integrieren
5. **UI vereinheitlichen** — Builder, Drawer und View-Header nutzen gemeinsame Controls
6. **Legacy bereinigen** — altes Pivot-/Analysis-UI entfernen, `allocation_pivot` in die Library migrieren

---

## Architektur-Überblick

```
AnalysisQuery (+ dataSource, hospitalPopulationMode, chartType, seriesMode, displayMode)
    → GenericAnalysisService
        → AnalysisQueryExecutorRegistry (Allocations | Hospitals)
        → AnalysisQueryModifierRegistry (z. B. HospitalPopulationModifier)
    → ResultNormalizer → Chart / Table / Pivot Pipeline
```

Zentrale neue Bausteine:

| Baustein | Zweck |
|----------|-------|
| `AnalysisConfigurationValidator` | Regeln für Pie, Heatmap, Multi-Metric, Pivot |
| `AnalysisDataSourceRegistry` | Metadaten pro Data Source (Default-Metrik, Period-Hinweis, …) |
| `HospitalPopulationModifier` | All / Participating / Compare auf Hospital-Queries |
| `HospitalStatisticsScopeDefaultPolicy` | Default `scope=public` für Hospital-Statistiken |

Neue Query-Parameter: `ga_chart`, `ga_series_mode`, `ga_display`, `ga_data_source`, `ga_hospital_population`, …

---

## Umgesetzte Features

### Chart & Display

- Nutzer wählen Chart-Typ, Serienmodus und Anzeigemodus im Builder und Customize-Drawer.
- ApexCharts-Rendering um Pie und Heatmap erweitert (`build-analysis-chart-options.js`).
- `AnalysisViewConfig` v3 mit Round-Trip für gespeicherte Views.

### Hospital Data Source

- Eigener SQL-Builder (`GenericHospitalAnalysisSqlBuilder`) gegen Hospital-Entitäten.
- Hospital-Dimensionen (Tier, Size, State, …) und Metriken (`hospital_count`, `avg_beds`, …) in Registries.
- Neue Library-Views: `hospitals_by_tier`, `hospitals_by_size`, Compare-Varianten, …

### Hospital Population (All | Participating | Compare)

- **Compare** vergleicht `isParticipating = true` vs. `false` (nicht „alle Krankenhäuser“ vs. „teilnehmend“).
- Compare nutzt `CROSS JOIN` mit `hospital_population_group` als Serien-Dimension.
- Scope-Default **Public** für Hospital-Routen, damit Compare nicht durch implizites „My Hospitals“ verfälscht wird.

### UI-Vereinheitlichung

- Gemeinsame Partials: `_analysis_source_controls`, `_analysis_context_controls`
- Builder: `TabbedCard` für Data-Source-Tabs mit URL-Navigation
- Customize-Drawer: Data Source + Population per URL-Refresh (kein Stimulus-Partial nötig)

### Customize-Drawer: Data-Source-Wechsel

**Problem:** Beim Wechsel Allocations → Hospitals blieben alte `ga_primary`/`ga_series` in der URL → ungültige Dimensionen.

**Lösung:**
1. Navigation entfernt alle Custom-Parameter (`REMOVE_CUSTOM`), setzt nur `ga_data_source`.
2. Config-Resolver erkennt Data-Source-Override und mappt ungültige Dimensionen auf Hospital-Defaults.

### Legacy-Entfernung

- Altes `/statistics/pivot` und zugehörige Controller/Queries entfernt.
- `allocation_pivot` als GenericAnalysis-View in der Analytics Library.
- Legacy `generic_analysis/show.html.twig` und parallele Analysis-Controller-Logik bereinigt.

---

## Bewusste Entscheidungen / Offen

| Thema | Entscheidung |
|-------|--------------|
| Hospital Pivot (Legacy) | Nicht migriert — andere Datenkörnung, bleibt außerhalb |
| Dual Y-Axis bei `by_metric` | Blockiert, wenn Metriken unterschiedliche Formate haben |
| Drawer nach Data-Source-Wechsel | Voller Page-Reload; Chart-Einstellungen starten neu (sinnvoll) |
| `percent_of_row` | Neue Metrik für Pivot-Zeilenanteile |

---

## Relevante Dateien (Auswahl)

- Pipeline: `GenericAnalysisService.php`, `GenericAnalysisConfigResolver.php`
- Hospital: `GenericHospitalAnalysisSqlBuilder.php`, `HospitalPopulationModifier.php`
- UI: `_builder_form.html.twig`, `_customize_drawer.html.twig`, `_analysis_source_controls.html.twig`
- Scope: `HospitalStatisticsScopeDefaultPolicy.php`, `StatisticsFilterInputFactory.php`
- Frontend: `build-analysis-chart-options.js`, `analytics-customize-drawer_controller.js`

---

*Erstellt als Arbeitshilfe — kann nach Review gelöscht werden.*
