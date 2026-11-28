# ADR 047: Inline style attributes stay allowed by the Content Security Policy

**Status:** accepted

## Context

The Content Security Policy is still report-only (SEC-014). Before it can be enforced (#580), `'unsafe-inline'` has to
go wherever it lets scripts run. Styles are a separate question.

Production reports in Sentry over 90 days (as of 2026-11-28) show a single kind of violation: inline `style=""`
attributes on `/admin` pages (`style-src-attr`, about 2,150 events). EasyAdmin adds nonces to `style-src`, and a nonce
switches off `'unsafe-inline'` for that directive, so every style attribute there is reported. The rest of the
application sends no reports, because its policy still has `'unsafe-inline'` for styles.

The templates set about 470 style attributes, nearly all with computed values: bar widths, chart colours, map and
sparkline positions.

Options considered:

1. **Remove every style attribute.** Computed values would have to move into SVG attributes, data attributes read by
   JavaScript, or a fixed set of CSS classes. A large change across all contexts for little security gain.
2. **Keep `'unsafe-inline'` in `style-src`.** Allows `<style>` elements as well, and does not help on `/admin`, where the
   nonce switches it off.
3. **Allow style attributes only**, with the CSP Level 3 directive `style-src-attr 'unsafe-inline'`.

## Decision

1. **Style attributes are an accepted exception.** Every policy (report-only now, enforced later) contains
   `style-src-attr 'unsafe-inline'`. A style attribute cannot run script. The remaining risk is injected CSS changing
   the look of a page or leaking data through background requests. Twig escaping is still what keeps user input out of
   attributes.
2. **`<style>` elements stay under `style-src`.** EasyAdmin keeps its nonces. Outside `/admin`, `style-src` keeps
   `'unsafe-inline'` for now. Whether that can go is decided when the policy is enforced.
3. **Static inline styles still move to CSS classes** when a template is touched. The exception is meant for computed
   values.
4. **Added by the application.** NelmioSecurityBundle 3.9 does not know `style-src-attr`, so
   `StyleAttributePolicyListener` (Shared) appends it to the header Nelmio sends. When Nelmio supports the directive,
   it moves to `config/packages/nelmio_security.yaml`.

## Consequences

**Positive:**

- The report noise from `/admin` stops, so new reports point at real gaps again.
- Templates keep computed widths and colours, and no JavaScript is needed to apply them.

**Negative:**

- CSS injection through a style attribute is not blocked by the policy.
- Browsers without CSP Level 3 ignore `style-src-attr` and fall back to `style-src`: there, attributes on `/admin` stay
  blocked under an enforced policy. Current browsers support the directive.
- One header directive lives outside the Nelmio configuration until Nelmio supports it.
