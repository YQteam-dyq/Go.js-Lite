# Migrating 0.8.x → 0.9.0

0.9.0 is a hardening release. It changes what the panel accepts and how it treats an authenticated
session, and it adds a public status page, but it does not change the on-disk layout of `.gojs/` and
it does not remove any endpoint. Upgrading is still an overwrite of the `gojs/` folder.

Read this before upgrading a production instance, because four of the changes are behavioural and
one of them can sign users out.

> Everything below is overridable through `config.php`. The defaults are deliberately strict; a
> deployment that legitimately needs the old behaviour should relax the specific policy rather than
> disable the hardening wholesale.

## What happens automatically on first run

- Nothing has to be migrated. `users.json`, `groups.json`, `config.php`, the audit log, the
  operation log and the backup metadata keep their 0.8 format.
- The first backup created after the upgrade starts carrying an integrity manifest. Backups written
  by 0.8.x keep working and are reported as `legacy` (see below).
- The version now comes from `version.json` at the repository root. `api.php` and
  `tests/bootstrap.php` read it through `gojs_version()`, so a stale hard-coded version can no longer
  drift from the frontend.

## Uploads are validated before the file is written (behaviour change)

0.8.x decided what an upload was from the client-supplied name and a four-pattern scan of the first
4 KiB. 0.9.0 runs `backend/upload_guard.php` from both `upload` and `upload-chunk` and rejects a
request before anything reaches the disk.

Rejected by default:

- Names that hide an executable extension behind a trailing dot, a trailing space, a semicolon or a
  zero-width character - `payload.php.`, `payload.php `, `payload.php;.jpg`, `.htac<ZWSP>cess`.
  Every dot segment is checked, not just the last one, and percent-decoded variants are re-checked,
  so `photo.php.jpg` and `photo.php%2Ejpg` are rejected too.
- Reserved server configuration names: `.htaccess`, `.user.ini`, `php.ini`, `.env`, `web.config`.
- Names longer than `max_filename_bytes`.
- Content that does not match the declared type (sniffed with `finfo`, with a magic-byte fallback).
- Active content in markup: SVG scripts, event handlers, frames, external entities, meta refresh and
  polyglot images.

If an existing workflow depends on any of those, adjust the policy in `config.php` instead of turning
the guard off:

```php
'upload_guard' => array(
    'allowed_extensions' => array('php', 'phtml', 'phar'),
    'allow_active_svg' => true,
    'max_filename_bytes' => 255,
),
```

`GET|POST /api/upload-guard` reports the effective policy, and `POST` with a `path` inspects one
existing file and returns `data.inspection`, which is the quickest way to find out why a specific
upload is refused. Set `enforce` to `false` to log the policy result without blocking, which is the
recommended way to stage the change on a busy instance.

## Sessions are bound to the client (behaviour change, can sign users out)

0.8.x recorded `login_ip` and `login_ua` at sign-in and never checked them again, so a copied session
cookie worked from anywhere. 0.9.0 derives a fingerprint from the client signals plus a random
per-session salt and compares it on every authenticated request.

- Default signals: `ip` (the /24 prefix on IPv4, the first three hextets on IPv6), `ua` and `lang`
  (the `Accept-Language` header).
- The salt is re-issued every `rotate_seconds` (900 by default, minimum 60).
- A mismatch answers `401` with `error.code = "session_fingerprint_mismatch"` and destroys the
  session. The user has to sign in again; there is no grace retry.

Practical consequences to plan for:

- A user who switches network (mobile to office Wi-Fi) will be signed out. On an instance that is
  reached over several networks, drop `ip` from the signals.
- A browser that changes its preferred language will be signed out. Drop `lang` if that is a real
  pattern for your users.

```php
'session_fingerprint' => array(
    'rotate_seconds' => 3600,
    'signals' => array('ua'),
),
```

`GET /api/session-fingerprint` reports the current binding, and `POST` rotates it on demand, which
is the polite way to move a user to a new network without forcing a sign-out.

## Backups are verified before they are restored (behaviour change)

- `backup/create` writes a `manifest.json` entry into the archive recording the size and SHA-256 of
  every other entry plus a digest over that list, so the manifest is tamper evident. The SHA-256 of
  the finished archive is written to `<CONFIG_DIR>/backups/<filename>.sha256` and returned by the API
  as `sha256`.
- `backup/verify` recomputes every entry hash and reports `mismatched`, `missing` and `extra`
  entries. An archive written before 0.9.0 has no manifest and is reported with `legacy` set.
- `backup/precheck` runs the verification and then checks the restore target: the `backup.json`
  metadata entry, parent directory segments in entry names, files root availability and writability,
  free space against the uncompressed footprint.

Restore the same way you did before - the checks run on the server side. If a restore is refused,
`backup/precheck` names the reason; a `legacy` archive that fails only the archive-hash comparison is
intact but cannot be proven so, and can be restored after `backup/verify` reports no entry mismatch.

## Every response now carries a header policy (behaviour change)

`backend/security_headers.php` is the single source of truth for the response headers and applies
them on every response, replacing the three ad-hoc headers that the JSON path used to set. The
notable addition is a context-aware `Content-Security-Policy`:

- API and download responses get `default-src 'none'`, `frame-ancestors 'none'`, `form-action 'none'`.
- Rendered pages get a self-only policy that still allows the inline theme bootstrap in `index.html`.
- `Strict-Transport-Security` is only sent over HTTPS, including behind a proxy that sets
  `X-Forwarded-Proto`.

`GET /api/security/headers` reports the effective policy, which is the fastest way to confirm what a
deployment is actually sending. If you front the panel with custom pages that load remote scripts or
styles, extend the policy rather than disabling it:

```php
'security_headers' => array(
    'csp' => array('html' => "default-src 'self'; img-src 'self' data: https:"),
    'overrides' => array('X-Frame-Options' => 'SAMEORIGIN'),
    'disable' => array('Permissions-Policy'),
),
```

## The status page is public

`/status` is a new, session-independent page. It reports panel availability and component versions
to anyone who can reach the URL, and adds storage usage, configuration checks and thresholds only
when an authenticated session is present. It is intended for uptime monitoring. If the panel is not
supposed to be discoverable, block `/status` at the web server in the same way you would block any
other unauthenticated path.

## Frontend delivery changed

- Routes are code-split. The first paint downloads only the module for the opened route, so a
  deployment that serves the frontend through a proxy must serve the per-route chunk files, not a
  single bundled script.
- The shell is offline-capable: `sw.js`, `manifest.webmanifest` and `offline.html` are served from
  the web root and the service worker is only registered in production builds. A cached shell can
  survive a deploy, so hard-reload once after upgrading if the panel looks stale.
- Directory listings above the windowing threshold are virtualised. Below it the plain DOM is kept,
  so find-in-page and tab order behave as before.

## Deprecations enter their warning stage

0.9.0 is the **warn** milestone of the schedule in [deprecations.md](deprecations.md). Nothing is
removed and nothing breaks; the two legacy surfaces below keep working until 1.0.0, and are frozen
in 0.9.9.

| Deprecated | Replacement | Removed in |
|---|---|---|
| `?api=<action>` query form | `/api/<action>` path form | 1.0.0 |
| `?token=`, `X-Access-Token`, `POST /api/regenerate-access-token` | a scoped API token (`X-API-Token` or `Authorization: Bearer`) | 1.0.0 |

Check whether a script or an integration still uses them:

```bash
curl -sS -D- -o /dev/null 'https://panel.example/gojs/api/bootstrap?token=REDACTED' \
  | grep -iE '^(deprecation|sunset|x-gojs-deprecations):'
```

A non-empty result means the caller is on a deprecated surface. `GET /api/bootstrap` returns the
same information in its `deprecations` field.

> Note: in this release the notice is only emitted on the wire (headers and the bootstrap payload).
> It is not yet repeated in the login or settings screens, so nobody will be warned in the UI.

## New endpoints at a glance

`/api/security/headers`, `/api/upload-guard`, `/api/session-fingerprint`, `/api/backup/verify`,
`/api/backup/precheck`.

The rest of the 0.8.0 surface that had no reference entry - the PHP toolchain, Composer, the App
Store, file sharing, directory protection and the database table and query-builder endpoints - is now
documented in [api.md](api.md).

## Checklist

1. Back up `.gojs/`, `config.php` and the `backups/` directory before you overwrite the folder.
2. Upgrade, then check `GET /api/security/headers` and adjust `security_headers.csp` if you front the
   panel with custom pages.
3. Decide on the session signals. Keep the default `ip` + `ua` + `lang` only if your users stay on one
   network and one browser language; otherwise drop `ip` or `lang` in `config.php`.
4. Re-test the uploads your team actually performs, and set `allowed_extensions` for any extension the
   guard now refuses. Start with `enforce` off if you want to audit first.
5. Confirm that your reverse proxy serves the code-split chunks, `sw.js`, `manifest.webmanifest` and
   `offline.html`.
6. Decide whether `/status` may stay publicly reachable.
7. Re-run any automation that relies on the access-token URL or the query-form API, and plan the move
   to a scoped token before 1.0.0.

## Rollback

Overwrite the folder with the 0.8.x build again. The `.gojs/` directory is not converted, so no data
step is needed. Two things stay behind and are harmless: the `.sha256` sidecars next to newer backups,
and the `manifest.json` entry inside archives created by 0.9.0.
