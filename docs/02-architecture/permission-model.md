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
| `ROLE_CLOSURE_BETA` | Opt-in beta switch for closure-list imports and Closure Analytics. Not part of the role hierarchy, so `ROLE_ADMIN` does not grant it. Assign it on the user in EasyAdmin. It is not a public role. |
| `ROLE_ENCOUNTER_BETA` | Participation in the encounter data pilot: the encounter tools under `/tools` (review, validation, data preparation), the encounter analysis and the encounter grant pages. Inherited by `ROLE_ADMIN`. The role itself grants no case access. Assign it on the user in EasyAdmin. |

`ROLE_ADMIN` inherits `ROLE_PARTICIPANT`, `ROLE_REVIEW_INDICATIONS` and `ROLE_ENCOUNTER_BETA` only. `ROLE_CLOSURE_BETA` stays explicit so production users and the first testers stay separate.

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

## Voters

| Voter | Attributes | Subject |
|-------|------------|---------|
| `HospitalVoter` | `ACCESS`, `EDIT`, `MANAGE_ACCESS_GRANTS` | `Hospital` |
| `AllocationVoter` | `VIEW` | `Allocation` |
| `ImportVoter` | `VIEW`, `DELETE`, `DOWNLOAD_SOURCE` | `Import` |
| `ExportVoter` | `EXPORT` | (none) |
| `IndicationRawReviewVoter` | `VIEW`, `EDIT_MATCH`, `REVIEW` | `IndicationRaw` |
| `EncounterVoter` | `EncounterAttribute::*` (see [Encounter grants](#encounter-grants)) | `Hospital` |

`AllocationVoter::VIEW` requires `ROLE_PARTICIPANT` (not merely `ROLE_USER`) and applies to **any** allocation — it does **not** check `HospitalPermission::View`. Path `access_control` on `/explore` also requires `ROLE_PARTICIPANT`.

Use `$this->denyAccessUnlessGranted()` in controllers and `#[IsGranted]` attributes where appropriate.

## Explore collaboration

Explore is a **collaborative** overview: participants may list and open allocations from other hospitals. Hospital filters (including “My hospitals”) are optional UX, not an authz boundary. See [ADR 011](decisions/011-collaborative-explore-allocation-visibility.md).

Import, export, clinic management, and hospital-scoped statistics filters remain grant-based via `HospitalPermissionAccess`.

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

See [../04-features/statistics/statistics-filter-and-scope.md](../04-features/statistics/statistics-filter-and-scope.md) for how permissions affect filter scopes.

Closure analytics (`/statistics/closure-analytics`) keeps that Statistics grant and
adds `ROLE_PARTICIPANT` on top of `ROLE_CLOSURE_BETA`. `ClosureAnalyticsHospitalScope`
forces every overview, timeline, event, detail, interval, group and export query
onto `accessibleHospitalIds()`. Administrators with the beta role may select across
hospitals. The restriction is not applied to other statistics pages, and closures
are not listed in the Analysis Explorer.
