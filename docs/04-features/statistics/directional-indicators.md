# Directional indicators

**Audience:** Developers changing comparison markup in statistics views.

Colour shows direction and magnitude. A higher share is not automatically better, so no metric inverts green and red because the change is “worse”.

## Delta

`Statistics:DeltaIndicator` ([`DeltaIndicator.php`](../../../src/Statistics/UI/Twig/Components/DeltaIndicator.php)) formats one numeric change.

| Prop | Values |
|------|--------|
| `value` | Number or `null`. `null` renders nothing; the caller shows an em dash when the column must stay filled. |
| `unit` | `count` (`+12`), `percent` (`+1,2%`), `minutes` (`+1,2 min`). There is no percentage-point suffix. |
| `display` | `badge` (default) or `text`. |
| `whenZero` | `hide` (default) or `neutral`. Neutral is grey, without a leading `+`. |
| `ariaKey` | Statistics translation. The `{delta}` placeholder is the formatted value. |
| `title`, `extraClass` | Optional tooltip and extra CSS class. `data-testid` is a normal HTML attribute. |

Positive values are green (`bg-green-lt` or `text-green`) and start with `+`. Negative values are red (`bg-red-lt` or `text-red`).

Used by Top Lists comparison (count and share on side B), Case Flow segment tables, hospital-population representativity, closed-department share bars, the monthly-report distribution legend, the benchmarking indication-mix delta column, and Transport Time Profile percent badges. The Transport Time Profile still shows a badge only when the existing heat threshold says so. Cell heat, insight arrows, and the ranked-row highlight stay local.

Do not use this component for:

- The executive scorecard (`above` / `below` / `ok`)
- Monthly-report KPI tiles, including the inverted Notzuweisungen colour
- Benchmark KPI tiles and the indication-mix ratio column
- DataTable badges

## Rank shift

[`_rank_shift_badge.html.twig`](../../../src/Statistics/UI/Twig/templates/_rank_shift_badge.html.twig) stays a separate include: new, up, or down. Top Lists comparison and the Transport Time Profile ranking use it. Do not fold rank movement into `DeltaIndicator`.
