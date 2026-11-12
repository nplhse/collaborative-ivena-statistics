# ADR 026: Hospital-held pseudonym key with key generations

**Status:** accepted

## Context

Encounter data (ADR 025) needs identifiers computed from data that must not reach the server: the
visit fingerprint (ADR 028) and, optionally, a pseudonym of the administrative case number. Both are
computed in the browser tool.

Forces:

- No server secret can be used in the tool. The existing `CaseIdHasher` uses `kernel.secret`.
- The same visit must give the same fingerprint across exports and users of one hospital.
- Users should not type a long passphrase on every run.
- A replaced key must not silently mix data of two keys.
- An import uploaded with the old key and still waiting must not write after a key change.

## Decision

### Key file

- The tool generates one random 256-bit key per hospital (`crypto.getRandomValues`) and saves it as
  a key file (key, label, creation date, check value). No passphrase, no key derivation needed.
- Keyed values are `HMAC-SHA256(key, prefix + normalised input)` via WebCrypto, with a versioned prefix
  per kind (for example `ed-visit:v1` for the visit fingerprint, `case:v1` for the case number).
- The hospital keeps the key file in a team folder; all its users use the same file. The tool can
  remember it in the browser (IndexedDB) for convenience only; the file stays the source of truth.
- The server never receives the key.

### Check value and generations

- Check value = `HMAC-SHA256(key, "encounter-key-check")`, written into the manifest of every file.
- Per hospital the server stores a `key_generation` (integer), the active check value and the retired
  check values. Every encounter stores the generation it was imported with.
- The first encounter upload of a generation registers its check value. Later uploads must carry the
  active value. A retired value is always rejected.
- Every encounter import stores the generation at upload. The import handler checks it again before
  every commit (like `ImportRunCommitGuard` for runs); a mismatch fails the run without writing.

### Key change

Only the **hospital owner** may change the key; administrators and users with the upload grant
(ADR 027) may not.

1. The hospital enters the state "key change in progress": new encounter uploads are refused.
2. The generation is incremented and the active check value retired. Queued and running imports of the
   old generation can no longer write (commit check above).
3. The owner chooses what happens to the existing data:
   - **Delete:** all encounters of the hospital with their links and review decisions, their imports
     and source files are deleted asynchronously.
   - **Keep:** existing encounters stay, distinguishable by their generation; their links remain
     effective.
4. The hospital leaves the state; the next upload registers the new check value.

The confirmation names the consequence for case counts and analyses: with **keep**, exporting old
periods again with the new key creates duplicates, because the fingerprints change and duplicate
detection only works within one generation. The action is audited (`encounter.key.changed` with the
chosen option) and administrators are notified.

## Consequences

**Positive:**

- The server cannot reverse pseudonyms or fingerprints and never sees case numbers
- Duplicate detection across uploads is deterministic within a hospital and generation
- A wrong or old key file is detected at upload
- The owner decides consciously between losing old data and risking duplicates

**Negative:**

- A lost key file forces a key change
- Every user of a hospital needs access to the same file
- With "keep", duplicates across generations are possible and must be avoided by not re-exporting
  old periods
- Browser storage for "remember" differs between browsers and may be cleared

## Alternatives

- **Server secret (`CaseIdHasher`)**: rejected; the secret would have to be shipped in the tool.
- **Passphrase with key derivation**: rejected; weaker, and users would type it on every run.
- **Server-generated key embedded in the tool download**: rejected; the server would hold the key.
- **Key change always deletes old data**: rejected; the owner may need the existing links.
- **Administrators may change the key**: rejected; the key belongs to the hospital.

## References

- [025-encounter-data-context-and-exchange-format.md](025-encounter-data-context-and-exchange-format.md)
- [027-encounter-access-grants.md](027-encounter-access-grants.md)
- [028-encounter-import-lifecycle.md](028-encounter-import-lifecycle.md)
- [../../04-features/import/import-runs.md](../../04-features/import/import-runs.md)
- Issues #1044, #1050
