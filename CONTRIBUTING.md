# How can you contribute to this project?

Any contribution to this project is appreciated, whether it is related to fixing bugs, suggestions or improvements. Feel
free to take your part in the development!

Coding agents start at [AGENTS.md](AGENTS.md). It points to the same documentation and checks as this guide.

However, you should follow the following simple guidelines for your contribution to be properly received:

-   Contributions are accepted via pull requests on
    [GitHub](https://github.com/nplhse/collaborative-ivena-statistics). Work on a branch from `main` and open the
    pull request against `main`. Larger issues use an integration branch `feature/<topic>`: each step gets its own
    branch and pull request into it, and a final pull request to `main` closes the issue. A switch to the
    [GitFlow branching model](http://nvie.com/posts/a-successful-git-branching-model/) is planned after beta v0.2.
-   Please keep in mind, that this project follows [SemVer v2.0.0](http://semver.org/).
-   You should make sure to follow the [PHP Standards Recommendations](http://www.php-fig.org/psr/) and the
    [Symfony best practices](http://symfony.com/doc/current/best_practices/index.html), except where the
    [architecture docs and ADRs](docs/02-architecture/README.md) decide otherwise (for example colocated Twig templates).
-   Also, you must agree to comply to the [Code of Conduct](CODE_OF_CONDUCT.md) of this project.

## Before opening a pull request

-   Before opening the pull request, run `make check` (every check, changes no code) and the tests of every context
    whose behaviour can change (`make test PATH_ARG=tests/<Context>`). The complete suite (`make ci`) runs in GitHub
    Actions on every pull request; run it locally only for changes to central infrastructure or to reproduce a CI
    failure. `make fix` applies the automatic fixes; review its diff. See
    [docs/03-development/development-workflow.md](docs/03-development/development-workflow.md#checks-fixes-and-tests).
-   Update the documentation affected by your change in the same pull request.
-   In unit tests, prefer `createStub()` for return-only collaborators; use a spy for side effects;
    reserve `expects()` for when call count or `never()` is part of the spec. See
    [docs/03-development/testing.md](docs/03-development/testing.md#test-doubles).
-   Do not commit secrets, credentials, or unrelated scope changes.
