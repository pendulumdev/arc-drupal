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

### Settings setup

Settings opens with a greeting and four steps: Install, Connect, Analytics,
and Invite. Install is already done on this screen. Only the next unfinished
step is a button. Analytics is done when the scores row is linked or ready
to assign. Invite is done when one of the first five invite pages has an
active member.

### Report previews

Report tables follow the Arc preview: accessibility and AI pages, bots,
llms.txt, security surfaces, and structured rows for pages, socials, forms,
and brand slots. A page screenshot is one file name. The browser loads it
from `GET /admin/pendulum-arc/report-media`, which fetches the image from
Arc and returns only webp, png, jpeg, or gif under 2 MB.

The admin tabs are Analytics, Settings, and GDPR Consent. The paths stay
`/admin/pendulum-arc` and `/admin/pendulum-arc/connect`.

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

OAuth connect requests `arc:read arc:enqueue arc:invite`. A site connected
before enqueue must reconnect before Generate is allowed. A site connected
before invite must reconnect before Arc can invite a project member. API-key
connects already include enqueue when the key was created with that capability.

The menu reads `GET /admin/pendulum-arc/runs` when the notice opens, then
once a minute while any service is queued or running. When a service leaves
in-flight, the scores and the open report reload. Each list spends a public
API credit.

### Sync keyphrases

Connect Settings has **Sync keyphrases**. It keeps a local list of focus
phrases already stored by Real-time SEO (`yoast_seo` field type,
`focus_keyword`). That covers the Real-time SEO module and the Drupal CMS
SEO Tools recipe, whatever machine name the site gave the field. The module
is not required. Metatag keywords and Neo SEO are not read.

A cron scan fills that list one page at a time. It does not call Arc. Entity
save, delete, and path alias changes update one row. The table is
`pendulum_arc_keyphrases`. Sync state is `pendulum_arc.keyphrases`.

Phrases are folded the way Arc stores them: NFC when intl is loaded, curly
quotes and dashes become ASCII, and invisible marks are removed. A phrase
with no letter or number, an embedded comma, more than 80 UTF-16 units, or
a joined list over 500 units drops that row. A phrase Arc would drop is
left out of the hash, and a phrase that was synced and then dropped is sent
as a delete.

When that list differs from Arc, an alert at the top of Analytics, Settings,
and GDPR Consent says so, and the Arc toolbar item shows a count of 1. That
is the only alert that adds to the count. The same area also says to connect
a Google account when Analytics has none, and to connect this install when
it is not paired with Arc. Those notices do not change the menu count.
**Sync with Arc** is shown after the first scan, and only when the local
list hash differs from `GET /v1/sites/{siteId}/seo-targets`. The click sends
dirty rows when Arc still matches the last accepted hash, or the whole list
when Arc has moved. A replace with no page phrases and no focus is not sent,
so an empty site does not clear the Arc list. Commit uses `auto_run: true`, which queues the SEO
report. Turning the checkbox off clears the Drupal list and leaves the Arc
target in place. New installs start with sync off.

### Connect Google

Connect Google and Assign in Arc call `POST /admin/pendulum-arc/google-connect`.
That mints `POST /v1/sites/{siteId}/google-connect-handoffs`, then reads
invite-status for the current Drupal user's email. An `active` Arc account
is sent to the ticket URL. An `absent` account with status `none` is invited
with `continue` set to that ticket path, and Drupal asks them to open the
email. A pending invite is not sent again. After save or cancel, Arc returns
to the dashboard. The module accepts that return only when `state` matches
the value stored for that user, then reads the site again. The query string
is not the proof that Google is bound. A missing Google account is remembered
in `pendulum_arc.google_missing` when scores say `connect_google`. Disconnect
clears it.

A grant from before `arc:invite` can still open the ticket for someone who
already has an Arc account. Inviting a new account needs Disconnect and
Connect with Arc again.

### Invite administrators

The Connect screen shows an Invite card to the left of Connection when the
site is paired.

An invited person becomes a member of this project. They can view and
generate reports for every site on it. They cannot invite other people,
change the plan, or remove sites.

Invite opens a list of Drupal users with `administer pendulum arc`, 100 per
page. A search box matches name or email across the whole list, and Previous
and Next stay on that search. The module reads
`POST /v1/projects/{projectId}/invite-status` for that page. Someone who is
not on this project is checked, whether or not they already have an Arc
account. A member, a pending invite, or a user with no email cannot be
selected. Send invites posts the checked people on the current page to
`POST /admin/pendulum-arc/invites`, which calls
`POST /v1/projects/{projectId}/invites` once for that page with
`{ "emails" }` and no `continue` path. Other pages are not included. A
pending-invite cap comes back as a refusal for that person and is not
retried.

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
- State keys `pendulum_arc.keyphrases` and `pendulum_arc.google_missing`
- Table `pendulum_arc_keyphrases`
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
