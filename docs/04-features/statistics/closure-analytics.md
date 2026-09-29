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
with the same `(hospital_id, source_group_id)`. A row without an id is its own
event.

Repeated exports may contain the same row. Analytics canonicalises exact natural
duplicates (hospital, department, speciality, start/end, care level, reason,
closure unit, and group id); the most recently changed/imported representation
wins. Similar but non-identical rows remain separate.

## Metrics

- **Individual closures** counts canonical rows overlapping the period.
- **Groups/events** counts hospital-local IVENA groups plus ungrouped rows.
- **Summed duration** adds the clipped duration of every individual closure and
  therefore counts parallel closures repeatedly.
- **Actual closure time** is the union of matching intervals. Five parallel
  two-hour closures are ten summed hours but two actual hours.
- **Exactly one / multiple** first unites all children of each hospital-local group
  and then partitions actual closure time by the number of active groups/events.
  An ungrouped row is one event.

Group and dimension durations are unions of their matching children. Duration
shares may overlap. The reason and urgency cards instead divide each category's
canonical individual-closure count by the total individual-closure count in the
selected analysis context. Because every closure has exactly one value in each of
these dimensions, each card's percentages sum to 100%.

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

One or more departments, specialities, care levels, and reasons can be selected
in the page's filter drawer independently of the shared statistics scope and
period controls. Hospital and My Hospitals scopes additionally offer local
`closure_unit` values. In My Hospitals scope, each value is qualified by its
hospital so identical local labels are not applied across hospitals. These
contextual filters apply to metrics, events, development, heatmap and timeline.
Children from the same group remain one concurrent event after filtering. Import
spans are derived from all rows in the hospital scope and are intentionally not
shortened to the selected dimensions.

The development chart and heatmap show absolute, united closure duration. Their
cells and buckets are not percentages and have no inferred open-time denominator.

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

## Deferred allocation matching

Allocations during closures and forced/emergency allocations require the SK-aware
join contract from issue #571. They are intentionally absent here. No value is
derived from `department_was_closed`, and the existing Notzuweisungen analysis is
not changed.
