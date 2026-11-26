# Participant onboarding (dashboard)

Alpha onboarding for users with `ROLE_PARTICIPANT`: a dashboard card guides new participants through five steps until all are completed.

## Steps

1. Request clinic access (the hospital assignment request form, [#975](../allocation/hospital-assignment-requests.md))
2. Review own clinic
3. Start first import (CSV or `.txt` export; not Excel — see [import-pipeline.md](../import/import-pipeline.md#upload-validation))
4. Browse imported allocations in Explore (links to `/explore/allocation?hospitalFilter=my_hospitals`)
5. Open statistics overview

Explore requires `ROLE_PARTICIPANT` and shows only allocations of the participant's own hospitals ([ADR 044](../../02-architecture/decisions/044-explore-restricted-to-own-hospitals.md)); the onboarding link opens it with “My hospitals”.

Step availability is **strictly sequential**: a step becomes actionable only after all previous steps are completed. Users unlock the next step by marking the current one complete on the dashboard card; there is no automatic check yet for whether an import actually exists.

## Step 1 and the assignment request

Step 1 completes only through clinic access: the user owns a hospital or holds an access grant with `VIEW`. It cannot be marked complete by hand (`POST …/request_clinic_access/complete` returns 403), and a completion stored before #1483 is ignored while the user has no access, so nobody ends up with only locked steps.

Until then the step shows the user's current hospital assignment request (`OnboardingStepCatalog::resolveClinicAccessState()`; the newest pending request, otherwise the newest request):

| State | Step shows | Links |
|---|---|---|
| No request (or the latest was withdrawn, or approved and the access removed later) | the request text | request form |
| Pending | badge "Pending", hospital name | the request on "My requests" (`/hospitals/requests#request-<public id>`) |
| Rejected | badge "Rejected", hospital name; not the reviewer's message | the request, and a new request |

The same state decides the first action tile of the dashboard: "View your hospitals" with clinic access, otherwise "Request hospital assignment", "Request under review" or "Request rejected", each linking like the step. The reason for a rejection is shown only on the request page.

Step-specific permissions still apply (for example, import or statistics rights on at least one hospital). Completed steps are stored in `user_onboarding_step`.

## Code locations

| Area | Path |
|---|---|
| Domain / application | `src/Onboarding/` |
| Dashboard card | `src/Content/UI/Twig/templates/dashboard/_onboarding_card.html.twig` |
| Complete step endpoint | `POST /onboarding/steps/{stepKey}/complete` |
