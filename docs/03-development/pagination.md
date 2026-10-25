# Pagination

How lists in this application page their results, and how to choose for a new list. Decided in [#647](https://github.com/nplhse/collaborative-ivena-statistics/issues/647); the allocation keyset in [#651](https://github.com/nplhse/collaborative-ivena-statistics/issues/651).

All lists use [Symfony UX Pagination](https://symfony.com/bundles/ux-pagination/current/index.html) and render through the shared `DataTable` footer (see [twig-components.md](twig-components.md#footer)), which picks page numbers or previous/next from the paginator type.

## Decision rule

| Use | When | How |
|---|---|---|
| **Numbered** (default) | The total is useful to users and cheap to get. | `PaginatorInterface::query($source)->paginate()`; for prepared arrays or totals already known, `query($array)` or `fromCallbacks($slice, $count)`. |
| **No pagination** | The result set is bounded by design and fits on one screen in full. | Render the rows directly. |
| **Lookahead** | The count is measurably expensive *and* the total tells users nothing. | `PaginatorInterface::query($source)->lookahead()`. Not used anywhere yet. |
| **Cursor (keyset)** | The table is large or changes while people page through it. | Hand-written keyset query returning a `CursorPaginator` (see the allocation list). |

Numbered is the default because in this application the total is usually part of the answer: “80 hospitals”, “1,573 closure events”, rank 26–50 of a top list. Reach for lookahead only with a measurement that shows the count matters, and write down which total the users lose.

### Before choosing lookahead

- Measure the count query on the demo database (production copy), not on fixtures.
- If the total is already known (rows built in PHP, a ranking held in memory), take it from there. That is free, so lookahead brings nothing.
- If the rows and the total come from the same expensive computation, compute it once and page the result with `fromCallbacks` (see the recurring profiles below). Never run it twice, once for the rows and once for the count.

## Current lists

Measured with the Symfony profiler on the demo database (about 2.3 million allocations), October 2026.

| Lists | Kind | Source size | Count cost | Decision |
|---|---|---|---|---|
| Explore catalogs: states, dispatch areas, hospitals, specialities, departments, assignments, occasions, infections, secondary transports, indication groups, indications (normalized/raw), MCI cases | Numbered | 4 to ~1,200 rows, no joins on allocations | 2–5 ms | Keep numbered |
| Imports, users, raw indication review worklist | Numbered | ~50 to ~1,100 rows | a few ms | Keep numbered |
| Top lists | Numbered over a ranking held in memory | ranking limit 10/25/50/100/all (all is capped at 1,000) | none (count of the array) | Keep numbered: rank positions across pages read well |
| Analysis library, insight value directory, blog | Numbered over prepared arrays | small | none | Keep numbered |
| Closure analytics: units, recurring profiles | Numbered over rows built in PHP | small | none, the total comes from the same list | Keep numbered |
| Closure analytics: events | Numbered | ~1,600 events | ~8 ms | Keep numbered |
| Closure analytics: details (intervals) | Numbered | ~17,000 intervals | ~180 ms of ~1 s database time | Keep numbered: the number of closures is itself a statistic |
| Allocation explorer | Cursor (keyset) | ~2.3 million rows | estimate from `EXPLAIN`, no `COUNT(*)` | See [explore-allocation-list.md](../04-features/allocation/explore-allocation-list.md#pagination) |

Out of scope here: the activity timeline and user-profile feeds (their own Turbo Frame cursor feeds) and EasyAdmin.

### Top lists: ranking limit and page size

Top lists have two separate controls. The **ranking limit** (top 10/25/50/100/all) decides how many entries the ranking contains; the **page size** (25/50/100) decides how many of them one page shows. They only interact for top 50, top 100 and “all”. The full ranking is computed in memory, so the total and the page links cost nothing.

### Closure recurring profiles

`ClosureProfileQuery::recurringGroupRows()` returns every matching profile, already filtered and sorted. The controller pages that list with `fromCallbacks` and takes the total from `count()`. Before #647, rows and total each ran the grouping query, so the page paid for it twice (about 245 ms each on the demo database).
