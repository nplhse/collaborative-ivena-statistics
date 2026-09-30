# Closure Analytics

Closure Analytics evaluates imported `closure_interval` rows. It is separate from
[closed-department assignments](closed-department-assignments.md), which continues to
use the IVENA allocation snapshot `department_was_closed`.

Access to the navigation entry and every `/statistics/closure-analytics` endpoint
requires the explicit `ROLE_CLOSURE_BETA` opt-in. `ROLE_ADMIN` does not grant this
role implicitly.

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

Hospital, My Hospitals, State, hospital cohort, and public scope use the shared
Statistics scope contract. Dispatch-area scope describes the origin of allocations;
closures have no allocation origin, so Closure Analytics redirects that scope to
public instead of assigning a misleading hospital portfolio.

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
The timeline drilldown paints groups, clusters and individual closures in the
same blue/violet family as the event table. Coarser grid cells still only mark
closed versus concurrent time.

Hospital-local `closure_unit` labels are grouped by hospital id and label. Identical
labels from different hospitals are not merged. In Hospital and My Hospitals
scope, each unit in the overview links to the timeline with that hospital-local
unit, scope, period, and the remaining contextual filters preserved.

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
column, with a stacked flyover of the contained closures. Care levels in the
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
`my_hospitals`. This is a date-and-hospital jump into the allocation list, not
an SK-aware join of allocations *during* the closure.

## Deferred allocation matching

Allocations during closures and forced/emergency allocations require the SK-aware
join contract from issue #571. They are intentionally absent here. No value is
derived from `department_was_closed`, and the existing Notzuweisungen analysis is
not changed.
