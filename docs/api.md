# Go.js Lite — Backend API Documentation

> This document describes the HTTP API exposed by the Go.js Lite backend (`backend/`). All requests go through the `api.php` entry point and are dispatched by `/api/<action>` or `?api=<action>` via `router.php` and `backend/Router.php`.

Project location: `/workspace/Go.js-Lite`

---

## Table of Contents

1. [General Conventions](#general-conventions)
2. [Authentication & Authorization](#authentication--authorization)
3. [Endpoint Overview](#endpoint-overview)
4. [Endpoint Details](#endpoint-details)
   - [Authentication & Installation](#authentication--installation)
   - [File Management](#file-management)
   - [System & Settings](#system--settings)
   - [Database](#database)
   - [HTACCESS](#htaccess)
   - [Backup / Trash / Backup Destinations](#backup--trash--backup-destinations)
   - [Cron / Scheduled Tasks](#cron--scheduled-tasks)
   - [SSL / ACME](#ssl--acme)
   - [Two-Factor Authentication 2FA](#two-factor-authentication-2fa)
   - [Notifications / Monitoring / Alerts](#notifications--monitoring--alerts)
   - [Upgrade / Deploy](#upgrade--deploy)
   - [Security Scan](#security-scan)
   - [FTP](#ftp)
   - [API Token / REST](#api-token--rest)
   - [Internal Cron / WebCron](#internal-cron--webcron)

---

## General Conventions

### Request Entry

- Route prefix: `/api/<action>` (path form). The `?api=<action>` query parameter form still works but is **deprecated** since 0.8.0 and is removed in 1.0.0; see [deprecations.md](deprecations.md#query_api). Requests that use it receive `Deprecation`, `Sunset` and `X-Gojs-Deprecations` response headers.
- Note: `router.php` strips the deployment prefix (e.g. `/gojs/`), so the backend only cares about the `/api/<action>` part.
- In this project, most actions are registered by `gojs_build_router()` in `backend/core.php`; dynamic endpoints (containing `{id}`) are registered with `addPrefix()`.

### Request Methods

- `GET`: queries (parameters are passed via the query string).
- `POST`: creates / performs an action (JSON body or `multipart/form-data`).
- `PUT` / `PATCH`: updates.
- `DELETE`: deletes.
- When the route does not match the method, `405 method_not_allowed` is returned.

### Parameter Sources

`gojs_get_param($key, $default)` reads values in the order `$_GET` → JSON body → `$_POST`, so the same parameter can be passed either via the query string or a JSON request body.

### Unified Response Structure

All endpoints return `Content-Type: application/json` with one of two structures:

**Success (HTTP 2xx):**

```json
{
  "ok": true,
  "data": { ... }
}
```

**Failure (HTTP 4xx / 5xx):**

```json
{
  "ok": false,
  "error": {
    "code": "error_code",
    "message": "human-readable error message"
  }
}
```

Notes:

- `ok`: a boolean indicating whether the request succeeded.
- `data`: business data on success (may be an object, an array, or absent).
- `error.code`: a machine-readable error code (e.g. `forbidden`, `not_found`, `method_not_allowed`).
- `error.message`: a human-readable error description.
- Some endpoints also attach `error.message_key` (frontend i18n key) or `error.retry_after` extension fields.
- Some download endpoints (`download`, `db/export`, `backup/download`, `settings/export`, etc.) return a binary file stream directly instead of JSON.

### Authentication & Authorization

- Public routes (no login required): `bootstrap`, `install`, `login`, `env-check`.
- All other routes require `gojs_check_auth()` (session authentication) and `gojs_check_csrf()` (CSRF validation).
- Some endpoints support access via `?token=<access_token>` (e.g. `bootstrap`, `login`). This legacy access token is **deprecated** since 0.8.0 and is removed in 1.0.0; use a scoped API token instead. See [deprecations.md](deprecations.md#legacy_access_token) for the headers and the removal schedule.
- Deprecated surfaces answer with `Deprecation`, `Sunset`, `Link ... rel="deprecation"` and `X-Gojs-Deprecations` headers; `GET /api/bootstrap` also returns the whole registry in its `deprecations` field.
- Requests authenticated with an API Token can only access the `api/*` REST endpoints; otherwise `403 token_not_allowed` is returned.

---

## Authentication & Authorization

| action | Method | Description |
| --- | --- | --- |
| `bootstrap` | GET/POST | App bootstrap: installation status, login status, CSRF token, capability list |
| `install` | POST | First-time installation (set admin password, root directory) |
| `login` | POST | Login (password + TOTP / recovery code) |
| `logout` | POST | Logout |
| `change-password` | POST | Change the admin password |
| `auth/totp/status` | GET | Query 2FA status |
| `auth/totp/enroll` | POST | Enable 2FA (generate secret and recovery codes) |
| `auth/totp/confirm` | POST | Confirm enabling 2FA |
| `auth/totp/disable` | POST | Disable 2FA |
| `auth/totp/recovery-codes` | POST | View / regenerate / download recovery codes |

---

## Endpoint Overview

| Module | action | Method | Description |
| --- | --- | --- | --- |
| Authentication | `bootstrap` | GET/POST | App bootstrap |
| Authentication | `install` | POST | First-time installation |
| Authentication | `login` | POST | Login |
| Authentication | `logout` | POST | Logout |
| Authentication | `change-password` | POST | Change password |
| Authentication | `auth/totp/status` | GET | 2FA status |
| Authentication | `auth/totp/enroll` | POST | Enable 2FA |
| Authentication | `auth/totp/confirm` | POST | Confirm 2FA |
| Authentication | `auth/totp/disable` | POST | Disable 2FA |
| Authentication | `auth/totp/recovery-codes` | POST | Recovery code management |
| Settings | `settings` | GET/POST | Read / update settings |
| Settings | `settings/export` | GET | Export settings |
| Settings | `settings/reset` | POST | Reset settings (uninstall) |
| Settings | `regenerate-access-token` | POST | Regenerate the access token |
| Files | `files` | GET/POST | List directory / file operations (action) |
| Files | `file-content` | GET/PUT | Read / write file content |
| Files | `file-save` | POST | Save a file |
| Files | `file-mkdir` | POST | Create a directory |
| Files | `file-touch` | POST | Create a file |
| Files | `file-delete` | POST | Delete (move to trash) |
| Files | `file-rename` | POST | Rename |
| Files | `file-copy` | POST | Copy |
| Files | `file-chmod` | POST | Change permissions |
| Files | `file-search` | GET/POST | Search files |
| Files | `file-zip` | POST | Compress to zip |
| Files | `file-unzip` | POST | Extract zip |
| Files | `file-targz` | POST | Compress to tar.gz |
| Files | `file-untargz` | POST | Extract tar.gz |
| Files | `upload` | POST | Upload files |
| Files | `upload-chunk` | POST | Chunked upload |
| Files | `upload-guard` | GET/POST | Upload policy and content inspection |
| Files | `download` | GET/POST | Download a file |
| Trash | `trash` | GET | Trash list |
| Trash | `trash/restore` | POST | Restore a file |
| Trash | `trash/purge` | POST | Permanently delete |
| Trash | `trash/config` | GET/POST | Trash on/off |
| System | `dashboard` | GET/POST | Dashboard overview |
| System | `system` | GET/POST | System information |
| System | `system/processes` | GET/POST | Process list |
| System | `phpinfo` | GET/POST | PHP information |
| System | `phpinfo/ini` | GET/POST | PHP ini configuration |
| System | `health-check` | GET/POST | Configuration health check |
| System | `env-check` | GET/POST | Environment check (public) |
| System | `install/check` | GET/POST | Pre-installation check |
| System | `disk-analysis` | GET/POST | Disk usage analysis |
| System | `disk-analysis/large-files` | GET/POST | Large file analysis |
| Logs | `error-log` | GET/POST | Error log |
| Logs | `error-log/clear` | POST | Clear error log |
| Logs | `operation-log` | GET/POST | Operation log |
| Logs | `operation-log/clear` | POST | Clear operation log |
| Logs | `operation-log/export` | POST | Export operation log |
| Alerts | `alert-rules` | GET/POST | Alert rule list / create |
| Alerts | `alert-rules/{id}` | PUT/DELETE | Update / delete alert rule |
| Alerts | `alert-rules/{id}/test` | POST | Test alert rule |
| Database | `db/connections` | GET/POST | Connection list / create |
| Database | `db/connections/{id}` | PUT/DELETE | Update / delete connection |
| Database | `db/databases` | GET/POST | Database list |
| Database | `db/tables` | GET/POST | Table list |
| Database | `db/structure` | GET/POST | Table structure |
| Database | `db/sql` | POST | Execute SQL |
| Database | `db/export` | POST | Export SQL |
| Database | `db/import` | POST | Import SQL |
| htaccess | `htaccess` | GET/POST | Read / update `.htaccess` |
| htaccess | `htaccess/generate` | POST | Generate `.htaccess` |
| htaccess | `htaccess/reset` | POST | Reset `.htaccess` |
| Backup | `backup/create` | POST | Create a backup |
| Backup | `backup/list` | GET/POST | Backup list |
| Backup | `backup/download` | GET/POST | Download a backup |
| Backup | `backup/delete` | POST | Delete a backup |
| Backup | `backup/restore` | POST | Restore a backup |
| Session Fingerprint | `session-fingerprint` | GET/POST | Session binding state, or rotate the binding now |
| Backup | `backup/verify` | GET/POST | Verify a backup against its integrity manifest |
| Backup | `backup/precheck` | GET/POST | Restore precheck for a backup |
| Backup Destination | `backup/destinations` | GET/POST | Destination list / create |
| Backup Destination | `backup/destinations/{id}` | PUT/DELETE | Update / delete destination |
| Backup Destination | `backup/destinations/test` | POST | Test destination |
| Backup Destination | `backup/destinations/browse` | POST | Browse destination |
| Backup Destination | `backup/destinations/download` | POST | Download from destination |
| Backup Schedule | `backup/schedules` | GET/POST | Schedule list / create |
| Backup Schedule | `backup/schedules/{id}` | PUT/DELETE | Update / delete schedule |
| Backup Schedule | `backup/schedules/{id}/run-now` | POST | Run now |
| Backup Run | `backup/runs` | GET | Run record list |
| Backup Run | `backup/runs/{id}` | GET | Run record details |
| Cron | `system/cron` | GET/POST | System crontab list |
| Cron | `cron/capabilities` | GET/POST | Cron capability detection |
| Cron | `cron/list` | GET/POST | Task list |
| Cron | `cron/save` | POST | Save tasks |
| SSL | `ssl/check` | POST | Check SSL |
| SSL | `ssl/list` | GET/POST | Certificate list |
| SSL | `ssl/add-domain` | POST | Add a domain |
| SSL | `ssl/remove-domain` | POST | Remove a domain |
| SSL | `ssl/capabilities-acme` | GET | ACME capabilities |
| SSL | `ssl/certificates` | GET | ACME certificate list |
| SSL | `ssl/issue-cert` | POST | Issue a certificate |
| SSL | `ssl/certificates/{id}` | DELETE/PATCH | Delete / auto-renew |
| SSL | `ssl/certificates/{id}/renew` | POST | Renew a certificate |
| SSL | `ssl/certificates/{id}/download-pem` | POST | Download PEM |
| SSL | `ssl/certificates/{id}/auto-renew` | PATCH | Set auto-renew |
| Notifications | `notification/channels` | GET/POST | Channel list / create |
| Notifications | `notification/channels/{id}` | PUT/DELETE | Update / delete channel |
| Notifications | `notification/channels/{id}/test` | POST | Test channel |
| Notifications | `notifications` | GET | Notification list |
| Notifications | `notifications/summary` | GET | Notification summary |
| Notifications | `notifications/{id}` | PATCH/DELETE | Mark read / delete |
| Notifications | `notifications/read-all` | PATCH | Mark all read |
| Notifications | `notifications/clear-read` | DELETE | Clear read notifications |
| Monitoring | `monitor` | GET | Panel traffic monitoring |
| Upgrade | `upgrade/check` | GET | Check for upgrades |
| Upgrade | `upgrade/progress` | GET | Upgrade progress |
| Upgrade | `upgrade/apply` | POST | Apply upgrade |
| Deploy | `deploy/apps` | GET | Deployable app list |
| Deploy | `deploy/run` | POST | Run deployment |
| Security Scan | `secscan/frontend` | GET/POST | Frontend security scan |
| Security Scan | `secscan/backend` | GET/POST | Backend security scan |
| Security Headers | `security/headers` | GET | Effective HTTP response header policy |
| FTP | `ftp/capabilities` | GET | FTP capabilities |
| FTP | `ftp/accounts` | GET/POST | Account list / create |
| FTP | `ftp/accounts/{id}` | PUT/DELETE | Update / delete account |
| FTP | `ftp/accounts/{id}/test-login` | POST | Test login |
| FTP | `ftp/sync` | POST | Sync to FTP service |
| FTP | `ftp/export` | POST | Export FTP configuration |
| API Token | `api-tokens` | GET/POST | Token list / create |
| API Token | `api-tokens/{id}` | DELETE | Revoke a token |
| REST | `api/status` | GET | Service status |
| REST | `api/backup/run` | POST | Trigger a backup run |
| REST | `api/files` | GET | File REST list |
| Composer | `composer/status` | GET | Composer availability and project lock statistics |
| Composer | `composer/install` | POST | Run composer install |
| Composer | `composer/require` | POST | Add a composer dependency |
| Composer | `composer/update` | POST | Update composer dependencies |
| Composer | `composer/json` | GET | Read composer.json and composer.lock |
| PHP Toolchain | `php/opcache/status` | GET | OPcache statistics |
| PHP Toolchain | `php/opcache/reset` | POST | Reset the OPcache |
| PHP Toolchain | `php/opcache/toggle` | POST | Enable or disable OPcache |
| PHP Toolchain | `php/opcache/profile` | POST | Apply a recommended OPcache profile |
| PHP Toolchain | `php/extensions` | GET | List loaded PHP extensions |
| PHP Toolchain | `php/extensions/favorite` | POST | Toggle a favorite extension |
| PHP Toolchain | `php/errors` | GET | Parse and aggregate the PHP error log |
| PHP Toolchain | `php/fpm/status` | GET | PHP-FPM pool status |
| PHP Toolchain | `php/fpm/slowlog` | GET | Tail the PHP-FPM slow log |
| PHP Toolchain | `php/bench/run` | POST | Run PHP micro-benchmarks |
| PHP Toolchain | `php/bench/compare` | GET | Compare two benchmark runs |
| PHP Toolchain | `php/ini-diff` | GET | Diff current php.ini against the baseline |
| PHP Toolchain | `php/jit` | GET/POST | Read or set the JIT configuration |
| PHP Toolchain | `php/include-path` | GET/POST | Read or set the include_path |
| PHP Toolchain | `php/processes` | GET | List PHP processes |
| PHP Toolchain | `php/processes/snapshot` | GET/POST | Capture or download a PHP snapshot |
| PHP Toolchain | `php/upgrade-check` | GET | Check PHP version upgrade blockers |
| PHP Toolchain | `php/autoload-audit` | GET | Audit backend autoload statements |
| Database | `db/table/data` | GET/POST/PUT/DELETE | Browse table rows (paginated) |
| Database | `db/table/insert` | POST | Insert one row |
| Database | `db/table/update` | POST | Update one row |
| Database | `db/table/delete` | POST | Delete one row |
| Database | `db/table/create` | POST | Create a table |
| Database | `db/table/alter` | POST | Alter a table column |
| Database | `db/table/create-index` | POST | Create an index |
| Database | `db/table/drop-index` | POST | Drop an index |
| Database | `db/query/builder` | POST | Build and run a SELECT query |
| Database | `db/query/preview` | POST | Preview a SELECT query (LIMIT 10) |
| Database | `db/export/enhanced` | POST | Export database (sql/json/csv/xml) |
| App Store | `appstore/list` | GET | List installed apps |
| App Store | `appstore/install` | POST | Install an app |
| App Store | `appstore/uninstall` | POST | Uninstall an app |
| File Sharing | `share/create` | POST | Create a share link |
| File Sharing | `share/list` | GET | List share links |
| File Sharing | `share/revoke` | POST | Revoke a share link |
| Directory Protection | `dir-protect/status` | GET | Directory protection status |
| Directory Protection | `dir-protect/enable` | POST | Enable directory protection |
| Directory Protection | `dir-protect/disable` | POST | Disable directory protection |
| Directory Protection | `dir-protect/users` | POST | Manage directory users |
| Accounts | `profile` | GET/POST/PATCH/PUT | Read / update account profile |
| Accounts | `logout-all` | POST | Log out all sessions |
| Accounts | `audit/aggregate` | GET | Aggregate audit log |
| Internal | `internal/cron` | POST | Internal cron trigger |
| Internal | `internal/cron/tick` | POST | Internal cron trigger |
| Internal | `internal/cron/regenerate-token` | POST | Regenerate internal token |
| Internal | `internal/cron/drain-outbox` | POST | Drain notification outbox |
| Internal | `webcron/status` | GET | WebCron status |

---

## Endpoint Details

### Authentication & Installation

#### `bootstrap` (GET/POST)

App bootstrap endpoint, called by the frontend on startup.

- Parameters: none (optional `?token=<access_token>`).
- Returns `data`:

```json
{
  "authenticated": true,
  "installed": true,
  "csrfToken": "…",
  "capabilities": { "phpVersion": "7.4.33", "cron": true, "mysql": true, "maxUpload": 104857600 },
  "backendVersion": "0.5.0",
  "frontendVersion": "0.5.0",
  "user": { "username": "admin" },
  "settings": { "theme": "system", "language": "en", "sessionTimeout": 1800 }
}
```

#### `install` (POST)

First-time installation.

- Parameters: `password` (at least 8 characters), `rootPath` (optional, must be an existing directory).
- Returns `data`: `authenticated`, `installed`, `csrfToken`, `capabilities`, `user`, `accessToken`, etc.
- Failure codes: `already_installed`, `invalid_password`, `invalid_root_path`, etc.

#### `login` (POST)

- Parameters: `username`, `password`, `totp` (optional, TOTP code), `recovery_code` (optional, recovery code).
- Returns `data`: same login-state fields as `bootstrap`.
- Failure codes: `invalid_credentials` (401), `ip_locked` (429), `totp_required` (401), `totp_invalid` (401), `recovery_code_invalid` (401), etc.

#### `logout` (POST)

- Parameters: none.
- Returns `data`: `{ "success": true }`.

#### `change-password` (POST)

- Parameters: `oldPassword`, `newPassword` (at least 8 characters).
- Returns `data`: `{ "success": true }`.
- Failure codes: `invalid_password`.

#### `auth/totp/status` (GET)

- Returns `data`: `{ "enabled": bool, "hasSecret": bool, "recoveryCodesCount": number }`.

#### `auth/totp/enroll` (POST)

- Parameters: none.
- Returns `data`: `{ "secret": string, "otpauth_url": string, "qr_svg_data_url": string, "recovery_codes": string[] }`.

#### `auth/totp/confirm` (POST)

- Parameters: `code` (6-digit number).
- Returns `data`: `{ "success": true }`.

#### `auth/totp/disable` (POST)

- Parameters: `admin_password`.
- Returns `data`: `{ "success": true }`.

#### `auth/totp/recovery-codes` (POST)

- Parameters: `admin_password`, `action` (`view` / `regenerate` / `download`).
- Returns `data`: `{ "recovery_codes": string[], "recovery_codes_count": number, ... }`.

### Settings

#### `settings` (GET/POST)

- GET: read current settings. Returns `data`: `{ "theme", "language", "sessionTimeout", "logRetention", "accessToken" }`.
- POST: update settings. Parameters (JSON body): `theme` (light/dark/system), `language` (en/zh), `sessionTimeout`, `logRetention`. Returns the updated settings object.

#### `settings/export` (GET)

- Returns a JSON file download (includes `theme`, `language`, `sessionTimeout`, `rootPath`, `exportedAt`, `version`).

#### `settings/reset` (POST)

- Resets and clears the configuration (equivalent to uninstall). Returns `data`: `{ "success": true }`.

#### `regenerate-access-token` (POST)

- Returns `data`: `{ "accessToken": "…" }`.

### File Management

#### `files` (GET / POST)

- GET: list a directory.
  - Parameters: `path` (relative to the root, default `/`), `sort` (name/size/mtime), `order` (asc/desc).
  - Returns `data`: `{ "files": [{ "name", "path", "type", "size", "mtime", "perms", "readable", "writable" }], "path" }`.
- POST: file operations, distinguished by `action`:
  - `create_file` → create a file (same as `file-touch`)
  - `create_dir` → create a directory (same as `file-mkdir`)
  - `delete` → delete (same as `file-delete`)
  - `rename` → rename (same as `file-rename`)
  - `copy` → copy (same as `file-copy`)
  - `chmod` → change permissions (same as `file-chmod`)

#### `file-content` (GET / PUT)

- GET: read file content.
  - Parameters: `path`.
  - Returns `data`: text file → `{ "type": "text", "content", "size", "mime", "encoding", "lines", "truncated" }`; image/binary → `{ "type": "image"|"binary", "content": base64, ... }`.
- PUT: save file content (same as `file-save`).

#### `file-save` (POST)

- Parameters: `path`, `content`.
- Returns `data`: `{ "success": true }`.

#### `file-mkdir` (POST)

- Parameters: `path`.
- Returns `data`: file info object.

#### `file-touch` (POST)

- Parameters: `path`.
- Returns `data`: file info object.

#### `file-delete` (POST)

- Parameters: `path`, `recursive` (optional boolean, recursively delete a non-empty directory).
- Moves to trash by default. Returns `data`: `{ "success": true, "trashed": true }`.

#### `file-rename` (POST)

- Parameters: `path`, `target` (new name within the same directory; cross-directory moves are not supported).
- Returns `data`: the renamed file info object.

#### `file-copy` (POST)

- Parameters: `path`, `target`.
- Returns `data`: `{ "success": true }`.

#### `file-chmod` (POST)

- Parameters: `path`, `perms` (octal string, e.g. `0755`).
- Returns `data`: `{ "success": true }`.

#### `file-search` (GET/POST)

- Parameters: `path`, `q` (search keyword).
- Returns `data`: `{ "files": [...], "total": number }` (up to 100 entries).

#### `file-zip` (POST)

- Parameters: `paths` (array), `target`.
- Returns `data`: `{ "success": true, "target" }`.

#### `file-unzip` (POST)

- Parameters: `path`, `target`.
- Returns `data`: `{ "success": true, "extracted": number }`.

#### `file-targz` (POST)

- Parameters: `paths` (array), `target`.
- Returns `data`: `{ "success": true, "target" }`.

#### `file-untargz` (POST)

- Parameters: `path`, `target`.
- Returns `data`: `{ "success": true, "extracted": number }`.

#### `upload` (POST)

- Form fields: `target` (target directory), `files` (file array, or `file` for a single file).
- Returns `data`: `{ "success": true, "files": [{ "name", "size" }], "errors": [...] }`.
- Every file passes the upload guard before it is written and again after it lands on disk. Rejected files appear in `data.errors[]` with `name`, `error`, `code` and `reason`; see [Upload rejection reasons](#upload-rejection-reasons).

#### `upload-chunk` (POST)

- Parameters (JSON body): `chunk` (base64), `chunkIndex`, `totalChunks`, `fileName`, `target`, `uploadId`.
- Returns `data`: `{ "success": true, "merged": bool, "progress": "n/total", "received", "totalChunks" }`.
- The merged file is inspected with the same policy as `upload`; a rejection answers `400` with `error.code`, `error.reason` and `error.details`.

#### `upload-guard` (GET/POST)

- Returns `data`: `{ "success": true, "policy": { ... } }`.
- `policy.enforce`: whether the guard is blocking requests.
- `policy.sniff`: whether content sniffing is enabled.
- `policy.block_active_content`: whether active markup (scripts, event handlers, frames) is rejected.
- `policy.block_php_payload`: whether server-side script tags are rejected.
- `policy.allow_active_svg`: whether SVG files may carry active content.
- `policy.max_filename_bytes`: maximum accepted file name length in bytes.
- `policy.max_scan_bytes`: content scan ceiling, `0` scans the whole file.
- `policy.blocked_extensions`: extensions that are always rejected, including nested segments such as `photo.php.jpg`.
- `policy.allowed_extensions`: when non-empty this list becomes the primary gate and everything else is rejected.
- `policy.protected_names`: server configuration file names that cannot be uploaded.
- `policy.php_payload_exempt_extensions`: extensions that keep the legacy exemption for embedded script tags.
- `policy.sniff_available`: whether `finfo` or `mime_content_type` is available on this host.
- Optional parameter `path` inspects one existing file and adds `data.inspection` (`ok`, `code`, `message`, `details`).

#### Upload rejection reasons

Both upload endpoints share one policy. A rejected file reports `error.code` (or `errors[].code`) as one of:

- `blocked_extension`: the extension chain resolves to a server-executable extension such as `php`, `phtml`, `phar` or `pht`.
- `protected_name`: the name is reserved by the server configuration (`.htaccess`, `.htpasswd`, `.user.ini`, `php.ini`, `.env`, `web.config`).
- `extension_not_allowed`: an allow list is configured and the extension is not part of it.
- `filename_too_long`: the normalised name exceeds `max_filename_bytes`.
- `invalid_name`: the name becomes empty after normalisation.
- `php_payload`: the content carries server-side script tags that do not match the extension.
- `type_spoof`: the detected content type contradicts the extension.
- `active_svg`: the SVG carries scripts, event handlers, frames or external entities.
- `active_content`: non-SVG content carries active markup.
- `unreadable`: the file could not be read for inspection.

The name checks run before the file is written, so `blocked_extension`, `protected_name`, `extension_not_allowed`, `filename_too_long` and `invalid_name` never leave a file on disk.

#### `download` (GET/POST)

- Parameters: `path`.
- Returns: binary file stream (`Content-Disposition: attachment`).

### Trash

#### `trash` (GET)

- Returns `data`: `{ "items": [{ "id", "orig_path", "type", "size", "deleted_at" }], "total_size", "enabled" }`.

#### `trash/restore` (POST)

- Parameters: `id`.
- Returns `data`: `{ "success": true }`.

#### `trash/purge` (POST)

- Parameters: `id` (optional; defaults to purging everything).
- Returns `data`: `{ "success": true, "purged": number }`.

#### `trash/config` (GET/POST)

- GET returns `{ "enabled": bool }`; POST accepts the `enabled` parameter and saves it.

### System & Settings

#### `dashboard` (GET/POST)

- Returns `data`: `phpVersion`, `sapi`, `webServer`, `hostname`, `timezone`, `now`, `diskTotal`, `diskFree`, `diskUsed`, `rootPath`, `fileCount`, `totalSize`, `maxUpload`, `maxPost`, `memoryLimit`, `recentFiles`.

#### `system` (GET/POST)

- Returns `data`: `diskTotal`, `diskFree`, `diskUsed`, `loadAverage`, `uptime`, `serverAddr`, `serverName`, `webServer`, `memTotal`, `memAvailable`, `memUsed`, `memPercent`.

#### `system/processes` (GET/POST)

- Returns `data`: process array `[{ "pid", "name", "cmdline", "cpu", "mem" }]`.

#### `phpinfo` (GET/POST)

- Returns `data`: `version`, `sapi`, `iniFile`, `loadedExtensions`, `coreIni`, `env`, `server`.

#### `phpinfo/ini` (GET/POST)

- Parameters: `search` (optional).
- Returns `data`: ini key-value object.

#### `health-check` (GET/POST)

- Returns `data`: `{ "security": [...], "performance": [...], "compatibility": [...], "summary": { pass, warning, danger, total } }`.

#### `env-check` (GET/POST)

- Public endpoint. Returns `data`: `{ "items": [...], "summary": { total, passed, failed } }`.

#### `install/check` (GET/POST)

- Returns `data`: `{ "pass": bool, "checks": [...], "disabledFunctions": [...] }`.

#### `disk-analysis` (GET/POST)

- Parameters: `path`.
- Returns `data`: `{ "directories": [{ "name", "path", "size", "fileCount", "percent" }], "totalSize", "diskTotal", "diskFree" }`.

#### `disk-analysis/large-files` (GET/POST)

- Parameters: `path`, `threshold` (bytes, default 10485760).
- Returns `data`: `{ "files": [...], "total": number }`.

### Logs

#### `error-log` (GET/POST)

- Parameters: `limit` (default 50, max 500).
- Returns `data`: `{ "found": bool, "path": string|null, "entries": [{ "message", "type" }], "size": number }`.

#### `error-log/clear` (POST)

- Returns `data`: `{ "success": true }`.

#### `operation-log` (GET/POST)

- Parameters: `type`, `ip`, `user`, `date_from`, `date_to`, `page`.
- Returns `data`: `{ "logs": [...], "total", "page", "per_page", "total_pages" }`.

#### `operation-log/clear` (POST)

- Returns `data`: `{ "ok": true }`.

#### `operation-log/export` (POST)

- Parameters: `format` (csv/jsonl/json), `scope` (all/current_filter) and filter conditions.
- Returns: a file download.

### Alert Rules

#### `alert-rules` (GET / POST)

- GET: returns a rules array.
- POST: parameters `name`, `enabled`, `when`, `then`. Returns the created rule object.

#### `alert-rules/{id}` (PUT / DELETE)

- PUT: parameters as above, updates the rule. DELETE: deletes the rule.

#### `alert-rules/{id}/test` (POST)

- Triggers a rule test notification. Returns `data`: `{ "ok": true, "fired": true }`.

### Database

#### `db/connections` (GET / POST)

- GET: returns a connections array (without passwords).
- POST: parameters `name`, `host`, `port`, `username`, `password`, `database`. Returns the new connection (without password).

#### `db/connections/{id}` (PUT / DELETE)

- PUT: updates the connection (same parameters as above, partial updates allowed). DELETE: deletes the connection.

#### `db/databases` (GET/POST)

- Parameters: `connId`.
- Returns `data`: database name array.

#### `db/tables` (GET/POST)

- Parameters: `connId`, `database`.
- Returns `data`: table array `[{ "name", "engine", "rows", "size", "collation", "comment" }]`.

#### `db/structure` (GET/POST)

- Parameters: `connId`, `database`, `table`.
- Returns `data`: column array `[{ "name", "type", "nullable", "key", "default", "extra" }]`.

#### `db/sql` (POST)

- Parameters: `connId`, `database`, `sql`.
- Returns `data`: `{ "results": [{ "success", "statement", "rows"|"affectedRows"|"error" }], "executionTime" }`.

#### `db/export` (POST)

- Parameters: `connId`, `database`, `tables` (array, optional), `mode` (structure_only/structure_data).
- Returns: an SQL file download.

#### `db/import` (POST)

- Form fields: `connId`, `database`, `file` (.sql), `allowDangerous` (optional boolean).
- Returns `data`: `{ "success": true, "executed", "failed", "errors" }`.

### Database Tables

#### `db/table/data` (GET / POST / PUT / DELETE)

- Parameters: `connId`, `database`, `table`, `page` (default 1), `limit` (default 50), `sortField`, `sortOrder` (ASC/DESC).
- Returns `data`: `{ "success", "data", "pagination": { "page", "limit", "total", "totalPages" } }`.
- The handler does not branch on the HTTP method; every method returns the same paginated row slice.

#### `db/table/insert` (POST)

- Parameters: `connId`, `database`, `table`, `data` (associative array of column => value).
- Returns `data`: `{ "success", "insertId", "sql" }`.
- Failure codes: `invalid_request` (400) when database, table or data is missing.

#### `db/table/update` (POST)

- Parameters: `connId`, `database`, `table`, `primaryKey`, `primaryKeyValue`, `data`.
- Returns `data`: `{ "success", "affectedRows", "sql" }`.
- Failure codes: `invalid_request` (400) when a required parameter is missing.

#### `db/table/delete` (POST)

- Parameters: `connId`, `database`, `table`, `primaryKey`, `primaryKeyValue`.
- Returns `data`: `{ "success", "affectedRows", "sql" }`.
- Failure codes: `invalid_request` (400) when a required parameter is missing.

#### `db/table/create` (POST)

- Parameters: `connId`, `database`, `tableName`, `columns` (array of column definitions), `engine` (default InnoDB), `charset` (default utf8mb4).
- Returns `data`: `{ "success", "sql" }`.
- Failure codes: `invalid_request` (400), `invalid_table_name` (400), `invalid_engine` (400), `invalid_charset` (400), `invalid_column_definition` (400).

#### `db/table/alter` (POST)

- Parameters: `connId`, `database`, `tableName`, `action` (`ADD`/`DROP`/`MODIFY`), `column` (definition array).
- Returns `data`: `{ "success", "sql" }`.
- Failure codes: `invalid_request` (400), `invalid_table_name` (400), `invalid_action` (400), `invalid_column_definition` (400), `invalid_column_name` (400), `invalid_after_column` (400).

#### `db/table/create-index` (POST)

- Parameters: `connId`, `database`, `tableName`, `indexName`, `columns` (array), `indexType` (default INDEX).
- Returns `data`: `{ "success", "sql" }`.
- Failure codes: `invalid_request` (400), `invalid_table_name` (400), `invalid_index_name` (400), `invalid_index_type` (400), `invalid_column_name` (400).

#### `db/table/drop-index` (POST)

- Parameters: `connId`, `database`, `tableName`, `indexName`.
- Returns `data`: `{ "success", "sql" }`.
- Failure codes: `invalid_request` (400), `invalid_table_name` (400), `invalid_index_name` (400).

### Database Query Builder

#### `db/query/builder` (POST)

- Parameters: `connId`, `database`, `table`, `columns`, `conditions`, `groupBy`, `orderBy`, `limit` (default 100), `offset` (default 0).
- Builds a `SELECT` statement from the supplied clauses, executes it, and returns `data`: `{ "success", "data", "sql", "count" }`.
- `conditions` use operators `eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `like`, `in`, `null`, `notnull`.

#### `db/query/preview` (POST)

- Parameters: `connId`, `database`, `table`, `columns`, `conditions`, `groupBy`, `orderBy`.
- Builds the same `SELECT` statement but ignores `limit`/`offset` and appends `LIMIT 10`, executes it, and returns `data`: `{ "success", "data", "sql", "count" }`.

#### `db/export/enhanced` (POST)

- Parameters: `connId`, `database`, `tables` (array, optional), `format` (sql/json/csv/xml, default sql), `compression` (default none), `includeStructure` (default true), `includeData` (default true), `whereClause` (default empty).
- Returns a file download in the chosen `format` (an SQL/JSON/CSV/XML dump); this is a binary stream, not a JSON `data` object.
- Failure codes: `db_export_failed` (400) when the output stream cannot be opened.

### HTACCESS

#### `htaccess` (GET/POST)

- GET returns the current `.htaccess` content; POST updates the content.

#### `htaccess/generate` (POST)

- Generates the default `.htaccess`.

#### `htaccess/reset` (POST)

- Resets `.htaccess`.

### Backup / Trash / Backup Destinations

#### `backup/create` (POST)

- Parameters: `include_files`, `include_db`, `include_config`, `exclude_dirs` (array).
- Returns `data`: `{ "filename", "size", "sha256", "metadata" }`.
- Every archive carries a `manifest.json` entry that records the size and the SHA-256 of every other entry, plus a digest over that list. The archive SHA-256 is written to `<filename>.sha256` next to the archive.

#### `backup/list` (GET/POST)

- Returns `data`: `{ "backups": [{ "filename", "size", "created", "metadata" }] }`.

#### `backup/download` (GET/POST)

- Parameters: `filename`.
- Returns: a zip file download.

#### `backup/delete` (POST)

- Parameters: `filename`.
- Returns `data`: `{ "success": true }`.

#### `backup/restore` (POST)

- Parameters: `filename`, `strict` (optional), `force` (optional).
- Runs the restore precheck first. When the precheck fails the endpoint answers `409` with `error.code = "restore_precheck_failed"` and the full precheck report in `error.precheck`, and nothing is written.
- `force` skips the precheck, and `strict` makes a missing integrity manifest an error instead of a warning.
- Returns `data`: `{ "success": true }`.

#### `backup/verify` (GET / POST)

- Parameters: `filename`.
- Recomputes every entry hash from the archive and compares it with `manifest.json`, then compares the archive SHA-256 with the `<filename>.sha256` sidecar when one exists.
- Returns `data`: `{ "filename", "ok", "legacy", "code", "message", "entry_count", "entries_checked", "mismatched", "missing", "extra", "archive_sha256", "expected_archive_sha256" }`.
- `code` is one of `ok`, `manifest_missing` (an archive written before this feature, `legacy` is true), `manifest_invalid`, `manifest_mismatch`, `entry_mismatch`, `entry_missing`, `entry_untracked`, `archive_hash_mismatch`, `invalid_filename`, `not_found`, `zip_unavailable`, `zip_open_failed`.

#### `backup/precheck` (GET / POST)

- Parameters: `filename`, `strict` (optional).
- Runs `backup/verify` and then checks the restore target: metadata entry present, no parent directory segments, files root available and writable, free space against the uncompressed footprint, and whether the database dumps map to a configured connection.
- Returns `data`: `{ "filename", "ok", "code", "message", "strict", "errors", "warnings", "verification", "stats", "free_space", "required_bytes", "known_databases" }`.

#### `backup/destinations` (GET / POST)

- GET: returns a destinations array. POST: parameters (type, host, credentials, etc.) create a destination.

#### `backup/destinations/{id}` (PUT / DELETE)

- Updates / deletes a destination.

#### `backup/destinations/test` (POST)

- Tests destination connectivity.

#### `backup/destinations/browse` (POST)

- Browses destination directories.

#### `backup/destinations/download` (POST)

- Downloads a file from a destination.

#### `backup/schedules` (GET / POST)

- GET: returns `{ "schedules": [...] }`. POST: parameters `name`, `cron_expr`, `destination_ids`, `source`, `retention`, etc. create a schedule.

#### `backup/schedules/{id}` (PUT / DELETE)

- Updates / deletes a schedule.

#### `backup/schedules/{id}/run-now` (POST)

- Runs immediately. Returns `data`: `{ "run_id", "ok" }`.

#### `backup/runs` (GET)

- Parameters: `schedule_id`, `limit`, `offset`.
- Returns `data`: `{ "runs": [...], "total", "limit", "offset" }`.

#### `backup/runs/{id}` (GET)

- Returns `data`: `{ "run": {...} }`.

### Cron / Scheduled Tasks

#### `system/cron` (GET/POST)

- Reads the system crontab tasks. Returns `data`: task array `[{ "expression", "command", "raw" }]`.

#### `cron/capabilities` (GET/POST)

- Returns `data`: `{ "available", "exec_available", "crontab_available", "method", "cron_file", "message" }`.

#### `cron/list` (GET/POST)

- Returns `data`: `{ "jobs": [...] }`.

#### `cron/save` (POST)

- Parameters: `jobs` (array, each item contains `expression`, `command`).
- Returns `data`: `{ "ok": true }`.

### SSL / ACME

#### `ssl/check` (POST)

- Checks the SSL status of a domain.

#### `ssl/list` (GET/POST)

- Returns a certificate list.

#### `ssl/add-domain` (POST)

- Adds a domain for SSL.

#### `ssl/remove-domain` (POST)

- Removes a domain.

#### `ssl/capabilities-acme` (GET)

- Returns `data`: `{ "available", "acme_extensions_ok", "docroot_known", "challenges_dir_writable", "reason_key" }`.

#### `ssl/certificates` (GET)

- Returns `data`: `{ "records": [{ "id", "domain", "status_derived", "not_after_ts", ... }] }`.

#### `ssl/issue-cert` (POST)

- Parameters: `domain`, `email`, `accept_tos`, `ca`.
- Returns `202` on success; returns `data`: `{ "ok": true, "certificate_id" }`.

#### `ssl/certificates/{id}` (DELETE / PATCH)

- DELETE: deletes a certificate. PATCH: sets auto-renewal (parameter `auto_renew_days_before`).

#### `ssl/certificates/{id}/renew` (POST)

- Renews a certificate immediately.

#### `ssl/certificates/{id}/download-pem` (POST)

- Downloads the PEM file.

#### `ssl/certificates/{id}/auto-renew` (PATCH)

- Sets the auto-renewal days.

### Two-Factor Authentication 2FA

See the `auth/totp/*` endpoints in the [Authentication & Installation](#authentication--installation) section.

### Notifications / Monitoring / Alerts

#### `notification/channels` (GET / POST)

- GET: returns a channels array (masked). POST: parameters `type` (email/smtp/webhook), `name`, `enabled` and corresponding type-specific fields create a channel.

#### `notification/channels/{id}` (PUT / DELETE)

- Updates / deletes a channel.

#### `notification/channels/{id}/test` (POST)

- Sends a test message. Returns the send result.

#### `notifications` (GET)

- Parameters: `category`, `read`, `unread_only`, `limit`, `offset`.
- Returns `data`: `{ "items": [...], "total", "unread_count" }`.

#### `notifications/summary` (GET)

- Returns `data`: `{ "total", "unread", "latest_5": [...] }`.

#### `notifications/{id}` (PATCH / DELETE)

- PATCH: marks the notification as read. DELETE: deletes the notification.

#### `notifications/read-all` (PATCH)

- Marks all as read. Returns `{ "success": true }`.

#### `notifications/clear-read` (DELETE)

- Clears read notifications. Returns `{ "success": true }`.

#### `monitor` (GET)

- Returns panel traffic monitoring data.

### Upgrade / Deploy

#### `upgrade/check` (GET)

- Checks whether a new version is available.

#### `upgrade/progress` (GET)

- Queries upgrade progress.

#### `upgrade/apply` (POST)

- Applies an upgrade.

#### `deploy/apps` (GET)

- Returns the deployable application list.

#### `deploy/run` (POST)

- Runs a deployment.

### Security Scan

#### `secscan/frontend` (GET / POST)

- GET returns scan results (without running); POST triggers a scan.

#### `secscan/backend` (GET / POST)

- GET returns scan results; POST triggers a scan.

### Security Headers

#### `security/headers` (GET)

- Admin only. Returns the effective header policy that the panel emits on every response.
- `data.https`: whether the current request is considered HTTPS, which controls `Strict-Transport-Security`.
- `data.defaultContext`: the context used when a response does not declare one (`api`).
- `data.headers`: the name to value map for the default context.
- `data.contexts`: the same map per response context (`api` for JSON and attachment responses, `html` for rendered pages).
- `data.count`: number of headers in the default context.

The policy is emitted automatically and can be tuned through the `security_headers` key of `config.php`:

- `security_headers.disable`: array of header names to stop sending.
- `security_headers.overrides`: array of header name to replacement value; names are matched case-insensitively.
- `security_headers.csp`: array of context (`api`, `html`) to a replacement `Content-Security-Policy`.

### FTP

#### `ftp/capabilities` (GET)

- Returns `data`: FTP capabilities (`available`, `method`, `default_uid`, `default_gid`, etc.).

#### `ftp/accounts` (GET / POST)

- GET: returns an accounts array (masked). POST: parameters `username`, `password`, `home_dir`, `uid`, `gid`, `quota_size_mb`, `quota_files`, `upload_bw_kbps`, `download_bw_kbps`, `allow_client_ips`, `deny_client_ips`, `enabled`, `expires_at_ts` create an account.

#### `ftp/accounts/{id}` (PUT / DELETE)

- Updates / deletes an account.

#### `ftp/accounts/{id}/test-login` (POST)

- Tests account login.

#### `ftp/sync` (POST)

- Syncs accounts to the FTP service (proftpd/pure-ftpd).

#### `ftp/export` (POST)

- Exports the FTP configuration.

### Session Fingerprint

#### `session-fingerprint` (GET / POST)

- GET returns the binding state of the current session; POST rotates the fingerprint now and returns the new state.
- Available to every signed-in account, like `devices`.
- Returns `data`: `{ "bound", "signals", "enforce", "rotateSeconds", "issuedAt", "age", "rotations", "mismatches", "lastMismatchAt" }`.
- The fingerprint itself is never returned. It is derived from the client signals listed in `signals` and a random per-session salt, and a new salt is issued every `rotateSeconds` (900 by default).
- When `enforce` is on and the client signals no longer match the binding, the request is rejected with `401` and `error.code = "session_fingerprint_mismatch"`, and the session is destroyed.
- Configure it through `session_fingerprint` in `config.php`: `enforce` (default `true`), `rotate_seconds` (default `900`, minimum `60`), and `signals` as a subset of `ip`, `ua`, `lang` (default all three). The `ip` signal compares the first three IPv4 octets, or the first three IPv6 hextets, so a new address inside the same subnet stays valid. Narrow `signals` to `["ua", "lang"]` on deployments where client addresses change often.

### API Token / REST

#### `api-tokens` (GET / POST)

- GET: returns a token list. POST: creates an API Token.

#### `api-tokens/{id}` (DELETE)

- Revokes the specified token.

#### `api/status` (GET)

- Returns service status.

#### `api/backup/run` (POST)

- Triggers a backup run (REST).

#### `api/files` (GET)

- File REST list endpoint.

### Composer

- Admin only: the ACL pre-check in `core.php` restricts every `composer` and `php/` action to the admin role.

#### `composer/status` (GET)

- Returns `data`: `{ "available", "executable", "php_version", "php_requirement", "composer_json", "composer_lock", "composer_json_path", "composer_lock_path", "vendor_autoload", "vendor_present", "lock", "install_guide" }`.
- `lock` carries `{ "packages", "dev_packages", "depth", "content_hash", "plugin_api_version", "platform" }`.
- When composer is unavailable, `install_guide` lists install steps.

#### `composer/install` (POST)

- Runs `composer install --no-dev --no-interaction`.
- Returns `data`: `{ "ok": true, "log" }` (last 80 install log lines).
- Failure returns `composer_failed` (500) with `detail` and `log`.
- Returns `501 composer_unavailable` when no composer executable is found.

#### `composer/require` (POST)

- Parameters (JSON body): `package` (vendor/name), `version` (optional).
- Validates the package name; invalid form returns `400 invalid_package`.
- Returns `data`: `{ "ok": true, "package", "log" }`. Failure returns `composer_failed` (500).
- Returns `501 composer_unavailable` when no composer executable is found.

#### `composer/update` (POST)

- Runs `composer update --no-interaction`.
- Returns `data`: `{ "ok": true, "log" }`. Failure returns `composer_failed` (500).
- Returns `501 composer_unavailable` when no composer executable is found.

#### `composer/json` (GET)

- Returns `data`: `{ "json", "lock", "json_path", "lock_path" }` (decoded `composer.json` and `composer.lock`, or null).

### PHP Toolchain

- Admin only: the ACL pre-check in `core.php` restricts every `php/` and `composer` action to the admin role.

#### `php/opcache/status` (GET)

- Returns `data`: `{ "available": true, "summary", "raw", "config" }`.
- `summary`: `{ "enabled", "hits", "misses", "hit_rate", "cached_scripts", "used_memory", "free_memory", "wasted_memory", "oom_restarts", "hash_restarts", "manual_restarts", "jit" }`.
- `config` reflects `opcache.enable`, `opcache.memory_consumption`, `opcache.jit`, `opcache.jit_buffer_size`.
- When OPcache is unavailable returns `501 opcache_unavailable`.

#### `php/opcache/reset` (POST)

- Resets the OPcache. Returns `data`: `{ "reset": true, "summary" }`.
- Returns `501 opcache_reset_failed` if the reset fails (e.g. restricted by `opcache.restrict_api`).
- Returns `501 opcache_unavailable` if OPcache is missing.

#### `php/opcache/toggle` (POST)

- Parameters (JSON body): `enable` (bool) or `state` (`on`/`off`/`enable`/`disable`/`1`/`0`).
- Returns `data`: `{ "target", "runtime", "effective", "current", "reload_required" }`.
- Invalid state returns `400 invalid_state`.
- When the value cannot change at runtime returns `501 ini_readonly` with the same payload.
- Returns `501 opcache_unavailable` if OPcache is missing.

#### `php/opcache/profile` (POST)

- Applies a recommended OPcache profile (tracing JIT + 256M buffers). Returns `data`: `{ "applied", "php_ini_required", "profile" }`.
- Returns `501 ini_readonly` when no directive could be applied at runtime.

#### `php/extensions` (GET)

- Returns `data`: `{ "count", "zend_count", "favorites" (array), "extensions" }`.
- Each extension is `{ "name", "version", "zend", "favorite" }`.

#### `php/extensions/favorite` (POST)

- Parameters (JSON body): `name`, `favorite` (optional bool; defaults to toggling).
- Returns `data`: `{ "name", "favorite", "favorites" }`.
- Returns `400 invalid_name` when the name is missing, `404 extension_not_loaded` when it is not loaded, `500 write_failed` when the favorites file cannot be written.

#### `php/errors` (GET)

- Parameters: `since` (default `24h`; accepts `<n>[smhd]` or a date string) and `severity` (comma-separated `fatal`/`warning`/`notice`/`deprecated`).
- Returns `data`: `{ "since", "since_ts", "severity_filter", "sources", "sources_count", "parsed_total", "aggregate" }`.
- `aggregate`: `{ "total", "by_severity", "by_code", "buckets", "top_codes" }`.

#### `php/fpm/status` (GET)

- Optional parameter `port` selects the FPM status port (default 9000).
- Returns `data`: `{ "url", "status" }`, where `status` is `{ "pool", "active", "idle", "total", "max_active", "max_children_reached", "accepted_conn", "listen_queue", "requests_per_sec", "format", "raw" }`.
- Returns `501 fpm_not_applicable` when the SAPI is not fpm-fcgi/cgi-fcgi.
- Returns `502 fpm_status_unreachable` or `502 fpm_status_unparsable` on endpoint failure.

#### `php/fpm/slowlog` (GET)

- Optional parameter `path` points at a slowlog file.
- Returns `data`: `{ "path", "count", "lines" }` (last 100 lines).
- Returns `501 fpm_not_applicable` when the SAPI is not applicable.
- Returns `404 slowlog_not_configured` or `404 slowlog_unreadable` when no file is found or readable.

#### `php/bench/run` (POST)

- Parameters (JSON body): `iterations` (1-100000, optional).
- Returns `data`: `{ "id", "created_at", "iterations", "duration_ms", "items" }`.
- Each item is `{ "name", "available", "reason", "avg_us", "ops_per_sec" }`.
- Out of range returns `400 invalid_iterations`. The run is saved for later comparison.

#### `php/bench/compare` (GET)

- Parameters: `version` (comma-separated run ids, optional).
- Returns `data`: `{ "a", "b", "rows" }`, where each row is `{ "name", "a_avg_us", "b_avg_us", "diff_pct", "faster" }`.
- Returns `404 not_enough_runs` when fewer than two runs exist, or `404 bench_run_not_found` for unknown ids.

#### `php/ini-diff` (GET)

- Returns `data`: `{ "loaded_file", "scanned_file", "baseline_file", "total", "mismatch", "rows" }`.
- Each row is `{ "directive", "current", "recommended", "severity", "note", "match" }`.

#### `php/jit` (GET / POST)

- GET: returns `data`: `{ "raw_mode", "mode", "buffer_size", "buffer_size_mb", "user_ini_path" }`.
- POST: parameters `mode` (`tracing`/`function`/`none`) and `buffer_size_mb` (0-4096). Writes to `.user.ini` and attempts a runtime change.
- Returns `data`: `{ "saved_to_ini", "user_ini_path", "runtime", "effective", "current", "reload_required" }`.
- Invalid values return `400 invalid_mode` or `400 invalid_buffer`.
- Returns `501 ini_readonly` when the change could not be applied at runtime.

#### `php/include-path` (GET / POST)

- GET: returns `data`: `{ "current", "user_ini_path", "user_ini", "user_ini_include_path", "writable", "separator" }`.
- POST: parameter `paths` (array of strings). Writes to `.user.ini` and attempts a runtime change.
- Returns `data`: `{ "saved", "user_ini_path", "include_path", "paths", "runtime", "reload_required" }`.
- Returns `400 invalid_paths` when `paths` is not an array or contains NUL/newline characters.
- Returns `500 write_failed` when `.user.ini` cannot be written.

#### `php/processes` (GET)

- Returns `data`: `{ "supported", "os", "self_pid", "php_binary", "sapi", "count", "processes" }`.
- Each process is `{ "pid", "user", "mem_percent", "cpu_percent", "elapsed", "cmdline", "mem_kb" }`.
- `supported` is false when `shell_exec` is unavailable.

#### `php/processes/snapshot` (GET / POST)

- POST: captures `php -m` and `php -i`, writes `php_snapshot.txt`. Returns `data`: `{ "path", "bytes", "modules_lines", "php_binary" }`.
- Returns `501 snapshot_unavailable` when the PHP CLI cannot be run; `500 write_failed` on write failure.
- GET: returns the saved snapshot. Returns `data`: `{ "path", "bytes", "content" }`.
- Returns `404 snapshot_missing` when no snapshot has been captured yet.

#### `php/upgrade-check` (GET)

- Returns `data`: `{ "current", "required_constraint", "required_min", "recommended", "upgrade_needed", "blocker_count", "blockers" }`.
- Each blocker is `{ "file", "line", "msg", "requires" }`.

#### `php/autoload-audit` (GET)

- Returns `data`: `{ "backend_dir", "autoload_file", "vendor_autoload_present", "registered_count", "unregistered_count", "registered", "suggestions" }`.
- Returns `500 no_panel_root` when `PANEL_ROOT` is not defined.

### App Store

#### `appstore/list` (GET)

- Returns `data`: `{ "apps": [{ "id", "installed", ...manifest fields }] }` from each app's `manifest.json`.

#### `appstore/install` (POST)

- Parameters: `app_id`.
- Runs the app install script (or `install.php`), writes the install marker, and returns `data`: `{ "app_id", "success", "steps" }`.
- Failure codes: `missing_param` (400), `app_not_found` (404), `invalid_manifest` (500).

#### `appstore/uninstall` (POST)

- Parameters: `app_id`.
- Runs the app uninstall script (or `uninstall.php`), removes the install marker, and returns `data`: `{ "app_id", "success" }`.
- Two-person approval: when two or more admins exist this route returns `202` with `data` `{ "status": "approval_pending", "approval" }` and defers execution until a second admin approves; a single admin returns `409` (`single_admin_no_second_factor`), or `409` (`approval_already_pending`) if a request is already open.
- Failure codes: `missing_param` (400), `app_not_found` (404), `not_installed` (400).

### File Sharing

#### `share/create` (POST)

- Parameters: `path`, `expires_in` (hours, default 24), `password` (default empty), `max_downloads` (default 0).
- Returns `data`: `{ "share_url", "token", "expires_at", "expires_in" }`.
- Failure codes: `missing_param` (400), `invalid_param` (400, non-string password/expires_in/max_downloads), `forbidden` (403, path access denied), `not_found` (404).

#### `share/list` (GET)

- Returns `data`: `{ "shares": [{ "token", "path", "created_at", "expires_at", "remaining_seconds", "max_downloads", "download_count", "has_password", "is_dir" }] }`.

#### `share/revoke` (POST)

- Parameters: `token` (32 hex chars).
- Returns `data`: `{ "success": true }`.
- Failure codes: `missing_param` (400) when the token is missing or malformed.

### Directory Protection

#### `dir-protect/status` (GET)

- Parameters: `path`.
- Returns `data`: `{ "protected", "auth_name", "users" }`.
- Failure codes: `missing_param` (400), `forbidden` (403).

#### `dir-protect/enable` (POST)

- Parameters: `path`, `auth_name` (default "Restricted Area"), `users` (array of `{ "username", "password" }`).
- Writes `.htpasswd` and `.htaccess` and returns `data`: `{ "success", "protected", "auth_name" }`.
- Failure codes: `missing_param` (400), `forbidden` (403), `not_writable` (403).

#### `dir-protect/disable` (POST)

- Parameters: `path`.
- Strips the auth block from `.htaccess` and removes `.htpasswd`; returns `data`: `{ "success", "protected": false }`.
- Failure codes: `missing_param` (400), `forbidden` (403).

#### `dir-protect/users` (POST)

- Parameters: `path`, `action` (`add`/`delete`/`change-password`), `username`, `password`.
- Returns `data`: `{ "success", "users" }` (array of remaining usernames).
- Failure codes: `missing_param` (400), `forbidden` (403), `invalid_password` (400), `missing_password` (400), `invalid_action` (400).

### Users

#### `users` (GET)

- Parameters: none.
- Returns `data`: `{ "users": [ sanitized user objects ], "total" }`.
- Each user object omits `password_hash` and `totp`.
- Restricted: admin only (ACL pre-check).

#### `users` (POST)

- Parameters: `username`, `password`, `role` (admin / operator / viewer, default viewer), `path_allowlist` (array), `permissions_boost` (array).
- Returns `data`: the created (sanitized) user object.
- Failure codes: `invalid_username` (400), `invalid_role` (400), `weak_password` (400), `username_exists` (409), `write_failed` (500).
- Restricted: admin only; answers `201` on success.

#### `users/{id}` (PATCH)

- Parameters: `role`, `username`, `path_allowlist`, `permissions_boost`, `disabled`, `password`.
- Returns `data`: the updated (sanitized) user object.
- Failure codes: `not_found` (404), `cannot_change_own_role` (409), `cannot_disable_own` (409), `weak_password` (400), `write_failed` (500).
- Restricted: admin only.

#### `users/{id}` (DELETE)

- Parameters: none.
- Returns `data`: `{ "success": true }`.
- Failure codes: `not_found` (404), `cannot_delete_last_admin` (409), `write_failed` (500).
- Restricted: admin only.

### Sessions

#### `sessions` (GET)

- Parameters: none.
- Returns `data`: `{ "sessions": [ ... ], "total" }`.
- Restricted: admin only (ACL pre-check).

#### `sessions/kick` (POST)

- Parameters: `sid` (session fingerprint).
- Returns `data`: `{ "success": true, "sid" }`.
- Failure codes: `invalid_sid` (400), `cannot_kick_self` (409), `session_not_found` (404).
- Restricted: admin only.

#### `sessions/{sid}/kick` (POST)

- Parameters: none (the fingerprint is taken from the path).
- Returns `data`: `{ "success": true, "sid" }`.
- Failure codes: `invalid_sid` (400), `cannot_kick_self` (409), `session_not_found` (404).
- Restricted: admin only.

### Groups

#### `groups` (GET)

- Parameters: none.
- Returns `data`: `{ "groups": [ ... ], "total" }`.
- Restricted: admin only (ACL pre-check).

#### `groups` (POST)

- Parameters: `name`, `path_allowlist` (array), `member_ids` (array).
- Returns `data`: the created group object.
- Failure codes: `invalid_name` (400), `name_exists` (409), `write_failed` (500).
- Restricted: admin only; answers `201` on success.

#### `groups/{id}` (PATCH / PUT)

- Parameters: `name`, `path_allowlist` (array), `member_ids` (array).
- Returns `data`: the updated group object.
- Failure codes: `not_found` (404), `invalid_name` (400), `name_exists` (409), `write_failed` (500).
- Restricted: admin only.

#### `groups/{id}` (DELETE)

- Parameters: none.
- Returns `data`: `{ "success": true }`.
- Failure codes: `not_found` (404), `write_failed` (500).
- Restricted: admin only.

#### `groups/{id}/members` (POST)

- Parameters: `add` (array of user ids), `remove` (array of user ids).
- Returns `data`: the updated group object.
- Failure codes: `not_found` (404), `write_failed` (500).
- Restricted: admin only.

### API Tokens

#### `tokens` (GET)

- Parameters: none.
- Returns `data`: `{ "tokens": [ sanitized token objects ], "total" }`.
- A non-admin only sees tokens whose `created_by` equals the caller.
- Restricted: operator or admin (ACL pre-check).

#### `tokens` (POST)

- Parameters: `name`, `scopes` (array), `path_prefix`, `rate_limit_per_min`, `expires_at`.
- Returns `data`: the created token object, including `token_plain_once` (the only time the secret is shown).
- Failure codes: `invalid_name` (400), `invalid_scopes` (400), `scope_not_allowed` (403), `invalid_expires_at` (400), `write_failed` (500).
- Restricted: operator or admin; answers `201` on success.

#### `tokens/{id}` (DELETE)

- Parameters: none.
- Returns `data`: `{ "success": true }`.
- Failure codes: `not_found` (404), `insufficient_role` (403), `write_failed` (404).
- Restricted: operator or admin; a non-admin may only revoke tokens they created.

### Invitations

#### `invitations` (GET)

- Parameters: none.
- Returns `data`: `{ "invitations": [ ... ], "total" }`.
- Restricted: admin only (ACL pre-check).

#### `invitations` (POST)

- Parameters: `email`, `role` (admin / operator / viewer, default viewer), `path_allowlist` (array), `groups` (array), `message`.
- Returns `data`: the created invitation object, including `token` and `invite_url`.
- Failure codes: `invalid_email` (400), `invalid_role` (400), `already_pending` (409), `write_failed` (500).
- Restricted: admin only; answers `201` on success.

#### `invitations/{id}` (DELETE)

- Parameters: none.
- Returns `data`: `{ "success": true }`.
- Failure codes: `not_found` (404), `not_pending` (409), `write_failed` (409).
- Restricted: admin only.

#### `invitations/preview` (GET)

- Parameters: `token` (the invitation token, query string).
- Returns `data`: `{ "status", "role", "email_masked", "expires_at", "suggested_username" }`.
- Failure codes: `invite_not_found` (404).
- Public: reachable without a session. The ACL pre-check returns before the role test for this route, so the invitation token is the only credential.

#### `invitations/accept` (POST)

- Parameters: `token`, `username`, `password`.
- Returns `data`: `{ "success": true, "username", "role" }`.
- Failure codes: `invalid_token` (400), `invalid_password` (400), `invite_not_found` (404), `invite_expired` (410), `invite_revoked` (410), `invite_used` (409), `invalid_username` (400), `username_exists` (409), `weak_password` (400), `write_failed` (500).
- Public: reachable without a session. The ACL pre-check returns before the role test for this route, so the invitation token authorizes the acceptance.

### Approvals

#### `approvals` (GET)

- Parameters: none.
- Returns `data`: `{ "pending": [ ... ], "mine": [ ... ], "total", "pending_total", "admin_count", "ttl_seconds", "policy" }`.
- `policy` lists the gated action names: `database.delete`, `monitoring.disable`, `sessions.kick_all`, `trash.purge_all`, `appstore.uninstall`.
- Restricted: admin only (ACL pre-check).

#### `approvals/{id}/approve` (POST)

- Parameters: `reason` (optional).
- Returns `data`: `{ "status", "approval", "result" }`.
- Failure codes: `unauthorized` (401), `invalid_decision` (400), `approval_expired` (410), `approval_already_decided` (409), `cannot_self_approve` (409), `not_found` (404), `write_failed` (500).
- Restricted: admin only.

#### `approvals/{id}/deny` (POST)

- Parameters: `reason` (optional).
- Returns `data`: `{ "status", "approval", "result" }`.
- Failure codes: `unauthorized` (401), `invalid_decision` (400), `approval_expired` (410), `approval_already_decided` (409), `cannot_self_approve` (409), `not_found` (404), `write_failed` (500).
- Restricted: admin only.

Two-person approval gate (defined in `approvals.php`, not an account endpoint): when an admin performs a gated action (db/import, monitor/disable, logout-all, trash/purge, appstore/uninstall) and fewer than two admins exist, the request answers `409` with `single_admin_no_second_factor`; otherwise the action is deferred and the request answers `202` with `status: approval_pending` and the `approval` object. If an identical pending request already exists the request answers `409` with `approval_already_pending`. None of the account endpoints documented above are gated by this flow.

### Trusted Devices

#### `devices` (GET)

- Parameters: none.
- Returns `data`: `{ "devices": [ ... ], "total", "ttl_days" }`.
- Available to every signed-in account; scoped to the current account.

#### `devices/trust` (POST)

- Parameters: none (trusts the caller's current device).
- Returns `data`: the trusted device object.
- Failure codes: `unauthorized` (401), `write_failed` (500).
- Available to every signed-in account; answers `201` on success.

#### `devices/{fingerprint}` (DELETE)

- Parameters: none (the fingerprint is taken from the path).
- Returns `data`: `{ "success": true }`.
- Failure codes: `invalid_fingerprint` (400), `user_not_found` (400), `not_found` (404), `write_failed` (400).
- Available to every signed-in account; scoped to the current account.

### Profile Export

#### `profile/export` (POST)

- Parameters: none.
- Returns `data`: `{ "export_id", "bytes", "signed_expires_at", "download_path" }`.
- Failure codes: `unauthorized` (401), `write_failed` (500).
- Available to every signed-in account; scoped to the current account; answers `202` on success.

#### `profile/export/{id}` (GET)

- Parameters: `exp`, `sig` (the signed query returned in `download_path`).
- Returns: a zip file stream (`Content-Disposition: attachment`).
- Failure codes: `unauthorized` (401), `export_not_found` (404), `forbidden` (403), `export_link_expired` (410), `invalid_signature` (403), `export_file_missing` (410).
- Available to every signed-in account; scoped to the current account.

### Notification Preferences

#### `notification-preferences` (GET)

- Parameters: none.
- Returns `data`: `{ "notifications", "defaults", "levels", "categories", "role" }`.
- Available to every signed-in account; scoped to the current account.

#### `notification-preferences` (PATCH / PUT)

- Parameters: `notifications` (per-channel `severity_min` and `categories`), or the channel map directly.
- Channels are `email` and `inapp`; categories are `security`, `auth`, `system`, `files`, `backup`, `cron`.
- Returns `data`: the same shape as GET.
- Failure codes: `unauthorized` (401).
- Available to every signed-in account; scoped to the current account.

### User Activity

#### `user_activity` (GET)

- Parameters: `since` (default `24h`).
- Returns `data`: `{ "since", "since_ts", "total", "failed", "rows" }` (aggregate by user).
- Restricted: admin only (ACL pre-check, unlisted action).

#### `user_activity/recent` (GET)

- Parameters: `since` (default `24h`).
- Same response as `user_activity` (the router maps `user_activity` GET to `user_activity/recent`).
- Restricted: admin only.

#### `user_activity/online` (GET)

- Parameters: none.
- Returns `data`: `{ "sessions": [ ... ], "total" }` (currently active sessions).
- Restricted: admin only.

#### `user_activity/{id}` (GET)

- Parameters: `since` (default `7d`), `limit` (default 100, max 500).
- Returns `data`: `{ "user_id", "username", "role", "since", "since_ts", "count", "entries" }`.
- Restricted: admin only.

---

## Part 2 - Overview rows

| Users | `users` | GET | List users |
| Users | `users` | POST | Create a user |
| Users | `users/{id}` | PATCH | Update a user |
| Users | `users/{id}` | DELETE | Delete a user |
| Sessions | `sessions` | GET | List active sessions |
| Sessions | `sessions/kick` | POST | Kick a session by fingerprint |
| Sessions | `sessions/{sid}/kick` | POST | Kick a session by path fingerprint |
| Groups | `groups` | GET | List groups |
| Groups | `groups` | POST | Create a group |
| Groups | `groups/{id}` | PATCH/PUT | Update a group |
| Groups | `groups/{id}` | DELETE | Delete a group |
| Groups | `groups/{id}/members` | POST | Add or remove group members |
| API Tokens | `tokens` | GET | List API tokens |
| API Tokens | `tokens` | POST | Create an API token |
| API Tokens | `tokens/{id}` | DELETE | Revoke an API token |
| Invitations | `invitations` | GET | List invitations |
| Invitations | `invitations` | POST | Create an invitation |
| Invitations | `invitations/{id}` | DELETE | Revoke an invitation |
| Invitations | `invitations/preview` | GET | Preview an invitation |
| Invitations | `invitations/accept` | POST | Accept an invitation |
| Approvals | `approvals` | GET | List pending and own approvals |
| Approvals | `approvals/{id}/approve` | POST | Approve a request |
| Approvals | `approvals/{id}/deny` | POST | Deny a request |
| Trusted Devices | `devices` | GET | List trusted devices |
| Trusted Devices | `devices/trust` | POST | Trust the current device |
| Trusted Devices | `devices/{fingerprint}` | DELETE | Revoke a trusted device |
| Profile Export | `profile/export` | POST | Create a profile export |
| Profile Export | `profile/export/{id}` | GET | Download a profile export |
| Notification Preferences | `notification-preferences` | GET | Read notification preferences |
| Notification Preferences | `notification-preferences` | PATCH/PUT | Update notification preferences |
| User Activity | `user_activity` | GET | Recent activity aggregate |
| User Activity | `user_activity/recent` | GET | Recent activity aggregate (alias) |
| User Activity | `user_activity/online` | GET | Currently active sessions |
| User Activity | `user_activity/{id}` | GET | Activity feed for a user |

### Accounts

#### `profile` (GET / POST / PATCH / PUT)

- GET: read the current account profile.
  - Returns `data`: `{ "id", "username", "role", "path_allowlist", "avatar_color", "preferences" }`.
- POST / PATCH / PUT: update preferences.
  - Parameters (JSON body): `theme` (light/dark/system), `language` (en/zh), `dashboardLayout`, `notifications`.
  - Returns `data`: the updated `preferences` object.
- Failure codes: `unauthorized` (401) when not signed in.

#### `logout-all` (POST)

- Revokes all other sessions, destroys the current session, and returns `data`: `{ "success": true }`.
- Two-person approval: when two or more admins exist this route returns `202` with `data` `{ "status": "approval_pending", "approval" }` and defers execution until a second admin approves; a single admin returns `409` (`single_admin_no_second_factor`), or `409` (`approval_already_pending`) if a request is already open.

#### `audit/aggregate` (GET)

- Parameters: `since` (relative like `24h` or absolute time, default `24h`), `by` (`user_id`/`action`/`hour`, default `user_id`).
- Returns `data`: `{ "since", "since_ts", "by", "total", "failed", "rows" }` where each row is `{ "key", "total", "failed", "last_at", "error_rate" }`.
- Failure codes: `invalid_by` (400).

### Internal Cron / WebCron

#### `internal/cron` / `internal/cron/tick` (POST)

- Triggers internal cron tasks (requires `internal_cron_token` or admin login).

#### `internal/cron/regenerate-token` (POST)

- Regenerates the internal cron token.

#### `internal/cron/drain-outbox` (POST)

- Drains the notification outbox queue (requires `internal_cron_token` or admin login).

#### `webcron/status` (GET)

- Returns WebCron status.

#### `internal/cron/tick` (POST)

- Parameters: `internal_cron_token` (optional, query/body or `X-Internal-Cron-Token` header); an authenticated admin session is also accepted.
- Runs due backup schedules, drains the notification outbox and records a tick; returns `data`: `{ "processed_schedules", "processed_runs", "drained_outbox", "tick_at" }`.
- Failure codes: `forbidden` (403) when the token is missing/invalid and the caller is not an admin.
