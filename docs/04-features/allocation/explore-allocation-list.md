# Explore allocation list

Route: `/explore/allocation` (`app_explore_allocation_list`)

Requires `ROLE_PARTICIPANT` (`access_control` on `/explore`). Cursor-paginated list of allocation records with a filter drawer (hospital attributes, geography, clinical flags, and more).

**Collaborative by design** ([ADR 011](../../02-architecture/decisions/011-collaborative-explore-allocation-visibility.md)): the default “All hospitals” scope shows allocations across centers. That is intentional; hospital filters are UX, not an authorization boundary. `ROLE_USER` alone cannot access Explore.

## My hospitals filter

Participants with view access to at least one hospital see a combined hospital select in the filter drawer:

- **All hospitals** — no hospital scope (default)
- **My hospitals** — all accessible hospitals (`HospitalPermission::View`)
- **Separator**
- **Individual hospitals** — filter to one accessible clinic

Query parameter: `hospitalFilter`

| Value | Effect |
|---|---|
| (empty) | No hospital filter |
| `my_hospitals` | Allocations for all hospitals the user can view |
| `{id}` | Allocations for that hospital if the user has view access |

Legacy URLs with `hospitalScope=my_hospitals` and optional `hospital={id}` remain supported.

## Created-at date filter

The filter drawer offers inclusive calendar dates (`createdFrom`, `createdUntil` as `Y-m-d`) on `created_at`. Times are not part of the public filter: from is start of day, until is the last included calendar day (SQL stays half-open via `createdToExclusive` = until + 1 day). Either bound may be omitted. Legacy query values `createdToExclusive` and `Y-m-d\TH:i:s` timestamps still parse so older bookmarks keep working.

## Catalog year drill-down

Explorer catalog detail pages (occasions, indications, hospitals, dispatch areas, …) show yearly allocation counts as a heatmap. Cells with a visible count link to this list with the catalog entity filter **and** the same created-at date filter for that calendar year:

`createdFrom={year}-01-01` and `createdUntil={year}-12-31`

The linked list is the same unscoped collaborative population as the catalog coverage cell (no extra user Scope). Hospital year cells additionally set `hospitalFilter={id}` and are only linked when the viewer has `HospitalPermission::View`. Indication groups have coverage years but no allocation-list filter, so those cells stay unlinked.

## Optional relation filters

Some Explore filters distinguish **no filter**, a **concrete value**, and an explicit **absence** (and sometimes **any present value**). Empty select = no filter; this is not the same as “none”.

Query helpers: `App\Allocation\Application\Filter\OptionalRelationFilter`.

| Filter | Unset | Concrete value | Absence (`none`) | Presence (`any`) |
|---|---|---|---|---|
| `secondaryTransport` | No filter | `st.id = {id}` | `secondary_transport_id IS NULL` | `secondary_transport_id IS NOT NULL` |
| `occasion` | No filter | `occasion_id = {id}` | `occasion_id IS NULL` | not offered (almost always set) |
| `secondaryIndication` | No filter | `secondary_indication_normalized_id = {id}` | `secondary_indication_normalized_id IS NULL` | not offered |
| `infection` | No filter | `infection={id}` | `infection=none` → `infection_id IS NULL` | `infection=any` → any infection (`IS NOT NULL`) |

The secondary-indication dropdown lists only diagnoses that appear as a secondary indication on at least one allocation (`explore_filter.secondary_indications`). The primary indication catalog is unchanged. A selected id that is missing from that occurrence list is still shown (bookmarked URL / stale cache).

Legacy URLs `?isInfectious=1` / `?isInfectious=0` still work (any / none). Specific diseases are always listed in the same select.

Labels: `label.all_allocations` (empty infection option), `label.all_secondary_indications`, `label.no_secondary_indication`, `label.no_secondary_transport`, `label.any_secondary_transport`, `label.no_occasion`, `label.no_infection`, `label.any_infection`. The infection and secondary-indication selects separate those special options from the catalog list with a disabled divider.

## Closed according to closure data

`closureAtAssignment` (`closed`, `open`, `unknown`) filters by the imported closure intervals, not by the IVENA flag `departmentWasClosed`; both filters can be combined. The rule is described in [closure-architecture.md](../statistics/closure-architecture.md#closed-at-assignment). Empty select = no filter, and the three values add up to all allocations. The allocation detail page shows the same value under the department. The help text says that only hospitals and periods with imported closure data can be `closed` or `open`.

## Pagination

The list uses keyset (cursor) pagination, not `OFFSET`, because `allocation` is the largest table. Pages are ordered by the sort field (`arrivalAt` or `age`) plus `id` as a tie-breaker, in the same direction. That keeps the order stable when many rows share a timestamp or age.

- **Cursor** — `CursorCodec` stores the boundary row (sort value + id), the sort it belongs to and a direction. A *next* cursor holds the last row of a page; a *previous* cursor holds the first row. A cursor for another sort, or one that cannot be decoded, is ignored and the first page is shown. Version 1 cursors (no direction) still work and point forward.
- **Predicate** — `sort <= :v AND (sort < :v OR (sort = :v AND id < :id))` for descending pages (mirrored for ascending). The leading bound lets PostgreSQL start the index scan (`idx_allocation_arrival_at_id`, `idx_allocation_age_id`) at the cursor row. Without it, the OR alone made PostgreSQL scan and discard every row before the cursor: on 2.3 million rows a deep page took about 80–180 ms warm and over a second cold, now well under 1 ms.
- **Previous** — paging back runs the same query in the opposite order and reverses the rows. When paging back reaches the start with fewer rows than a page (rows were deleted meanwhile), a normal first page is loaded instead. The first page has no previous link.
- **Limit + 1** — one extra row tells whether another page exists in that direction.
- **Count** — the footer shows the number of rows of the filtered list (`AllocationListResultCountQuery`, #1432), see [Result count](#result-count).

### Result count

The list counts its rows with `COUNT(*)` over the same Doctrine query as the page, so every filter parameter is bound like in the list itself (multi-value filters such as several hospitals included).

| Situation | Footer |
|---|---|
| Count finished | exact: "195.172 Ergebnisse auf 3.904 Seiten" |
| Count exceeded the time limit (500 ms), the first 10,001 rows could be counted | "Mehr als 10.000 Ergebnisse" (exact below 10,000) |
| Both failed | "Ergebnisse werden angezeigt" |

- **Time limit** — the count runs in a nested transaction with `statement_timeout` set locally and reset before release, so the limit never applies to later queries, also inside an outer transaction (tests). A failed or timed-out count is logged as a warning.
- **Cache** — `cache.allocation.list_result_count`, keyed by the count SQL, its parameters and a data version. Paging through the same filter does not count again. The data version sums the row writes (`n_tup_ins + n_tup_upd + n_tup_del`) of `allocation`, `hospital`, `import` and `closure_interval` from `pg_stat_user_tables` and, for the current transaction, `pg_stat_xact_user_tables`, so imports, deletions, generator runs and edits of these tables start a new count. Exact counts are kept up to 24 hours, lower bounds one minute (a cold cache can make a single count miss the limit).
- **Why not `EXPLAIN`** — until #1432 the footer showed the row estimate of `EXPLAIN`. On 2.3 million rows it was within 1–2 % for simple filters but 17 times too low for an indication filter, twice too high for hospital + urgency + year and 22 times too high for the closure filter; with several hospitals it failed silently. An exact `COUNT(*)` took 8–270 ms on the same data (4 cores, warm cache). A capped count does not help for large results: the `LIMIT` disables parallel workers, so counting up to one million rows was slower than counting all.

`CursorPaginator` implements the Symfony UX `CursorPaginationInterface`, so `DataTable` treats it like any UX cursor pagination. A full switch to the UX Doctrine cursor adapter was evaluated in [#651](https://github.com/nplhse/collaborative-ivena-statistics/issues/651) and kept back: it builds the same unbounded OR predicate, routes our entity-class joins through Doctrine's two-query paginator, has no estimated count and is still experimental.

## Columns

`AllocationListColumns` defines the columns and the preference schema `explore.allocations` (column picker, column order, page size; default 50 rows). Users can show gender, transport type, speciality, department, assignment, occasion and secondary transport in addition to the default columns. Timestamps are required; the row action stays fixed at the end.

Sorting stays on `arrivalAt` and `age`, the two keyset keys. Speciality, department, assignment and occasion need a join: `ListAllocationsQuery` reads their names for the ids of the current page only, after the keyset query. Joining them into the keyset query took 113 ms instead of 43 ms per page on the demo database; the page lookup adds about 5 ms. Filters, the filter reset and paging keep the chosen columns.

## Code locations

| Area | Path |
|---|---|
| Filter reference cache | `src/Allocation/Application/Explore/ExploreFilterOptionsProvider.php` (see [explore-filter-reference-cache.md](explore-filter-reference-cache.md)) |
| Scope resolution | `src/Allocation/Application/Allocations/AllocationListHospitalScopeResolver.php` |
| Filter criteria | `src/Allocation/Application/Allocations/AllocationListFilterCriteriaFactory.php` |
| Created-at range | `src/Allocation/Application/Allocations/AllocationCreatedAtRangeParser.php` |
| SQL filter | `src/Allocation/Application/Export/AllocationListFilterApplicator.php` |
| Closed at assignment | `src/Allocation/Application/Filter/ClosureAtAssignmentRule.php`, `src/Allocation/Application/Allocations/ClosureAtAssignmentResolver.php` |
| Keyset query | `src/Allocation/Infrastructure/Query/ListAllocationsQuery.php`, `src/Shared/Infrastructure/Pagination/` |
| Columns | `src/Allocation/UI/Twig/AllocationListColumns.php` |
| UI | Shared `FilterDrawer` / `FilterDrawerTrigger` Twig components (`src/Shared/UI/Twig/Components/`); allocation-specific fields in `src/Allocation/UI/Twig/templates/allocations/_allocation_filter_drawer.html.twig` |
