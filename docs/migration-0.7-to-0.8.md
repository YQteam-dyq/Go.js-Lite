# Migrating 0.7.x → 0.8.0

0.8.0 turns Go.js-Lite into a multi-user panel (admin / operator / viewer). The data format of existing files is backwards compatible; the behaviour of login, settings and a few sensitive endpoints changes. Read this before upgrading a production instance.

> Multi-user is a **collaboration tool, not multi-tenancy**. All users of an instance share one `files_root`, one `config.php`, one set of database connections and one audit log. Serve independent customers with separate instances and keep treating `path_allowlist` as a viewer least-privilege subset — never as a tenant boundary.

## What happens automatically on first run

- `users.json` is created under `.gojs/` and seeded with a single `admin` user mirrored from the legacy `password_hash` in `config.php` (full record: `password_changed_at`, `password_expires_at`, `preferences`, `avatar_color`, …).
- Any legacy global TOTP enrolment in `config.php` (`totp.secret_enc`, recovery codes) is migrated into that admin user's record on first access. `config.php` is no longer the source of truth for TOTP.
- No data migration is required for `operation_log.json`, `monitor_history.json` or file-history data.

## Login changes (breaking)

| 0.7.x | 0.8.0 |
|---|---|
| Single admin password only (`POST /api/login` with `password`) | Username + password against `users.json` (`401 invalid_credentials` on failure) |
| Legacy `?token=<access_token>` URL logs in the implicit admin | Still works for admins (compatibility window), logs a `token_login` audit entry and binds the session to the real admin `user_id`. Deprecated in 0.8.0, removed in 1.0.0 — see [deprecations.md](deprecations.md#legacy_access_token) |
| No 2FA per user | TOTP enrolment, recovery codes and login challenges are per user |

Action: after upgrading, sign in as `admin` with the previous panel password, then create real accounts (Users page) and set strong per-user passwords.

## Settings (breaking)

- The global settings write flow was removed. Theme, language, session timeout, dashboard layout and notification preferences are now stored **per user** in `users.json[].preferences` and managed via `GET/POST /api/profile`.
- `GET /api/settings/export` / `settings/reset` remain admin-only and affect global config, not preferences.
- Notification defaults changed: `admin` users start fully subscribed, `operator` / `viewer` start unsubscribed. Adjust per user on the Notification Preferences page.

## Roles, ACL and tokens

- Unlisted API actions are **admin-only by default**. Existing operator/viewer integrations must either use a role with sufficient rank or be granted `permissions_boost[]` entries (e.g. `files.upload`).
- Viewer file access is limited to `path_allowlist` prefixes; user groups (`groups.json`) contribute their allowlist as a union with the user's own.
- Legacy access tokens (the `api-tokens` API of 0.7) keep working for REST endpoints. New public Bearer tokens live under `/api/tokens` with scopes and per-token rate limits.

## Sensitive actions now require approval

`db/import`, `trash/purge` (purge-all), `logout-all` and `appstore/uninstall` no longer execute immediately for admins when more than one admin exists:

- The API answers `202 { status: "approval_pending", approval: {...} }` and the UI shows a "submitted for approval" notice.
- A second admin (requester ≠ approver) must approve within 60 minutes on the Approvals page; expired requests return `410`.
- Deployments with exactly one admin get `409 single_admin_no_second_factor`. Either add a second admin or accept that these actions cannot be executed.
- Single-item trash purge (`{ id: ... }`) is not gated.

## Session management

- `GET /api/sessions` now lists **all** live sessions (session-store scan + current session) instead of only the caller's session.
- `POST /api/sessions/kick` (or `POST /api/sessions/{sid}/kick`) revokes a session by fingerprint; kicking your own session returns `409 cannot_kick_self` — use `POST /api/logout-all` (itself approval-gated) instead.

## New endpoints at a glance

`/api/groups`, `/api/tokens` (Bearer), `/api/invitations[/preview|/accept]`, `/api/devices[/trust|/{fp}]`, `/api/profile/export[/{id}]`, `/api/notification-preferences`, `/api/approvals[/{id}/decide]`, `/api/audit/aggregate`, `/api/user_activity/{recent,online,{user_id}}`, `/api/composer/{status,install,require,update,json}`, `/api/php/{opcache/*,extensions,errors,fpm/*,bench/*,ini-diff,jit,include-path,processes,upgrade-check,autoload-audit}`.

Unavailable host capabilities answer `501` with a machine-readable code (`composer_unavailable`, `opcache_unavailable`, `fpm_not_applicable`, `ini_readonly`) instead of failing silently.

## Checklist

1. Back up `.gojs/` and `config.php`.
2. Deploy the new build, open the panel, log in as `admin` (previous panel password).
3. Verify `.gojs/users.json` exists and contains the mirrored admin.
4. Create real user accounts and assign roles / `path_allowlist` / groups.
5. Re-check any scripts that used the global settings write API or assumed admin-only defaults.
6. If you rely on the gated actions, add a second admin or update your automation.
