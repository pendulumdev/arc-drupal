# Roadmap

Status: **alpha**. The connect flow, site scores, and report previews are the module.
It is not on Drupal.org yet. Install from this repository until the project
page exists.

Machine name: `pendulum_arc`. Issued OAuth client: `arc_app_drupal`.
Drupal 11 only (`core_version_requirement: ^11`).

## Place in Arc platform reach

This module is Wave 0 of the Arc platform reach sequence. The Drupal.org
listing on this page is the reach work for this repository. Later platforms
are sequenced in the Arc repo at `docs/research/system/platform-reach.md`.

## Known gaps

### Drupal.org listing

The project short name to request is `pendulum_arc`. The project does not
exist yet. Until it does, install from a checkout of this repository. The
steps from an empty Drupal.org account to a covered stable release are
below, under **Public launch**.

### Generate reports

The dashboard notice uses the same confirm-then-queue menu as the portal
sites page. It calls `POST /admin/pendulum-arc/runs`, which queues one
service through `POST /v1/sites/{siteId}/runs` with a fresh idempotency key.
Run all queues each service that is not already queued or running.

OAuth connect requests `arc:read arc:enqueue`. A site connected before that
scope must reconnect before Generate is allowed. API-key connects already
include enqueue.

The menu reads `GET /v1/sites/{siteId}/runs` when the notice opens, then
once a minute while any service is queued or running. When a service leaves
in-flight, the scores and the open report reload. Each list spends a public
API credit.

### API key cannot create a project

Sign-in on Arc can add a project by name. An API key can pair a site, or
confirm a site on a project that already exists. Confirming a new site
sends `auto_run`, `auto_schedule`, and `auto_sitemap`. Arc probes for a
sitemap, queues the first reports against it, and enables the recommended
schedules. An account with no project has to add one in Arc first.

## Distribution

Until the Drupal.org release exists, install from git or from the
`pendulum_arc.zip` attached to a GitHub Release. Composer can use a VCS
repository on `drupal/pendulum_arc` and pin a `vX.Y.Z` tag. After the
project page exists, sites install with Composer from `packages.drupal.org`
or with the Update Manager. This module does not check GitHub for updates
the way the WordPress plugin does.

A Drupal.org release is a tag on the `1.0.x` branch (`1.0.0-alpha1`, then
`1.0.0`). Pin a release. Do not track an unreleased branch on a production
site.

## Stability

Treat these as the module contract. Changing any of them is a breaking
change:

- State key `pendulum_arc.connection`
- Routes under `/admin/pendulum-arc`
- The OAuth client id `arc_app_drupal` and the redirect back to
  `/admin/pendulum-arc/connect`
- Permission `administer pendulum arc`

## Public launch

Drupal.org does not use a GitHub organisation. A person creates the project.
The project page is public as soon as it exists, and it warns that the
project is not covered by the security advisory policy until the maintainer
opts in. That opt-in is the public-launch bar.

### Account and project

1. Create a Drupal.org account for the maintainer who will own the commits.
   Reviewers expect that person to have written the code. Confirm the email,
   accept the Git access agreement, and set the Git username.
2. Add a Module project. Title: `Arc Analytics`. Short name: `pendulum_arc`.
   That short name is the Composer package `drupal/pendulum_arc` and the git
   project.
3. The empty repository is `git.drupalcode.org/project/pendulum_arc`.
4. Push a branch named `1.0.x`. Drupal.org does not use `master` or `main`
   for releases. `core_version_requirement: ^11` in
   `pendulum_arc.info.yml` is what marks the module Drupal 11 compatible.
   Tags on that branch are `1.0.0-alpha1`, then `1.0.0`.
5. `composer.json` name is `drupal/pendulum_arc`. It requires
   `drupal/core: ^11` and `php: >=8.3`, license GPL-2.0-or-later. Keep that
   in step with the info file. The Composer facade also reads the info file.
6. `README.md` is the project page description: what the module does, Arc as
   an external service, install, and the permission.
7. Enable GitLab CI. `.gitlab-ci.yml` includes the Drupal Association
   `gitlab_templates` so phpcs runs on the project. Fix findings before
   asking for coverage. `.ddev`, `dev/`, `docs/`, and this file are
   `export-ignore` in `.gitattributes`, so the packaged zip is the module.

GitHub remains the authoring remote. Drupal.org `1.0.x` is a release branch
of the module tree, not a second codebase.

### Alpha, before coverage

1. Tag `1.0.0-alpha1` on `1.0.x` and create the release in the Drupal.org UI.
   The project page then offers a zip, and Composer can require the module
   from `packages.drupal.org`.
2. The page shows that the project is not covered by the security advisory
   policy. That is expected. Do not describe it as covered.
3. Keep this roadmap at alpha until `1.0.0` is covered.

### Security advisory coverage

Coverage is a permission on the maintainer, then an opt-in on this project.
The queue is [projectapplications](https://www.drupal.org/project/projectapplications).
The checklist reviewers use is the
[application checklist](https://www.drupal.org/docs/develop/managing-a-drupalorg-theme-module-or-distribution-project/security-coverage/opting-into/application-checklist).

Before opening the application:

- One open application only. The applicant does not already have opt-in
  permission.
- The applicant committed the code on `1.0.x` (all of it, or nearly all).
- Enough PHP that a reviewer can see Drupal APIs used for real: routes,
  access checks, state, the HTTP client, and render. Empty hooks fail this.
- No stubs and no debug output. Release-candidate quality. A stable tag is
  not required for the application.
- GitLab CI enabled. phpcs clean against Drupal coding standards. README
  present.
- Access checks on every route. CSRF on the JSON posts. Tokens never
  rendered. Uninstall removes stored secrets.

Then:

1. Open an issue in the projectapplications queue. Set it to Needs review
   when `1.0.x` is ready.
2. Optional and faster: review three other applications and link them (the
   review bonus). The queue is volunteer-run and otherwise waits weeks
   between reviews.
3. A project moderator grants the opt-in permission. On the Pendulum Arc
   project, opt into security advisory coverage.
4. Tag `1.0.0` on `1.0.x` and publish that release. The project page drops
   the uncovered warning. Composer metadata gains the covered flag. Sites
   get the release through Composer or the Update Manager.

Until `1.0.0` is covered, the status of this module stays alpha.
