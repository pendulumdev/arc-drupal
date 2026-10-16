# Arc Analytics for Drupal

[![Status][Status-shield]][Status-url]
[![Docs][Docs-shield]][Docs-url]
[![License][License-shield]][License-url]

[![Pendulum][Pendulum-shield]][Pendulum-url]
[![Drupal][Drupal-shield]][Drupal-url]
[![PHP][PHP-shield]][PHP-url]

Connects a Drupal 11 site to a [Pendulum Arc](https://arc.pendulumdev.co.uk)
account. The git repository is `arc-drupal`. The module Drupal loads, and the
short name for Drupal.org, is `pendulum_arc`.

## What this module does

**Scores for this site, in the Drupal admin.** Sign in on Arc, or paste an
API key. Arc pairs an existing site or adds this one. The dashboard then
shows the latest scores.

It is a Drupal module, not the Arc portal. Reports stay in Arc. This install
stores the connection, reads the scores, and keeps a local cookie banner.

- **Arc sign-in** - the browser goes to Arc, which pairs a site or creates
  one, including a project name when the studio needs a new project. Drupal
  stores the site that comes back with the token.
- **API key** - pairs a matching site from Drupal. An http address matches
  the same host stored in Arc as https. www and a subdirectory stay different
  sites. When nothing matches, you confirm the site name, URL, and project
  here. That create asks Arc to find a sitemap, queue the first reports, and
  turn on the recommended schedules. A key with no project sends you to Arc
  to add one. A saved key stays on the form until you focus the field to
  replace it.
- **Reconnect** - when Arc cannot find the site stored for this address,
  Reconnect uses the Arc account or API key already saved on this site.
- **Sealed token** - the connection is encrypted with sodium before it is
  saved. Disconnect stops this install reading Arc. The site and its reports
  stay in Arc.
- **GDPR Consent** - a local cookie banner under Arc Analytics. It does not
  call Arc and works before the site is paired. Accept allows the optional
  cookies left checked. Decline refuses every optional cookie. Listed
  optional scripts stay inert until Accept. The page HTML is the same for
  every visitor. Turn on the Arc Analytics cookie tags filter to use the
  `arc_cookie_*` tags on a page.

There is no report settings form. Report settings stay in Arc. The cookie
banner is edited at GDPR Consent. Environment, client id, and host overrides
are `$settings` in `settings.php`.

## External service

This module sends the site address and an API key or OAuth token to Pendulum
Arc.

- Production portal: `https://arc.pendulumdev.co.uk`
- Production API: `https://api.arc.pendulumdev.co.uk`
- Preview portal: `https://arc.preview.pendulumdev.co.uk`
- Preview API: `https://api.arc.preview.pendulumdev.co.uk`

Tokens are stored encrypted and are not shown again. Disconnecting here stops
this install from calling Arc. An Arc admin revokes the key or the connected
app in Arc.

## Requirements

- Drupal 11
- PHP 8.3 or newer, with the sodium extension
- Permission `administer pendulum arc` (the Administrator role has this)

The issued OAuth client id is `arc_app_drupal`. Arc must store that id before
sign-in works. Override it with `$settings['pendulum_arc_oauth_client_id']`.

Set `$settings['pendulum_arc_env']` to `preview` to use the preview portal
and API. An `arc_test_` key always talks to preview. An `arc_live_` key
always talks to production.

## Install on another site

Requires **Drupal 11** and **PHP 8.3+** with the sodium extension.

The Drupal.org project is not published yet. Copy this module into the other
site. The folder name Drupal loads is `pendulum_arc`.

From the other site's project root, with this checkout at
`/Users/mark/Git/arc-drupal`:

```bash
mkdir -p web/modules/custom
rsync -a --delete \
  --exclude .ddev --exclude .git --exclude .github --exclude node_modules \
  --exclude vendor --exclude web --exclude ui --exclude docs --exclude dev \
  --exclude tests --exclude pack \
  /Users/mark/Git/arc-drupal/ web/modules/custom/pendulum_arc/
ddev drush pm:enable pendulum_arc -y
ddev drush updatedb -y
ddev drush cr
ddev launch /admin/pendulum-arc/consent
```

`js/build/` must be in that copy. Drupal loads it and does not run Node.
If the other site's docroot is not `web/`, use that docroot instead.

After a GitHub Release exists, unzip `pendulum_arc.zip` so `pendulum_arc/`
lands in `web/modules/custom/`, then enable it the same way. Composer can
also require the tag once this repository is the `drupal/pendulum_arc`
package:

```bash
ddev composer config repositories.arc-drupal vcs https://github.com/pendulumdev/arc-drupal.git
ddev composer require drupal/pendulum_arc:^0.1
ddev drush pm:enable pendulum_arc -y
ddev drush updatedb -y
ddev drush cr
```

Open Arc, then Connect. GDPR Consent works before the site is paired.

## Develop

```bash
composer install
npm install
npm run hooks
npm run build
composer test
composer lint
npm test
```

`npm run build` writes `js/build/`, which Drupal loads. Commit that directory
with the source. `npm run hooks` installs the tag-line commit check.

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Local development

DDEV downloads Drupal 11. The core tree is not committed. Docker and DDEV
are required.

```bash
ddev config --project-type=drupal11 --docroot=web --php-version=8.3 --project-name=pendulum-arc
ddev add-on get ddev/ddev-drupal-contrib
ddev start
ddev poser
ddev symlink-project
ddev drush site:install minimal -y
ddev drush pm:enable pendulum_arc -y
ddev launch /admin/pendulum-arc/connect
```

`ddev poser` installs Drupal and this module's PHP dependencies without
rewriting `composer.json`. `ddev symlink-project` links this repository into
`web/modules/custom`.

Day to day:

```bash
ddev start
ddev phpunit
ddev phpcs
composer test
npm test
npm run build
```

`dev/local-arc.php` points PHP at a local Arc. DDEV loads it from settings.
The browser still uses `http://localhost:3001`. PHP inside the container
rewrites that host to `host.docker.internal`. That file is not in the
release zip.

## Versioning and tags

- Version source of truth: root `package.json`, `version` in
  `pendulum_arc.info.yml`, and `version` in `composer.json`. The library
  versions in `pendulum_arc.libraries.yml` match those.
- A release is an annotated git tag `vX.Y.Z` on `main`
- Consumers should pin the tag, not a branch
- Write `docs/releases/vX.Y.Z.md` first - the workflow publishes that file
  as the GitHub Release body and attaches `pendulum_arc.zip`. Format and
  steps: [`docs/releasing.md`](docs/releasing.md)

```bash
git tag -a v0.1.0 -m "v0.1.0"
git push origin main --tags
```

Pushing the tag runs the release workflow, which refuses to publish if the
tag and the version fields disagree. It then re-runs the full gate against
the tagged commit and publishes a GitHub release.

## Documentation

- [docs/security.md](docs/security.md) - what this module sends and stores
- [docs/releasing.md](docs/releasing.md) - tag a release and the notes format
- [ROADMAP.md](ROADMAP.md) - what is in, and what is not scheduled

## Limits

- An API key cannot create an Arc project. Sign-in can, on Arc's
  authorize page. A key can pair a site, or confirm a new site on a
  project that already exists.
- Disconnect does not delete the site or its reports in Arc.
- Needs the PHP sodium extension to store a connection.
- Does not edit Arc report settings. Those stay in the portal.

## Contributing

Issues and pull requests are welcome at
[`pendulumdev/arc-drupal`](https://github.com/pendulumdev/arc-drupal).
Prefer small, single-purpose changes. Start with
[CONTRIBUTING.md](CONTRIBUTING.md); report vulnerabilities per
[SECURITY.md](SECURITY.md).

## Contributors

- **[Pendulum](https://pendulumdev.co.uk)** - lead development and maintenance
- **[Devhalls](https://github.com/devhalls)** - primary author

## License

**GPL-2.0-or-later** - full text in [`LICENSE`](LICENSE).

---

<!-- Badge definitions (reference-style; for-the-badge, black) -->
[Pendulum-shield]: https://img.shields.io/badge/pendulum-000000?style=for-the-badge&logo=data:image/svg+xml;base64,PHN2ZyBpZD0iTGF5ZXJfMSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB4bWxuczp4bGluaz0iaHR0cDovL3d3dy53My5vcmcvMTk5OS94bGluayIgdmlld0JveD0iMCAwIDE2NSAxNjUiPjxkZWZzPjxsaW5lYXJHcmFkaWVudCBpZD0ibGluZWFyLWdyYWRpZW50IiB4MT0iMCIgeTE9IjgzLjUiIHgyPSIxNjEuMzkiIHkyPSI4My41IiBncmFkaWVudFRyYW5zZm9ybT0idHJhbnNsYXRlKDAgMTY2KSBzY2FsZSgxIC0xKSIgZ3JhZGllbnRVbml0cz0idXNlclNwYWNlT25Vc2UiPjxzdG9wIG9mZnNldD0iMCIgc3RvcC1jb2xvcj0iIzMyYjdkNiIvPjxzdG9wIG9mZnNldD0iMSIgc3RvcC1jb2xvcj0iI2Y0OTYzYyIvPjwvbGluZWFyR3JhZGllbnQ+PC9kZWZzPjxyZWN0IHdpZHRoPSIxNjUiIGhlaWdodD0iMTY1IiBzdHlsZT0iZmlsbDp1cmwoI2xpbmVhci1ncmFkaWVudCk7Ii8+PHBhdGggZD0iTTU0LjA4LDEzM2gtMjUuOThWMzMuMDNoNDEuMzZjMTEuMjIsMCwxOS44MiwyLjkyLDI1Ljc4LDguNzVzOC45NSwxNC4wNSw4Ljk1LDI0LjY2LTIuOTgsMTguODMtOC45NSwyNC42NmMtNS45Niw1LjgzLTE0LjU2LDguNzUtMjUuNzgsOC43NWgtMTUuMzhzMCwzMy4xNSwwLDMzLjE1Wk01NC4wOCw3OC45aDguNjJjOS41NCwwLDE0LjMyLTQuMTUsMTQuMzItMTIuNDZzLTQuNzctMTIuNDYtMTQuMzItMTIuNDZoLTguNjJ2MjQuOTNoMFpNMTQzLjEsMzNsLTI2LjExLDEwMGgtMjVsMjYuMTEtMTAwaDI1WiIgc3R5bGU9ImZpbGw6I2ZmZjsiLz48L3N2Zz4=
[Pendulum-url]: https://pendulumdev.co.uk/
[Status-shield]: https://img.shields.io/badge/status-alpha-000000?style=for-the-badge
[Status-url]: ROADMAP.md
[Drupal-shield]: https://img.shields.io/badge/Drupal-11-000000?style=for-the-badge&logo=drupal
[Drupal-url]: https://www.drupal.org/
[PHP-shield]: https://img.shields.io/badge/PHP-8.3+-000000?style=for-the-badge&logo=php
[PHP-url]: https://www.php.net/
[Docs-shield]: https://img.shields.io/badge/docs-000000?style=for-the-badge&logo=readthedocs
[Docs-url]: docs/
[License-shield]: https://img.shields.io/badge/license-GPL--2.0--or--later-000000?style=for-the-badge
[License-url]: LICENSE

