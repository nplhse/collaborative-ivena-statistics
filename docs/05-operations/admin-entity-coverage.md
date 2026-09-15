# Admin entity coverage

Matrix of domain entities exposed in the EasyAdmin backend (`/admin`). Use this checklist when adding entities or fields.

Legend: **Full** = create/edit/delete; **Read** = index/detail only; **—** = not exposed (by design or pending).

## Core entities

| Entity | CRUD controller | Mode | Notes |
|--------|-----------------|------|-------|
| User | `UserCrudController` | Full | concise index; detail has locale, reminder preference, owned hospitals, access grants, timestamps; [conditional delete](#user-accounts) |
| Hospital | `HospitalCrudController` | Full | coordinates, access grants and timestamps on detail |
| HospitalAccessGrant | `HospitalAccessGrantCrudController` | Full | permission mask UI |
| Allocation | `AllocationCrudController` | Full | secondary transport/indications, notes |
| Import | `ImportCrudController` | Limited edit | no NEW (pipeline-created); file metadata and blame on detail; links to rejects and batch items |
| ImportReject | `ImportRejectCrudController` | Read | |
| ImportBatchRun | `ImportBatchRunCrudController` | Read | |
| ImportBatchRunItem | `ImportBatchRunItemCrudController` | Read | |
| MonthlyReminderDispatch | `MonthlyReminderDispatchCrudController` | Read | recipient and delivery on detail; send reminder from index/detail |
| SavedExplorerView | `SavedExplorerViewCrudController` | Read | timestamps and config JSON on detail |
| UserOnboardingStep | `UserOnboardingStepCrudController` | Read | three-column index; no fieldsets |

## Reference data (full CRUD)

Allocation, Assignment, Department, DispatchArea, IndicationNormalized, IndicationGroup, IndicationRaw (read-only forms), Infection, MciCase, Occasion, SecondaryTransport, Speciality, State.

## Content

| Entity | CRUD controller | Mode | Notes |
|--------|-----------------|------|-------|
| Page | `PageCrudController` | Full | structural identity; translations panel on detail; timestamps on detail |
| PageTranslation | `PageTranslationCrudController` | Full | block editor only on forms; detail shows a block summary; path/timestamps read-only |
| Post | `PostCrudController` | Full | Trix content off index; timestamps and blame on detail |
| PostCategory | `PostCategoryCrudController` | Full | slug is generated and disabled |
| PostTag | `PostTagCrudController` | Full | slug is generated and disabled |
| PostComment | `PostCommentCrudController` | Read | no create/edit; content on detail |
| Media | `MediaCrudController` | Full | file metadata and usage snippet on detail |

## System (read-only)

| Entity | CRUD controller | Mode | Notes |
|--------|-----------------|------|-------|
| AuditEntry | `AuditLogCrudController` | Read | concise index (time, intent, action, entity, changed fields, actor); origin, request ID and diffs on detail; time-range and notification actions kept |
| CookieConsent | `CookieConsentCrudController` | Read | version and `updatedAt` on detail; no `createdAt` getter |
| Feedback | `FeedbackCrudController` | Limited | no manual create |

Failed messages stay a custom list/detail Twig view, not EasyAdmin CRUD.

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
