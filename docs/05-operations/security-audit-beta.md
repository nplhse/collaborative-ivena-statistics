# Pre-beta security audit

Related: [Issue #257](https://github.com/nplhse/collaborative-ivena-statistics/issues/257), [permission-model.md](../02-architecture/permission-model.md), [content-security-policy.md](content-security-policy.md), [health-check.md](health-check.md), [audit-log-maintenance.md](audit-log-maintenance.md)

| Field | Value |
|-------|-------|
| Date | 2026-07-28 |
| Scope | Full application (backend + Twig/Stimulus UI), read-only review |
| Method | Manual code review against issue checklist; existing security tests used as coverage checks, not as substitutes |
| Out of scope | Code fixes, RateLimiter implementation, CSP enforce, follow-up GitHub issues |

## Verdict

**Conditional go for beta** — no Critical findings. AuthN/AuthZ foundations (firewalls, hospital grants, export scoping, import upload guards, admin impersonation) are solid. Several **Medium** items should be tracked before or early in beta; they do not individually block a limited beta audience if product accepts the collaborative Explore data model and monitors the Medium backlog.

Top risks to track:

1. ~~Registration account enumeration via duplicate username/email (error path).~~ **Resolved** (SEC-001 — generic validation).
2. ~~Blog index preview renders unsanitized HTML (`|raw`) while show uses `PostContentSanitizer`.~~ **Resolved** (SEC-002 — sanitized list preview).
3. ~~CSV exports without formula neutralization (Excel formula injection).~~ **Resolved** (SEC-003 — `CsvFormulaEscaper`).
4. ~~Live Component mount path `/_components` outside `access_control`.~~ **Resolved** (SEC-004 — `ROLE_USER` path gate).
5. ~~Cross-hospital Explore visibility for all participants.~~ **Accepted** (SEC-005 — collaborative by design; `ROLE_PARTICIPANT` required).

---

## Inventory

### Firewall and `access_control`

Source: [`config/packages/security.yaml`](../../config/packages/security.yaml)

| Path | Role |
|------|------|
| `^/health` | `PUBLIC_ACCESS` |
| `^/_error_preview`, `^/_error/` | `PUBLIC_ACCESS` |
| `^/admin` | `ROLE_ADMIN` |
| `^/hospitals` | `ROLE_PARTICIPANT` |
| `^/explore/...`, `^/explore` | `ROLE_PARTICIPANT` |
| `^/statistics` | `ROLE_USER` |
| `^/_components` | `ROLE_USER` |
| `^/login/confirm` | `IS_AUTHENTICATED_REMEMBERED` |

**Not listed** (controller attributes only): `/import`, `/settings`, public content (`/`, `/blog`, `/register`, `/feedback`, …).

Firewall `main`: form login + confirm-password authenticators, `UserAccountStatusChecker`, login throttling **5 / 15 min**, remember-me 7 days, `switch_user` for `ROLE_ADMIN`, `expose_security_errors: none`.

Role hierarchy: `ROLE_ADMIN` → `ROLE_PARTICIPANT`, `ROLE_REVIEW_INDICATIONS`.

### Voters

| Voter | Attributes | Notes |
|-------|------------|-------|
| `HospitalVoter` | `ACCESS`, `EDIT`, `MANAGE_ACCESS_GRANTS` | Hospital-scoped |
| `AllocationVoter` | `VIEW` | Any `ROLE_PARTICIPANT`; **no hospital scope** |
| `ImportVoter` | `VIEW`, `DELETE`, `DOWNLOAD_SOURCE` | Hospital + creator/admin rules |
| `ExportVoter` | `EXPORT` | Via `ExportAccessService` |
| `IndicationRawReviewVoter` | `VIEW`, `EDIT_MATCH`, `REVIEW` | Participant + review roles |

### Rate limiters

Source: [`config/packages/rate_limiter.yaml`](../../config/packages/rate_limiter.yaml)

| Limiter | Policy | Protected surface |
|---------|--------|-------------------|
| Firewall `login_throttling` | 5 / 15 min | Login |
| `verify_email_resend` | 3 / 15 min | Settings resend |
| `explore_list` | 100 / 10 min | Exact paths: `/explore/allocation`, `/explore/mci_case`, `/explore/hospital`, `/explore/assignment` |
| `feedback_submit` (+ anon IP/email) | 5 / 1 h (+ 4 / 3) | `POST /feedback` |
| `transactional_mail` | 1 / 3 s | Messenger mail |

**No app RateLimiter** on: register, reset-password (form IP), import, allocations export, analysis explorer export, remaining explore lists, statistics pages.

### Public / anonymous surfaces

`/`, `/login`, `/register*`, `/verify/email`, `/reset-password*`, `/blog*`, CMS `/{path}`, `/sitemap*`, `/robots.txt`, `POST /feedback`, cookie/locale helpers, `/health`, `/_error*`.

### Security tests (coverage snapshot)

Well covered: admin access, import functional access, explore/statistics access, hospital grants, impersonation guards, login/resend rate limits, CSP headers, voter unit tests for hospital/import/indication.

Gaps: Live Component denial paths, registration enumeration, import source-download audit persistence, explore rate-limit paths beyond `/explore/allocation`.

CI: [`.github/workflows/security.yml`](../../.github/workflows/security.yml) (Psalm security analysis).

---

## Findings

Severity: **Critical** > **High** > **Medium** > **Low** > **Info**. Status remains `open` until remediated in follow-up work.

### SEC-001 — Registration username/email enumeration

| Field | Value |
|-------|-------|
| Severity | Medium |
| Area | AuthN / User management |
| Evidence | [`RegistrationController.php`](../../src/User/UI/Http/Controller/RegistrationController.php) (flush on persist); `User` unique constraints without form-level `UniqueEntity`; duplicate → DB exception / error page vs success → check-email |
| Risk | Attacker can probe whether a username or email is already registered. |
| Mitigation | Add `UniqueEntity` (or equivalent) with a generic validation message **or** always take the check-email path on collision without leaking existence; add functional tests for response parity. |
| Status | resolved (2026-07-28) — generic root FormError via `RegistrationIdentityChecker` (no Silent Success; UX); race fallback on `UniqueConstraintViolationException`; field-level leak avoided |

### SEC-002 — Blog index preview uses unsanitized `\|raw`

| Field | Value |
|-------|-------|
| Severity | Medium |
| Area | XSS / Content |
| Evidence | [`blog/index.html.twig`](../../src/Content/UI/Twig/templates/blog/index.html.twig) L55–56; show path sanitizes via [`PostContentSanitizer`](../../src/Content/Application/Blog/PostContentSanitizer.php) in [`BlogController`](../../src/Content/UI/Http/Controller/BlogController.php) |
| Risk | Stored XSS on public blog listing if admin-authored HTML bypasses intended sanitizer (or sanitizer config drifts). Show is safe; index is inconsistent. |
| Mitigation | Sanitize preview in controller (same sanitizer as show) or stop using `\|raw` on index (e.g. `striptags` + truncate only). |
| Status | resolved (2026-07-28) — `PostContentSanitizer::preview()` builds list snippets; index/category/tag pass `previews` map (no unsanitized Twig `|raw` on `post.content`) |

### SEC-003 — CSV exports lack formula neutralization

| Field | Value |
|-------|-------|
| Severity | Medium |
| Area | Export |
| Evidence | [`CsvStreamExportResponseFactory::writeRow`](../../src/Shared/Application/Export/CsvStreamExportResponseFactory.php) L44–57; analogous tabular exporter for Analysis Explorer — cells written via `fputcsv` without escaping leading `=`, `+`, `-`, `@`, tab |
| Risk | Imported free-text fields opened in Excel/LibreOffice can execute formulas (CSV injection) on the analyst’s machine. |
| Mitigation | Prefix risky cells with `'` (or neutralize) in shared CSV writers before `fputcsv`. |
| Status | resolved (2026-07-28) — `CsvFormulaEscaper` prefixes dangerous string cells in stream/tabular/reject CSV writers; numeric cells unchanged |

### SEC-004 — Live Component route `/_components` not in `access_control`

| Field | Value |
|-------|-------|
| Severity | Medium |
| Area | AuthZ / AJAX |
| Evidence | [`config/routes/ux_live_component.yaml`](../../config/routes/ux_live_component.yaml) prefix `/_components`; not matched by `^/statistics` / `^/explore`; [`AnalysisExplorerShell`](../../src/Statistics/AnalysisExplorer/UI/LiveComponent/AnalysisExplorerShell.php) calls `requireParticipant()` only on `save` / `submitSaveAs` |
| Risk | Component endpoints are reachable without path-level auth; relies on per-action checks and LiveProp integrity. Persist paths are guarded; preview/rerun actions are weaker. |
| Mitigation | Add `access_control` for `^/_components` (e.g. `IS_AUTHENTICATED_REMEMBERED` or `ROLE_USER`); require participant (or ownership) on mutating/analysis LiveActions; add denial tests for guest / non-participant. |
| Status | resolved (2026-07-28) — `^/_components` → `ROLE_USER` in `access_control`; `#[IsGranted('ROLE_USER')]` on both Live Components; persist still requires participant; guest denial tests added |

### SEC-005 — Cross-hospital Explore allocation visibility

| Field | Value |
|-------|-------|
| Severity | Medium (or accepted risk — product decision) |
| Area | AuthZ / Privacy |
| Evidence | [`AllocationVoter`](../../src/Allocation/Infrastructure/Security/Voter/AllocationVoter.php) L14–15, L36–44 — any `ROLE_USER` may `VIEW`; `/explore` gated to `ROLE_PARTICIPANT` in `security.yaml`; default allocation list is unscoped across hospitals |
| Risk | Every participant can read allocation detail (incl. hospital identity and clinical fields) for other centers. Fits a collaborative model; conflicts with strict tenant isolation. |
| Mitigation | **If collaborative-by-design:** document as accepted risk in permission model + onboarding. **If isolation required:** bind `VIEW` to `HospitalPermission::View` and default list scope to accessible hospitals. |
| Notes | Pure `ROLE_USER` cannot reach `/explore` (403). Firewall is the effective gate; voter alone would be weaker. |
| Status | accepted (2026-07-28) — collaborative by design ([ADR 011](../02-architecture/decisions/011-collaborative-explore-allocation-visibility.md)); `AllocationVoter` requires `ROLE_PARTICIPANT` (hierarchy-aware); not hospital-grant-scoped |

### SEC-006 — Incomplete Explore list rate limiting

| Field | Value |
|-------|-------|
| Severity | Low–Medium |
| Area | RateLimit / Scraping |
| Evidence | [`ExploreListRateLimitSubscriber`](../../src/Allocation/Infrastructure/Http/ExploreListRateLimitSubscriber.php) exact match on four paths only; other lists (`indication`, `infection`, `occasion`, `speciality`, `department`, `dispatch_area`, `secondary_transport`, …) unlimited |
| Risk | Authenticated participants can scrape unbounded Explore lists. |
| Mitigation | Include all Explore list routes (prefix or explicit allowlist); keep ~100 / 10 min per user+IP as baseline. |
| Status | resolved (2026-07-28) — all GET under `/explore` share `explore_list` (100 / 10 min per user+IP); subscriber runs after firewall; test env uses a high limit so suites are not poisoned |

### SEC-007 — No RateLimiter on register / reset-password / import / export

| Field | Value |
|-------|-------|
| Severity | Medium (register); Low–Medium (others) |
| Area | RateLimit |
| Evidence | Limiter config has no register/export/import keys; reset-password has bundle per-user throttle only |
| Risk | Registration spam / mail flood; export DoS (CPU/IO); reset-password cross-account mail flooding via IP. Login already throttled. |
| Mitigation | IP (+ optional email) limiters: e.g. register 5/h, reset-password 10/h per IP, allocations export 10/10 min, explorer export 20/10 min, import upload 30/h. |
| Status | resolved (2026-07-28) — `register`, `reset_password_request`, `import_create` (30/h), `allocations_export`, `analysis_explorer_export` limiters via `ClientRateLimit`; reset reject keeps check-email UX |

### SEC-008 — Import source download audit intent does not persist

| Field | Value |
|-------|-------|
| Severity | Medium |
| Area | Auditability |
| Evidence | [`DownloadImportSourceFileController`](../../src/Import/UI/Http/Controller/DownloadImportSourceFileController.php) L37–39 — `beginIntent('import.source_file.downloaded')` with no entity mutation; Doctrine audit subscriber only writes on audited entity changes; unlike [`ExportAuditLogger`](../../src/Shared/Application/Export/ExportAuditLogger.php) / impersonation |
| Risk | Admin downloads of original import files leave no durable audit row. |
| Mitigation | Persist an explicit `AuditEntry` (same pattern as export/impersonation) including actor, import id, IP. |
| Status | resolved (2026-07-28) — `ImportSourceDownloadAuditLogger` persists `download` AuditEntry after successful source-file delivery; 404 does not audit |

### SEC-009 — `ImportFileStorage::resolve` lacks path containment

| Field | Value |
|-------|-------|
| Severity | Low–Medium |
| Area | Import / Files |
| Evidence | [`ImportFileStorage::resolve`](../../src/Import/Application/Service/ImportFileStorage.php) L23–29 — absolute paths returned as-is; relative paths joined to project dir without asserting prefix under `var/imports` |
| Risk | If `file_path` in DB is tampered (compromised admin DB write / SQL injection elsewhere), download or worker can read arbitrary files. Normal upload path uses generated relative names only. |
| Mitigation | After resolve: `realpath` + assert path is under configured imports base dir; reject absolute / `..` paths. |
| Status | resolved (2026-07-28) — `ImportFileStorage::resolve` asserts canonicalized paths under `%app.imports_base_dir%`; absolute/`..` escapes throw `ImportFilePathOutsideBaseException` (download → 404; worker → failed) |

### SEC-010 — Favorite toggle open redirect via Referer

| Field | Value |
|-------|-------|
| Severity | Low–Medium |
| Area | Open redirect |
| Evidence | [`SavedExplorerViewFavoriteController`](../../src/Statistics/AnalysisExplorer/UI/Http/Controller/SavedExplorerViewFavoriteController.php) L52–54 — `redirect($referer)` without host/path validation; CSRF required |
| Risk | CSRF-protected but still redirects to attacker-controlled Referer after a valid POST (phishing / token fixation UX). Inconsistent with Feedback/Locale safe-target helpers. |
| Mitigation | Reuse safe local-path / same-host resolver; fallback to library route. |
| Status | resolved (2026-07-28) — Favorite toggle uses shared `SafeRedirectTargetResolver` (local path or same-host); unsafe/empty Referer falls back to `app_stats_analysis_library` |

### SEC-011 — `/import` missing from `access_control`

| Field | Value |
|-------|-------|
| Severity | Low |
| Area | AuthZ / Defense-in-depth |
| Evidence | `security.yaml` has no `^/import`; all Import controllers use `#[IsGranted('ROLE_PARTICIPANT')]` (+ voters) |
| Risk | A new import route without attributes would be anonymous by default. |
| Mitigation | Add `- { path: ^/import, roles: ROLE_PARTICIPANT }` (source download remains `ROLE_ADMIN` via attribute). |
| Status | resolved (2026-07-29) — `access_control` requires `ROLE_PARTICIPANT` for `^/import`; controller attributes/voters unchanged (source download still `ROLE_ADMIN`) |

### SEC-012 — Public `/health` discloses version and failed-queue count

| Field | Value |
|-------|-------|
| Severity | Low |
| Area | Information disclosure |
| Evidence | [`HealthCheckReport::toArray`](../../src/Shared/Application/Health/HealthCheckReport.php); public via `access_control` |
| Risk | Attackers learn app version and whether the failed messenger queue is non-empty. Useful for uptime monitoring by design. |
| Mitigation | Slim public payload to `status` (+ optional `database`); put detailed checks behind auth or internal network. Document accepted disclosure if kept for Sentry Uptime. |
| Status | resolved (2026-07-29) — public `GET /health` uses `toPublicArray()` (`status` + `checks.database` only); version/messenger details remain on full report for Admin ops |

### SEC-013 — Media files publicly served; directory listing not hardened in-app

| Field | Value |
|-------|-------|
| Severity | Low |
| Area | Files / Media |
| Evidence | `vich_uploader` → `public/uploads/media`; Admin-only upload with MIME allowlist; `.htaccess` disables PHP execution |
| Risk | Public URLs by design; if server enables directory indexes, filenames may be listable (unique namer mitigates guessing). |
| Mitigation | Ensure `Options -Indexes` / `autoindex off` in deploy docs; optional signed URLs if media must not be public. |
| Status | resolved (2026-07-29) — `Options -Indexes` in `public/.htaccess` and `public/uploads/media/.htaccess`; deploy docs cover shared `.htaccess` and nginx `autoindex off` |

### SEC-014 — CSP remains Report-Only with `unsafe-inline`

| Field | Value |
|-------|-------|
| Severity | Low / Info (planned) |
| Area | Headers |
| Evidence | [`nelmio_security.yaml`](../../config/packages/nelmio_security.yaml) `when@prod` CSP `report`; [`content-security-policy.md`](content-security-policy.md) P2 enforce roadmap |
| Risk | CSP does not block XSS; only reports. Inline scripts/styles allowed. |
| Mitigation | Follow existing CSP beta triage → enforce when violation noise is low; reduce `unsafe-inline`. |
| Status | accepted (beta, 2026-07-29) — Report-Only + `unsafe-inline` intentional for monitoring; ops: [content-security-policy.md](content-security-policy.md) triage + enforce roadmap; Enforce is a separate follow-up when violation noise is low |

### SEC-015 — Session cookie settings rely on Symfony defaults

| Field | Value |
|-------|-------|
| Severity | Low |
| Area | Session hardening |
| Evidence | [`framework.yaml`](../../config/packages/framework.yaml) — explicit `cookie_httponly`, `cookie_samesite: lax`, `cookie_secure: auto` (prod override `cookie_secure: true`); Nelmio forced SSL + HSTS in prod |
| Risk | Defaults (`secure: auto`, `httponly: true`, `samesite: lax`) are acceptable behind HTTPS; not explicitly pinned for ops review. |
| Mitigation | Set explicit prod values (`cookie_secure: true`, document SameSite choice). |
| Status | resolved (2026-07-29) — explicit session cookie attrs in `framework.yaml`; prod pins `cookie_secure: true`; `SameSite=Lax` documented in [deployment.md](deployment.md) pre-deploy checklist |

### SEC-016 — Login failure log may miss username hash for form field name

| Field | Value |
|-------|-------|
| Severity | Low |
| Area | Logging |
| Evidence | [`LoginFailureSubscriber`](../../src/User/Infrastructure/Security/LoginFailureSubscriber.php) resolves `_username`, then `login[username]`, then Passport `UserBadge` |
| Risk | Some failure paths may log `username_hash: null`, reducing forensic value. |
| Mitigation | Also read `login[username]` from the request. |
| Status | resolved (2026-07-29) — `resolveAttemptedUsername()` reads form field `login[username]` before UserBadge fallback; unit tests cover early-failure paths without Passport |

### SEC-017 — Permission model documentation drift

| Field | Value |
|-------|-------|
| Severity | Info |
| Area | Docs |
| Evidence | [`permission-model.md`](../02-architecture/permission-model.md) ImportVoter table omitted `DOWNLOAD_SOURCE` while code already enforced it in [`ImportVoter`](../../src/Import/Infrastructure/Security/Voter/ImportVoter.php) |
| Risk | Maintainers mis-implement access checks. |
| Mitigation | Update voter table + document Explore collaboration / accepted risk (SEC-005). |
| Status | resolved (2026-07-29) — `permission-model.md` voter table now includes `ImportVoter::DOWNLOAD_SOURCE`; Explore collaboration remains documented via SEC-005 / ADR 011 |

### SEC-018 — Messenger import handlers trust queue without permission re-check

| Field | Value |
|-------|-------|
| Severity | Info / accepted with hardening notes |
| Area | Import / Async |
| Evidence | Worker re-check now validates `Import->createdBy` against current `HospitalPermission::Import` for the import hospital in [`ImportAllocationsMessageHandler`](../../src/Import/Application/MessageHandler/ImportAllocationsMessageHandler.php) before file access / cleanup / run start |
| Risk | Compromised DB/queue writer can re-trigger import processing. Not reachable from normal HTTP alone. |
| Mitigation | Document trust boundary; harden DB/worker credentials; optional status gate (`PENDING` only) and/or actor id on message. |
| Status | resolved (2026-07-29) — handler fails imports whose creator no longer has `HospitalPermission::Import`; integration test covers unauthorized-creator failure path |

### SEC-019 — Export empty hospital intersect falls back to all allowed hospitals

| Field | Value |
|-------|-------|
| Severity | Low |
| Area | Export |
| Evidence | `ExportAccessService::resolveEffectiveHospitalIds` — empty intersection → all exportable hospitals (no foreign hospital leak) |
| Risk | Tampered hospital id list expands scope to all permitted hospitals instead of failing closed. |
| Mitigation | Prefer empty result / 400 when requested ∩ allowed is empty. |
| Status | open |

### SEC-020 — AllocationVoter / Live Component test gaps

| Field | Value |
|-------|-------|
| Severity | Info |
| Area | Testing |
| Evidence | Allocation voter tests cover auth vs anon only; Live Component auth tests do not assert guest denial on `/_components` actions |
| Risk | Regressions in AuthZ go unnoticed. |
| Mitigation | Add denial tests for SEC-004/005 decisions; extend Explore rate-limit path coverage. |
| Status | open |

---

## RateLimiter recommendations (for follow-up)

| Surface | Suggested limiter | Notes |
|---------|-------------------|-------|
| `POST /register` | 5 / hour / IP | SEC-007 **resolved** |
| `POST /reset-password` | 10 / hour / IP | SEC-007 **resolved** (silent check-email) |
| Explore list GETs | All GET `/explore` via `explore_list` | SEC-006 **resolved** |
| Allocations export estimate/download | 10 / 10 min / user+IP | SEC-007 **resolved** |
| Analysis Explorer CSV export | 20 / 10 min / user+IP | SEC-007 **resolved** |
| Import create (`POST /import/new`) | 30 / hour / user+IP | SEC-007 **resolved** |

Do **not** implement in this audit deliverable.

---

## What looks solid (keep)

- Login throttling, `expose_security_errors: none`, hashed login-failure usernames.
- Hospital permission bitmask + owner/admin grant management; no self-escalation to system roles on registration.
- Statistics filter/scope strips unauthorized hospital IDs (public fallback).
- Import upload allowlist (csv/txt), size limit, storage under `var/imports`, source download Admin + voter.
- Export CSRF + hospital intersection via `ExportAccessService`; row cap.
- Feedback CSRF, multi-layer rate limits, spam heuristics, safe redirect resolver.
- Admin EasyAdmin double gate (`access_control` + `IsGranted`); impersonation blocks self/admin targets and audits.
- Reset-password anti-enumeration (uniform check-email flow).
- Nelmio clickjacking DENY, nosniff, referrer/permissions policy; prod HSTS + CSP report-only readiness.
- Blog show + page blocks HTML sanitization; comment content escaped.

---

## Accepted / intentional designs

| Topic | Current behaviour | Recommendation |
|-------|-------------------|----------------|
| Collaborative Explore | Participants see cross-hospital allocations; `ROLE_PARTICIPANT` required | **Accepted** (SEC-005 / ADR 011) |
| Public statistics | `ROLE_USER` sees aggregates / public scopes | Keep |
| Public health endpoint | Slim public JSON (`status` + `database`) | **Resolved** (SEC-012) |
| CSP report-only | Beta monitoring before enforce; `unsafe-inline` allowed | **Accepted** (SEC-014) — see [content-security-policy.md](content-security-policy.md) |
| Queue trust for import workers | No HTTP re-auth in handler | Ops hardening (SEC-018) |
| Admin can assign `ROLE_ADMIN` | ChoiceField whitelist | Accept for small admin set |

---

## Suggested remediation priority (follow-up issues only)

| Priority | Findings | Rationale |
|----------|----------|-----------|
| P0 before wider beta | SEC-001, SEC-002 | Enumeration + public XSS inconsistency |
| P1 early beta | SEC-003, SEC-004 | Export safety, Live Component defense-in-depth |
| P2 hardening | SEC-013, SEC-015, SEC-019 | Media indexes, session cookies, docs (SEC-014 CSP accepted for beta) |
| P3 backlog | SEC-014 enforce, SEC-020 | CSP enforce + reduce `unsafe-inline`, tests |

Follow-up GitHub issues are **out of scope** for this audit and should be derived separately from the table above.

---

## Appendix A — Review checklist coverage

| Issue #257 area | Covered | Primary findings |
|-----------------|---------|------------------|
| Authentication / authorization | Yes | SEC-001, SEC-004, SEC-005, SEC-011 |
| Voters / permission checks | Yes | SEC-005, SEC-017 |
| RBAC / privilege boundaries | Yes | Grants, registration, admin roles — solid |
| Privilege escalation | Yes | No self-escalation found |
| Ownership / clinic resources | Yes | Import/export/grants solid; Explore collaborative |
| Public endpoints / anonymous | Yes | SEC-012, content XSS SEC-002 |
| Scraping / RateLimiter | Yes | SEC-006, SEC-007 |
| Import / export security | Yes | SEC-003, SEC-008, SEC-009, SEC-019 |
| Admin / restricted ops | Yes | Solid; SEC-008 audit gap |
| User management / permissions | Yes | SEC-001 |
| API / AJAX / Live auth | Yes | SEC-004, SEC-010 |
| Input validation / common vulns | Yes | SEC-002, SEC-003, SEC-010 |
| Uploaded files / import processing | Yes | SEC-009, SEC-013, SEC-018 |
| Error messages / disclosure | Yes | Solid; SEC-012 |
| Logging / auditability | Yes | SEC-008, SEC-016 |

## Appendix B — Key file index

```
config/packages/security.yaml
config/packages/rate_limiter.yaml
config/packages/nelmio_security.yaml
config/packages/csrf.yaml
config/packages/framework.yaml
config/routes/ux_live_component.yaml
.github/workflows/security.yml
src/*/Infrastructure/Security/Voter/*.php
src/Allocation/Infrastructure/Http/ExploreListRateLimitSubscriber.php
src/Import/Application/Service/ImportFileStorage.php
src/Shared/Application/Export/CsvStreamExportResponseFactory.php
src/Statistics/AnalysisExplorer/UI/LiveComponent/AnalysisExplorerShell.php
docs/02-architecture/permission-model.md
docs/05-operations/content-security-policy.md
```
