# ADR 026: Hospital-held pseudonym key with key generations

**Status:** accepted

## Context

Encounter data (ADR 025) needs a stable identifier for an administrative case: re-uploads and
overlapping exports must hit the same case, and later sources (controlling data) must attach to it.
The natural identifier is the hospital's case number, which must not reach the server.

Forces:

- The pseudonym is computed in the browser tool, so no server secret can be used. The existing
  `CaseIdHasher` uses `kernel.secret` and cannot run in the tool.
- The same case number must give the same pseudonym across exports, users and years of one hospital.
- Users should not type a long passphrase on every run.
- A lost or replaced key must not silently break linkage or mix data of two keys.
- The key reset must not be undone by an import that was uploaded with the old key and is still
  waiting in the queue.

## Decision

### Key file

- The tool generates one random 256-bit key per hospital (`crypto.getRandomValues`) and saves it as
  a key file (key, label, creation date, check value). No passphrase, no key derivation needed.
- Pseudonym = `HMAC-SHA256(key, normalised case number)` via WebCrypto.
- The hospital keeps the key file in a team folder; all its users use the same file. The tool can
  remember it in the browser (IndexedDB) for convenience only; the file stays the source of truth.
- The server never receives the key.

### Check value and generations

- Check value = `HMAC-SHA256(key, "encounter-key-check")`, written into the manifest of every file.
- Per hospital the server stores a `key_generation` (integer), the active check value and the retired
  check values.
- The first encounter upload of a generation registers its check value. Later uploads must carry the
  active value. A retired value is always rejected, so a file made with an old key cannot register
  itself again after a reset.
- Every encounter import stores the generation at upload. The import handler checks it again before
  every commit (like `ImportRunCommitGuard` for runs); a mismatch fails the run without writing.

### Reset

Users with the upload grant on the hospital (ADR 027) and administrators may reset the key. Old and
new pseudonyms cannot be linked, so the reset removes all encounter data of the hospital:

1. increment `key_generation`, retire the active check value
2. cancel queued and running encounter imports of the old generation
3. delete imports, encounters, visits, allocation links, match decisions and source files
   asynchronously in the worker

The action is shown as a danger action with the counts that will be deleted and a confirmation by
typing the hospital name. It is audited (`encounter.key.reset`) and administrators are notified.

## Consequences

**Positive:**

- The server cannot reverse pseudonyms and never sees case numbers
- Linkage across uploads and later sources is deterministic within a hospital
- A wrong or old key file is detected at upload, not discovered in the statistics
- No typing of secrets; strong random key instead of a passphrase

**Negative:**

- A lost key file means a reset and a new upload of all periods
- Every user of a hospital needs access to the same file
- Browser storage for "remember" differs between browsers and may be cleared

## Alternatives

- **Server secret (`CaseIdHasher`)**: rejected; the secret would have to be shipped in the tool.
- **Passphrase with key derivation**: rejected; weaker, and users would type it on every run.
- **Server-generated key embedded in the tool download**: rejected; the server would hold the key.
- **Keep old data after a reset**: rejected; re-uploads with the new key would duplicate every case.

## References

- [025-encounter-data-context-and-exchange-format.md](025-encounter-data-context-and-exchange-format.md)
- [027-encounter-access-grants.md](027-encounter-access-grants.md)
- [../../04-features/import/import-runs.md](../../04-features/import/import-runs.md)
- Issues #1044, #1050
