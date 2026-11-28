# Directional indicators

**Audience:** Developers changing comparison markup in statistics views.

Colour shows how a change is **assessed**, not its sign (#1230). Green means favourable, red unfavourable. A change that is neither stays grey; the sign (`+`/`-`) and, on `KpiCard`, the trend icon still show the direction. A higher share is not automatically better, so each place decides per metric whether more is better, worse, or neutral. The assessments listed below are product decisions for this application.

## Delta

`Statistics:DeltaIndicator` ([`DeltaIndicator.php`](../../../src/Statistics/UI/Twig/Components/DeltaIndicator.php)) formats one numeric change.

| Prop | Values |
|------|--------|
| `value` | Number or `null`. `null` renders nothing; the caller shows an em dash when the column must stay filled. |
| `unit` | `count` (`+12`), `decimal` (`+1,2`), `percent` for a relative change (`+1,2%`), `pp` for the difference between two shares (`+1,2 pp` / `+1,2 %-Punkte`), `minutes` (`+1,2 min`). See [Percentage points](#percentage-points). |
| `display` | `badge` (default) or `text`. |
| `whenZero` | `hide` (default) or `neutral`. Neutral is grey, without a leading `+`. |
| `colorizeTrend` | `false` (default): every change is grey. `true`: an increase is green, a decrease red. Same meaning and default as on `KpiCard`. |
| `invertTrend` | `true` swaps green and red for figures where more is worse. It only applies together with `colorizeTrend`; without it the change stays grey. |
| `ariaKey` | Statistics translation. The `{delta}` placeholder is the formatted value. |
| `title`, `extraClass` | Optional tooltip and extra CSS class. `data-testid` is a normal HTML attribute. |

Positive values start with `+`. Neutral values are `bg-secondary-lt` or `text-secondary`; with `colorizeTrend` a favourable change is `bg-green-lt` / `text-green` and an unfavourable one `bg-red-lt` / `text-red`. Zero is always grey.

Every call site sets `colorizeTrend` explicitly. All current uses show descriptive differences against a comparison, where more is neither better nor worse, so they set `:colorizeTrend="false"`:

| Where | Delta | Why neutral |
|------|------|------|
| Top Lists comparison (`RankingTable`, count and share on side B) | count, pp | Rank and volume, not quality |
| Case Flow segment tables | pp | Segment share against its parent population |
| Hospital-population representativity | pp | Any deviation from the population is equally relevant |
| Closed-department share bars | pp | Share during closures against regular operation |
| Monthly-report distribution legend | pp | Case mix (urgency, gender) |
| Benchmarking indication-mix delta column | pp | Case mix |
| Closure volume windows (absolute and relative) | decimal, % | Observed against expected volume |
| Transport Time Profile percent badges | pp | Depends on the time class; the heat cell already carries colour |

The Transport Time Profile still shows a badge only when the existing heat threshold says so. Cell heat, insight arrows, and the ranked-row highlight stay local.

Do not use this component for:

- The executive scorecard (`above` / `below` / `ok`)
- Monthly-report KPI tiles (`KpiCard` with `colorizeTrend` / `invertTrend`)
- Compare tiles and the indication-mix ratio column
- DataTable badges

## Compare tiles

[`_kpi_compare_tiles.html.twig`](../../../src/Statistics/UI/Twig/templates/_kpi_compare_tiles.html.twig) (overview self-benchmark, benchmarking, insights compare) colours the difference and the ratio badge by the same assessment:

| Metric | Assessment |
|------|------|
| Share with physician (`with_physician`), resuscitation bay (`resus`), cath lab (`cathlab`) | more is favourable |
| Mean transport time (`mean_transport`) | less is favourable |
| Allocations, cases per day, median age, 80+, night, weekend | neutral (grey) |

The tone follows the difference as shown, rounded to its display precision: `+0,04` pp is shown as `+0,0` and stays grey. A ratio above 1 always comes with a positive difference, so the badge shares the tone. When the comparison value is 0 the ratio is undefined (`BenchmarkMetric::hasRatio()` is false); the badge then shows a grey `—` with “Ratio not computable” as title and hidden text instead of `0,0×` (#1231).

**Comparison value 0 (#1252).** Ratio and relative difference are then undefined: `BenchmarkMetric::$ratio`,
`$relativeDelta` and `BenchmarkDistributionBucket::$ratio` are `null` (the relative change on Overview and monthly
report was already `null`). Every consumer treats `null` as "no ratio", never as 0:

- The Overview benchmark scorecard compares the own value with 0: above 0 is "above", 0 is "within".
- The baseline hints of the monthly report and reminder skip 0 % against 0 % and a baseline period without any
  case (it has no rate, not a rate of 0 %, see #1788); a positive value against a real 0 % is a rise in percentage
  points.
- The indication mix only lists buckets with a ratio (its minimum counts already keep the comparison share above 0).
- The benchmark insights only use median age and mean transport time when the ratio exists.

## Percentage points

The difference between two shares (20,0 % → 25,0 %) is a change in percentage points (+5,0), not a relative
change (+25 %). Never label it with `%`.

| Where | How |
|------|-----|
| `Statistics:DeltaIndicator` | `unit="pp"`. The title defaults to the spelled-out unit unless `title` is set; the accessible name always uses it. |
| Other statistics markup | `{{ include('@Statistics/_percentage_points_unit.html.twig') }}` after the number: short unit visible, spelled-out unit as `title` and visually hidden text. |
| Engagement texts and e-mails | `'monthly_reminder.unit.percentage_points'\|trans({}, 'engagement')`, appended after a space. |

Translation keys: `stats.unit.percentage_points.short` (`pp` / `%-Punkte`) and `stats.unit.percentage_points.long`
(`statistics`), `monthly_reminder.unit.percentage_points` (`engagement`). Relative changes such as month-over-month
allocation counts or closure volume deviations stay `percent`.

## Rank shift

[`_rank_shift_badge.html.twig`](../../../src/Statistics/UI/Twig/templates/_rank_shift_badge.html.twig) stays a separate include: new, up, or down. Top Lists comparison and the Transport Time Profile ranking use it. Do not fold rank movement into `DeltaIndicator`.
