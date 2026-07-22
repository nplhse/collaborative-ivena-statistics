# Development

**Audience:** Developers working on the codebase day to day.

**Purpose:** Daily workflows, fixtures, testing, translations, and frontend conventions.

## Documents

| Document | Type | Description |
|----------|------|-------------|
| [development-workflow.md](development-workflow.md) | Guide | Make targets, worker, lint, migrations |
| [fixtures.md](fixtures.md) | Guide | Reference YAML, fixture groups, synthetic data |
| [testing.md](testing.md) | Guide | PHPUnit suites, CI, test doubles, static analysis |
| [test-architecture-audit.md](test-architecture-audit.md) | Audit | Suite findings, double strategy, improvement backlog (#324) |
| [translations.md](translations.md) | Guide | Symfony domains, extract/lint workflow |
| [frontend.md](frontend.md) | Concept | Stimulus, Asset Mapper, Live Components |

## Reading order

1. [development-workflow.md](development-workflow.md)
2. [fixtures.md](fixtures.md)
3. [testing.md](testing.md)

### i18n / translation work

1. [translations.md](translations.md)
2. [../06-reference/glossary-i18n-de.md](../06-reference/glossary-i18n-de.md)
3. [testing.md](testing.md) — `make lint-trans`, `make lint-trans-de`

Other role-based paths: [../README.md#reading-paths](../README.md#reading-paths)
