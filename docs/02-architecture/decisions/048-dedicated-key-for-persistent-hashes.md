# ADR 048: Dedicated key for persistent hashes

**Status:** accepted

## Context

`APP_SECRET` (`kernel.secret`) signs short-lived things: sessions and remember-me cookies, login links, CSRF tokens.
Until v0.4 it was also the HMAC key of two kinds of stored, pseudonymised values:

- the case-ID hashes of allocations (`CaseIdHasher`), which recognise re-imported cases as duplicates;
- the analytics user keys (`AnalyticsUserKeyGenerator`), which keep the series of the access statistics together.

The operations docs recommend rotating `APP_SECRET` after a possible exposure, and `app:env:check` asks for at least
32 characters. A rotation changed every new case-ID hash: re-imports of periods already imported were no longer
recognised as duplicates and inflated the statistics for those periods (#1572, reproduced: two allocations of the
same case stay in the projection after a rotation, one without it). Access statistics broke their series (#1706).

The project otherwise avoids new environment variables.

## Decision

1. Case-ID hashes and analytics user keys use their own key from `APP_HASH_KEY` (parameter `app.hash_key`). This is a
   deliberate exception to the rule of adding no new environment variables.
2. On upgrade, operators set `APP_HASH_KEY` to the current `APP_SECRET` value. No stored hash and no number shown to
   users changes. After that, `APP_SECRET` can be rotated on its own.
3. An empty `APP_HASH_KEY` falls back to `APP_SECRET`, so development, tests and an upgrade that missed the step keep
   working with unchanged hashes. `app:env:check --check-profile=prod` fails while it is empty.
4. `APP_HASH_KEY` itself is not rotated. Changing it is a data migration (all stored hashes would have to be
   recomputed) and is out of scope until there is a reason for it.
5. Symfony's own signatures and the short-lived sign-in failure hashes (30 days, #1487) stay on `APP_SECRET`.

## Alternatives considered

- **Only document that `APP_SECRET` must never be rotated:** no change, but the risk after an exposure stays.
- **Store the key in the database:** no new variable, but key and hashes would be in the same backup, which weakens the
  pseudonymisation.
- **Key generation per allocation (#1572):** would allow rotating the hash key later, but needs a schema change and
  re-hashing from kept source files for little gain now.
- **Content fingerprint as a second match when the hash differs:** changes duplicate detection and needs a study of
  false matches.

## Consequences

- One more secret to back up with `.env.local`; losing it means new imports no longer match earlier cases.
- The test environment sets `APP_HASH_KEY` to a value different from `APP_SECRET`, so a test shows the wiring.
- Rotation of the three secrets is described in
  [configuration.md](../../06-reference/configuration.md#secrets-and-rotation).
