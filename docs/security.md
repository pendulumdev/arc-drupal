# Security

What this module sends, and what it keeps on the site.

## Sent to Arc

Connect and score reads call the Arc API for the active environment.

- Production portal: `https://arc.pendulumdev.co.uk`
- Production API: `https://api.arc.pendulumdev.co.uk`
- Preview portal: `https://arc.preview.pendulumdev.co.uk`
- Preview API: `https://api.arc.preview.pendulumdev.co.uk`

An API key is sent once, to pair or create the site, and again if you
reconnect with the key already saved here. Arc sign-in uses the
OAuth code flow with PKCE. The browser goes to the portal, where Arc pairs
or creates the site, then returns to this site's Connect screen. The module
stores the token that comes back. It does not store the Arc password.

## Stored on this site

The connection is one state value, `pendulum_arc.connection`. The token is
sealed with the PHP sodium extension before it is written. The key is derived
from the Drupal hash salt. The admin screens receive a public view: site,
project, and method. They do not receive the token or the API key.

A pending API-key attempt and the PKCE verifier are sealed in the expirable
key-value store, per user, and expire within 30 minutes. A flash notice
expires within 2 minutes.

Disconnect deletes that state on this install. It does not delete the site
or its reports in Arc. An Arc admin revokes the key or the connected app
there. Uninstall deletes the connection state, the expirable keys, the
consent state, and the consent log.

## Cookie consent

The banner is local. It does not send the choice to Arc, and it works
before the site is paired.

The visitor's choice is the cookie `arc_cookie_consent`. It lasts 180 days,
with `SameSite=Lax`. It stores the consent version, whether they accepted
or declined, and the ids of the cookies they allowed. It is not `HttpOnly`,
because the page script has to read it. `HttpOnly` cookies cannot be
deleted by that script. Drupal session cookies (`SSESS` and `SESS`) are
treated as required for that reason, so the banner does not try to delete
them.

Optional scripts are printed as inert `text/plain` tags, with
`data-arc-cookie` and `data-arc-script-type`. The alter uses the saved
settings, never the visitor cookie, so the page HTML does not change per
visitor. After a choice, the script turns the allowed tags into real
scripts. Disable leaves the page and its script tags unchanged.

Settings are the state value `pendulum_arc.consent`. Catalog detection can
write during an anonymous page view, so this is not exported config. A
renew or reset bumps the consent version and the banner asks again.

When consent logging is on, rows go in `pendulum_arc_consent_log`: time,
consent version, allowed cookie names, refused cookie names, the direct
`REMOTE_ADDR` value, and the user agent. Forwarded headers are not trusted.
The log route is public (`/pendulum-arc/consent/log`), takes no CSRF token
(a cached page would keep a stale one), rejects unknown cookie ids, and
allows 30 writes an hour from one address. Disable identifiable data points
leaves the IP and user agent empty on new rows. Rows already stored stay
as they are. Logging is off until an administrator enables it.

Uninstall drops the log table and the consent state.

## Accepted limits

- **Administrators can connect the site.** The screens and the admin JSON
  routes require `administer pendulum arc`. A user without that permission
  cannot read the connection. Those JSON routes also require the session
  CSRF token. The public consent log route does not: it checks the payload
  and the hourly limit instead.
- **The local DDEV bridge is not part of the module.** `dev/local-arc.php`
  is included only by the DDEV settings hook. It rewrites `localhost` so PHP
  inside Docker can reach a portal on the host. Do not ship that file in a
  release. `.gitattributes` marks it `export-ignore`.
- **`http://localhost` is refused unless `$settings['pendulum_arc_allow_http_local']`
  is true.** That is the local-development door, not a production setting.
