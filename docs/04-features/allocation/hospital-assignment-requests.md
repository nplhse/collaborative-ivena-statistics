# Hospital assignment requests

How participants ask to be assigned to a hospital and how administrators decide (#975). The request does not change
the permission model: an approval creates an ordinary hospital access grant or makes the requester owner
([permission-model.md](../../02-architecture/permission-model.md)).

## Requesting

| Page | Route | Who |
|---|---|---|
| New request | `/hospitals/requests/new` (`app_hospital_assignment_request_new`), `?hospital=<public id>` preselects | `ROLE_PARTICIPANT` |
| My requests | `/hospitals/requests` (`app_hospital_assignment_request_index`) | `ROLE_PARTICIPANT` |
| Withdraw | `POST /hospitals/requests/{publicId}/withdraw` (CSRF) | the requester; other users get 404 |

- Entry points: My Hospitals (header and empty state), the hospital detail page for participants without access to it
  (a pending request is shown instead), the onboarding step "Request access to your clinic" and the first action tile
  of the dashboard. While a request is pending or rejected, the onboarding step and the tile link to it instead
  ([participant onboarding](../onboarding/participant-onboarding.md#step-1-and-the-assignment-request), #1483).
- Every card on "My requests" is an anchor `#request-<public id>`; the linked card is highlighted.
- The participant picks a hospital by name from every hospital (label: name and city). A name that matches no entry
  is sent as the name of a hospital they could not find; no hospital is created from it. Without JavaScript an
  exact label still selects the hospital.
- Reasons: emergency department leadership, hospital management, data submission, other (with a required
  explanation); a comment is optional (both at most 2000 characters).
- Optional checkbox "I am authorised to upload data for this hospital." (#1565): stored on the request with the time
  and the terms version in force (`upload_authority_confirmed_at`, `upload_authority_terms_version`).
- Refused: hospitals the participant can already see (`HospitalPermission::View`, so also hospitals they own) and a
  second pending request for the same hospital (also enforced by a partial unique index).
- Users without participation cannot request; participant approval and hospital assignment stay separate.

## Lifecycle

`pending` → `approved`, `rejected` or `withdrawn` (`HospitalAssignmentRequest`, table `hospital_assignment_request`).
A closed request keeps the reviewer, the time, the message to the requester, the outcome (`access_grant` with the
permission mask, or `ownership` with the previous owner and the choice for their access), so it stays clear who
granted access, when, and on which request.

## Review (administrators)

EasyAdmin, *Users → Assignment requests* (`HospitalAssignmentRequestCrudController`), pending first and counted in
the menu. The detail page shows the requester (account, current hospitals), the requested hospital with its owner
and users with access, reason, explanation and comment, and the decision forms:

| Decision | Effect |
|---|---|
| Match hospital | A request for a named hospital is matched to an existing hospital; only then it can be approved. The entered name is kept. |
| Approve as access | Creates an access grant. The permissions are preset by the reason (ED leadership and hospital management: all; data submission: VIEW, STATISTICS, IMPORT; other: VIEW, STATISTICS) and normalised (BENCHMARKING adds STATISTICS and VIEW). |
| Approve as ownership | Makes the requester owner. With an owner the page shows them, the transfer must be confirmed, and the reviewer chooses: the previous owner **keeps full access** (an access grant with all permissions) or **loses access**. A grant the requester held is removed. |
| Reject | Closes the request with an optional message shown to the requester. |

The detail page also shows whether the requester confirmed the upload authority. An approval with the import permission
(an access grant whose mask contains IMPORT, or ownership) stores that confirmation as the requester's upload
authority for the hospital, with the time and terms version of the request; the upload form then does not ask again
([import-pipeline.md](../import/import-pipeline.md#who-may-upload)). Without the checkbox, or with an approval without
IMPORT, nothing is stored and the person confirms before their first upload.

`HospitalAssignmentRequestDecider` runs every decision in one transaction: request and hospital are locked, the
request must still have the version shown to the reviewer and a transfer the owner shown to the reviewer; otherwise
nothing changes and the page says why. Losing access through a transfer also removes encounter grants, as every
owner change does (`RevokeEncounterGrantsOnHospitalAccessLoss`).

## Audit and notifications

- Audit intents: `hospital.assignment_request.submitted`, `.withdrawn`, `.hospital_resolved`, `.approved`,
  `.rejected`; the grant and owner changes of an approval are recorded under the same intent.
- After the commit: `admin.hospital_assignment_requested` to administrators (link to the review page; opening it
  changes nothing), `hospital_assignment.decided` to the requester, `hospital_assignment.ownership_transferred` to
  the previous owner of a transfer ([notifications](../notification/README.md#notification-types)). They store names
  and codes, not the comment or the message.
