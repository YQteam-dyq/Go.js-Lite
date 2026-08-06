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

- Route prefix: `/api/<action>` (path form) or `?api=<action>` (query parameter form).
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
- Some endpoints support access via `?token=<access_token>` (e.g. `bootstrap`, `login`).
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

#### `upload-chunk` (POST)

- Parameters (JSON body): `chunk` (base64), `chunkIndex`, `totalChunks`, `fileName`, `target`, `uploadId`.
- Returns `data`: `{ "success": true, "merged": bool, "progress": "n/total", "received", "totalChunks" }`.

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
- Returns `data`: `{ "filename", "size", "metadata" }`.

#### `backup/list` (GET/POST)

- Returns `data`: `{ "backups": [{ "filename", "size", "created", "metadata" }] }`.

#### `backup/download` (GET/POST)

- Parameters: `filename`.
- Returns: a zip file download.

#### `backup/delete` (POST)

- Parameters: `filename`.
- Returns `data`: `{ "success": true }`.

#### `backup/restore` (POST)

- Parameters: `filename`.
- Returns `data`: `{ "success": true }`.

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

### Internal Cron / WebCron

#### `internal/cron` / `internal/cron/tick` (POST)

- Triggers internal cron tasks (requires `internal_cron_token` or admin login).

#### `internal/cron/regenerate-token` (POST)

- Regenerates the internal cron token.

#### `internal/cron/drain-outbox` (POST)

- Drains the notification outbox queue (requires `internal_cron_token` or admin login).

#### `webcron/status` (GET)

- Returns WebCron status.