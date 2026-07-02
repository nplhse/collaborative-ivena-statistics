# ADR 004: Colocated Twig templates

**Status:** accepted

## Context

Symfony's default convention places templates in a root `templates/` directory, separate from controller code. With many bounded contexts, a flat template tree becomes hard to navigate and encourages cross-context coupling.

## Decision

Place Twig templates next to their controllers under `src/<Context>/UI/Twig/templates/`. Attribute routing loads from `src/` with excludes for dev-only code. No root `templates/` directory is used.

## Consequences

**Positive:**

- Templates live alongside the code they render
- Bounded context boundaries are visible in the file tree
- Easier to find and refactor context-specific UI

**Negative:**

- Deviates from Symfony documentation defaults
- IDE/template path configuration may need adjustment
- Shared layouts must live in `Shared` context or be imported explicitly

## Alternatives

- **Root `templates/<context>/`** — rejected; splits UI from controller layer
- **Twig components only (no colocation)** — rejected; most pages still use traditional templates

## References

- [../overview.md](../overview.md)
- [../../03-development/frontend.md](../../03-development/frontend.md)
