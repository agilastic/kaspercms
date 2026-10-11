# Security Policy

## Supported Versions

| Version | Supported | Notes |
|---|---|---|
| KasperCMS 12.4.45.x | :white_check_mark: | Security fixes via Ultra Long Term Support (ULTS) |
| TYPO3 CMS 12.4.45 and older | :x: | Upgrade to the latest KasperCMS 12.4.45.x release |

Run KasperCMS on a PHP version that still receives security updates
(currently PHP 8.3, 8.4 or 8.5), see [README.md](README.md#php-version).

Only the latest KasperCMS release receives security fixes. See the
[release table](README.md#releases) and the coverage matrices in
[ULTS-PROCESS.md](ULTS-PROCESS.md).

## Hardening recommendations for operators

Restrict outgoing webhook requests to the hosts your installation needs by
setting `$GLOBALS['TYPO3_CONF_VARS']['HTTP']['allowed_hosts']['webhooks']`
in your installation configuration. An empty array blocks all webhook targets;
without a configured host list, this restriction is not enabled.

Keep `verify_ssl` enabled for every webhook and use HTTPS targets so that
certificate validation protects the connection. Limit permissions to configure
webhooks to administrators who need them.

Restrict outgoing connections at the network level as well, including access
to cloud metadata endpoints and internal services that webhooks do not need.
A host allowlist alone does not guarantee that a host resolves to a public
IP address.

## Reporting a Vulnerability

Please choose the right recipient:

* **Vulnerabilities in TYPO3 CMS itself**, also when found in KasperCMS:
  report them to the TYPO3 Security Team at
  [security@typo3.org](mailto:security@typo3.org), so that all TYPO3 users
  benefit from a fix. See the
  [TYPO3 Security Team](https://typo3.community/contribute/teams-committees/security) page
  for details and their GPG key.
* **Vulnerabilities specific to KasperCMS**, for example introduced by an
  adapted backport: report them privately through
  [GitHub security advisories](https://github.com/agilastic/kaspercms/security/advisories/new)
  of this repository.

Your report should include:

* the affected KasperCMS version or commit,
* a description of the issue and its impact,
* the steps needed to reproduce it.

## Coordinated Disclosure

> [!CAUTION]
> Please do not publish vulnerabilities in public issues, pull requests or
> other public channels before a fix is available.

Reports to the KasperCMS maintainers are acknowledged, fixed on the
`12.4-ults` branch and released as a new `12.4.45.N` version. The fix is
listed in the coverage matrix of that release.

## Further Information

* [ULTS process](ULTS-PROCESS.md): how security fixes are tracked,
  backported, verified and released
* [TYPO3 Security Advisories](https://typo3.org/security)
* [TYPO3 Security Guidelines](https://docs.typo3.org/m/typo3/reference-coreapi/12.4/en-us/Security/Index.html)
