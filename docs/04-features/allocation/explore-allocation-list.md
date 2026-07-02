# Explore allocation list

Route: `/explore/allocation` (`app_explore_allocation_list`)

Cursor-paginated list of allocation records with a filter drawer (hospital attributes, geography, clinical flags, and more).

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

## Code locations

| Area | Path |
|---|---|
| Scope resolution | `src/Allocation/Application/Allocations/AllocationListHospitalScopeResolver.php` |
| Filter criteria | `src/Allocation/Application/Allocations/AllocationListFilterCriteriaFactory.php` |
| SQL filter | `src/Allocation/Application/Export/AllocationListFilterApplicator.php` |
| UI | `src/Allocation/UI/Twig/templates/allocations/_allocation_filter_drawer.html.twig` |
