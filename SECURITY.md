# Security Policy

We take the security of this software product and services running seriously
and appreciate any reports of vulnerabilities.

## Supported Versions

The project is in its beta phase. During the beta only the latest released beta
receives hotfixes, for security problems as well as critical bugs. Hotfixes are
released as patch versions of that beta (for example `v0.2.1-beta`).

| Version        | Supported                                                         |
| -------------- | ----------------------------------------------------------------- |
| v0.2.3-beta    | :white_check_mark: security and critical bug fixes                |
| v0.1-beta      | :warning: security fixes until v0.3-beta (planned for 2026-12-31) |
| alpha (v0.0.x) | :x:                                                               |

When v0.3-beta is released, it becomes the only supported version. The support
policy for v1.0 and later will be defined before the v1.0 release.

## How we keep the project secure

-   Before every release we run an internal security audit, assisted by AI
    coding agents (for example Claude). It covers common risks such as the
    [OWASP Top 10](https://owasp.org/www-project-top-ten/) and established
    practices for Symfony and modern web applications. Findings are fixed
    before the release or tracked as follow-up issues.
-   Dependabot opens version and security updates for our dependencies, and
    `composer audit` is part of the release checks, so known vulnerabilities
    in dependencies are fixed quickly.
-   `roave/security-advisories` prevents installing dependency versions with
    known vulnerabilities, and a Psalm taint analysis runs on every pull
    request.

## Reporting a Vulnerability
If you believe you have found a security vulnerability in this repository,
please report it to us through coordinated disclosure.

**Please do not report security vulnerabilities through public GitHub issues,
discussions, or pull requests.** Instead, please
[open a draft security advisory on GitHub](https://github.com/nplhse/collaborative-ivena-statistics/security/advisories/new).

Also, please include as much of the information listed below as you can to help
us better understand and resolve the
issue:

-   The type of issue (e.g., buffer overflow, SQL injection, or cross-site
    scripting)
-   Full paths of source file(s) related to the manifestation of the issue
-   The location of the affected source code (tag/branch/commit or direct URL)
-   Any special configuration required to reproduce the issue
-   Step-by-step instructions to reproduce the issue
-   Proof-of-concept or exploit code (if possible)
-   Impact of the issue, including how an attacker might exploit the issue

This information will help us triage your report more quickly.
