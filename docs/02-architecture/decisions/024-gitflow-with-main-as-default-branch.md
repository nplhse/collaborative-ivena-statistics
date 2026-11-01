# ADR 024: Gitflow with `main` as the default branch

**Status:** accepted

## Context

Until v0.2-beta every change was merged into `main`. Larger issues used an integration branch `feature/<topic>` with one pull request per step and a final pull request to `main`. A release was a tag on whatever `main` held at that moment, and the changelog section and the version were prepared by hand just before it.

With the beta in real use this no longer fits (issue #944):

- A released version needs fixes (security problems, critical bugs) while the next version is already being built. With a single branch, a fix can only ship together with all unreleased work.
- The released state is not visible in the repository: `main` moves on after the tag.
- Dependabot opens security updates against the default branch only. `target-branch` does not redirect them.
- Release preparation was not written down. For v0.2-beta the version in `src/Kernel.php` was almost released unchanged (#946, #947).

The project has a single maintainer, so the model must not depend on reviews by a second person.

## Decision

### Branches

| Branch | From | Into | Purpose |
|---|---|---|---|
| `main` | – | – | Released state only; every merge is a release or hotfix and is tagged |
| `develop` | `main` at `v0.2-beta` | `release/*` | Integration of finished work |
| `feature/*`, `fix/*`, `docs/*`, … | `develop` | `develop` | One issue or one step |
| `release/<version>` (e.g. `release/0.3-beta`) | `develop` | `main`, then back into `develop` | Stabilisation, version, changelog and release notes |
| `hotfix/<version>` (e.g. `hotfix/0.2.1-beta`) | `main` | `main`, then back into `develop` | Minimal fix of the released version |

- Larger issues keep the integration branch with step pull requests. The integration branch starts from `develop` and its final pull request goes to `develop`.
- Nothing is pushed directly to `main` or `develop`; every change goes through a pull request.
- Only `release/*` and `hotfix/*` are merged into `main`. After every such merge the release is tagged and `main` is merged back into `develop` through a pull request.
- Merges are merge commits. Shared branches (`main`, `develop`, `release/*`, `hotfix/*`, integration branches) are never rebased or force-pushed.

### `main` stays the default branch

- Clones and coding agents read `AGENTS.md` and the docs from the default branch, so rules about the workflow are merged into `main` as well as `develop` (this ADR came in through a pull request to `main` before `develop` existed).
- Deployer deploys the default branch, which is now always a released and tagged state. `deploy.php` is unchanged.
- GitHub and `gh pr create` propose `main` as the base. Pull requests for regular work name `--base develop` explicitly; a workflow marks pull requests into `main` that do not come from `release/*` or `hotfix/*`.

### Versions and changelog

- Releases are tagged `v<major>.<minor>-beta` during the beta, hotfixes `v<major>.<minor>.<patch>-beta` (e.g. `v0.2.1-beta`).
- Pull requests do not edit `CHANGELOG.md`. The section of a release is written on its `release/*` or `hotfix/*` branch from the pull requests merged since the previous tag, and the GitHub release notes are generated from it. There is no `[Unreleased]` section.
- The steps are a checklist in [development-workflow.md](../../03-development/development-workflow.md#releases).

### Dependabot

- Version updates target `develop`.
- Security updates target `main` and are labelled `security` and `hotfix`. A production-relevant one is shipped as a hotfix; one for a development-only dependency is closed and left to the next version update on `develop`.

## Consequences

- A released version can be fixed without shipping unreleased work, and `main` always shows what runs in production.
- Every change needs one more step: release and hotfix branches have to be merged back into `develop`, and forgetting it loses the fix in the next release.
- Every pull request for regular work has to name `develop` as its base, because GitHub defaults to `main`. A wrong base is caught by the source branch check, not prevented.
- The changelog is written once per release instead of per pull request. Pull requests no longer conflict on `CHANGELOG.md`, but the release branch has to collect and sort the entries.
- CI runs on pushes to `main` and `develop` and on every pull request.
- With a single maintainer, rulesets on `main` and `develop` require pull requests and status checks but no approvals. GitHub does not let authors approve their own pull requests. This is revisited when a second maintainer joins.

## Alternatives

- **Trunk-based development on `main` with release tags.** The model until v0.2-beta. Simple, but a released version cannot get a fix without the unreleased work, and the released state is only a tag.
- **Gitflow with `develop` as the default branch.** Pull requests would target `develop` without an explicit base. Rejected: Dependabot security updates would land on `develop` instead of the released code, Deployer would deploy unreleased work unless every deploy names a branch, and the repository front page would show an unreleased state.
- **Release branches without `develop` (GitHub Flow plus maintenance branches).** Work stays on `main`, and a `release/0.2` branch is cut for fixes. Fewer back-merges, but `main` stops being the released state, and Deployer and Dependabot would need to point at changing maintenance branches.
