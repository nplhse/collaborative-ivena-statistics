# AGENTS.md

Instructions for coding agents working in this repository. People follow the same rules
through [CONTRIBUTING.md](CONTRIBUTING.md). Facts live in [`docs/`](docs/README.md); this file only
says where to look and what to check. If this file and `docs/` disagree, say so.

## Project

Collaborative IVENA Statistics: a Symfony monolith that imports anonymised EMS allocation exports
(IVENA eHealth) from participating hospitals and turns them into statistics for comparison and
research. Real clinical data is never part of the repository; local work uses synthetic fixtures.

- Code is organised by bounded context under `src/{Context}/{UI,Application,Domain,Infrastructure}`,
  tests mirror it under `tests/{Context}/{Unit,Integration,Functional}`. Context list and
  responsibilities: [bounded-contexts.md](docs/02-architecture/bounded-contexts.md).
- Versions come from `composer.json`, `package.json` and `compose.yaml`; commands from `make help`.
  Do not copy them into other files.

## Before you change anything

1. Read the code you are about to change and the docs for that area. Start at
   [docs/README.md](docs/README.md) and follow the reading path for your role, in particular
   [statistics](docs/04-features/statistics/README.md#reading-order-statistics-feature-work).
2. Check the accepted ADRs in [decisions/](docs/02-architecture/decisions/README.md) that touch the
   area. They record deliberate trade-offs (ADRs 007–010): Doctrine in the domain layer, concrete
   repositories without interfaces, documented context partnerships, a Deptrac baseline. Do not
   "fix" these towards general Symfony or DDD ideals unless the task asks for it.
3. If docs and code disagree, or the task leaves a decision open that changes results, data or
   permissions, stop and ask. Mention smaller mismatches in your report.

## Implementing

- Keep the change to the task. No drive-by refactors, renames or architecture moves; propose
  them separately (ADR 010: small, single-purpose PRs). For findings outside the task, see
  [Side findings](#side-findings).
- Respect context boundaries and use the existing extension points
  ([extension-points.md](docs/02-architecture/extension-points.md)) instead of new mechanisms.
- Project rules that differ from Symfony defaults:
  - Twig templates live next to their context in `src/{Context}/UI/Twig/templates/` and are
    referenced as `@Context/...`; root `templates/` only holds bundle overrides (ADR 004).
  - Translations: pick the domain of the owning context, always pass it explicitly, add EN and DE
    together ([translations.md](docs/03-development/translations.md)).
  - Hospital data is protected by per-hospital permission grants, not only roles. Use
    `HospitalPermissionAccess` and the voters ([permission-model.md](docs/02-architecture/permission-model.md)).
  - Never read the wall clock directly; use `ClockInterface` / `Clock::get()` and `mockTime()` in
    tests ([testing.md](docs/03-development/testing.md#time-in-code-and-tests), ADR 021).
  - New analytical reads go into query classes under `Infrastructure/Query/`, not into large
    repositories (ADR 009).
- Migrations: never edit one that is already on `main`; new ones follow
  [development-workflow.md](docs/03-development/development-workflow.md#migrations).

## Changes to statistics

When a change can alter a number shown to users, state for each affected analysis what happens
to: population, filters and scope, numerator and denominator, missing or unknown values, period
boundaries, and comparison groups. Definitions:
[statistics-filter-and-scope.md](docs/04-features/statistics/statistics-filter-and-scope.md).

- Cover changed calculations with tests whose expected values can be followed by hand
  (small fixture, explicit numbers), not snapshots of the current output.
- Projection and materialized views: follow
  [projection-and-materialized-views.md](docs/04-features/statistics/projection-and-materialized-views.md)
  for refresh rules and the test trait; MV tests run with `make test SUITE=materialized-view`.

## Checks

| Command | Effect |
|---|---|
| `make check` | All regular checks, no tests, offline; changes no source code |
| `make fix` | Changes files: Swiss Knife, Rector, PHP-CS-Fixer, twig-cs-fixer, Prettier/ESLint |
| `make test SUITE=… PATH_ARG=…` | PHPUnit; needs the test database ([testing.md](docs/03-development/testing.md)) |
| `make ci` | `check` plus the complete test suite (slow; GitHub Actions runs it on every PR) |

Details and the single check targets: [development-workflow.md](docs/03-development/development-workflow.md#checks-fixes-and-tests).

- Before a PR: `make check` and the tests of every context whose behaviour can change
  (`make test PATH_ARG=tests/<Context>`), not only the directory you edited. Do not run `make ci` or
  the complete suite by default; GitHub Actions runs it. Run it locally only for changes to central
  infrastructure or to reproduce a CI failure.
- Do not run database tests in two checkouts at the same time; they share the test database.
- Run `make fix` only on purpose and review its diff; it can touch files outside your change.
- A green local run does not replace the GitHub workflow results.
- When a check fails, fix the cause. Do not raise ignore lists, add `@phpstan-ignore` or
  `@psalm-suppress`, or regenerate `phpstan-baseline.neon` / `deptrac.baseline.yaml` to get green;
  if an exception is justified, explain it in the PR.
- Report what you ran, what passed, and what you could not run and why.

## Language, commits and pull requests

- English is the shared language: code, comments, docs, commit messages, branch names, and
  everything on GitHub (issues, pull requests, comments, reviews), even when the conversation with
  the user is in another language. Exception: the German translation files.
- Commit messages and pull request titles follow
  [Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/):
  `<type>(<optional scope>): <description>`, for example `fix(import): skip empty rows (#123)`.
  - Types: `feat`, `fix`, `docs`, `test`, `refactor`, `perf`, `build`, `ci`, `chore`, `style`,
    `revert`. Scope is the bounded context or area in lowercase (`statistics`, `fixtures`, `adr`).
  - Description in the imperative, lowercase start, no trailing period; reference the issue as
    `(#123)` at the end.
  - Breaking changes get `!` after the type/scope and a `BREAKING CHANGE:` footer.
- One logical change per commit; the pull request title summarises the whole change in the same
  format.

## Side findings

When you notice something outside the task (a potential bug, inconsistent behaviour, a missing
test, outdated docs), do not fix it in passing and do not let it get lost: mention it in your report
and offer to open a GitHub issue for it. Open the issue only after the user agrees, in English, with
what you observed, where (file and line), and how to reproduce it if known.

## Docs, ADRs and environment

- Update the affected docs in the same change as the behaviour. Add an ADR only for a lasting
  decision with alternatives (see the [ADR index](docs/02-architecture/decisions/README.md)).
- Parallel work: use git worktrees with `bin/worktree-init`
  ([development-workflow.md](docs/03-development/development-workflow.md#work-in-a-git-worktree)).
  Worktrees share the databases of the source checkout.
- These commands drop data or remove installed files and are never routine setup for an existing
  environment:
  `make install`, `make install-dev`, `make purge`, `make reset`, `make fixtures`, `make clear`,
  `make restore-db`, `make demo-install`, `composer setup-database`, `composer setup-env`.
  Use `make upgrade-dev` to bring an existing environment up to date, and ask before anything else.
- Do not commit secrets or `.env.local` files.
