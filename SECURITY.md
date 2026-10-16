# Security policy

## Reporting a vulnerability

Please do not open a public issue. Do not include an API key or an OAuth token.

Report privately through
[GitHub Security Advisories](https://github.com/pendulumdev/arc-drupal/security/advisories/new),
or email **security@pendulumdev.co.uk**.

Include what you did, what happened, and what you expected. A proof of concept
helps. We will acknowledge within 5 working days and aim to have a fix or a
clear plan within 30 days for anything we can reproduce.

Please do not test against systems you do not own.

## Supported versions

Alpha: only the latest tagged release is supported. Fixes land on `main` and
go out in the next tag.

## Known and accepted characteristics

These are documented properties of the module, not vulnerabilities. Full
detail in [`docs/security.md`](docs/security.md).

- **Disconnect is local.** It stops this install reading Arc. The site and
  its reports stay in Arc until an admin removes them there.
- **The connection token is sealed, not hashed.** An attacker who can read
  the database and the Drupal hash salt can unseal it. That is the same trust
  boundary as the rest of the site state.
- **Administrators choose the Arc account.** A user with `administer pendulum arc`
  can pair this site to any Arc studio they can authenticate.
- **The consent log route is public.** It takes no CSRF token, because a
  cached page would keep a stale one. It rejects unknown cookie ids and
  allows 30 writes an hour from the direct address.

Reports that consist only of one of the above, without a concrete escalation
beyond what is documented, will be closed with a pointer here.
