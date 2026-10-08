# Closure Analytics

Closure Analytics evaluates imported `closure_interval` rows. It is separate from
[closed-department assignments](closed-department-assignments.md), which continues to
use the IVENA allocation snapshot `department_was_closed`.

Access to the navigation entry and every `/statistics/closure-analytics` endpoint
requires the explicit `ROLE_CLOSURE_BETA` opt-in and `ROLE_PARTICIPANT`.
`ROLE_ADMIN` does not grant the beta role implicitly, but an administrator who has
the beta role inherits `ROLE_PARTICIPANT` and may evaluate every hospital.

Hospital access is enforced in `ClosureAnalyticsHospitalScope` through the existing
`HospitalPermission::Statistics` grants (`HospitalAccessInterface::accessibleHospitalIds()`).
Every closure query — overview, indicators, charts, timeline, events, detail frame,
interval, group and CSV export — receives that id list and never a public scope.
Direct interval ids, numeric event ids, `closureHospitals[]`, and scope
parameters for state, cohort, dispatch area or a foreign hospital are checked on the
same list. A mixed selection keeps only the caller's hospitals. An empty selection
or a user without a statistics grant yields no rows and does not fall back to public
data. The period does not change the hospital list. Linked allocation jumps keep the
existing Explore permissions.

The analysis-context picker uses `AnalysisContextScopeMode::AssignedHospitals` only
when `ClosureAnalyticsController` asks for it. It is a single dropdown: participants
see “My hospitals” plus each granted hospital, administrators see “All hospitals”
plus every hospital. Other statistics pages keep the shared scope picker. Closure
analytics is not part of the Analysis Explorer.

## Data and event model

Definitions, the three levels, and the rebuild path are in
[closure-architecture.md](closure-architecture.md).

An imported row stays in `closure_interval`. Matching rows become one analysis
interval; their care levels are a set. Remarks stay on the source row. Only
completed and partial imports are analysed. A non-empty IVENA group id becomes
one `source_group` event per hospital. Two or more ungrouped intervals with the
same hospital and the exact same start and end become a `cluster`. Everything
else is a `single`. The event id is numeric and stays when the same grouping
key is rebuilt. A volume rebuild does not change it. Divergent times or
features stay separate intervals. `other` stays `other` until the volume
evaluation opens SK1–SK3.

## Metrics

- **Individual closures** counts canonical rows overlapping the period.
- **Groups/events** counts hospital-local IVENA groups, coincident ungrouped
  clusters and remaining individual events.
- **Summed duration** adds the clipped duration of every individual closure and
  therefore counts parallel closures repeatedly.
- **Actual closure time** is the union of matching intervals. Five parallel
  two-hour closures are ten summed hours but two actual hours.
- **Exactly one / multiple** first unites all children of each hospital-local
  group or analytical cluster and then partitions actual closure time by the
  number of active events. Children of the same cluster count as one event.

Group and dimension durations are unions of their matching children. Duration
shares may overlap. The reason and urgency cards instead divide each category's
canonical individual-closure count by the total individual-closure count in the
selected analysis context. Because every closure has exactly one value in each of
these dimensions, each card's percentages sum to 100%. The event-type card in the
overview right column divides groups, clusters and individual closures by the
distinct event count, always listing all three types (including zeros) so a
five-child group still counts as one group.

## Period and scope

Periods are half-open ranges `[from, toExclusive)`. An interval is included when
`starts_at < toExclusive AND ends_at > from`. Its duration is clipped to both the
selected period and, in the time series, each individual time bucket.

The source files do not declare their export period. For orientation only, the
application derives a span from the earliest start to the latest end per import
and unites overlapping spans. This span is not a reliable denominator: an export
containing one two-hour closure would otherwise appear as 100% closed. The UI
therefore shows absolute closure durations, explicitly marks the closure share as
unavailable, and never treats time outside the derived spans as open.

Hospital, My Hospitals and an explicit subset of the caller's hospitals are the only
scopes. State, hospital cohort, dispatch area and public scope are rewritten to that
hospital selection; they are not queried. Dispatch-area scope describes the origin
of allocations and is not a closure portfolio. With one granted hospital the URL is
`scope=hospital`. With several, `scope=my_hospitals` is exactly those hospitals, and
`closureHospitals[]` can narrow them. An empty `closureHospitalsSubmitted` selection
stays empty.

For multiple hospitals, times are calculated per hospital and then summed. The
result is cumulative **hospital closure time**, not wall-clock time in which any
hospital happened to be closed. The derived import spans follow the same
hospital-time aggregation but are shown only as orientation.

One or more hospitals, departments, specialities, care levels, reasons, and event
types (group, cluster, or individual closure) can be selected in the page's filter
drawer independently of the shared statistics scope and period controls. The
hospital filter is offered only when more than one hospital has closures in the
current scope and period. Optional from/to calendar dates further restrict the
selected period when filled; they stay empty by default so applying other
filters does not set a date range. The end date is inclusive. Hospital and My Hospitals scopes additionally offer local
`closure_unit` values. In My Hospitals scope, each value is qualified by its
hospital so identical local labels are not applied across hospitals. These
contextual filters apply to metrics, events, development, heatmap and timeline.
Children from the same group or cluster remain one concurrent event after
filtering. Import spans are derived from all rows in the hospital scope and are
intentionally not shortened to the selected dimensions.

The development chart and heatmap show absolute, united closure duration. Their
cells and buckets are not percentages and have no inferred open-time denominator.
For the rolling last-12-months period the development chart lists every month
from the period start through the current month, including months with no
closures at zero. Hours on that chart are whole numbers.
The timeline drilldown paints groups, clusters and individual closures in the
same blue/violet family as the event table. Coarser grid cells still only mark
closed versus concurrent time.

Hospital-local `closure_unit` labels are grouped by hospital id and label. Identical
labels from different hospitals are not merged. The overview lists them in a
paginated table with hospital, unit name, share, event count, and actual
duration (`Dauer`). The unit name wraps inside its cell and does not repeat the
hospital. Page sizes are 25, 50, and 100, defaulting to 25 rows sorted by
duration descending. The table uses `unitsSort`, `unitsOrder`, `unitsLimit`,
`unitsPage`, `unitsColumns`, and `unitsColumnOrder`, so its state stays
independent of the event table. Visible columns, their order, the page size,
and the sort chosen in the sort menu are stored per user under
`statistics.closure_analytics.units`. A link can still override that layout for
one request; saving the layout removes those parameters so the stored choice
applies again. The unit name stays visible. It opens the closure-unit profile
for that hospital and label. The profile keeps the scope, period, and the
remaining contextual filters, and its timeline action applies the same unit.

Stored timestamps are Europe/Berlin wall-clock values. Temporal analytics converts
them to an absolute timeline before calculating elapsed durations, so daylight
saving changes produce 23- or 25-hour days.

The overlap queries use the existing period and hospital-period indexes. A local
`EXPLAIN (ANALYZE, BUFFERS)` run of the all-time event sweep over 10,218 raw rows
completed in about 77 ms; no additional materialized view or index was justified.

## Event table

The Events tab uses the shared `DataTable` and keeps groups, analytical clusters
and individual closures in one list. The event column only shows the type badge.
Group and cluster rows move the compact child preview into the department
column. The flyover lists each contained closure with the department name as
the title, the Berlin time span as smaller muted text underneath with a clock
icon, and the urgency badge on its own last line. Care levels in the
table use the shared urgency labels (`Notfallversorgung`, `Stationäre
Versorgung`, `Ambulante Versorgung`). Event type badges stay in a blue/violet
family. Closure reasons use quiet gray badge fills, not just gray text. Local closure units stay
uncolored for now. Optional speciality,
urgency, reason and local closure-unit columns stack multiple values behind a
flyover instead of repeating every badge inline.

The table can be switched to an individual-closure view. That view paginates and
sorts canonical intervals directly and therefore shows one unambiguous
speciality, department, urgency, reason and local closure unit per row. Its event
badge still links each interval to its IVENA group or analytical cluster. The
The compact view switch lives in the table header and is transient: it preserves
scope, period and Closure filters. The buttons show short labels (`Cluster` /
`Single`) and keep the longer explanation on hover: grouped/clustered events
versus showing events individually.

The default event-table order is start, end, hospital, event, speciality,
departments, count, urgency, sum, duration, reason, and local closure units.
Start, event, and duration stay required; the other dimensions can be hidden
from the column picker. Short headers (`Count`, `Sum`, `Duration`, `Reason`)
are table-only; KPI and filter copy keep the longer names.

The table uses shareable GET state. `page`, `limit`, `sortBy`, `orderBy`,
`columns`, and `columnOrder` are carried alongside scope, period, and Closure
filters. Page sizes are 25, 50, and 100. The header uses compact outlined button
groups: the view switch with short labels, then sort plus columns, and a
separate whole-table reset, all in the same compact label style. The sort
flyover is a small form for column, direction, and page size.

Authenticated users can save visible columns, the complete left-to-right order,
and page size under the stable preference key
`statistics.closure_analytics.events`. Hidden columns keep their configured
position. Explicit presentation parameters in a shared URL override the stored
defaults for that request. Row sorting and all analysis state remain transient.
Reset deletes only this table preference and does not change the
selected scope, period, or Closure filters.

Sorting happens in PostgreSQL before pagination. Public sort keys are mapped to
fixed SQL expressions and the direction is restricted to `asc` or `desc`; invalid
values fall back to start time descending. Column keys are likewise validated
against the declared table configuration. No request value is interpolated as an
arbitrary SQL identifier.

The events table can export the current view as CSV (`/statistics/closure-analytics/export.csv`).
The file uses the same scope, period, Closure filters, event/interval view, sort
allowlist, and resolved visible columns (including left-to-right order) as the
HTML table. Pagination is ignored: the stream contains every matching row, not
only the current page. Cell values are semantic (translated labels, Berlin
datetimes, humanized durations), not HTML. Presentation preferences still apply
when the URL does not override columns.

## Event and interval detail

Group, cluster and individual-closure detail pages follow the Explore entity-show
layout: avatar and `h1`, a muted meta line, back to the events list, an 8/4 card
grid, and an Actions sidebar. The meta line links the hospital name to its
Explore profile, then shows the Europe/Berlin start and end, the actual event
duration, the event-type badge, and the numeric event id. The side card keeps
the affected specialities, departments and care levels, the member count, the
source-group id, and the summed duration only when it differs from the actual
duration. Count and both durations stay in the KPI card below the allocations
action.

The event page has two in-page tabs, `tab=overview` (default) and `tab=course`.
Overview shows the day timeline and the member table. Assignments that arrived
while a department was closed (any SK / urgency) are listed on the course tab in
the **Währenddessen** phase table, not in a separate sidebar card. The sidebar
action is still the date-wide `createdFrom` / `createdUntil` jump to Explore and
can use `my_hospitals`.

Course volume defaults to the **closed-department** projection
(`volumeSeries=department`, also the default). On the observed-vs-expected
assignments card, a scope switch reloads charts, KPIs, and windows for
`volumeSeries=speciality` (affected specialities of the event). While
department scope is active, the lower **course against the reference** chart
adds a dashed speciality ratio line only (no extra lines on the assignment
chart). Interval detail stays on department scope without that card switch. Optional
background bars for SK1–SK3, shock room and cath lab are toggled in the chart
area and do not change the main series. A compact breakdown table lists observed
assignment counts for all categories in the three ±6 hour windows. Below that,
a Turbo Frame (`stats-closure-event-assignments`) holds the phase bar, local list
filters, and the paginated table. Phase buttons use short labels (`Davor`,
`Währenddessen`, `Danach`); the exact half-open window is shown separately. List
filters use the query key `assignmentList` and reset pagination. The global
`volumeGroup` selector is removed from charts; main lines always use
`ClosureVolumeStratum::All`.

The course header switch `volumeSeries=department|speciality` drives the charts,
the sidebar observed-count table, and the assignment list together: **closed
departments** (`department`) vs **affected specialities** (`speciality`), with
the same semantics as the volume projection. Row counts are not required to
match the decimal **observed** cells in the volume window table, which come
from the hourly projection.

Assignment rows in the course table are enriched with context: whether the
department belongs to the event, and whether a matching analysis interval
(hospital, department, care level / SK rule, half-open `[start, end)`) was
active at `created_at`. Badges distinguish affected departments, other
departments in the speciality (speciality population only), and rows closed at
assignment time. The interval
detail list under the day timeline uses the same SK-aware overlap join on the
analysis interval id. Allocation detail links use `data-turbo-frame="_top"`.
Allocation detail links still require `ROLE_PARTICIPANT`.

Profile course charts mark closure relative to hour 0 (start- or end-aligned)
with a share of contributing events still in closure per hour bucket. Optional
background bars (SK1–SK3, shock room, cath lab) use the same toggles as event
volume charts. Profile pages expose `assignmentPhaseBreakdown`: per-event totals
and means across the profile’s events in the same ±6 hour windows and
speciality/department population as the course analysis. Summed totals may count
an allocation more than once when event windows overlap; the UI states that
explicitly.

The timeline uses the Europe/Berlin calendar day, midnight to midnight, so a
two-hour closure sits on a 24-hour axis. Closures that cross midnight are split
across consecutive days. The current event’s segments use a contour and a
stripe, the label “Aktuell”, and `aria-current`. Neighbouring context stays
faded. The track shares the 64rem minimum width of the day header. On the first
display the scroll position moves to that segment, after the sticky label; a
segment wider than the viewport is aligned to the start of the track. “Zum
Ereignis” repeats that positioning. Later updates do not move the scroll again.
Opening the course tab resizes the charts, because ApexCharts otherwise measures
a hidden pane as width 0.

Interval detail still lists allocations whose `created_at` falls inside that
interval’s department window.

Automated checks cover the hospital link, the side card without the event
window, both tabs, the half-open population query
(including a department that is not closed, excluding it from the closed
population, no duplicate when several members match, and a page of 10), and the
current-segment markup plus the jump button. The running page at
`https://127.0.0.1:8000` redirects this browser to `/login`, so scroll position,
chart width after the tab change, a narrow viewport, and dark mode were not
observed in the live UI.

## Clinic profiles

Hospital, speciality and department names in the three breakdown cards open the
matching profile, as does each local closure unit in the overview table. The
**Constellations** tab (last tab) lists non-redundant **constellations**
in a paginated data table with search (`profileQ`), sort, and column preferences.
Each row shows speciality and department facets (badge stacks like the events
list), SK and reason badges, a link to the constellation profile, and a composition
dropdown with every member combination. The event list is opened from the
profile detail, not from this table. A constellation row
is omitted when, in the selected period, every event
with that signature uses exactly one closure unit and every homogeneous event on
that unit carries only that signature (strict bidirectional match with the
closure-unit profile). Statistics stay on the unit profile; the constellation
route remains available by URL.

A row that belongs to one hospital opens that hospital's profile. A speciality or
department that spans several hospitals in the current scope opens the same
profile across that scope. The event page links the constellation only when it
is not redundant to the event's local closure unit; speciality links are unchanged.

Both profile pages use the event-detail header: hospital link, profile name,
and KPI cards. The composition is a second in-page tab, `tab=composition`,
rendered as a data table. Each row is one combination of speciality, department,
care level and reason, plus the department provenance. A department that appears
only in assignments is a row without care level or reason. The period picker and
the closure date filter are the same controls as the rest of closure statistics.
Access stays `ROLE_CLOSURE_BETA` and the hospital scope.

KPIs are the event count, how many of those events have at least one volume
piece of quality `reliable` or `limited` with positive evaluable seconds, the
equal-weighted duration (union per event, then median, interquartile range and
mean; under five events the individual values), care levels as “events that
contain this level”, and the weekday and start hour in the closure heatmap.

The data stock uses the same year heatmap as catalog coverage on a department
page. Each cell is one year and shows how many profile events overlap it.
Years inside the row without events stay empty. Years after the current year
are future cells. The period above the grid is the first and last closure of
the profile in the selected range.

The timeline and the event list receive `closureProfile` together with the
period. Event rows still open the event detail. The course charts read the
volume projection; opening a profile does not rebuild it. Below four
contributing events the course card shows an empty state that asks to widen
the period, choose another care level, or loosen the filters. The
whole-closure block still shows the count. When enough events contribute, it
shows the equal-weighted rate and, separately, the deduplicated total volume,
labelled as a deviation from the reference.

The key, the department provenance, the populations, the two time axes, the
equal event weight, the quality rule, and the limit that no hospital structure
is stored are described in
[closure-architecture.md](closure-architecture.md).

## Duration and time burden

A dedicated tab, `/statistics/closure-analytics/duration`, shows event durations,
department concurrency, phases and gaps. It reuses the same clipped
`valid_closures` and `observed_segments` as the rest of Closure Analytics,
loaded once, and the same analysis context and hospital scope. The overview
KPI row shows actual closure time, the deviation of observed and expected
assignments, and the share of expected hospital volume. The duration tab
counts distinct departments.

An event keeps its `event_key`. Its duration is the union of its clipped child
intervals, so department or reason joins do not multiply the count or the
duration. A department observation still drives concurrency: one union per
`(event_key, department)`. A speciality observation is one union per
`(event_key, speciality)`, so several departments of the same speciality do
not multiply that speciality’s duration. A reason observation is one union per
`(event_key, reason)`. An event with
several reasons appears once in each of those groups; the group counts must not
be added. A null reason is labelled “Ohne Angabe” / “No reason given”.
`not_specified` stays the explicit “k. A.” category.

Quartiles use the same linear interpolation as
`DescriptiveStatisticsCalculator` (Hyndman–Fan type 7, equivalent to
`percentile_cont`). Box whiskers follow the 1.5-IQR rule and stop at the
outermost values inside the fences; points outside the fences are outliers.
Fewer than five observations are drawn as individual values. The chart keeps
the ten specialities or reasons with the most events. Ties break by name, then
id. The selected rows are then ordered by median duration. Displayed durations
round exact epoch seconds to minutes with the existing formatter. Shares and
the 100% check use the unrounded seconds.

Phases are merged per hospital on the half-open timeline: the next interval
joins the phase when its start is less than or equal to the current end.
Hospitals are never merged. A pause is only the gap between two phases of the
same hospital, and only when that whole gap lies inside estimated import
coverage. Open time at the edges of the window counts toward “no department
closed” and is not a pause. With fewer than two phases in a hospital, that
hospital contributes no pause. If no pause remains, the UI says “Not
computable”. A department filter builds phases only from the selected
departments, still separately per hospital. The timeline drilldown does not
repeat these figures.

Evaluable time is the sum of `observed_segments`, already cut to the analysis
period and, in this section only, to the current Europe/Berlin wall clock.
Future time is not part of the denominator. Unobserved gaps between imports are
excluded rather than treated as open. Inside a span, absence of a matching
closure counts as no department closed. That span is still only the earliest
start and latest end per import.
Department and other context filters do not shorten the span; they choose which
closures count. Department concurrency is a sweep of distinct departments per
hospital. The three shares — none, exactly one, several — add up to the
evaluable hospital time. “Per 24 h” multiplies a share by 24 hours and is a
normalised average, not a claim about each calendar day.

## Expected assignment volume

The overview KPI row shows the deviation of observed and expected assignments
during closures, and that expectation as a share of the hospital's expected
volume. It does not replace the duration tab and it does not claim lost
patients, diversion or a causal effect. Deviation is observed minus expected.
A relative deviation is omitted when the expectation is zero or missing. The
separate volume block is not shown on the overview.

The primary series is the closed department (`scope = department` in
`closure_volume_hour`). The closed departments and care levels are listed in
the UI. On the event course tab, an optional speciality reference overlay uses
`scope = speciality` (broader than the closed units). The hospital series is
context. Care levels
emergency, inpatient and outpatient limit the population to SK 1, SK 2 and SK 3.
Care level `other` is stored as `other` and opens SK1–SK3 only in this
evaluation. Shock room (`requires_resus`) and cardiac catheter
(`requires_cathlab`) are separate views and must not be added to each other or
to the urgency views. No ABCD score and no conspicuous-score threshold exist in
the allocation model, so that view is not offered.

The reference for a closure ends at its start. It uses the previous 8 weeks
(`app.closure_volume.reference_weeks`) of the same weekday and clock hour, and
only hours that lie fully inside import coverage and outside known closures of
the same population. A partly closed reference hour is dropped. At least 4 such
hours are required (`app.closure_volume.minimum_reference_slots`). Otherwise the
same weekday and the existing day-time bucket (night, morning, afternoon,
evening) are used, again with at least 4 hours. Below that, the expectation is
missing rather than zero. Expectation for a span is the hourly rate times the
actual epoch seconds, so a twelve-hour closure is not half of an average day.
Durations use Europe/Berlin, including 23- and 25-hour daylight-saving days.

A Berlin calendar day counts as covered only when the hospital has at least one
row in `allocation_stats_projection` on that day. Days without any assignment
are left out of the reference and are not stored as zero observations. Inside a
covered day, zero assignments in the closed department are real zeros. A genuine
hospital-wide zero day cannot be distinguished from a missing import.

The context around an event is 6 hours before the start and 6 hours after the
end. Overview figures are clipped to the selected period and to now. A clock
hour that the period cuts is weighted by the overlap inside that hour. Detail
figures use the full closure and the cumulative 1, 3 and 6 hour windows before
and after it, even when they extend beyond the overview period; the page says
so. Follow-up windows are omitted while a closure is still running. Each partial
hour counts allocations with `created_at >= start AND created_at < end`. Windows
influenced by another closure of the same population, or windows that are
incomplete or not computable, stay visible and are excluded from the before/after
comparison. The chart uses consecutive hour pieces of the department series.
100 percent means the observation matches that series' own reference.

Overlapping hour pieces of the same population are united before summing. Event
totals are not the sum of child totals. Several hospitals are summed as
numerators and denominators; their percentages are not averaged.

`app:statistics:rebuild-closure-volume-projection` rebuilds the analysis and
then `closure_volume_hour`. A full rebuild swaps the side table. A hospital
rebuild replaces only that hospital in one transaction. The previous table stays
readable until commit. The projection stores no method version. Closure imports
schedule analysis asynchronously; the same run then builds volume for those
hospitals. An allocation import schedules volume only for its hospital, after
the allocation projection. Deleting closure sources schedules analysis; deleting
only allocations schedules volume. A retry cleanup does not schedule a second
rebuild. The worker memory limit stays 256 MB. The build walks one hospital in
weekly partitions and logs runtime and memory per partition.

### Volume read performance

Department scope can return more `closure_volume_hour` rows per event than
speciality scope (one grain per closed department). Overview burden aggregates
across all filtered events. Before release, compare Symfony profiler timings for
overview burden, event course, and interval detail against the previous
speciality read path; `EXPLAIN (ANALYZE)` on `draftsForEvent` and `affectedRows`
should use indexes on `(event_id, scope, …)`. The speciality reference on the
department course view runs a second `draftsForEvent` (speciality) only to build
the optional ratio reference line; `volumeSeries=speciality` replaces the primary
series instead of overlaying it.

## Deferred allocation matching

SK-aware joins for the course-tab assignment list on the detail page and Explore
filters for closure intervals still require the contract from issue #571. The
volume view above is separate from that list. No value is derived from
`department_was_closed`, and the dedicated Notzuweisungen statistics module is
not changed.
