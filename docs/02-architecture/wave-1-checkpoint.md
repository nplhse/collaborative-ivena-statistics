# Phase 5 Welle 1 — Checkpoint

Stand nach Umsetzung der sechs Refactoring-Schritte auf Branch `refactor/architecture-review`.

## LOC-Hotspots (nach Welle 1)

| Komponente | Vorher (Phase 4) | Nachher | Ziel Welle 1 |
|------------|------------------|---------|--------------|
| `AllocationRepository` | 1 862 | **1 636** | &lt; 1 650 |
| `AnalysisExplorerShell` | 897 | **867** | &lt; 700 (Teilschritt; Runner extrahiert) |
| `StatisticsPageViewModelFactory` | ~584 | **50** | &lt; 450 |
| `BenchmarkComparisonPageViewModelFactory` | ~586 | **51** | &lt; 450 |
| Gemeinsame Logik | — | `StatisticsScopeViewModelBuilder` **623** | dedupliziert |

## Deptrac

| Metrik | Vorher | Nachher |
|--------|--------|---------|
| Baseline-Skips gesamt | 390 | **383** |
| Neue Violations | — | **0** |

Entfernt aus Baseline: 6× `KpiDashboardService` → Admin-UI-CrudController (ersetzt durch `AdminLinkGeneratorInterface` / `EasyAdminAdminLinkGenerator`).

`SettingsController`: `EntityManagerInterface` entfernt; `UserSettingsUpdater` in `User/Application`. Baseline-Eintrag (EmailVerifier) unverändert — Welle 2.

## Umgesetzte PRs

1. **AllocationTimeSeriesQuery** — Time-Series-Methoden aus `AllocationRepository` extrahiert
2. **ExplorerAnalysisRunner** — Analyse-Ausführung aus `AnalysisExplorerShell` delegiert
3. **StatisticsScopeViewModelBuilder** — Scope/Period/URL-Logik für Statistics + Benchmarking vereinheitlicht
4. **UserSettingsUpdater** — Settings ohne EntityManager im Controller
5. **AdminLinkGenerator** — KPI-Admin-URLs über Contract + Infrastructure
6. **Checkpoint** — dieser Bericht; Coverage **88,22 %** Lines (Stand `make complexity`-Lauf)

## Welle 2 (Vorschlag)

- Weitere `AllocationRepository`-Bucket-Queries (PR 1b)
- Explorer Edit-Drawer / ConfigMapper
- Restliche ARCH-008-Controller (`RegistrationController`, …)
- `AnalysisExplorerShell` weiter auf &lt; 700 LOC
