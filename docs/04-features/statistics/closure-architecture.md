# Closure architecture

Imported closure rows, analysis intervals, and events are three different things.
`closure_interval` stays the import protocol. Analytics and the volume projection
read the derived tables.

## Three levels

IVENA stores both assignments on every closure row and on every allocation. There
is no parent foreign key.

| Level | Column | IVENA | Role in this view |
| --- | --- | --- | --- |
| Speciality | `speciality_id` | Fachgebiet, for example Innere Medizin | Alternate primary series via `volumeSeries=speciality` on the event course tab; ratio reference line when department is primary |
| Department | `department_id` | Fachbereich, for example Allgemeine Innere Medizin | Default primary volume series (closed departments) |
| Hospital | `hospital_id` | Krankenhaus | Context, not the primary series |

The **primary** closure volume read path uses `scope = department`: assignments
to the departments that were actually closed. The projection also stores a
`speciality` scope (all assignments in that speciality, including departments
not closed in the event). On the event course tab, department mode shows a dashed
speciality ratio in the lower course chart only; switching to speciality mode
reloads the whole volume block on the wider scope. Closing one department is not
shown as closing the whole speciality in the default charts. The detail view lists the departments
and care levels that were closed. Entity names elsewhere in the product stay as
they are; only this view names the three levels.

Strata `base`, `resus`, and `cathlab` stay separate series and are never added
together. Inside one level each allocation counts once, even when an event has
several members. Totals across events drop overlapping hour pieces of the same
population. An average aligned to the event start, when a chart shows one, is
labelled as such.

## Analysis intervals

`closure_analysis_interval` is one row per matching key: hospital, speciality,
department, exact start, exact end, reason, facility kind, closure unit, and
IVENA group id. Care levels are a set in `closure_analysis_care_level`. A
different time bound or a different feature stays its own interval. `other`
stays the value `other`. Only the volume evaluation maps it to SK1–SK3.

`closure_analysis_source` keeps every source row and every import. Remarks and
change timestamps stay on the source row. The same key from several imports is
analysed once. Only imports with status completed or partial feed the analysis.
A remaining source keeps the interval when another source is deleted.

## Events

`closure_event.id` is the stable identity. `grouping_key` is separate.

| Type | Key | Rule |
| --- | --- | --- |
| `source_group` | `sg:{hospital}:{group id}` | One event per hospital and non-empty IVENA group id. The key does not contain the times. Members may have different bounds. The detail view shows those bounds. A group is not merged into one interval. |
| `cluster` | `cl:{hospital}:{start}\|{end}` | At least two analysis intervals with no group id, the same hospital, and the exact same start and end. The department is not a split. Rule `exact_start_end_ungrouped_same_hospital`. |
| `single` | `si:{fingerprint}` | Exactly one analysis interval. |

Rebuilding the analysis keeps the id when `grouping_key` still exists. A volume
rebuild does not write `closure_event`. A disappeared key deletes the event. A
type change is a new key. Extensions and corrections with a moved end are not
merged. Shared start, overlap, or a shared group id is not enough to treat two
intervals as one clinical event.

Overlapping or half-open adjacent intervals of the same department that belong
to different events are marked in `closure_relation_candidate` (`overlap`,
`adjacent`). `export_boundary` is used only when both imports have an export
period and the intervals sit on those bounds.

IVENA files carry no export period, so `import.export_starts_at` and
`import.export_ends_at` are derived from the imported rows when an import
completes (`export_period_source = derived`, ADR 015):

- allocation imports cover whole days from the first to the last `created_at`
- closure imports cover whole days from the first start to the last end; they
  are widened to calendar months only when allocation imports of the same
  hospital cover those whole months
- an administrator can correct a period in the admin area
  (`export_period_source = manual`); derivation never overwrites it

The derivation is conservative: an under-estimated period only marks more data
as missing. `app:import:derive-export-periods` backfills existing imports. The
volume rebuild reads the union of allocation export periods as coverage, so a
covered hour without allocations counts as zero and an uncovered hour as
missing. While a hospital has a completed import without a derived period, the
rebuild falls back to "day with at least one allocation". Hospital-phase gaps
stay computable and are labelled as gaps inside that estimate.

## Phases and counting

Phases are a derived view (ADR 015). A population is hospital, scope,
speciality and department, without urgency. Every event gets rows for each
closed department (per speciality, because a department can close under two
specialities), the specialities of those departments, and the hospital. Every
population carries all five metrics: `emergency` (SK1), `inpatient` (SK2),
`outpatient` (SK3), `resus` and `cathlab`. Resource metrics count allocations
of any urgency and are never added to the SK total.

A closure binds a metric to a population:

- urgency metric: an interval whose care level opens that urgency (`other`
  opens SK1–SK3); overlapping and adjacent half-open intervals are united
- `resus` / `cathlab`: hours in which SK1, SK2 and SK3 are closed in the same
  department at the same time, possibly by several intervals
- speciality and hospital: where any of their departments binds the metric

`closure_relevance` is `closed` (department binds the metric, or every known
department of a speciality/hospital does), `partial` (some departments do;
`closed_department_count` keeps how many) or `none` (calculated for comparison
only, using the population's whole closure extent). Known departments are the
departments with allocations in the loaded window plus the closed ones; there
is no hospital–department table. Burden, profiles and the event figures count
only `closed` and `partial`.

The context window is 6 hours before the start and 6 hours after the end. Hour
pieces stay the grain and are clipped at the bounds. Detail windows are 1, 3,
and 6 hours.

The reference is symmetric: 4 weeks before the start
(`app.closure_volume.reference_weeks_before`) and 4 weeks after the end
(`app.closure_volume.reference_weeks_after`). The event extent, including gaps
between its segments, is never part of it; context hours are. Weekday/hour
needs at least 4 slots, then the day-time bucket, again at least 4 slots, with
qualities `reliable`, `limited`, `insufficient`, and `incomplete`. Zero
allocations, missing coverage, and an insufficient reference stay distinct.

Reference blocking is metric-specific. An urgency metric drops hours in which
the department has an interval opening that urgency. `resus` and `cathlab` drop
only hours in which SK1–SK3 are closed in the same department. A speciality
drops an hour for a metric when any of its departments blocks it; counts of
single departments are never subtracted. The hospital is not blocked.
`blocked_slot_count` keeps how many covered slots blocking removed from the
level that was used.

Influence applies only outside the closure itself: another event closes the
same metric in the same population (department, speciality or hospital).
Resource metrics count as closed only by an interval that opens SK1–SK3.

An event is `provisional` while allocation coverage (`awaiting_allocations`)
or, after that, closure coverage (`awaiting_closures`) does not reach
`event_end + 4 weeks`. Coverage that is not derived yet confirms nothing. The
flag and reason are stored on every row of the event and become final with the
next rebuild after the missing import.

The projection grain is event, scope, speciality, department, metric, and hour
piece. Null scope ids are stored as `0` so the unique index applies. Each partial interval counts allocations with
`created_at >= bucket_start AND created_at < bucket_end`. A later partial of
the same clock hour is not zeroed because an earlier partial was already seen.
A pre-aggregated full hour is used only when the piece is exactly that hour.
The detail allocation list uses the same half-open bound. Europe/Berlin wall
time and absolute seconds are unchanged, including daylight-saving days.

## Build, memory, and publish

Analysis is built per hospital in PostgreSQL. PHP does not materialise every
closure. The volume build walks one hospital in weekly partitions of event
start. It loads only the neighbours and assignment hours required for the
reference weeks before the first and after the last closure of the partition
and six hours of context. It writes only
events whose start lies in the partition. The same reference block is computed
once per series (population and metric), event extent, weekday, and day-time
bucket and reused for the six hours. That cache ends with the partition. The
hour pieces of an anchor are split once and shared by every anchor of the same
event with the same segments (the five metrics of a population usually have the
same ones); partial counts, reference groups and the planner use that one split.

The worker limit stays `--memory-limit=256M`. `memory_limit` is not raised.
Large scans are SQL aggregations or a cursor with `FETCH`. Rows are written with
`COPY` in batches of 2,000 (200-row `INSERT`s without the PostgreSQL driver) into
a build table without keys; its primary key and indexes are created after the
last row, before publish (or earlier, before a late claim deletes a hospital's
rows from it), so the unique grain index still rejects a duplicate.
There is no ORM unit of work on this path. Each partition logs runtime, current
memory, and peak. The retained PHP heap of a later week must
not grow with the earlier week.

Closure imports request an analysis rebuild. The handler then builds volume for
those hospitals. Allocation imports request volume only for that import's
hospital, after the allocation projection. A retry cleanup does not schedule a
second rebuild; a failure after that cleanup does. A successful completion
schedules one analysis. Deleting a source schedules analysis for that hospital;
deleting only allocations schedules volume.

Rebuilds are incremental (ADR 015 phase 4). A request carries the changed range
(`range_from`/`range_to`): an allocation import its export period (or the range
of its allocations), a closure import and deleted import data the import's
export period. Coverage changes and failed re-runs carry no range. An open
request that already covers the hospital is widened instead of queuing a second
one; a missing range wins. The analysis rebuild reports which events it created,
changed or removed (a signature over their intervals). `ClosureVolumeRebuildPlanner`
then selects, per hospital, the events whose load range
`[start − 4 weeks − 6 h, end + 4 weeks + 6 h)` intersects a changed range or the
old/new extent of a changed event, the created and changed events, and every
event that is still provisional. Only those events are rebuilt; publishing
replaces their rows and drops rows of removed events in one transaction. A
request without range, a request for all hospitals and late claims rebuild whole
hospitals. Results do not depend on which events are rebuilt together: an
event's known departments come from its own load range.
`ClosureVolumeIncrementalRebuildTest` compares every incremental case with a full
rebuild. On the production dump, a simulated monthly allocation import for
hospital 40 rebuilt 105 of 822 events in 7.4 s (full: 60 s), a monthly closure
import 207 events in 16.1 s, both identical to a full rebuild.

`closure_rebuild_request` stores the hospital, the kind (`analysis` or
`volume`) and the range. The handler claims open requests under the lock
`closure-volume-projection`. Requests for the same hospitals that arrive during
the run are claimed before publish and rebuilt in a second pass, which the
console progress shows; a console rebuild takes requests it already covers
before its first pass. The previous table stays readable until commit. A full
rebuild swaps the side table. A partial rebuild replaces only the affected
hospitals in one transaction. An error drops the build table.
A writer that deletes and writes closure data of many imports in a row (the
synthetic data generator) holds the same lock through `whileRebuildsPaused()`:
the worker waits instead of deadlocking with it on `closure_analysis_source`,
and requests made in the writing process stay open for its own rebuild
afterwards.
`app:statistics:rebuild-closure-volume-projection` rebuilds analysis and volume
for every hospital, or for the hospitals given with `--hospital`. There is no
projection version. `--reference-window=backward` uses all reference weeks
before the event; it exists for comparison runs only, and `job_run.metrics`
records the window of every run (`reference_window`: `4/4`, `8/0`).

The schema change of ADR 015 (`Version20261025103722`) recreates
`closure_volume_hour` empty and queues a full `volume` request. Either the
worker consumes it, or a console full rebuild takes it: a console rebuild
claims the open requests it covers (all of them for a full rebuild, only those
of the given hospitals with `--hospital`) before its first pass, so only
requests that arrive while it runs cause a second pass.

Each run records seconds and SQL statements per step; the command prints the
table and `job_run.metrics` keeps the statement count (`queries`). Context
hours look up influencing closures in an index per population and metric
(`ClosureVolumeNeighbourIndex`), reference slots are shared by every population
with the same event extent, and the build table is written with `COPY`.

Measured for #899 on the synthetic `realistic` preset with closures from
department-hour shares (#876; `FIXTURES_UNTIL=2026-09-30`, seed 740: 6,809 events,
5.1 million rows), Xdebug off: a full rebuild took 221 s before and 133 s after
splitting hours once per event and segments, keeping the reference cache for the
whole partition and indexing the build table after the load; `closure_volume_hour`
was byte-identical. Incremental runs on the same data: a one-month `volume`
request for one hospital (273 of its 667 events) took 19.8 s before and 11.4 s
after, a whole-hospital rebuild (`--hospital`) 44.2 s and 23.1 s; both left the
table byte-identical to a full rebuild. The cost follows the number of written rows (events ×
populations × metrics × hours), not the number of intervals as such. The
remaining time is mostly database work: loading and indexing the rows (about
35 s and 22 s) and the hourly counts per partition (about 16 s).

A request is queued only for hospitals with `closure_interval` or
`closure_event` rows, and only when no open, unclaimed request covers it: an
open `analysis` covers `volume`, and a request for all hospitals covers each
one. Claimed or failed requests never cover a new change.

A request moves through these states (all timestamps Europe/Berlin):

- **open:** not claimed.
- **claimed:** `claimed_at` and `claimed_by` (the run id) are set. Only the lock
  holder claims, and the semaphore lock ends with its process, so a request
  claimed by another run belongs to a run that died and is claimed again.
- **consumed:** `consumed_at` is set after the publish.
- **released:** a failed run clears the claim and stores `last_error`; the next
  run retries it. Messenger retries the message three times.
- **failed:** after three claims (failed or orphaned) `failed_at` parks the
  request. `app:statistics:closure-rebuild-requests` lists unconsumed requests
  with their range; `--retry-failed` re-queues failed ones with a fresh budget,
  `--run` processes the open ones now as the worker would.

An hourly scheduler message (minute 40) consumes requests nobody picked up, for
example after a killed console rebuild. It skips the run while another rebuild
holds the lock.

Each run is recorded in `job_run` (job `closure_rebuild`, ADR 018) and as Sentry transaction
`closure_rebuild`, with a summary log and threshold warnings on the
`closure_rebuild` channel. See
[observability-sentry.md](../../05-operations/observability-sentry.md#closure-rebuild).

Event pages use the numeric `closure_event.id`. The route parameter is still
named `eventKey` and accepts only digits.

## Clinic profiles

A profile is a hospital-bound pattern, read at request time. It does not write
`closure_event` and it does not rebuild analysis or volume.

There is no stored hospital structure. `Hospital` and `Department` have no
department or speciality catalogue. A speciality is represented at a hospital
only when it appears in `closure_analysis_interval` or in `allocation` for that
hospital. The profile lists departments with their provenance: closure,
assignment, or both. A global speciality list is not presented as the
hospital's organisation.

The hospital profile is
`/statistics/closure-analytics/profiles/hospital/{hospitalId}`. It contains every
closure of that hospital in the selected period.

The speciality profile is
`/statistics/closure-analytics/profiles/speciality/{hospitalId}/{specialityId}`.
An event counts once in each speciality it touches. Duration is the union of
that speciality's members. Figures across specialities are not additive. When
the overview row spans more than one hospital in the current scope, the same
page is `/statistics/closure-analytics/profiles/speciality/{specialityId}` and
stays inside that scope.

The department profile is
`/statistics/closure-analytics/profiles/department/{hospitalId}/{departmentId}`,
or without the hospital segment when the overview row spans several hospitals.
It counts closures of that department. It is not a stored department catalogue.

The closure-unit profile is
`/statistics/closure-analytics/profiles/unit/{hospitalId}?unit=…`. The label is
free text and stays hospital-local.

The constellation profile is
`/statistics/closure-analytics/profiles/group/{hospitalId}/{profileKey}`.
`sg:{hospital}:{IVENA group id}` stays an episode. The profile key ignores time
bounds. It is the MD5 of the sorted unique tokens

`speciality_id:department_id:care_level:reason`

taken from every analysis interval of the event. The same set across events is
the same profile. A different department, care level or reason is a different
profile. The profile heading and the event link name the specialities
and summarize the departments. A care level or reason appears only when the
whole configuration shares that one value. The composition tab lists every
member. `closure_unit` and `facility_kind` are not part of the key. An event
has one constellation profile and may also sit in several speciality profiles. The
event page still opens that constellation, including a one-off. The overview
does not list constellations.

The same period selection as the rest of closure statistics applies. Drilldowns
to the timeline and the event list carry `closureProfile` as
`hospital:{hospital}`, `speciality:{hospital}:{speciality}`,
`department:{hospital}:{department}`, `closure_unit:{hospital}:{unit}` or
`group:{hospital}:{md5}`. A hospital id of `0` means the current scope. The
list, the timeline and the profile KPIs use one predicate.

The course reads `closure_volume_hour` only. SQL returns one row per relative
hour and series. The start window is six hours before the actual `starts_at`
and the first six hours after it. The end window is the last six hours before
the actual `ends_at` and six hours after it. Inside one event the rate is
observed divided by evaluable hours; a partial piece enters by its overlap.
Missing values and quality `insufficient` or `incomplete` do not enter as zero.
Events are then equally weighted: median as the typical course, the
interquartile range as the spread, the mean only as a supplement. Below four
contributing events the profile shows an empty state instead of an average
line. A speciality series uses
`scope = speciality`, including departments that are not closed. A group has
one series per speciality. The combined series sums observed assignments across
specialities and divides by evaluable seconds once per event and slice. The
total volume uses the same population-hour deduplication as the burden view,
keeping the earlier reference cutoff. The equal-weighted average may reuse an
hour; the page says so when that happens. The difference is a deviation from
the reference. The outer event bounds are not a continuous closure of every
member.
