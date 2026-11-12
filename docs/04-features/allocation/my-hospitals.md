# My Hospitals

`GET /hospitals` (`app_hospitals_index`, `ListOwnedHospitalsController`) lists the hospitals a participant works
with. It requires `ROLE_PARTICIPANT`. Issue #971 turns the page into an overview of data coverage and contribution;
this document grows with it.

## Which hospitals are listed

`MyHospitalsScopeResolver` reads the hospitals and the user's permissions in one query
(`HospitalRepository::findOwnedOrGrantedForUser()`):

| User | Listed hospitals | Permissions on them |
|------|------------------|---------------------|
| Owner | hospitals they own | all |
| Grant holder | hospitals with a grant that includes `VIEW` | the grant mask |
| Administrator | only hospitals they own or were granted | all |

Administrators are deliberately not given every hospital here, unlike `HospitalPermissionAccess`, which treats
them as having access everywhere: a list of all hospitals is the back office's job. An administrator without own or
granted hospitals sees the empty state.

The permissions per hospital are carried in `MyHospitalsEntry::$permissionMask` (see
[permission model](../../02-architecture/permission-model.md)). The data-status parts of the overview (#971) use it
to show a hospital's information only with the matching permission. Edit and access-grant actions stay behind `HospitalVoter::EDIT`
and `HospitalVoter::MANAGE_ACCESS_GRANTS`, so grant holders see neither.

### Selection

With more than one listed hospital, the page header offers a choice (`_my_hospitals_selection.html.twig`): all
hospitals (the default) or a single one, as `?hospital=<public ID>`. `MyHospitalsSelection` looks the ID up among the
listed hospitals; an ID outside them (another user's hospital, a malformed value) is ignored and the page shows all
hospitals. The summary tiles, the cards and the data coverage follow the selection; the header names it (the toggle
shows the hospital's name, the subtitle "1 of 2 hospitals"). There is no selection of several but not all hospitals.
The statistics of a hospital are reached from its card.

## Data status

`MyHospitalsOverviewBuilder` builds the data status of the listed hospitals (`MyHospitalsOverview`, one
`HospitalDataStatus` per hospital). It does not change any statistics; the numbers are new and defined here.

### Sources and definitions

Only **allocation** imports count; closure imports are ignored. *Relevant* imports are those with status
Completed or Partial: only they contribute allocations and covered periods.

| Figure | Definition |
|--------|------------|
| Available allocations | rows in `allocation_stats_projection` of the hospital whose import is relevant, i.e. after deduplication, as the statistics see them. Not `rows_passed`, which counts overlapping files twice |
| Data period | first and last `created_at` of those rows (*latest data*), shown apart from the upload date |
| Relevant imports | allocation imports with status Completed or Partial |
| Failed, cancelled, pending | counted apart (Pending and Running are pending); they never add allocations or coverage |
| Latest upload | latest `import.created_at` of a relevant import |
| Own contribution | relevant imports the viewer uploaded (`created_by`) and their latest upload. No allocation count: deduplication moves rows between imports |

There is no "allocations per import" figure: with deduplication it would mislead.

### Permissions

Each part is shown only with the matching permission on the hospital (owners and administrators have all):

| Permission | Parts |
|------------|-------|
| `VIEW` | allocations, data period, hint *not updated* |
| `STATISTICS` | twelve-month view, coverage, possible gaps, hint *volume change* |
| `IMPORT` | import counts, latest upload, own contribution, hints on import problems |

Aggregates say on how many hospitals they rest: allocations and period over all listed hospitals, imports over
those with `IMPORT` (`OverviewImports::$hospitals`), months over those with `STATISTICS`
(`MyHospitalsOverview::$statisticsHospitals`). Without the permission on any hospital the part is missing (`null`).

### Monthly coverage

`MonthlyCoverageClassifier` classifies each calendar month `[1st, 1st of next month)` by naive IVENA time.
Coverage is the union of the covered periods of relevant imports: the export period (`export_starts_at` to
`export_ends_at`; a period ending exactly at midnight does not cover that day), or the first and last day with
records from `data_coverage` when no export period is stored. Overlapping imports count each day once.

| State | Rule |
|-------|------|
| `covered` | allocations, and imports cover every day |
| `partial` | allocations, but imports cover only some days, or no period is known |
| `no_allocations` | an import's period covers the month, but it has no allocations |
| `missing` | no allocations and no import covers it, between the periods of imports: a *possible* gap, an import may be missing |
| `outside` | no allocations and no import covers it, after the last import: no data delivered yet |
| `in_progress` | the current month, whatever its coverage; never compared |

A month without allocations never counts as covered, even when an import's period spans it: derived export periods
run from the first to the last row of a file and manual ones are maintained by hand, so neither proves a month
without cases. Only a month that no import covers, between covered periods, is a possible gap. Days without records
inside one import stay on the import detail page ([data-coverage.md](../import/data-coverage.md)).

The twelve-month view uses the window of `last_12_months` (`StatisticsPeriod::overviewPeriodStart()`): eleven
complete months and the current one. *Fully covered months* counts `covered` in it. Per month, the aggregate
(`OverviewMonth`) counts the allocations, how many hospitals delivered data (state other than `outside`) and how many
covered it fully; the summary's complete months need every delivering hospital to cover the month, so a total never
hides the gap of a single hospital.

### Hints

`DataStatusFindingRules` produces hints, never errors. Thresholds are constants of that class.

| Hint | Rule | Permission |
|------|------|------------|
| Import failed / cancelled | in the last 90 days and no relevant import uploaded later | `IMPORT` |
| Rejected rows | partial import with rejected rows, last 90 days | `IMPORT` |
| Days without records | relevant import whose `data_coverage` has gaps, last 90 days | `IMPORT` |
| Not updated | latest allocation more than 90 days before today | `VIEW` |
| Possible gap | consecutive `missing` months, whole history | `STATISTICS` |
| Volume change | a fully covered month in the twelve-month view differs by more than 50 % from the median of up to 12 earlier fully covered months; needs at least 6 of them | `STATISTICS` |

Partial, missing and current months are never compared, and a peak or a quiet month is not labelled an error.

### Page

The page (`owned_list.html.twig` with `_my_hospitals_summary.html.twig` and `_my_hospitals_card.html.twig`) shows:

- **At most four summary tiles**: available allocations (and from how many hospitals), data period, imports with
  their success rate (`IMPORT` on at least one hospital), and complete months of the last twelve months
  (`STATISTICS`). A tile resting on fewer hospitals than listed says so ("Based on 1 of 2 hospitals").
  - *Imports* counts finished imports: successful (completed or partial) plus failed and cancelled; pending ones
    are not finished. The *success rate* is successful / finished, rounded to whole percent.
  - *Complete months* counts the complete months of the window in which every hospital that delivered data
    covered every day.
- **One full-width card per hospital** (most users have one or two): master data badges (labelled from `md` up),
  *Owner* or *Shared with you*, and a data grid with data period, allocations, complete months (`STATISTICS`),
  imports as "successful of finished" with the success rate, partial and pending ones apart, latest upload and own
  uploads (`IMPORT`).
- **Quick actions** in the card footer: *View statistics* (`scope=hospital:ID`, `STATISTICS`, only with data),
  *View imports* (the hospital's import list) and *Upload new import* (`IMPORT`). **Administrative actions** (edit,
  manage access) sit in the card's menu and follow `HospitalVoter`. The hospital name opens its Explore page.
- **Data coverage** (`_my_hospitals_coverage.html.twig`, `STATISTICS`): in the right third next to the cards (from
  `lg` up, below them on smaller screens), a heatmap in the style of the import coverage calendar, one block per
  hospital with data, stacked, one row per year (January to December), one cell per month
  from the first month with data to the current month (`HospitalDataStatus::$history`). Cells use the import
  calendar's classes: four shades of the primary colour by volume relative to the hospital's busiest month
  (`covered`), hatched (`partial`), amber for a gap between imports (`missing`), white "no data" for months
  without allocations inside an import (`no_allocations`) or after the last import (`outside`), white with a dashed
  frame for the current month (`in_progress`); future months stay empty. A gap between two imports stands out more
  than months that simply have not been delivered yet. Each cell has a title and a screen-reader text with month,
  state and numbers. Peaks and quiet months are only shaded, never marked as problems. The heatmap replaces a
  separate volume chart: the volume trend is the shading, details are one click away in the statistics.
- **Empty states**: no hospital (the existing empty state), a hospital without allocations (upload as primary
  action; a hint when imports failed or are still running), no data in the summary period ("No data yet").

### Queries and cache

`HospitalDataSnapshotQuery` (Statistics, port `HospitalDataSnapshotReaderInterface`) reads all requested
hospitals in two queries: the allocation imports from `import`, and monthly counts from the projection
(index `idx_asp_hospital_created`, joined to `import` for the status). The result is a `HospitalDataSnapshot` per
hospital with raw data only.

`CachedHospitalDataSnapshotReader` keeps one snapshot per hospital in the pool
`cache.allocation.hospital_data_status` (24 hours) and reads all missing hospitals in one call. Permissions, the
viewer's own uploads, the current month, the window and the hints are derived per request, so an entry stays valid
across a month change and for every viewer. Clearing and warming the entries after an import follows with #971.

## Tests

- `tests/Allocation/Integration/Application/Hospital/MyHospitalsScopeResolverTest.php` (owner, grants, grant
  without `VIEW`, administrator with and without own hospitals, no hospitals)
- `tests/Allocation/Functional/Controller/Hospitals/ParticipantHospitalsControllerTest.php` (listing, actions, summary per permission)
- `tests/Allocation/Functional/Controller/Hospitals/MyHospitalsOverviewTest.php` (cards and summary per permission tier, basis, empty states, German page, coverage heatmap rows and states, selection of one or all hospitals, foreign and malformed IDs)
- `tests/Allocation/Unit/Application/Hospital/MyHospitals/` (month states, gaps, window, hints, cache, permissions and aggregates, selection)
- `tests/Statistics/Integration/Query/MyHospitals/HospitalDataSnapshotQueryTest.php` (import statuses, failed rows excluded, midnight rule, coverage fallback)
