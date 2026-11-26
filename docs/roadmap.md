# Roadmap

How the project has developed so far and which phases lead to IVENA 1.0. This page describes
phases and their goals, not individual tasks: the work itself is planned in the
[GitHub milestones](https://github.com/nplhse/collaborative-ivena-statistics/milestones), and what
each release contained is listed in the [changelog](../CHANGELOG.md).

## Overview

| Phase | Version | Period | Status |
|---|---|---|---|
| Alpha: proof of concept to beta readiness | v0.0.1-alpha – v0.0.5-alpha | May – July 2026 | Done |
| First beta | v0.1-beta | August 2026 | Done |
| Foundation Complete | v0.2-beta | September – October 2026 | Done |
| Feature Complete | v0.3-beta | November 2026 | Done |
| Hardening Complete | v0.4-beta | January 2027 | In progress |
| Release candidate | v1.0.0-rc.1 | February 2027 | Planned |
| Stable release | v1.0.0 | March 2027 | Planned |
| After 1.0 | – | from 2027 | Backlog |

Dates of future phases are the milestone due dates. They are targets, not commitments.

## Looking back

### Alpha (v0.0.1 – v0.0.5, May – July 2026)

Development started in July 2025; the first tagged release followed in May 2026. The alpha
phase answered one question first: can the core of the domain, importing pseudonymised IVENA
allocation exports and turning them into statistics, be built at all? v0.0.1 was that proof of
concept. The following alphas each had one focus: imports (v0.0.2), statistics (v0.0.3), user
and developer experience (v0.0.4) and beta readiness (v0.0.5). By the end, import, Explore, the
Analysis Explorer and the main statistics were in place.

### First beta (v0.1-beta, August 2026)

The first version for real participants. It added usage analytics, multilingual CMS pages, a
user directory with public profiles, summarised monthly reports and transport time profiles,
and turned the home dashboard into a project overview. Day-to-day operations were hardened in
parallel: blocked registration domains, deliverable feedback mail, CSP fixes for the admin area.

### Foundation Complete (v0.2-beta, September – October 2026)

The architectural, analytical and UI foundations intended for 1.0. The largest phase so far
(281 closed issues):

- A second data source: IVENA closure lists are imported and analysed as Closure Analytics.
- Statistics gained Insights across catalog dimensions, a guided analysis assistant, Top List
  comparisons and exports, and maps for orientation, isochrones and dispatch area catchments.
- Operations got a monitoring baseline: storage, background jobs, Messenger and a
  notification system.
- The reference catalog became portable, and a synthetic data generator with an offline demo
  makes the platform presentable without real hospital data.
- Shared UI components, consistent use of the Symfony clock, a pre-release security audit and
  stricter CI and agent instructions.

From here on, new product functionality is meant to build on these foundations instead of
introducing new ones.

### Feature Complete (v0.3-beta, November 2026)

The functionality planned for 1.0, built on those foundations (565 closed issues), and the first
release made with Gitflow (ADR 024) through a `release/*` branch:

- A second kind of clinical data: an encounter data pilot links emergency department visits with
  their Manchester Triage System assessment to IVENA allocations, prepared offline and
  pseudonymised by the hospitals, shipped as the first schema plugin. Closure Analytics left the
  beta.
- Comparisons of the own hospitals with others that protect small groups, stricter scopes and
  clearer statistics, and a navigation rebuilt around one tree with Tools, help, glossary and
  data catalog.
- Clear rules for participation: versioned acceptance of the terms of use, a stored upload
  authority per hospital, Explore restricted to the own hospitals, hospital assignment requests,
  and data extract requests for research with a Board vote and time-limited exports.
- Operations: verified weekly backups with an off-site copy, managed media storage, dispatch-area
  geography as master data, an audit log timeline, feedback quarantine, a page editor with
  drafts, two-factor authentication for administrators and a maintenance mode for upgrades.

## Looking ahead

### Hardening Complete (v0.4-beta)

No new features. IVENA becomes technically ready for the release candidate: no known critical
issues affecting security, data integrity or core functionality remain. That means consistent
numbers across all analysis surfaces, no plausible-looking statistics from incomplete data,
an authorization and CSP review, regression tests for the critical workflows, user and
operations documentation, and the Symfony 8.2 upgrade.

### Release candidate (v1.0.0-rc.1)

Feature freeze and validation under real conditions: user acceptance tests with clinic users,
end-to-end checks of the core workflows and production-like deployment checks. Only
release-critical fixes and documentation changes.

### Stable release (v1.0.0)

The validated release candidate becomes the first stable release: a validated foundation for
clinical analytics, benchmarking and future research.

### After 1.0

Valuable ideas that are not required for 1.0 are collected in the
[Post 1.0 milestone](https://github.com/nplhse/collaborative-ivena-statistics/milestone/12)
and prioritised after the release, for example an MCI event overview with geographic analysis.

## Maintaining this page

Update this page as part of each release on the `release/*` branch (step 7 of the
[release checklist](03-development/development-workflow.md#releases)): move the finished phase to
"Looking back" with a short summary, and adjust the outlook if the plan for later phases has
changed. Hotfixes do not change it. Keep it at the level of phases and goals; details belong in
the milestones and the changelog.
