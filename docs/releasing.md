# Cutting a release

A release is an annotated git tag `vX.Y.Z` on `main`. The tag must match
`package.json`, `version` in `pendulum_arc.info.yml`, `version` in
`composer.json`, and the library versions in `pendulum_arc.libraries.yml`.
Pushing the tag runs [`.github/workflows/release.yml`](../.github/workflows/release.yml),
which re-runs the gate, packs `pendulum_arc.zip` with `pendulum_arc/` as the
top folder, and publishes a GitHub Release. Drupal installs that zip as the
module directory. The release body is **not** auto-generated. Write it first.

The body format follows the
[pend-audit-analytics](https://github.com/pendulumdev/pend-audit-analytics)
releases: a one-line product sentence, fixed headed lists, then a support
badge.

Drupal.org stays a later step. GitHub is the release used for testing until
the project page exists. Do not publish a second copy of the behaviour.

## Before you tag

1. Bump `package.json` (and the lockfile if it changed) to `X.Y.Z`.
2. Set the same version in `pendulum_arc.info.yml`, `composer.json`, and
   `pendulum_arc.libraries.yml`.
3. Run `npm run build` and commit `js/build/` if the admin UI changed.
4. Write `docs/releases/vX.Y.Z.md` from the template below. The workflow
   refuses to publish if that file is missing.
5. `composer test`, `composer lint`, and `npm test` are green.
6. Commit. Tag-line only: `[UPD] Bump module version to X.Y.Z`.
7. Push `main`. Then:

```bash
git tag -a vX.Y.Z -m "vX.Y.Z"
git push origin main --tags
```

The tag and the version fields must agree. The workflow fails closed if they
do not, so a pin cannot land on a build that misreports its version.

## Body template

Copy this into `docs/releases/vX.Y.Z.md`. Keep the heading names. The first
tag uses **Changes:** and lists what that release includes. Later tags use
**Changes since vA.B.C:**.

```markdown
Drupal module that connects a site to Pendulum Arc. <one line on what this tag is> for vX.Y.Z.

**Min version support:**
- Drupal **11**
- PHP **8.3** with the sodium extension

**Requires:**
- A Pendulum Arc account, and an API key or Arc sign-in

**Runtime support:**
- Drupal 11

**Changes since vA.B.C:**
- <user-visible change>
- <user-visible change>

**Support us:**

If you find this useful, visit Pendulum:

[![Pendulum][Pendulum-shield]][Pendulum-url]

[Pendulum-shield]: https://img.shields.io/badge/pendulum-000000?style=for-the-badge&logo=data:image/svg+xml;base64,PHN2ZyBpZD0iTGF5ZXJfMSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB4bWxuczp4bGluaz0iaHR0cDovL3d3dy53My5vcmcvMTk5OS94bGluayIgdmlld0JveD0iMCAwIDE2NSAxNjUiPjxkZWZzPjxsaW5lYXJHcmFkaWVudCBpZD0ibGluZWFyLWdyYWRpZW50IiB4MT0iMCIgeTE9IjgzLjUiIHgyPSIxNjEuMzkiIHkyPSI4My41IiBncmFkaWVudFRyYW5zZm9ybT0idHJhbnNsYXRlKDAgMTY2KSBzY2FsZSgxIC0xKSIgZ3JhZGllbnRVbml0cz0idXNlclNwYWNlT25Vc2UiPjxzdG9wIG9mZnNldD0iMCIgc3RvcC1jb2xvcj0iIzMyYjdkNiIvPjxzdG9wIG9mZnNldD0iMSIgc3RvcC1jb2xvcj0iI2Y0OTYzYyIvPjwvbGluZWFyR3JhZGllbnQ+PC9kZWZzPjxyZWN0IHdpZHRoPSIxNjUiIGhlaWdodD0iMTY1IiBzdHlsZT0iZmlsbDp1cmwoI2xpbmVhci1ncmFkaWVudCk7Ii8+PHBhdGggZD0iTTU0LjA4LDEzM2gtMjUuOThWMzMuMDNoNDEuMzZjMTEuMjIsMCwxOS44MiwyLjkyLDI1Ljc4LDguNzVzOC45NSwxNC4wNSw4Ljk1LDI0LjY2LTIuOTgsMTguODMtOC45NSwyNC42NmMtNS45Niw1LjgzLTE0LjU2LDguNzUtMjUuNzgsOC43NWgtMTUuMzhzMCwzMy4xNSwwLDMzLjE1Wk01NC4wOCw3OC45aDguNjJjOS41NCwwLDE0LjMyLTQuMTUsMTQuMzItMTIuNDZzLTQuNzctMTIuNDYtMTQuMzItMTIuNDZoLTguNjJ2MjQuOTNoMFpNMTQzLjEsMzNsLTI2LjExLDEwMGgtMjVsMjYuMTEtMTAwaDI1WiIgc3R5bGU9ImZpbGw6I2ZmZjsiLz48L3N2Zz4=
[Pendulum-url]: https://pendulumdev.co.uk/
```

Rules:

- Opening line is `{what it is}. {what this tag is} for vX.Y.Z.`
- ASCII hyphens only. No em dash or en dash.
- Changes are user-visible. Do not list commit hashes or chore-only lockfile noise.
- The Pendulum badge at the end is required. Copy the `[Pendulum-shield]` URL
  from the README so the mark icon is included. Do not use the logo-less
  shields.io URL.
- Do not pass `--generate-notes`. The workflow publishes this file as the body
  and attaches `pendulum_arc.zip`.

## Drupal.org

When the project page exists, the `1.0.x` branch there matches the module
files you intend to ship, including `js/build/`. Alpha tags (`1.0.0-alpha1`)
are for the uncovered project page. `1.0.0` waits until security advisory
coverage is opted in. See [ROADMAP.md](../ROADMAP.md). Paths marked
`export-ignore` in `.gitattributes` are left out of that package. The GitHub
zip uses the same exclusions.

## Version bumps

`0.x` minors are for a new capability or a floor that drops a previously
supported Drupal or PHP version. Patches are fixes and docs. A change to
the stored state keys, the `/admin/pendulum-arc` routes, or the OAuth
client id is a breaking change - say so in the opening line.
