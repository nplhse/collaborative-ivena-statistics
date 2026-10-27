# ADR 021: Read the current time only through the Symfony clock

**Status:** accepted

## Context

Issue #694 was a test whose result depended on the current date. Fixing it (#702) and moving the statistics request path to the Symfony clock (#703) showed many places that read the wall clock directly: `new \DateTimeImmutable()`, `new \DateTimeImmutable('now')`, `'today'`, `'first day of this month'`, `time()`, `date('Y')`.

`ClockSensitiveTrait::mockTime()` cannot see these calls. A request in which only some code follows the mocked clock works with two different "now" values. Tests then pass or fail depending on the real date and cannot simulate a month change, a new year or a future date. A review of #703 found one more such place after the PR was opened, so checking by hand is not enough (issue #705).

## Decision

### Application code reads the time from the clock

- Services inject `Psr\Clock\ClockInterface` and call `$this->clock->now()`.
- Entities, value objects and static helpers, which cannot receive services, call `Symfony\Component\Clock\Clock::get()->now()`. This is the same global clock that backs the `clock` service and that `mockTime()` replaces.
- Write timestamps in entities (`createdAt`, `updatedAt`, `reviewedAt`, …) follow the same rule. Wall-clock timestamps also make tests depend on the date (sorting, "last 30 days" filters), and the fix is mechanical.
- Tests may keep using `time()` and `new \DateTimeImmutable()`; the rule only covers `src/`.

### PHPStan enforces it

Two project rules in `tests/PHPStan/Rules/` are registered in `phpstan.dist.neon`:

| Identifier | Reports |
|------------|---------|
| `wallClock.dateTime` | `new \DateTimeImmutable` / `new \DateTime` and `date_create()` / `date_create_immutable()` without a value or with a relative constant string |
| `wallClock.function` | `time()`, `date()` / `gmdate()` / `idate()` / `getdate()` / `localtime()` without a timestamp, `mktime()` / `gmmktime()` without a year, `strtotime()` with a relative constant string and no base timestamp |

- A string is **absolute** when it starts with `@` (Unix timestamp) or `date_parse()` finds year, month and day in it (`'2026-05-01'`, `'2026-05-01 +1 day'`, `'2026-05'`). Everything else (`'now'`, `'today'`, `'midnight'`, `'10:00'`, `'Monday'`, `'-30 days'`, an empty string) is relative and reported.
- Only constant strings are checked. Expressions such as `$row['created_at']` or `$date.' 00:00:00'` almost always carry an absolute value from the database or the request; reporting them would cause many false positives.
- `microtime()` and `hrtime()` stay allowed: they measure durations, not calendar time. `->modify('-1 day')` stays allowed: it is relative to a given value.
- Each error names the fix (`ClockInterface`, `Clock::get()->now()`). A deliberate exception needs `@phpstan-ignore wallClock.function (reason)`.
- `DateTimeImmutable::createFromFormat()` without `!` or `|` fills missing fields from the wall clock. It is not covered yet (#821).

### Existing code is baselined

Occurrences that existed when the rule was introduced were listed in `phpstan-baseline-wall-clock.neon`, separate from `phpstan-baseline.neon`, so new code was checked immediately. The baseline was reduced module by module, Statistics first (#801). It is empty and has been removed together with its include; every occurrence in `src/` is now reported.

## Consequences

- PHPStan fails on a new wall-clock read in `src/`, so the problem is caught in CI instead of in review or months later in a failing build.
- Code that switches to the clock gets `DatePoint` from `now()`. It extends `DateTimeImmutable`, so Doctrine `datetime_immutable` columns and type declarations keep working.
- Without `mockTime()` the clock returns the real time, so behaviour does not change; it only becomes controllable in tests.
- The rules live in `tests/` (autoload-dev) and are covered by `RuleTestCase` tests in `tests/PHPStan/Unit/`. Their fixtures in `tests/PHPStan/Unit/Rules/data/` are excluded from PHPStan, Rector and PHP-CS-Fixer.
