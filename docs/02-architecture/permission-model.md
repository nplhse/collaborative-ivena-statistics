# Permission model

The application uses Symfony roles for global access and hospital-scoped permission grants for participants.

Related: [decisions/002-hospital-permission-bitmask.md](decisions/002-hospital-permission-bitmask.md), [decisions/011-collaborative-explore-allocation-visibility.md](decisions/011-collaborative-explore-allocation-visibility.md)

## Global roles

Defined in `src/User/Domain/Security/UserRole.php`:

| Role | Purpose |
|------|---------|
| `ROLE_USER` | Base access; public statistics (`/statistics`) |
| `ROLE_PARTICIPANT` | Hospital participant; `/hospitals`, `/explore` (allocation list/detail) |
| `ROLE_ADMIN` | EasyAdmin back office, impersonation |
| `ROLE_REVIEW_INDICATIONS` | Indication raw review worklist |
| `ROLE_FEEDBACK_RECIPIENT` | Receives feedback admin notifications (with `ROLE_ADMIN`) |
| `ROLE_RECEIVES_NOTIFICATION` | General notification recipient |
| `ROLE_CLOSURE_BETA` | Opt-in beta switch for closure-list imports and Closure Analytics. Not part of the role hierarchy, so `ROLE_ADMIN` does not grant it. Assign it on the user in EasyAdmin. It is not a public role. |

`ROLE_ADMIN` inherits `ROLE_PARTICIPANT` and `ROLE_REVIEW_INDICATIONS` only. `ROLE_CLOSURE_BETA` stays explicit so production users and the first testers stay separate.

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

## Voters

| Voter | Attributes | Subject |
|-------|------------|---------|
| `HospitalVoter` | `ACCESS`, `EDIT`, `MANAGE_ACCESS_GRANTS` | `Hospital` |
| `AllocationVoter` | `VIEW` | `Allocation` |
| `ImportVoter` | `VIEW`, `DELETE`, `DOWNLOAD_SOURCE` | `Import` |
| `ExportVoter` | `EXPORT` | (none) |
| `IndicationRawReviewVoter` | `VIEW`, `EDIT_MATCH`, `REVIEW` | `IndicationRaw` |

`AllocationVoter::VIEW` requires `ROLE_PARTICIPANT` (not merely `ROLE_USER`) and applies to **any** allocation — it does **not** check `HospitalPermission::View`. Path `access_control` on `/explore` also requires `ROLE_PARTICIPANT`.

Use `$this->denyAccessUnlessGranted()` in controllers and `#[IsGranted]` attributes where appropriate.

## Explore collaboration

Explore is a **collaborative** overview: participants may list and open allocations from other hospitals. Hospital filters (including “My hospitals”) are optional UX, not an authz boundary. See [ADR 011](decisions/011-collaborative-explore-allocation-visibility.md).

Import, export, clinic management, and hospital-scoped statistics filters remain grant-based via `HospitalPermissionAccess`.

## Benchmarking vs. statistics

Benchmarking pages require `HospitalPermission::Benchmarking` (or admin/owner). Other statistics pages require `HospitalPermission::Statistics`.

See [../04-features/statistics/statistics-filter-and-scope.md](../04-features/statistics/statistics-filter-and-scope.md) for how permissions affect filter scopes.

Closure analytics (`/statistics/closure-analytics`) keeps that Statistics grant and
adds `ROLE_PARTICIPANT` on top of `ROLE_CLOSURE_BETA`. `ClosureAnalyticsHospitalScope`
forces every overview, timeline, event, detail, interval, group and export query
onto `accessibleHospitalIds()`. Administrators with the beta role may select across
hospitals. The restriction is not applied to other statistics pages, and closures
are not listed in the Analysis Explorer.
