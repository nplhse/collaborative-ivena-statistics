# Ranking table

**Audience:** Developers rendering a statistics ranking (rank, label, count, share).

`Statistics:RankingTable` ([`RankingTable.php`](../../../src/Statistics/UI/Twig/Components/RankingTable.php)) is the table body for that ranking. [`DataTable`](../../03-development/twig-components.md) stays the entity and catalog list: it owns card chrome, property columns, and the `25/50/100` footer. A ranking already lives inside another card, so those pieces do not belong on it.

## API

Pass already translated header strings and `list<RankingTableRow>`.

| Prop | Default | Notes |
|------|---------|--------|
| `rankHeader`, `labelHeader`, `countHeader`, `shareHeader` | `''` | Header text. Count and share are right-aligned. |
| `rows` | `[]` | `RankingTableRow` values. |
| `showShareBar` | `false` | Extra column using `top_lists/_share_bar.html.twig`. Top Lists set this on the normal view only. |
| `compact` | `false` | Adds `table-sm`. Comparison sides use it. |
| `valuePairs` | `false` | Wraps rank, count, and share in the flex pair so both comparison sides keep the same row height. |
| `emptyMessage` | `null` | Muted paragraph when `rows` is empty. No `EmptyState`, no footer. |
| `shareBarTestId` | `stats-top-lists-share-bar` | |
| `countDeltaAriaKey`, `shareDeltaAriaKey` | Top List comparison aria keys | Passed to `Statistics:DeltaIndicator` when the cell has a delta. |
| `countDeltaTestId`, `shareDeltaTestId` | Top List comparison test ids | |

`RankingTableRow` fields:

- `rank`, `label`, `count`, `share` — already formatted strings
- `labelHref`, `labelContext` — optional link and secondary line
- `rankShift` — optional `RankingTableRankShift`, rendered with `_rank_shift_badge.html.twig` in the rank cell
- `countDelta`, `shareDelta` — optional numbers, rendered with `Statistics:DeltaIndicator` in that cell
- `shareBar` — optional `0–100` width when `showShareBar` is true
- `action` — optional icon link (`insights/_table_icon_action.html.twig`). The actions column appears when any row has one
- `testId` — optional row `data-testid`

There is no comparison mode. Render the component twice and set delta or rank shift only on the rows that need them.

## Widget payload

`RankingTableRows::fromTableWidget()` reads a Top List `StatisticWidget` table payload: string cells plus parallel `labelRowTargets`, `insightRowTargets`, and `shareBars`. The caller supplies the URL for each `StatisticWidgetNavigationTarget`. `ranking_table_widget_rows(payload)` does that with the same URL rules as `statistics_nav_url`.

`dashboard/_table_card.html.twig` renders this component when the payload has `shareBars`. The card header and the limit footer (ranking depth, page size, pagination, CSV) stay outside the table. Payloads with `monthRowTargets`, `summaryStats`, or `footerRow` are analysis tables and stay on `_analysis_table_inner.html.twig`.

Comparison sides use `ranking_table_comparison_rows(rows, showDiff, rowTestIdPrefix)`. Side B adds delta and rank shift. The side header stays in `top_lists/_comparison_side_table.html.twig`, and both tables share one limit footer.

Insights directories and the overview featured table build rows with `ranking_table_insight_rows(rows, actionTestId)`.

## Left outside

- Card chrome, comparison side header, ranking depth, page size, pagination, and CSV
- Search, group tabs, and the Insights directory footer
- Analysis Explorer results, matrices, and heatmaps
- Case Flow segment tables (no rank column; they already include the share bar and the delta indicator)
