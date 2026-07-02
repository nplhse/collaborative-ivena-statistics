# Admin entity coverage

Matrix of domain entities exposed in the EasyAdmin backend (`/admin`). Use this checklist when adding entities or fields.

Legend: **Full** = create/edit/delete; **Read** = index/detail only; **—** = not exposed (by design or pending).

## Core entities

| Entity | CRUD controller | Mode | Notes |
|--------|-----------------|------|-------|
| User | `UserCrudController` | Full | locale, reminder preference, owned hospitals |
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
