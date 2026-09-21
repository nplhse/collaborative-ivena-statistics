# Explore MCI cases

Route: `/explore/mci_case` (`app_explore_mci_case_list`). Detail: `/explore/mci_case/{publicId}` (`app_explore_mci_case_show`).

Requires `ROLE_PARTICIPANT` (`access_control` on `/explore`). This list is already MCI-only: import rows with `manv` or `manv_id` are stored as `mci_case`, not as `allocation`.

## MCI ID

`mci_case.mci_id` keeps the source value (`manv_id`). It groups cases that belong to the same incident, including cases at different hospitals. It is not a unique key and is not treated as a global identity.

Lookup index: `idx_mci_case_mci_id` (non-unique). `allocation_stats_projection` has no MCI column; MCI cases stay outside statistics.

## Filters

Search stays in the page header (`search`, case-insensitive substring on `mci_id` or `mci_title`). The filter drawer offers catalog choices:

| Group | Query |
|---|---|
| Hospital | `hospital` |
| Geography | `state`, `dispatchArea` |
| Arrival | `arrivalFrom`, `arrivalUntil` (`Y-m-d`, inclusive) |
| Care | `urgency`, `transportType`, `speciality`, `department`, `departmentWasClosed` |
| Clinical | `indication` (normalized code), `occasion` (`none` or id), `infection` (`none`, `any`, or id), clinical flags including `isWithPhysician` |

`mciId` is an exact match set by the MCI-ID links. `importId` is set from the import detail page. Neither is a select in the drawer. Both stay active when other drawer filters are applied and show up as badges (the import badge uses the import name). Reset clears them together with the other filters.

Opening the list without these parameters shows every accessible MCI case.

**Collaborative by design** ([ADR 011](../../02-architecture/decisions/011-collaborative-explore-allocation-visibility.md)): a participant sees MCI cases for every hospital already in the dataset. The hospital select lists that catalog. The ID filter keeps that visibility model.
