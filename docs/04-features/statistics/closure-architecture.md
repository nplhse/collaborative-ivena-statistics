# Closure architecture

Imported closure rows, analysis intervals, and events are three different things.
`closure_interval` stays the import protocol. Analytics and the volume projection
read the derived tables. The October 2026 investigation that led here is
[closure-architecture-assessment.md](closure-architecture-assessment.md).

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
`adjacent`). `export_boundary` is used only when both imports have a known
export period and the intervals sit on those bounds. `import.export_starts_at`
and `import.export_ends_at` stay null unless the period is actually known. The
span from the earliest start to the latest end remains an estimate, not proof
of coverage. Without a known export period a pause is unknown coverage, not a
confirmed gap. Hospital-phase gaps stay computable and are labelled as gaps
inside that estimate.

## Phases and counting

Phases are a derived view. Per population, overlapping and immediately adjacent
half-open intervals `[start, end)` are united:

- department and care level
- speciality and care level, meaning at least one child department was closed
- the existing hospital phases, named separately

The context window is 6 hours before the start and 6 hours after the end. Hour
pieces stay the grain and are clipped at the bounds. Detail windows are 1, 3,
and 6 hours. The reference is still 8 weeks and at least 4 slots, with qualities
`reliable`, `limited`, `insufficient`, and `incomplete`. Zero allocations,
missing coverage, and an insufficient reference stay distinct.

Influence applies only outside the closure itself:

- department: another report of the same department and care level
- speciality: another report of the same speciality and care level, including another department
- hospital: another report of the same hospital and care level

The projection grain is event, level, speciality, department, care level,
stratum, and hour piece. Null scope ids are stored as `0` so the unique index
applies. Each partial interval counts allocations with
`created_at >= bucket_start AND created_at < bucket_end`. A later partial of
the same clock hour is not zeroed because an earlier partial was already seen.
A pre-aggregated full hour is used only when the piece is exactly that hour.
The detail allocation list uses the same half-open bound. Europe/Berlin wall
time and absolute seconds are unchanged, including daylight-saving days.

## Build, memory, and publish

Analysis is built per hospital in PostgreSQL. PHP does not materialise every
closure. The volume build walks one hospital in weekly partitions of event
start. It loads only the neighbours and assignment hours required for eight
reference weeks, spanning closures, and six hours of context. It writes only
events whose start lies in the partition. The same reference block is computed
once per population, cutoff, weekday, and day-time bucket and reused for the
six hours. That cache ends with the partition.

The worker limit stays `--memory-limit=256M`. `memory_limit` is not raised.
Large scans are SQL aggregations or a cursor with `FETCH`. Inserts flush about
200 rows. There is no ORM unit of work on this path. Each partition logs
runtime, current memory, and peak. The retained PHP heap of a later week must
not grow with the earlier week.

Closure imports request an analysis rebuild. The handler then builds volume for
those hospitals. Allocation imports request volume only for that import's
hospital, after the allocation projection. A retry cleanup does not schedule a
second rebuild; a failure after that cleanup does. A successful completion
schedules one analysis. Deleting a source schedules analysis for that hospital;
deleting only allocations schedules volume.

`closure_rebuild_request` stores the hospital and the kind (`analysis` or
`volume`). The handler takes open requests under the lock
`closure-volume-projection`. Requests for the same hospitals that arrive during
the run are included before publish. The previous table stays readable until
commit. A full rebuild swaps the side table. A partial rebuild replaces only
the affected hospitals in one transaction. An error drops the build table.
`app:statistics:rebuild-closure-volume-projection` rebuilds analysis and volume
for every hospital. There is no projection version.

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
