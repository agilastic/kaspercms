# Contributing to KasperCMS

KasperCMS is a community effort. Contributions are welcome: backports of
security fixes, tests, reviews of the coverage matrix and documentation.

## How to contribute

1. Fork [agilastic/kaspercms](https://github.com/agilastic/kaspercms) and
   create a branch from `12.4-ults`.
2. Follow the process in [ULTS-PROCESS.md](ULTS-PROCESS.md):
   - every fix needs a public upstream source (TYPO3 branch, advisory or
     library release) or a test that reproduces the issue,
   - backports are cherry-picked with `git cherry-pick -x`, so their origin
     stays traceable,
   - the test suites must pass on PHP 8.1, the minimum PHP version of
     TYPO3 12.4.
3. Open a pull request against `12.4-ults`.

KasperCMS only accepts security fixes, fixes for regressions caused by them
and security updates of dependencies. New features and breaking changes are
out of scope.

## Security issues

Do not open public issues or pull requests for undisclosed vulnerabilities.
See [SECURITY.md](SECURITY.md) for how to report them.

## Contributing to TYPO3 CMS

Bugs and features of TYPO3 CMS itself belong upstream. See the
[TYPO3 contribution walkthrough](https://docs.typo3.org/core-contribution).
