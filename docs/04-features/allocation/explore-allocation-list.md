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

## Code locations

| Area | Path |
|---|---|
| Filter reference cache | `src/Allocation/Application/Explore/ExploreFilterOptionsProvider.php` (see [explore-filter-reference-cache.md](explore-filter-reference-cache.md)) |
| Scope resolution | `src/Allocation/Application/Allocations/AllocationListHospitalScopeResolver.php` |
| Filter criteria | `src/Allocation/Application/Allocations/AllocationListFilterCriteriaFactory.php` |
| SQL filter | `src/Allocation/Application/Export/AllocationListFilterApplicator.php` |
| UI | `src/Allocation/UI/Twig/templates/allocations/_allocation_filter_drawer.html.twig` |
