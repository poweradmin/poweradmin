# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 4.5.x | Yes, current release |
| 4.4.x | Yes, LTS until December 2027 |
| 4.3.x, 4.2.x | Until 2027-01-31 |
| 3.9.x | Yes, LTS until December 2027 |
| 4.1.x, 4.0.x, 3.8.x and older | No, please upgrade |

See [Version Support](https://docs.poweradmin.org/getting-started/lifecycle/) for the support rules and dates.

## Reporting a Vulnerability

Please **do not** open a public issue, discussion or pull request for a vulnerability.

Report it privately through [GitHub private vulnerability reporting](https://github.com/poweradmin/poweradmin/security/advisories/new). If you can't use GitHub, email **edmondas@poweradmin.org** instead.

Please include:

- the affected version or commit
- steps to reproduce, or a proof of concept
- the impact, and whether the attacker needs to be logged in or needs a specific permission
- any relevant configuration, such as the database type, SQL or API backend, and LDAP/OIDC/SAML

Reports from automated scanners are welcome only with a working reproduction.

## One Vulnerability per Report

Please file one report for each vulnerability. A vulnerability here means one flaw that can be fixed on its own. If the same mistake shows up in several places that each need their own fix, such as a web path and an API path with the same missing permission check, file a separate report for each and mention the related reports in each one.

GitHub, which assigns CVEs for Poweradmin, issues one CVE per independently fixable vulnerability, so a combined report has to be split before a CVE can be requested, and that delays the advisory and your credit. If you're not sure whether two findings count as one, ask in the report and we'll sort it out before anything is published.

## What Happens Next

- You'll get a reply within 7 days.
- We verify the report and work out which versions are affected.
- Fixes go to every supported branch that is affected, and patch releases follow.
- After the releases ship, we publish a GitHub Security Advisory, request a CVE, and credit you unless you'd rather stay anonymous.

Please keep the details private until the advisory is published. Poweradmin is a volunteer project and does not offer a bug bounty.

## Out of Scope

- Vulnerabilities in PowerDNS itself. Report those to [PowerDNS](https://github.com/PowerDNS/pdns/blob/master/SECURITY.md).
- Vulnerabilities in third-party libraries, unless Poweradmin uses them in a way that makes it exploitable. Report those upstream first.
- Issues that need an already compromised server, or a setup that goes against the documentation.

Past advisories are listed under [Security Advisories](https://github.com/poweradmin/poweradmin/security/advisories).
