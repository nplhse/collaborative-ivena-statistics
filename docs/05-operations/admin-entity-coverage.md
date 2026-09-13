# Admin entity coverage

Matrix of domain entities exposed in the EasyAdmin backend (`/admin`). Use this checklist when adding entities or fields.

Legend: **Full** = create/edit/delete; **Read** = index/detail only; **—** = not exposed (by design or pending).

## Core entities

| Entity | CRUD controller | Mode | Notes |
|--------|-----------------|------|-------|
| User | `UserCrudController` | Full | locale, reminder preference, owned hospitals; [conditional delete](#user-accounts) |
| Hospital | `HospitalCrudController` | Full | coordinates, access grants on detail |
| HospitalAccessGrant | `HospitalAccessGrantCrudController` | Full | permission mask UI |
| Allocation | `AllocationCrudController` | Full | secondary transport/indications, notes |
| Import | `ImportCrudController` | Full | file metadata on detail |
| ImportReject | `ImportRejectCrudController` | Read | |
| ImportBatchRun | `ImportBatchRunCrudController` | Read | |
| ImportBatchRunItem | `ImportBatchRunItemCrudController` | Read | |
| MonthlyReminderDispatch | `MonthlyReminderDispatchCrudController` | Read | scheduler sends only |
| SavedExplorerView | `SavedExplorerViewCrudController` | Read | |
| UserOnboardingStep | `UserOnboardingStepCrudController` | Read | |

## Reference data (full CRUD)

Allocation, Assignment, Department, DispatchArea, IndicationNormalized, IndicationGroup, IndicationRaw (read-only forms), Infection, MciCase, Occasion, SecondaryTransport, Speciality, State.

## Content (full CRUD)

Post, PostCategory, PostTag, PostComment (read-only create), Page, Media.

## System (read-only)

AuditEntry, CookieConsent, Feedback (no manual create).

## Intentionally not in admin

| Entity | Reason |
|--------|--------|
| Address | Embeddable on Hospital |
| KpiDaily | Aggregated via dashboard service |
| ResetPasswordRequest | Security tokens |
| SavedExplorerViewFavorite | Low operational value |

## Operational views (non-CRUD)

| View | Controller | Purpose |
|------|------------|---------|
| Dashboard ops panel | `DashboardController` | Messenger, health, storage |
| Failed messages | `DashboardController` (`operations_failed_messages`) | Inspect `messenger_messages` failed queue |
| Usage analytics | `DashboardController` (`operations_usage_analytics_*`) | Overview, adoption, journeys, filters, performance |

## User accounts

Take an account out of use with **disable** (`isEnabled`) unless it is unused and has no blocking references.

**Delete** is offered on index, detail, and edit when the user does not own hospitals and is not the signed-in admin. Batch delete stays disabled.

Do **not** cascade-remove hospitals or allocations. Reassign (or clear) `Hospital.owner` in Hospital CRUD first. Users are also referenced from many non-nullable `createdBy` columns; a delete that still hits a foreign key flashes an error instead of a 500. Disable remains available in every case.
