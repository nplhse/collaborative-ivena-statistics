# My Hospitals

`GET /hospitals` (`app_hospitals_index`, `ListOwnedHospitalsController`) lists the hospitals a participant works
with. It requires `ROLE_PARTICIPANT`. Issue #971 turns the page into an overview of data coverage and contribution;
this document grows with it.

## Which hospitals are listed

`MyHospitalsScopeResolver` reads the hospitals and the user's permissions in one query
(`HospitalRepository::findOwnedOrGrantedForUser()`):

| User | Listed hospitals | Permissions on them |
|------|------------------|---------------------|
| Owner | hospitals they own | all |
| Grant holder | hospitals with a grant that includes `VIEW` | the grant mask |
| Administrator | only hospitals they own or were granted | all |

Administrators are deliberately not given every hospital here, unlike `HospitalPermissionAccess`, which treats
them as having access everywhere: a list of all hospitals is the back office's job. An administrator without own or
granted hospitals sees the empty state.

The permissions per hospital are carried in `MyHospitalsEntry::$permissionMask` (see
[permission model](../../02-architecture/permission-model.md)). The data-status parts of the overview (#971) use it
to show a hospital's information only with the matching permission. Edit and access-grant actions stay behind `HospitalVoter::EDIT`
and `HospitalVoter::MANAGE_ACCESS_GRANTS`, so grant holders see neither.

## Tests

- `tests/Allocation/Integration/Application/Hospital/MyHospitalsScopeResolverTest.php` (owner, grants, grant
  without `VIEW`, administrator with and without own hospitals, no hospitals)
- `tests/Allocation/Functional/Controller/Hospitals/ParticipantHospitalsControllerTest.php` (listing, actions)
