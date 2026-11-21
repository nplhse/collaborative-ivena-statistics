# Permission model

The application uses Symfony roles for global access and hospital-scoped permission grants for participants.

Related: [decisions/002-hospital-permission-bitmask.md](decisions/002-hospital-permission-bitmask.md), [decisions/011-collaborative-explore-allocation-visibility.md](decisions/011-collaborative-explore-allocation-visibility.md)

## Global roles

Defined in `src/User/Domain/Security/UserRole.php`:

| Role | Purpose |
|------|---------|
| `ROLE_USER` | Base access; public statistics (`/statistics`) |
| `ROLE_PARTICIPANT` | Hospital participant; `/hospitals`, `/explore` (allocation list/detail) |
| `ROLE_ADMIN` | EasyAdmin back office, impersonation; receives the admin notifications (`admin.*`), each admin chooses them on the notification settings page |
| `ROLE_REVIEW_INDICATIONS` | Indication raw review worklist |
| `ROLE_ENCOUNTER_BETA` | Participation in the encounter data pilot: the encounter tools under `/tools` (review, validation, data preparation), the encounter analysis and the encounter grant pages. Inherited by `ROLE_ADMIN`. The role itself grants no case access. Assign it on the user in EasyAdmin. |
| `ROLE_ENCOUNTER_CATALOG_REVIEWER` | Review of the matcher's plausibility table (#1153, [ADR 033](decisions/033-learning-plausibility-table.md)) as the tool `/tools/encounter-plausibility` ([ADR 031](decisions/031-tools-section-and-tool-registry.md)): edit the curated problem areas, review snapshots, activate and retire them, run the learning job and read its reports (#1155). Needs `ROLE_ENCOUNTER_BETA` as well; grants no case access. Inherited by `ROLE_ADMIN`. Maintenance of the MTS catalog itself stays with `ROLE_ADMIN` ([ADR 032](decisions/032-mts-catalog-as-maintained-data.md)). |

`ROLE_ADMIN` inherits `ROLE_PARTICIPANT`, `ROLE_REVIEW_INDICATIONS`, `ROLE_ENCOUNTER_BETA` and `ROLE_ENCOUNTER_CATALOG_REVIEWER`. The former opt-in role `ROLE_CLOSURE_BETA` was removed when Closure Analytics and closure imports left the beta (#1350); a migration took it off all users.

## Hospital permissions (bitmask)

Defined in `src/Allocation/Domain/Enum/HospitalPermission.php`:

| Permission | Bit | Requires |
|------------|-----|----------|
| `VIEW` | 1 | — |
| `STATISTICS` | 2 | `VIEW` |
| `IMPORT` | 4 | `VIEW` |
| `EXPORT` | 8 | `VIEW` |
| `BENCHMARKING` | 16 | `VIEW` + `STATISTICS` |

Grants are stored in `HospitalAccessGrant` as an integer mask and validated via `HospitalPermissionMask`.

## Access resolution

`HospitalPermissionAccess` (`src/Allocation/Application/Service/HospitalPermissionAccess.php`) is the central resolver:

- **Admins** have all permissions on all hospitals.
- **Owners** have all permissions on their hospitals.
- **Granted users** have permissions according to their grant mask.

Exception: My Hospitals (`/hospitals`) lists only hospitals an administrator owns or was granted, not all of them. See [my-hospitals.md](../04-features/allocation/my-hospitals.md).

Participants get owners and grants through hospital assignment requests (#975): administrators approve a request as
an access grant or as ownership, and choose for a transfer whether the previous owner keeps full access as a grant.
The request adds no permission of its own. See [hospital-assignment-requests.md](../04-features/allocation/hospital-assignment-requests.md).

## Voters

| Voter | Attributes | Subject |
|-------|------------|---------|
| `HospitalVoter` | `ACCESS`, `EDIT`, `MANAGE_ACCESS_GRANTS` | `Hospital` |
| `AllocationVoter` | `VIEW` | `Allocation` |
| `ImportVoter` | `VIEW`, `DELETE`, `DOWNLOAD_SOURCE` | `Import` |
| `ImportCreateVoter` | `IMPORT_CREATE` | (none) or `Hospital` |
| `ExportVoter` | `EXPORT` | (none) |
| `IndicationRawReviewVoter` | `VIEW`, `EDIT_MATCH`, `REVIEW` | `IndicationRaw` |
| `EncounterVoter` | `EncounterAttribute::*` (see [Encounter grants](#encounter-grants)) | `Hospital` |

`AllocationVoter::VIEW` requires `ROLE_PARTICIPANT` (not merely `ROLE_USER`) and applies to **any** allocation — it does **not** check `HospitalPermission::View`. Path `access_control` on `/explore` also requires `ROLE_PARTICIPANT`.

`ImportCreateVoter::IMPORT_CREATE` (#1351) asks `ImportUploadTargets`, the one place that says where a user may upload which import type: allocation and closure lists to hospitals with `HospitalPermission::Import` (owners and admins included), types of other contexts to the hospitals of their `import.type_extension` (for example the encounter grant `UPLOAD`), all only with `ROLE_PARTICIPANT`. Without a subject it grants when the user may upload at least one type to at least one hospital; with a `Hospital` it grants when they may upload at least one type there. It adds no rights of its own. `/import/new` redirects to the import list with a notice when it is denied, and on submit the chosen type and hospital must match.

Use `$this->denyAccessUnlessGranted()` in controllers and `#[IsGranted]` attributes where appropriate.

`MANAGE_ACCESS_GRANTS` also decides who sees the **Access** card on the hospital detail page (`/explore/hospital/{publicId}`, #1240): stacked avatars of the users with a grant (at most five plus "+N"), their count and a link to the access matrix. Other participants do not see who has access.

## Explore collaboration

Explore is a **collaborative** overview: participants may list and open allocations from other hospitals. Hospital filters (including “My hospitals”) are optional UX, not an authz boundary. See [ADR 011](decisions/011-collaborative-explore-allocation-visibility.md).

Import, export, clinic management, and hospital-scoped statistics filters remain grant-based via `HospitalPermissionAccess`.

Opening an Explore detail page by the internal numeric ID (`/explore/{type}/{id}`, #1354) is for `ROLE_ADMIN` only. Everyone else gets a 404, whether the ID exists or not, so the numeric IDs reveal nothing. The redirect target then applies its own access checks. The ID itself (`<twig:AdminObjectId>`) is shown to administrators only. Usernames of digits only are not allowed (`UserUsernameConstraints::DIGITS_ONLY_PATTERN`), because `/explore/user/{id}` shares the path with the profile.

## Encounter grants

Decided in [ADR 028](decisions/028-encounter-access-grants.md), implemented in #1046.

Encounter data (hospital ED visits) does not follow the collaborative Explore rule and is not covered
by the hospital bitmask:

- `ROLE_ENCOUNTER_BETA` controls the pilot pages; `ROLE_ADMIN` inherits it. The role itself grants no case access.
- A separate encounter grant per user and hospital with the actions `UPLOAD`, `REVIEW` (implies
  `VIEW_CASES`) and `VIEW_CASES`, checked by `EncounterVoter`. `UPLOAD` covers imports (including
  reprocessing and deleting them) and access to the retained upload files; it does not include the key change; only the hospital owner or an administrator may change the encounter key ([ADR 027](decisions/027-encounter-pseudonym-key.md)).
- Hospital ownership and hospital grants do **not** imply an encounter grant. This is a deliberate
  exception to `HospitalPermissionAccess`.
- `ROLE_ADMIN` counts as holding every grant action on every hospital without a stored grant. This
  includes the key change. An administrator impersonating a user has that user's rights
  only.
- Owners and administrators manage encounter grants; only users with access to the hospital can
  receive one. Changes are audited.
- Case-level requests check the grant on the hospital of the requested visit or allocation.
- The matcher's plausibility table ([ADR 033](decisions/033-learning-plausibility-table.md)) is learned from
  all hospitals, but no cross-hospital case counts are shown. `ROLE_ENCOUNTER_CATALOG_REVIEWER` (with the
  beta role) sees per cell only points, source and the change against the active snapshot; replay counts and
  rates in a snapshot report cover only hospitals on which the viewer holds the grant action `REVIEW`
  (`ROLE_ADMIN`: all, plus the sum over all hospitals); the number of eligible pairs over all hospitals is never
  shown to a reviewer. The reviewer role gives no encounter grant action. Its tool pages under
  `/tools/encounter-plausibility` (`src/Encounter/UI/Http/Controller/Plausibility/`) check both roles in the
  controllers, since `^/tools` in `access_control` only requires `ROLE_USER`; the tool entry is listed only with both.

Implementation:

- `EncounterAccessGrant` (`src/Encounter/Domain/Entity/`, table `encounter_access_grant`): one row per
  user and hospital with the flags `can_upload`, `can_review`, `can_view_cases`. Check constraints keep
  at least one action and `REVIEW` ⇒ `VIEW_CASES`. Deleting the hospital or the user deletes the grant.
- `EncounterVoter` votes on the attributes in `App\Encounter\Application\Security\EncounterAttribute`
  with the `Hospital` as subject. Every attribute needs `ROLE_ENCOUNTER_BETA` first. "Grant action"
  includes the implicit actions of `ROLE_ADMIN`:

  | Attribute | Granted when |
  |---|---|
  | `ENCOUNTER_UPLOAD`, `ENCOUNTER_VIEW_SOURCE_FILE`, `ENCOUNTER_DOWNLOAD_SOURCE_FILE`, `ENCOUNTER_REPROCESS_SOURCE_FILE`, `ENCOUNTER_DELETE_IMPORT` | grant action `UPLOAD` |
  | `ENCOUNTER_REVIEW` | grant action `REVIEW` |
  | `ENCOUNTER_VIEW_CASES` | grant action `VIEW_CASES` (also set by `REVIEW`) |
  | `ENCOUNTER_VIEW_AGGREGATES` | any grant action and hospital permission `STATISTICS` |
  | `ENCOUNTER_CHANGE_KEY` | hospital owner or `ROLE_ADMIN`; no grant action |
  | `ENCOUNTER_MANAGE_GRANTS` | hospital owner or `ROLE_ADMIN` |

- Grant management: `/hospitals/{id}/edit/encounter-access` (list, add, edit, remove), linked in the
  sidebar of the hospital edit pages. Only the owner and users with a hospital access grant appear as
  candidates; the server rejects anyone else. Administrators need no stored grant. Changes run under the audit intents
  `encounter.access_grant.created`, `.updated` and `.deleted`.
- Losing hospital access removes the encounter grant: a Doctrine `onFlush` listener in `Encounter`
  (`RevokeEncounterGrantsOnHospitalAccessLoss`) reacts when a hospital access grant is deleted or moved
  and when a hospital gets another owner, and removes the encounter grant in the same flush unless the
  user is still owner or still holds a hospital access grant. The deletion is audited under the intent of
  the change that caused it (for example `hospital.access_grant.deleted`). Allocation does not know the
  listener.

## Benchmarking vs. statistics

Benchmarking pages require `HospitalPermission::Benchmarking` (or admin/owner). Other statistics pages require `HospitalPermission::Statistics`.

Benchmarking also unlocks the reference hospital comparison in the Analysis Explorer (#1346): only hospitals with Benchmarking for the viewer (admins: any) can be references, checked per viewer on every request, for saved and public views and the CSV export (`ExplorerReferenceComparisonPolicy`). See [analysis-explorer.md](../04-features/statistics/analysis-explorer.md#reference-hospital-comparison-1346).

See [../04-features/statistics/statistics-filter-and-scope.md](../04-features/statistics/statistics-filter-and-scope.md) for how permissions affect filter scopes.

Closure analytics (`/statistics/closure-analytics`) keeps that Statistics grant and
requires `ROLE_PARTICIPANT` (`access_control` and the controllers), like the import pages; it needs
no beta role since #1350. `ClosureAnalyticsHospitalScope`
forces every overview, timeline, event, detail, interval, group and export query
onto `accessibleHospitalIds()`. Administrators may select across
hospitals. The restriction is not applied to other statistics pages, and closures
are not listed in the Analysis Explorer.
