# ADR 002: Hospital permission bitmask

**Status:** accepted

## Context

Hospital owners need to grant other participants fine-grained access (view, statistics, import, export, benchmarking) without creating separate role hierarchies per hospital. Permissions can be combined (e.g. view + statistics + import).

## Decision

Store hospital grants as an integer bitmask in `HospitalAccessGrant`. `HospitalPermission` is a backed `int` enum with power-of-two values. `HospitalPermissionMask` enforces implicit dependencies (statistics/import/export/benchmarking require view; benchmarking requires statistics).

## Consequences

**Positive:**

- Compact storage (single integer per grant)
- Efficient bitwise checks in `HospitalPermissionAccess`
- Easy to extend with new permission bits

**Negative:**

- Bitmask logic is less intuitive than separate boolean columns
- Invalid combinations must be validated (`Benchmarking` without `Statistics` is rejected)

## Alternatives

- **Separate boolean columns per permission** — rejected for schema verbosity with many permissions
- **Symfony voter-only without persisted grants** — rejected; grants must survive sessions and be manageable by owners

## References

- [../permission-model.md](../permission-model.md)
