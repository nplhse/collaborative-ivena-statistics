# PHPUnit test architecture audit

**Status:** Phase 0 complete (audit + quick wins)  
**Related:** [Issue #324](https://github.com/nplhse/collaborative-ivena-statistics/issues/324), [ADR 010](../02-architecture/decisions/010-architecture-guardrails-and-beta-scope.md)  
**Audience:** Developers improving the test suite before / during beta

This document records findings from a review of the PHPUnit suite and supporting infrastructure. It is the source of truth for iterative follow-up work. Day-to-day how-to remains in [testing.md](testing.md).

## Snapshot (audit date)

Approximate sizes:

| Layer | Test classes (approx.) |
|-------|------------------------|
| Unit | ~239 (+6 newly wired into the `unit` suite; see Quick wins) |
| Integration | ~151 |
| Functional | ~97 (+2 Onboarding into the `functional` suite) |
| Fixtures / System | smaller dedicated suites |

Infrastructure already in good shape:

- Context-mirrored layout under `tests/{Context}/{Unit|Integration|Functional}/`
- Named suites in `phpunit.dist.xml` and `make test SUITE=…`
- CI split: Unit job without PostgreSQL (ParaTest); database job excludes `unit`
- Foundry + DAMA + Zenstruck Browser extensions
- Shared helpers under `tests/Support/`
- No `#[ResetDatabase]` / `DatabaseKernelTestCase` under `tests/*/Unit/` (CI-safe)

## Findings

| ID | Priority | Finding | Evidence | Impact |
|----|----------|---------|----------|--------|
| F1 | P0 | Named suites omitted several context directories | Missing from `unit`: Admin, Install. Missing from `functional`: Onboarding. Empty `tests/Seed` only held `.DS_Store`. A Foundry-backed Onboarding catalog test had been misfiled under `Unit/` | Unit CI skipped real unit tests; suite macros were incomplete; misfiled DB test would fail unit CI once wired |
| F2 | P1 | `createMock()` used as default double | ~231 `createMock` vs ~6 `createStub`; ~37 files use `createMock` without `expects()` | Intent unclear; suite reads mock-heavy; refactor fragility |
| F3 | P1 | Strict mocks and spy-style recording mixed without convention | ~129 `expects(` across ~24 files; ~44 `willReturnCallback` | Reviews lack shared vocabulary |
| F4 | P2 | Hand-written fakes rare but effective | `tests/Import/Doubles/` (`InMemoryRejectWriter`, `InMemoryRowReader`) | Good pattern; underused elsewhere |
| F5 | P1 | Very large test classes | See [Hotspots](#hotspots-large-test-classes) | Hard to navigate; often mirrors production complexity |
| F6 | P2 | No documented test-double policy | [testing.md](testing.md) covered suites/CI only | Inconsistent new tests |
| F7 | P2 | Coverage without prioritized gap backlog | Codecov in CI; local `make coverage` | No ordered path to raise confidence on domain/auth |
| F8 | P3 | Optional later guardrails | Suite directory drift possible again | Regressions after adding contexts |

### Hotspots (large test classes)

| Lines (approx.) | File | Suggested split axis (later) |
|-----------------|------|------------------------------|
| ~973 | `tests/Statistics/Integration/AnalysisExplorer/AnalysisExplorerShellTest.php` | Happy path / filters / auth / edges |
| ~950 | `tests/Import/Integration/MessageHandler/ImportAllocationsMessageHandlerTest.php` | Success / rejects / idempotency / failure |
| ~925 | `tests/Statistics/Unit/AnalysisExplorer/ExplorerResultsTablePresenterTest.php` | Columns / empty data / formatting |
| ~717 | `tests/Content/Integration/Page/PageImageContentAnalyzerTest.php` | Analyzer scenarios by content type |
| ~611 | `tests/Statistics/Functional/Controller/DashboardControllerTest.php` | Auth / widgets / empty states |
| ~577 | `tests/Content/Unit/Page/PageContentValidatorTest.php` | Rule groups |
| ~535 | `tests/Allocation/Integration/Query/AllocationBucketQueryTest.php` | Query variants |
| ~499 | `tests/Allocation/Functional/Controller/Allocations/ListAllocationsControllerTest.php` | Filters / pagination / access |

Further classes in the ~350–480 line range (export builders, explorer controllers, indication review) can follow the same “split by behaviour” rule once the top three are done.

## Test double strategy

Taxonomy: Meszaros (*xUnit Test Patterns*) / Fowler (*Mocks Aren’t Stubs*), applied to PHPUnit 12. **No Mockery or Prophecy** as a project style — built-in PHPUnit doubles are enough. (`phpstan/phpstan-mockery` is an analyser extension only.)

| Type | Role | Prefer when | PHPUnit / project |
|------|------|-------------|-------------------|
| Dummy | Fills a constructor slot; never used | Logger unused in this scenario | `createStub()` with no setup, or a null object |
| Stub | Controls **input** to the SUT | Assert on SUT return value / state | **`createStub()`** + `method()->willReturn(…)` |
| Spy | Records **output** interactions | Side effects (mail, message, write); assert after act | `createMock()` + `willReturnCallback` → array, then `assert*` |
| Mock | Pre-programmed expectations | Exact call count / `never()` is part of the spec | `createMock()` + `expects(…)` |
| Fake | Working simplified collaborator | Multi-step state on one collaborator | Hand-written class under `tests/*/Doubles/` |

### Decision rule

1. Asserting on **result or state**? Stub query collaborators; construct real value objects / entities (do not mock them).
2. Asserting that **something was triggered**? Prefer a **spy** (explicit assertions) over a strict mock.
3. Need **`never()`** or a hard call-count guarantee? Use a real mock with `expects()`.
4. Same collaborator in many tests with **state across calls**? Prefer a **fake**.
5. Database / HTTP / full kernel? Belong in Integration / Functional with Foundry — do not force persistence doubles into Unit.

### What we deliberately avoid

- Introducing Mockery/Prophecy for application tests
- Mocking value objects, enums, or simple DTOs
- Mocking third-party types when an owned adapter/interface exists
- Rewriting every `createMock` call in one PR — convention first, then hotspot hygiene

Reference fakes: [`tests/Import/Doubles/`](../../tests/Import/Doubles/).

## Quick wins (Phase 0 — done in tree)

1. **Suites completed** in `phpunit.dist.xml`:
   - `unit`: `tests/Admin/Unit`, `tests/Install/Unit`
   - `integration`: `tests/Onboarding/Integration` (Foundry/`DatabaseKernelTestCase` catalog tests; not Unit)
   - `functional`: `tests/Onboarding/Functional`
2. **Empty `tests/Seed/` removed** (only contained `.DS_Store`; no tests to register).
3. **`testing.md`** extended with test-double guidance and a link here.
4. **This audit + backlog** written so follow-up issues/PRs can be opened without re-discovering findings.

Verify locally:

```bash
make test SUITE=unit
make test SUITE=functional PATH_ARG=tests/Onboarding
```

## Backlog (follow-up issues / PRs)

Work in **small, single-purpose PRs** (ADR 010). Suggested GitHub issues below; titles are copy-paste ready. Parent epic: #324.

### P1 — Test double hygiene

| ID | Suggested issue title | Scope | Acceptance criteria |
|----|----------------------|-------|---------------------|
| B1 | `test(feedback): prefer stubs/spies in AdminFeedbackMailerTest` | `tests/Feedback/Unit/Infrastructure/Mail/AdminFeedbackMailerTest.php` | Recipient resolver uses `createStub`; mailer uses spy or justified `expects`; no stub-only `createMock` |
| B2 | `test(engagement): prefer stubs/spies in MonthlyReminderMailerTest` | `tests/Engagement/Unit/Application/MonthlyReminderMailerTest.php` | Same convention as B1 |
| B3 | `test(import): finish stub migration in IndicationCreationStrategyTest` | `tests/Import/Unit/Service/Resolver/IndicationCreationStrategyTest.php` | Return-only doubles are `createStub`; remaining `expects` documented as intentional |
| B4 | `test(shared): mail/notification double hygiene` | `AdminNotificationSenderTest`, `SymfonyTransactionalMailerTest` | Stub vs spy vs mock aligned with [strategy](#test-double-strategy) |
| B5 | `test: context-wide createStub sweep (Statistics unit hotspots)` | High-count Statistics unit files using `createMock` without `expects` | Mechanical rename where usage is stub-only; no behaviour change |

Do **not** merge B1–B5 into one PR. One context (or one hot file) per PR.

### P1 — Split oversized test classes

| ID | Suggested issue title | Scope | Acceptance criteria |
|----|----------------------|-------|---------------------|
| B6 | `test(statistics): split AnalysisExplorerShellTest by behaviour` | Integration shell test (~973 lines) | Multiple focused classes; shared setup extracted only if duplication is real; suite still green |
| B7 | `test(import): split ImportAllocationsMessageHandlerTest` | Message handler integration (~950 lines) | Classes for success / rejects / idempotency / failure (adjust names to fit content) |
| B8 | `test(statistics): split ExplorerResultsTablePresenterTest` | Unit presenter (~925 lines) | Split by column/empty/format concerns |

If a split stays painful, open a **production** follow-up under #258 instead of only chopping tests.

### P2 — Coverage gaps (iterative)

Prioritize confidence, not a global percentage target. One gap issue → one PR → note Codecov/delta in the PR body.

| ID | Suggested issue title | Focus area | Layer |
|----|----------------------|------------|-------|
| B9 | `test: cover hospital permission / voter edge cases` | `HospitalVoter`, grant bitmasks, denied paths | Unit + Integration |
| B10 | `test: import reject and invalid-input paths` | Reject writer, unsupported upload, mapping failures | Unit / Integration (extend existing) |
| B11 | `test: projection rebuild invariants` | Rebuild handler / projection consistency | Unit + Integration |
| B12 | `test: explore allocations access and filter edges` | List/explore functional gaps | Functional |
| B13 | `test: onboarding failure / already-complete paths` | Beyond happy path | Functional |

Use `make coverage SUITE=unit` for fast loops; full Codecov from CI for trends.

### P2 — Documentation / process

| ID | Suggested issue title | Scope |
|----|----------------------|-------|
| B14 | `docs: PR checklist bullet for test doubles` | Short checklist in contributing / testing docs (stub default, spy for side effects) |
| B15 | `chore: remove obsolete or duplicated tests found during splits` | Only with evidence from B6–B8 reviews |

### P3 — Optional automation (post–double hygiene)

| ID | Suggested issue title | Scope | Notes |
|----|----------------------|-------|-------|
| B16 | `ci: fail if tests/*/Unit dirs missing from phpunit unit suite` | Small script or workflow check | Prevents F1 regression |
| B17 | `spike: Infection on one domain package` | e.g. Allocation permission or Import reject rules | Spike only; not a beta blocker |
| B18 | `chore: slow-test budget from report-slowest-tests` | Track outliers after splits | Soft budget first |

### Explicitly out of scope for now

- Project-wide Mockery adoption
- Mutation testing as a merge gate
- Splitting every test class over 300 lines before beta
- Homogenizing all Statistics folder layouts via tests alone

## Progress checklist

- [x] Audit document
- [x] Suite gaps fixed; empty Seed removed
- [x] Test-double section in testing docs
- [x] Backlog formulated (B1–B18)
- [ ] B1–B5 double hygiene PRs
- [ ] B6–B8 large-class splits
- [ ] B9–B13 coverage gap PRs (as needed)
- [ ] B14–B18 process / automation (optional)

When opening GitHub issues, link back to this file and to #324.
