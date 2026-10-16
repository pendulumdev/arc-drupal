## What and why

<!-- One or two sentences. What changes, and what problem it solves. -->

## Checklist

- [ ] `composer test`, `composer lint`, `npm test` and `npm run build` pass
- [ ] Meaningful tests for new rules and boundaries
- [ ] Docs updated in this change (`docs/`, `README.md`, `ROADMAP.md`) if behaviour moved
- [ ] ASCII hyphens only - no en or em dashes
- [ ] Commit messages are tag-line only (`[ADD] ...`)

## Module

- [ ] `package.json`, `pendulum_arc.info.yml`, and `composer.json` `version` still agree
- [ ] `npm run build` output in `js/build/` is committed when the admin UI changed
- [ ] A connection secret is stored sealed, and is not written into a notice, a log, or the page

## Breaking changes

<!-- The stored state keys, the routes under /admin/pendulum-arc, and the
     OAuth client id are the module contract. -->

- [ ] No contract change, or the break is described above and the version bump reflects it
