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
Direct interval ids, group and cluster event keys, `closureHospitals[]`, and scope
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

An imported row is one individual closure for one department and one care level.
`source_group_id` is an optional IVENA action id, not a separate entity and not an
interval of its own. Analytics therefore derives a group from all canonical rows
with the same `(hospital_id, source_group_id)`. Two or more ungrouped canonical
rows from the same hospital with exactly the same original start and end form an
analytical cluster. Other ungrouped rows remain individual events. Cluster
identity is assigned before period clipping and contextual filters, so a filtered
subset keeps the same stable event key.

Repeated exports may contain the same row. Analytics canonicalises exact natural
duplicates (hospital, department, speciality, start/end, care level, reason,
closure unit, and group id); the most recently changed/imported representation
wins. Similar but non-identical rows remain separate.

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
applies again. The unit name stays visible. In Hospital and My Hospitals scope,
it links to the timeline with that hospital-local unit, scope, period, and the
remaining contextual filters preserved.

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
layout: avatar and `h1`, muted context, back to the events list, an 8/4 card
grid, and an Actions sidebar. Count and duration sit in one card in the right
column below the allocations action. The timeline always uses the associated
Europe/Berlin calendar day, midnight to midnight, so a two-hour closure sits on
a 24-hour axis. Closures that cross midnight are split across consecutive days,
matching the Timeline tab. Other groups, clusters and individual closures of the
same departments on those calendar days appear on the same tracks as quieter,
type-coloured bars and link to their event detail.

The primary action opens `/explore/allocation` for the matching calendar day or
days (`createdFrom` / `createdUntil`) and the currently selected hospital
filter. A single selected hospital, Hospital scope, or the event's own hospital
becomes `hospitalFilter`. My Hospitals without a hospital selection uses
`my_hospitals`. That link is a date-and-hospital jump into the allocation list.

Below the interval list, the same detail pages list allocations whose
`created_at` (Europe/Berlin wall clock, same as Explore) falls inside a child
interval's displayed, period-clipped window (`starts_at <= created_at <= ends_at`)
and whose `department_id` matches that child. Groups and clusters therefore match
per department window, not against the overall event span. The list is compact
(time, department, indication, urgency) and links to Explore allocation show
when the user has `ROLE_PARTICIPANT`. An empty state is always shown when nothing
matches. `department_was_closed` and SK-aware urgency matching are not applied.

## Duration and time burden

A dedicated tab, `/statistics/closure-analytics/duration`, shows event durations,
department concurrency, phases and gaps. It reuses the same clipped
`valid_closures` and `observed_segments` as the rest of Closure Analytics,
loaded once, and the same analysis context and hospital scope. The existing
overview KPI is unchanged: that bar still counts concurrent groups and
clusters, while the duration tab counts distinct departments.

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

## Deferred allocation matching

SK-aware joins, Notzuweisungen / emergency-assignment interpretation, aggregate
overlap KPIs, and Explore filters for closure intervals still require the
contract from issue #571. They are intentionally absent here. No value is
derived from `department_was_closed`, and the existing Notzuweisungen analysis is
not changed.
