# ADR 011: Collaborative Explore allocation visibility

**Status:** superseded by [ADR 044](044-explore-restricted-to-own-hospitals.md) (#1566)

## Context

Explore (`/explore`) lists and shows allocation records that may belong to any participating hospital. The security audit (SEC-005) asked whether this cross-hospital visibility is intentional collaboration or a missing tenant boundary. Hospital grants (`HospitalPermission::View`) already gate clinic management, import, export, and scoped statistics filters — but not Explore allocation `VIEW`.

Separately, path-level `access_control` already requires `ROLE_PARTICIPANT` for `/explore`, while `AllocationVoter` historically granted `VIEW` to any `ROLE_USER`. That mismatch left the voter weaker than the firewall if it were ever reused outside Explore.

## Decision

1. **Collaborative by design:** Participants may view allocation list and detail records for **all** hospitals. Explore hospital filters (including “My hospitals”) are UX convenience, not an authorization boundary. Allocation `VIEW` is **not** bound to `HospitalPermission::View`.
2. **Participant required:** Viewing allocations requires `ROLE_PARTICIPANT`. `ROLE_USER` alone is insufficient. Enforce this both via `access_control` on `/explore` and in `AllocationVoter` (defense in depth).

## Consequences

**Positive:**

- Matches the product goal of a shared, collaborative allocation overview
- Clear docs reduce mis-implementation of hospital-scoped Explore checks
- Voter and path gate agree on `ROLE_PARTICIPANT`

**Negative:**

- Clinical free-text and hospital identity are visible across centers to every participant (free text corrected in November 2026, see [Correction](#correction-november-2026-1548))
- Must be communicated in onboarding / beta expectations

## Alternatives

- **Tenant isolation** — bind `AllocationVoter::VIEW` to `HospitalPermission::View` and default list scope to accessible hospitals — rejected; conflicts with collaborative Explore
- **Leave voter on `ROLE_USER`** — rejected; weaker than the `/explore` path gate and invites drift

## References

- [../permission-model.md](../permission-model.md)
- [../../04-features/allocation/explore-allocation-list.md](../../04-features/allocation/explore-allocation-list.md)
- `src/Allocation/Infrastructure/Security/Voter/AllocationVoter.php`

## Correction (November 2026, #1548)

The negative consequence above overstated what participants see. Hospital identity is visible to every participant in Explore, as decided. The IVENA free text (`allocation.notes`) is not shown anywhere in the application: no participant-facing page renders it, and since #1549 the admin area does not show it either. It can only be read with direct database access.

Rejected rows and uploaded source files still contain the original free text. They are available to administrators only, for error analysis and re-imports. The decision itself is unchanged.
