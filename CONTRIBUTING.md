# How can you contribute to this project?

Any contribution to this project is appreciated, whether it is related to fixing bugs, suggestions or improvements. Feel
free to take your part in the development!

However, you should follow the following simple guidelines for your contribution to be properly received:

-   This project uses the [GitFlow branching model](http://nvie.com/posts/a-successful-git-branching-model/) for the
    process from development to release. Because of GitFlow contributions can only be accepted via pull requests on
    [GitHub](https://github.com/nplhse/collaborative-ivena-statistics).
-   Please keep in mind, that this project follows [SemVer v2.0.0](http://semver.org/).
-   You should make sure to follow the [PHP Standards Recommendations](http://www.php-fig.org/psr/) and the
    [Symfony best practices](http://symfony.com/doc/current/best_practices/index.html).
-   Also, you must agree to comply to the [Code of Conduct](CODE_OF_CONDUCT.md) of this project.

## Before opening a pull request

-   Run the relevant tests (`make test SUITE=…` / `PATH_ARG=…`) and keep them green.
-   In unit tests, prefer `createStub()` for return-only collaborators; use a spy for side effects;
    reserve `expects()` for when call count or `never()` is part of the spec. See
    [docs/03-development/testing.md](docs/03-development/testing.md#test-doubles).
-   Do not commit secrets, credentials, or unrelated scope changes.
