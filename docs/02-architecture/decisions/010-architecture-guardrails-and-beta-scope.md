# ADR 010: Architecture guardrails and beta complexity scope

**Status:** accepted

## Context

Issue #258 calls for architecture review and reduced software complexity before beta. Phase 1 inventory found solid structure, missing automated boundary checks on `main`, and a few large hotspots (especially `AllocationRepository` and Analysis Explorer UI/application classes). Experimental Deptrac work exists on branch `refactor/architecture-review` but is not wired on `main`.

Enforcing every ideal boundary and refactoring every large class before beta would delay release without matching risk reduction.

## Decision

### Architecture automation (Deptrac)

After ADRs 007–009 are accepted:

1. Introduce Deptrac on `main`, aligned with those ADRs
2. Start in **report-only** mode with a baseline of known violations
3. Add CI visibility without failing the build until the baseline is stable and the team agrees to ratchet
4. Treat `refactor/architecture-review` as **input to review**, not as an automatic merge

Deptrac rules should encode the policies in ADRs 007–009 (context directions, Shared limits, pragmatic domain/Doctrine allowance, query/repository intent where expressible). Exact YAML is out of scope for this ADR and belongs to the Deptrac introduction PR.

### Complexity work before beta

Before beta, schedule **at most one** dedicated complexity-reduction PR, preferring:

1. **Preferred:** extract specialized reads from `AllocationRepository` into query classes (implements ADR 009)
2. **Alternative:** split a single Analysis Explorer hotspot if product risk there is higher at the time of execution

Do not run both large hotspot refactors in parallel before beta. Further hotspots (`AnalysisExplorerShell`, Engagement content builder, large view-model factories, …) stay in the post-beta backlog unless a concrete bug forces a touchpoint.

### Out of scope before beta

- Removing Doctrine from the domain layer
- Introducing repository interfaces project-wide
- Resolving every documented context partnership into an acyclic graph
- Homogenizing the entire Statistics folder layout
- Switching Deptrac to fail-CI on day one

### Delivery style

Architecture follow-ups for Issue #258 are delivered as **small, single-purpose PRs** (docs/ADRs, Deptrac report-only, one decoupling edge, one hotspot). No big-bang architecture commit.

## Consequences

**Positive:**

- Beta stays focused on enforceable guardrails and one high-value simplification
- Deptrac can land without blocking all merges
- ADR 009’s query-object goal gets a concrete first execution path

**Negative:**

- Known violations remain until baseline ratchets
- Explorer and other hotspots may stay large through beta
- Report-only mode requires discipline so new violations are still noticed

## Alternatives

- **Fail-CI Deptrac immediately with zero baseline** — rejected; would block unrelated feature work
- **Broad pre-beta refactor wave** — rejected; PR and review risk too high
- **Skip automation until after beta** — rejected; regressions in boundaries would be invisible

## References

- [007-bounded-contexts-and-dependency-directions.md](007-bounded-contexts-and-dependency-directions.md)
- [008-shared-platform-and-domain-framework-coupling.md](008-shared-platform-and-domain-framework-coupling.md)
- [009-ports-repositories-query-objects-and-naming.md](009-ports-repositories-query-objects-and-naming.md)
- [../overview.md](../overview.md)
- Issue [#258](https://github.com/nplhse/collaborative-ivena-statistics/issues/258)
