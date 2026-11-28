# Content Security Policy

Related: [observability-sentry.md](observability-sentry.md) (Sentry SDK), [deployment.md](deployment.md) (pre-deploy checks)

Production sends an **enforced Content-Security-Policy** via [NelmioSecurityBundle](https://symfony.com/bundles/NelmioSecurityBundle) (since #580; report-only before). The browser blocks what the policy does not allow and, when `SENTRY_CSP_REPORT_URI` is set, reports the violation to Sentry.

## When CSP is active

| Environment | CSP header | Sentry reporting |
|-------------|------------|------------------|
| `dev` / `test` | No | No |
| `prod`, `SENTRY_CSP_REPORT_URI` empty | Yes (`Content-Security-Policy`) | DevTools only |
| `prod`, `SENTRY_CSP_REPORT_URI` set | Yes, includes `report-uri` | Browser POSTs to Sentry **Issues** |

## Configuration

| Piece | Location |
|-------|----------|
| Policy directives | [`config/packages/nelmio_security.yaml`](../../config/packages/nelmio_security.yaml) (`when@prod` → `csp.enforce`) |
| Optional `report-uri` | Same file, `report-uri: ['%env(trim:SENTRY_CSP_REPORT_URI)%']`. Read at runtime, so a changed value needs no cache clear; when it is empty, Nelmio leaves the directive out |
| Env default | [`config/services.yaml`](../../config/services.yaml) — `env(SENTRY_CSP_REPORT_URI): ''` |
| Example env | [`.env.example`](../../.env.example) |

Clickjacking protection (`X-Frame-Options: DENY`) stays in the same Nelmio file and is independent of CSP.

### Environment variable

`SENTRY_CSP_REPORT_URI` — optional. Copy the **Report URI** from Sentry (**Project Settings → Security Headers → Content Security Policy**) or build it from `SENTRY_DSN`:

```
DSN:         https://{PUBLIC_KEY}@o{ORG}.ingest[.region].sentry.io/{PROJECT_ID}
Report URI:  https://o{ORG}.ingest[.region].sentry.io/api/{PROJECT_ID}/security/?sentry_key={PUBLIC_KEY}
```

Set in `shared/.env.local` on the server when you want violations in Sentry.

## Current policy

| Directive | Value | Notes |
|-----------|-------|-------|
| `default-src` | `'self'` | Baseline |
| `base-uri` | `'self'` | |
| `object-src` | `'none'` | |
| `script-src` | `'self'`, `'unsafe-inline'`, `data:` | Legacy inline scripts; AssetMapper CSS importmap (`data:application/javascript,`); `es-module-shims` is self-hosted (do not allow `ga.jspm.io`) |
| `style-src` | `'self'`, `'unsafe-inline'` | Twig `style=""`, inline `<style>` |
| `style-src-attr` | `'unsafe-inline'` | Inline `style=""` attributes, an accepted exception ([ADR 047](../02-architecture/decisions/047-inline-style-attributes-allowed-by-csp.md)). Appended by `StyleAttributePolicyListener`, because Nelmio 3.9 does not know the directive |
| `img-src` | `'self'`, `data:`, `https://*.tile.openstreetmap.org` | Favicon, Leaflet |
| `font-src` | `'self'`, `data:` | |
| `connect-src` | `'self'`, `https://*.tile.openstreetmap.org`, `https://*.ingest.sentry.io` | Fetch/XHR, OSM tiles, CSP report POST to Sentry |
| `form-action` | `'self'` | |
| `frame-ancestors` | `'none'` | No embedding in frames (#580) |

`frame-ancestors 'none'` forbids embedding the application in frames; `X-Frame-Options: DENY` stays as the fallback for older browsers.

## Verification

### Response header

```bash
curl -sI https://<APP_URL>/statistics/ | grep -i content-security-policy
```

Expect `Content-Security-Policy` with `default-src 'self'`. When `SENTRY_CSP_REPORT_URI` is set, the header should also contain `report-uri https://o….ingest….sentry.io/...`.

### Manual violation (browser console)

On any prod page, open DevTools → **Console** and run:

```javascript
const s = document.createElement('script');
s.src = 'https://cdn.jsdelivr.net/npm/lodash@4/lodash.min.js';
document.body.appendChild(s);
```

Expect `Refused to load …` — the script is blocked; the page itself keeps working.

With Sentry configured, check **Issues** (not Logs) within a few minutes. In **Network**, look for a POST to `…ingest….sentry.io/…/security/`.

### Automated test

- [`bin/check-prod-csp`](../../bin/check-prod-csp) checks the prod header, with `SENTRY_CSP_REPORT_URI` empty and set. CI runs it in the **Prod kernel smoke** job; locally: `APP_ENV=prod APP_DEBUG=0 php bin/check-prod-csp`.
- [`tests/Shared/Functional/Security/ContentSecurityPolicyTest.php`](../../tests/Shared/Functional/Security/ContentSecurityPolicyTest.php) asserts that the test environment sends no CSP header and smoke-loads `/explore/allocation` and `/statistics/`.

## Sentry integration

CSP violations are **not** PHP exceptions. They appear under **Issues**, often titled with the violated directive and `blocked_uri` (for example `script-src-elem` / `cdn.jsdelivr.net`).

| Channel | CSP violations? |
|---------|-------------------|
| **Issues** | Yes |
| **Logs** | No (unless you log them yourself) |
| **Performance** | No |

The browser sends reports only when:

1. `SENTRY_CSP_REPORT_URI` is set (adds `report-uri` to the header), and
2. `connect-src` allows `https://*.ingest.sentry.io` (already in the policy).

See [observability-sentry.md](observability-sentry.md) for general Sentry setup (`SENTRY_DSN`, environment, traces).

## Browser console messages

| Message | Cause | Action |
|---------|-------|--------|
| `does not specify a report-to` | No `SENTRY_CSP_REPORT_URI` | Set URI for Sentry, or ignore (DevTools reporting still works) |
| Report not in Sentry | Missing URI or blocked POST | Set URI; confirm `connect-src` includes `*.ingest.sentry.io` |
| `Refused to load …` | Blocked by the policy | Check whether a feature broke; see triage below |

## Triage checklist (beta)

A CSP issue is **not** automatically a security incident. Classify before escalating.

### Quick decision

```
New CSP issue in Sentry
        │
        ▼
Known test / deploy / single event from you?
   YES → Resolve, add note ("manual test", etc.)
   NO
        ▼
blocked_uri known and expected in your stack?
   YES → Policy backlog (P2) or false positive
   NO
        ▼
Multiple users / many events / unknown domain?
   YES → Investigate (see Escalate)
   NO → Watch 24–48h
```

### Ignore / low priority

| Pattern | Example | Why |
|---------|---------|-----|
| Your own test | `cdn.jsdelivr.net`, `example.com` | Manual console test |
| Browser extension | `chrome-extension://`, `moz-extension://` | Not your application |
| Single event, single user, you were testing | lodash script test | Reproducible |
| Import-map polyfill in ancient UAs | `blocked_uri: blob:` with source `es-module-shims` | Chrome without import maps (for example Chrome 79) creates blob workers; the polyfill is self-hosted. Do not add `blob:` to `script-src`. |

**Action:** Resolve the issue; comment with the reason.

### EasyAdmin and nonces

EasyAdmin calls Nelmio’s `csp_nonce()` and adds `'nonce-…'` to `script-src` / `style-src` on `/admin` responses. Under **CSP Level 3**, that **disables `'unsafe-inline'`** for those directives — even though the YAML policy still lists it. Inline event handlers (`onsubmit`), bare `<script>` / `style=""` attributes, and vendor-injected `<style>` without a matching nonce are therefore blocked on admin pages.

Style attributes are not affected: `style-src-attr 'unsafe-inline'` allows them on every page, also with a nonce in `style-src` ([ADR 047](../02-architecture/decisions/047-inline-style-attributes-allowed-by-csp.md)). Before it was added (#580), they caused nearly all reports (`style-src-attr`, `blocked_uri: inline`).

Mitigations already in place for the known Failed Messages / dashboard KPI noise:

- Confirm dialogs and select-all → Stimulus (`confirm-submit`, `checkbox-select-all`)
- Inline layout styles → CSS classes in `admin-kpi.css`
- ApexCharts injected styles → `chart.nonce` from `<meta name="csp-nonce">` via `applyChartNonce()` in `assets/lib/load-apexcharts.js`

Further admin surfaces (media, import reject, feedback context, audit timeline):

- Media snippet copy → Stimulus (`copy-to-clipboard`)
- Media upload filename preview → Stimulus (`file-input-preview`) instead of EasyAdmin `onchange`
- Import Reject / Feedback JSON → `<pre class="ea-json-pre">` (no CodeMirror `CodeEditorField`)
- Audit timeline: details load through the Stimulus controller `timeline-details`, the new-entries notice through `audit-new-entries`; JSON max-height and layout are CSS classes in `admin-kpi.css` (`.timeline-*`, `.ea-audit-*`)

Resolve matching Sentry CSP issues as `policy-gap` after deploy if they stop recurring.

### Policy gap (fix later, not an attack)

| Pattern | Example | Action |
|---------|---------|--------|
| Known CDN you plan to allow | New analytics or font host | Add to CSP in P2 or refactor to `'self'` |
| Same route after a feature deploy | New external asset on one page | Extend policy or self-host |
| OpenStreetMap variant | Unexpected tile subdomain | Adjust `img-src` / `connect-src` if legitimate |
| Other tile host | `MAP_TILE_URL` points to another provider | Add that host to `img-src` / `connect-src` |
| EasyAdmin + nonce (see above) | `script-src-attr` / `style-src-elem` on `/admin/…` with `blocked_uri: inline` | Prefer Stimulus / CSS / vendor nonce; do not “fix” by removing EasyAdmin nonces |

**Action:** Open a P2 ticket; resolve the issue if it is understood backlog.

### Watch (24–48 hours)

| Pattern | Example |
|---------|---------|
| Few events, few users | Same `blocked_uri` on one route |
| Admin-only routes | `/admin/…` after a template change |

**Action:** Leave open briefly; resolve if it does not repeat.

### Escalate (security concern)

| Pattern | Example | Why |
|---------|---------|-----|
| Unknown domain | Random TLDs, raw IPs | Not in your stack |
| `javascript:` or suspicious `data:` in `blocked_uri` | Injection vectors | |
| Many users or rapid spike | 50+ events in minutes | Possible active abuse |
| Suspicious `document_uri` | Query string with `%3Cscript%3E`, encoded payloads | Reflected XSS attempt |
| Unknown script on core routes | `/login`, `/import`, `/explore` | Targeted |

**Action:** Do not resolve immediately. Capture `document_uri`, `blocked_uri`, time, and user (if visible). Check whether the URI appears in templates, user content, or logs. Treat as a security review if the pattern persists.

### Report fields

| Field | Question |
|-------|----------|
| `document_uri` | Which page/route? Suspicious query? |
| `blocked_uri` | What was loaded? Known CDN or unknown? |
| `violated_directive` | `script-src-elem` = external script; `connect-src` = fetch; `img-src` = image |
| `original_policy` | Matches deployed config? |
| Event count | One-off vs wave? |

### Known-good sources (this project)

These should align with the current policy:

- Scripts/styles: `'self'`, `'unsafe-inline'`, `data:` (AssetMapper)
- Images: `'self'`, `data:`, `*.tile.openstreetmap.org`
- Connect: `'self'`, OSM tiles, `*.ingest.sentry.io`
- Import-map polyfill: `es-module-shims` from `'self'` via [`importmap.php`](../../importmap.php). AssetMapper falls back to `https://ga.jspm.io/…` only when that package is missing — a `ga.jspm.io` report after deploy is a regression, not a CDN to allow.

Reports outside these patterns on your own pages deserve a closer look.

### Sentry workflow (beta)

| Step | Recommendation |
|------|----------------|
| New CSP issue | Label `csp` or `security` if you use labels |
| After triage | `test`, `extension`, `policy-gap`, or `investigate` |
| Manual tests | Resolve with note |
| Real suspicion | Assign owner |

> **CSP issue ≠ hack.** Clarify the source first: test, extension, policy gap, or genuine concern. Escalate mainly on unknown `blocked_uri`, many users/events, or suspicious URLs.

## Audit (SEC-014)

Pre-beta security review **accepted** Report-Only CSP with `'unsafe-inline'` for the beta period. The exit criteria (report URI set in production, triage rhythm, low noise) were met in November 2026: over 90 days the only report was the style-attribute noise on `/admin`, resolved by [ADR 047](../02-architecture/decisions/047-inline-style-attributes-allowed-by-csp.md). The policy has been enforced since #580, with the accepted exceptions below.

**Accepted exceptions:**

- `style-src 'unsafe-inline'` outside `/admin` and `style-src-attr 'unsafe-inline'` everywhere ([ADR 047](../02-architecture/decisions/047-inline-style-attributes-allowed-by-csp.md)).
- `script-src 'unsafe-inline'` remains only as a fallback for browsers without nonce support; pages with a nonce ignore it.
- `script-src data:` for the AssetMapper CSS importmap.

## Inline scripts (#580)

The application has no inline event handlers and no `javascript:` URLs; use Stimulus instead (`click-proxy` to click another element, `confirm-submit` for confirm dialogs, `autosubmit` to submit a form on change). The few inline `<script>` elements carry a nonce: `<script nonce="{{ csp_nonce('script') }}">`, and `importmap()` gets one with `importmap('app', {nonce: csp_nonce('script')})`. Once a page asks for a nonce, Nelmio adds it to `script-src`, and browsers with CSP Level 2 or later ignore `'unsafe-inline'` for scripts on that page; it stays in the policy only as a fallback for older browsers. JSON data blocks (`<script type="application/json">`) are not executed and need no nonce. The downloaded data preparation tool is a standalone file with its own `<meta>` policy and keeps its plain inline scripts.

## Possible next steps

- Replace `data:` in `script-src` by `strict-dynamic` and nonces (AssetMapper).
- Move static `style=""` attributes and inline `<style>` to CSS when templates are touched, then drop `style-src 'unsafe-inline'`.

## Further reading

- [deployment.md](deployment.md#pre-deploy-verification) — pre-deploy CSP header check
- [configuration.md](../06-reference/configuration.md) — `SENTRY_CSP_REPORT_URI`
- [observability-sentry.md](observability-sentry.md) — errors, logs, traces, uptime
