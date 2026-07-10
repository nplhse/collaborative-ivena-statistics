# Content Security Policy (report-only)

Related: [observability-sentry.md](observability-sentry.md) (Sentry SDK), [deployment.md](deployment.md) (pre-deploy checks)

Production uses **Content-Security-Policy-Report-Only** via [NelmioSecurityBundle](https://symfony.com/bundles/NelmioSecurityBundle). The browser reports violations but **does not block** resources. This prepares beta monitoring before a future enforce phase (P2).

## When CSP is active

| Environment | CSP header | Sentry reporting |
|-------------|------------|------------------|
| `dev` / `test` | No | No |
| `prod`, `SENTRY_CSP_REPORT_URI` empty | Yes (`Content-Security-Policy-Report-Only`) | DevTools only |
| `prod`, `SENTRY_CSP_REPORT_URI` set | Yes, includes `report-uri` | Browser POSTs to Sentry **Issues** |

## Configuration

| Piece | Location |
|-------|----------|
| Policy directives | [`config/packages/nelmio_security.yaml`](../../config/packages/nelmio_security.yaml) (`when@prod` → `csp.report`) |
| Optional `report-uri` | [`config/packages/nelmio_security_csp_report_uri.php`](../../config/packages/nelmio_security_csp_report_uri.php) (only when `SENTRY_CSP_REPORT_URI` is set) |
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

## Current policy (report-only)

| Directive | Value | Notes |
|-----------|-------|-------|
| `default-src` | `'self'` | Baseline |
| `base-uri` | `'self'` | |
| `object-src` | `'none'` | |
| `script-src` | `'self'`, `'unsafe-inline'`, `data:` | Legacy inline scripts; AssetMapper CSS importmap (`data:application/javascript,`) |
| `style-src` | `'self'`, `'unsafe-inline'` | Twig `style=""`, inline `<style>` |
| `img-src` | `'self'`, `data:`, `https://*.tile.openstreetmap.org` | Favicon, Leaflet |
| `font-src` | `'self'`, `data:` | |
| `connect-src` | `'self'`, `https://*.tile.openstreetmap.org`, `https://*.ingest.sentry.io` | Fetch/XHR, OSM tiles, CSP report POST to Sentry |
| `form-action` | `'self'` | |

`frame-ancestors` is intentionally omitted from report-only (browsers ignore it there). Use `X-Frame-Options: DENY` for clickjacking instead.

## Verification

### Response header

```bash
curl -sI https://<APP_URL>/statistics/ | grep -i content-security-policy
```

Expect `Content-Security-Policy-Report-Only` with `default-src 'self'`. When `SENTRY_CSP_REPORT_URI` is set, the header should also contain `report-uri https://o….ingest….sentry.io/...`.

### Manual violation (browser console)

On any prod page, open DevTools → **Console** and run:

```javascript
const s = document.createElement('script');
s.src = 'https://cdn.jsdelivr.net/npm/lodash@4/lodash.min.js';
document.body.appendChild(s);
```

Expect `[Report Only] Refused to load …` — the page keeps working.

With Sentry configured, check **Issues** (not Logs) within a few minutes. In **Network**, look for a POST to `…ingest….sentry.io/…/security/`.

### Automated test

[`tests/Shared/Functional/Security/ContentSecurityPolicyTest.php`](../../tests/Shared/Functional/Security/ContentSecurityPolicyTest.php) asserts the prod header and smoke-loads `/explore/allocation` and `/statistics/`.

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
| `[Report Only] Refused to load …` | Expected on policy mismatch | Normal in report-only; see triage below |

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

**Action:** Resolve the issue; comment with the reason.

### Policy gap (fix later, not an attack)

| Pattern | Example | Action |
|---------|---------|--------|
| Known CDN you plan to allow | New analytics or font host | Add to CSP in P2 or refactor to `'self'` |
| Same route after a feature deploy | New external asset on one page | Extend policy or self-host |
| OpenStreetMap variant | Unexpected tile subdomain | Adjust `img-src` / `connect-src` if legitimate |

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

Reports outside these patterns on your own pages deserve a closer look.

### Sentry workflow (beta)

| Step | Recommendation |
|------|----------------|
| New CSP issue | Label `csp` or `security` if you use labels |
| After triage | `test`, `extension`, `policy-gap`, or `investigate` |
| Manual tests | Resolve with note |
| Real suspicion | Assign owner; do not only rely on report-only long term |

> **CSP issue ≠ hack.** Clarify the source first: test, extension, policy gap, or genuine concern. Escalate mainly on unknown `blocked_uri`, many users/events, or suspicious URLs.

## P2 roadmap (enforce)

Not in scope for the initial report-only rollout:

- Remove `'unsafe-inline'` where possible (nonces, Stimulus, extracted CSS)
- Inline event handlers (`onclick`, `onsubmit`) → Stimulus
- AssetMapper: consider `strict-dynamic` + nonces instead of `data:` in `script-src`
- Add `enforce` block in Nelmio (keep `report` for monitoring)
- Re-add `frame-ancestors 'none'` under enforce (with `X-Frame-Options` as fallback)

## Further reading

- [deployment.md](deployment.md#pre-deploy-verification) — pre-deploy CSP header check
- [configuration.md](../06-reference/configuration.md) — `SENTRY_CSP_REPORT_URI`
- [observability-sentry.md](observability-sentry.md) — errors, logs, traces, uptime
