# ADR 043: Terms of use version by configuration

**Status:** accepted

## Context

Registration requires a checkbox for the terms of use, but until #1564 the acceptance was not stored. The revised terms
require that every acceptance is recorded with account, time and version, and that a material change is accepted again
at the next login; without it, the access rests and leaving stays possible. Editorial changes are only published.

The terms are the CMS page with `PageKey::Terms`. It has one translation per language with block content and no version
field or revision history. Editors change the page for typos and wording as well as for material changes.

## Decision

1. **The version is configuration, raised by hand.** `app.terms.version` and `app.terms.published_at` name the version
   and its date. Both are set together and changed only for a material change. Editing the page never creates a version.
2. **Each acceptance is a row of `terms_acceptance`** in the User context: account, kind, version, time, language of the
   shown page and the SHA-256 of its published block content. Rows are only added, never changed, and are deleted with
   the account. The hash is evidence of the shown text, not a version: it is not compared at login.
3. **Content provides the page through a User contract.** `TermsDocumentProviderInterface` lives in
   `User\Application\Contract` and is implemented by `Content\Infrastructure\Adapter\DoctrineTermsDocumentProvider`,
   like the other User contracts that Content implements. User does not depend on Content.
4. **Registration stores the first row.** While no version is configured, it is stored without a version.
5. **A configured version is accepted before anything else.** A signed-in user without a row for it is sent to an
   acceptance page, like the two-factor obligation of ADR 040 and before it. Logout, the language and cookie choice and
   the terms, imprint and privacy pages stay reachable; impersonating administrators and stateless routes are exempt.
   Without a configured version nobody is asked, so a release can ship before the revised terms are published. The demo
   never asks, because its accounts are shared (ADR 023).

## Consequences

- An operator who changes the terms materially must also raise the version; otherwise nobody is asked again. The
  upgrade notes and the configuration reference say so.
- Accounts created before #1564 or by an administrator have no row and are asked at their first login once a version is
  set.
- Kinds other than `terms` (the upload authority per hospital, #1565) are added to the same table.
- The rows are not audited separately; they are themselves the record.

## Alternatives

- **Derive the version from the page content or its update time.** Rejected: every editorial change would ask everyone
  again, which the terms rule out.
- **A version field on the CMS page.** Rejected for now: it needs editor UI and rules for each language, and the terms
  are the only page that needs it. The configuration keeps the decision with the operator, where the Board's decision
  lands.
- **Columns on `User`.** Rejected: only the latest acceptance would remain, and the upload authority needs one row per
  hospital.
