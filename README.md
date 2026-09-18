# Go.js Lite — Lightweight PHP Shared Hosting Control Panel

> A lightweight server management panel built specifically for PHP shared hosting. **Does not occupy your web root**. Mobile-friendly.

**English** (primary) · [Full Chinese Translation (全文中文)](README.zh-CN.md)

[![License](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-777bb4.svg)](https://php.net)
[![React](https://img.shields.io/badge/React-18-61dafb.svg)](https://react.dev)
[![TypeScript](https://img.shields.io/badge/TypeScript-5-3178c6.svg)](https://www.typescriptlang.org)
[![Version](https://img.shields.io/badge/version-0.8.0-blue.svg)](CHANGELOG.md)

---

## What's new in 0.9.0 (unreleased)

- **Two-factor authentication** — per-user TOTP, with eight single-use recovery codes issued at enrolment and a code challenge at login. See [docs/mfa.md](docs/mfa.md).
- **Hardened uploads** — filenames are validated before the file is written, and active SVG is refused unless it is explicitly allowed.
- **Session binding** — a session is tied to the client signals it was created with and rotates on a schedule, so a stolen cookie is not enough on its own.
- **Backup verification** — a backup is checked against its manifest and checksums before a restore is allowed to start.
- **Response header policy** — one place to add, override or disable the security headers, including the Content-Security-Policy.
- **Documented scheduling** — the three layers that run recurring work, and how to drive them from `webcron.php` when the host gives you no shell. See [docs/scheduled-tasks.md](docs/scheduled-tasks.md).

Upgrading from 0.8.x: [docs/migration-0.8-to-0.9.md](docs/migration-0.8-to-0.9.md) describes each behavioural change and the configuration keys that control it.

---

## What's new in 0.8.0

- **Multi-user & RBAC** — real user accounts in `users.json` (admin / operator / viewer), username + password login, per-user preferences, lockout & password expiry. See [Multi-user](#multi-user--collaboration-not-multi-tenancy) below.
- **Path-based ACL** — `path_allowlist` restricts viewers to a subset of the file tree; user groups (`groups.json`) contribute their allowlist as a union with the user's own.
- **Public API Tokens** — Bearer tokens with scopes, per-token rate limiting, SHA-256 storage (the plaintext is shown only once at creation).
- **Two-person approvals** — sensitive actions (database import, trash purge-all, session kick-all, app uninstall) return `202 approval_pending` and need a second admin to approve within 60 minutes.
- **Invitations, trusted devices, GDPR-style export, per-user notification preferences, permission boosts**.
- **PHP toolchain pages** — Composer, OPcache, extensions, error-log parser, PHP-FPM pools, black-box benchmark, ini diff / JIT / include_path, process viewer, upgrade dry-run, autoload audit.
- **Operations** — audit log carries `user_id`; `/api/audit/aggregate` and per-user activity feeds; real online-session management (list / kick, self-kick protected).
- **WAF Security System** — Web Application Firewall with SQL injection / XSS / path-traversal detection, request-body inspection, configurable rule sets, real-time blocking and audit logging.
- **App Store** — one-click installer for popular PHP apps (Discuz!, Emlog, Ghost, Halo, Laravel, Nextcloud, ThinkPHP, Vue template), with app updates, cloning and uninstall support.
- **Web Shell** — in-browser terminal emulator with command history, tab-completion, colour output, and a chroot-like sandbox anchored to `files_root`.
- **Website Monitor** — uptime / response-time / SSL-certificate monitoring for user-defined sites, with alerting via notification channels and historical trend charts.
- **Custom Error Pages** — branded 403 / 404 / 500 / 503 pages deployed at the document root, fully editable in Markdown with live preview.
- **Log Analysis** — Apache / Nginx access-log parser with top pages, referrers, status-code breakdown, bot detection, geo-IP summary and slow-request ranking.
- **Security hardening** — deeper path-traversal defences, auth-bypass mitigations, config-injection protection, and additional brute-force lockout dimensions.

See [CHANGELOG.md](CHANGELOG.md) for the full list and [docs/migration-0.7-to-0.8.md](docs/migration-0.7-to-0.8.md) for breaking changes.

---

## Multi-user — collaboration, NOT multi-tenancy

> ⚠️ **READ THIS FIRST**
>
> - Multi-user in Go.js-Lite is a **collaboration tool** for teams: role split, audit and per-user preferences.
> - For **multiple independent customers**, deploy **one panel instance per customer**. Do **not** try to serve several customers from a single instance.
> - `path_allowlist` is **not a tenant boundary**. It is a least-privilege subset for viewers on a *shared* file tree. All users of an instance share the same `files_root`, `config.php`, database connections, audit log and monitoring data.

| Role | Files read | Files write | User/session admin | PHP toolchain | API tokens |
|---|---|---|---|---|---|
| `admin` | all | all | yes | yes | yes |
| `operator` | all | yes | no | no | create scoped tokens |
| `viewer` | allowlist only | no (unless boosted) | no | no | no |

---

## What's new in 0.7.0

- **REST contract** — adds the path form `/gojs/api/<action>` next to the historical query form `/gojs/api?api=<action>`. Both are recognised by `router.php` and `.htaccess` and dispatched to the same handler. The query form was deprecated in 0.8.0; see [API Routes](#api-routes).
- **File manager** — per-save history snapshots, in-browser preview for images / video / audio / Markdown / PDF / CSV, bulk operations, optional per-file AES-256-GCM encryption.
- **Database manager** — persistent connections, slow-query log, schema snapshots, sensitive-column masking on SQL export.
- **Monitoring** — CPU / memory / disk trend charts with 5m / 1h / 24h windows.
- **Notifications** — Microsoft Teams and Slack incoming-webhook adapters (in addition to email / SMTP / webhook / DingTalk / Lark / Telegram).
- **Security** — IP + UA + country triple on brute-force lockout, per-user / per-endpoint rate limiting.
- **Operations** — every write log now carries `request_id` / `trace_id`.
- **Diagnostics** — `/.gojs/diagnostics/export` bundles a redacted runtime snapshot for support.

The 0.7.0 release keeps Go.js-Lite pinned to its lightweight profile — single-file PHP entry (`api.php` + `router.php`), modular `backend/`, internal `webcron.php`, no external services required. See [CHANGELOG.md](CHANGELOG.md) for the full diff and migration notes.

---

## Quick Start

### For Users (Deployment)

Download the latest `gojs-lite-VERSION.zip` from [Releases](https://github.com/YQteam-dyq/Go.js-Lite/releases). Extract, then **upload the `gojs/` directory** as a whole to your web root.

- Panel URL: `https://your-domain.com/gojs/`
- All panel files are isolated inside `gojs/` — they will never interfere with your existing site.

### For Developers (Local Development)

```bash
# Clone the repo
git clone https://github.com/YQteam-dyq/Go.js-Lite.git
cd Go.js-Lite

# Install dependencies
npm install

# Start PHP backend (port 8080) with /gojs/ prefix-aware router
php -S 127.0.0.1:8080 router.php

# Start frontend dev server (port 5173), auto-proxies /gojs/api
npm run dev
```

Visit http://localhost:5173/gojs/ to start developing.

---

## Features

- **[Deploy]** Decoupled deployment — All panel files ship inside the standalone `gojs/` subdirectory. Your web root stays clean.
- **[Access]** Secret access URL — Access the panel via a token-based URL to hide its existence from public discovery.
- **[Hosting]** Shared hosting friendly — Automatically detects `disable_functions`, gracefully degrades based on available capabilities.
- **[Mobile]** Mobile-first — Responsive design, perfect on phones, tablets, and desktops. Touch-friendly.
- **[Security]** Secure & reliable — BCrypt password hashing, CSRF protection, path traversal prevention, system file protection, WAF web application firewall.
- **[WAF]** Web Application Firewall — SQL injection / XSS / path-traversal detection, request-body inspection, configurable rule sets, real-time blocking.
- **[App Store]** One-click app installer — Install popular PHP apps (Discuz!, Emlog, Ghost, Halo, Laravel, Nextcloud, ThinkPHP, Vue template) with updates and cloning.
- **[Web Shell]** In-browser terminal — Terminal emulator with command history, tab-completion, colour output, sandboxed to `files_root`.
- **[Files]** File management — Browse / edit / upload / download files online, permission changes supported.
- **[Archives]** Zip / Tar archives — Compress & extract zip / tar.gz archives online.
- **[Database]** Database management — MySQL connections, SQL console, table structure browser, **.sql import & export**.
- **[Logs]** PHP error log viewer — Auto-detects log paths, categorised filtering, live refresh.
- **[Log Analysis]** Access log analytics — Apache / Nginx access-log parser with top pages, referrers, status breakdown, bot detection, geo-IP summary.
- **[Monitor]** Website monitor — Uptime / response-time / SSL-certificate monitoring with alerting and historical trend charts.
- **[Error Pages]** Custom error pages — Branded 403 / 404 / 500 / 503 pages, editable in Markdown with live preview.
- **[Health]** Health check — One-click PHP security / performance / compatibility audit.
- **[Disk]** Disk analysis — Visualises per-directory usage and identifies large files.
- **[System]** System info — PHP info, server environment, disk usage, **memory monitor**, process CPU.
- **[Trends]** Resource trends — CPU / memory / disk trend charts in the dashboard.
- **[Lockout]** Brute-force lockout — IP + UA + country triple check.
- **[2FA]** Two-factor authentication — Per-user TOTP with eight single-use recovery codes, challenged at login.
- **[Tasks]** Scheduled tasks — Cron entries through `exec()` or a flat file, plus internal webcron jobs for hosts without shell access.
- **[Audit]** Operation log — Every write log carries `request_id` / `trace_id` for traceability.
- **[i18n]** Bilingual (EN/ZH) — Built-in i18n, supports both Chinese and English.
- **[Theme]** Light / dark themes — Supports light / dark / system preference.
- **[Stack]** Modern frontend — React + TypeScript + Vite + Tailwind CSS.

---

## Requirements & Deployment

### Requirements

| Item | Minimum | Recommended |
|------|---------|-------------|
| PHP | 7.4 | 8.0+ |
| Web Server | Apache / Nginx / LiteSpeed | Apache + mod_rewrite |
| PHP Extensions | `session`, `json`, `mbstring` | `mysqli`, `gd`, `openssl`, `zip` |
| Browser | Chrome 80+ / Safari 14+ | Latest stable |

### Deployment

1. **Download** the latest release (`gojs-lite-VERSION.zip`)
2. **Extract** the archive — you get a single standalone `gojs/` folder
3. **Upload** the `gojs/` folder to your web root (e.g. `public_html/gojs/`, `wwwroot/gojs/`)
4. **Visit** `https://your-domain.com/gojs/` — the setup wizard starts automatically
5. **Set** an admin password, save your secret access URL, and you are done.

> **Note**: All panel assets live inside `gojs/`. Zero pollution to the rest of your site.

### Directory Structure

After deployment on the server:

```
public_html/              <- Your user site (the panel never touches it)
├── index.html / index.php <- Keep your original content as-is
└── gojs/                  <- The panel lives here, reached through this path
    ├── api.php            # Backend API (single file)
    ├── router.php         # Router for the PHP built-in server (php -S)
    ├── .htaccess          # Apache rewrite rules (relative, adapts to any mount point)
    ├── dist/              # Frontend build
    │   ├── index.html
    │   └── assets/
    └── .gojs/             # Runtime config, created by the installer; web access blocked
        ├── config.php     # Main config (PHP array)
        └── auth.log       # Login log (brute-force protection)
```

> `.gojs/` lives **inside** the panel directory, next to `api.php`: the panel resolves it as `dirname(__FILE__) . '/.gojs'`. A rewrite rule rejects any request whose path contains a `.gojs` segment, so the directory is never served.

> **When the panel is not mounted at `/gojs/`** (for example `panel/`, or the web root itself): the backend `.htaccess` and `router.php` adapt on their own, but frontend asset paths are baked in at build time from `vite base`. If the frontend 404s, rebuild with the real path:
>
> ```bash
> npx vite build --base=/panel/    # use --base=/ when the panel owns the web root
> ```
>
> Then upload `dist/` again.

---

## Performance Tuning

### OPcache

Every request to the panel goes through `api.php`. Enabling **OPcache** lets PHP cache the compiled bytecode so scripts no longer need to be re-parsed on every request, which significantly reduces the per-request cost of `api.php`.

Recommended `php.ini` settings:

```ini
opcache.enable = 1                    ; enable the opcode cache (on by default in production)
opcache.enable_cli = 1                ; optional: also enable for CLI (e.g. cron) scenarios
opcache.validate_timestamps = 1       ; re-check file mtimes to detect code changes
opcache.revalidate_freq = 60          ; check for changed files at most once per 60s
opcache.memory_consumption = 128      ; 128 MB of shared memory for cached opcodes
opcache.max_accelerated_files = 10000 ; enough slots for the codebase
```

> **Tip**: In production, after deploying a new release you can either clear the cache (e.g. `opcache_reset()` / restart PHP-FPM) or briefly set `opcache.validate_timestamps = 0` while keeping `opcache.revalidate_freq` for development. If OPcache is not available, the panel still works correctly — it just parses files on every request.

### On-demand Loading

The backend logic has been split from a single monolithic `api.php` into modules under `backend/` (auth, files, database, ssl, backup, system, settings, cron, notifications, misc, …). A lightweight `autoload.php` loads only the modules needed for the current request, instead of parsing the whole file every time. This keeps the per-request parse footprint small and makes the codebase easier to maintain.

### Large Directory Rendering

The file manager renders long directories through a windowed list (`src/components/ui/VirtualList.tsx`). Only the rows inside the viewport, plus a small overscan margin, are mounted; the scroll container keeps the full height so the scrollbar behaves normally. Listings below 60 entries keep the plain markup so find-in-page and the tab order stay untouched. The same component drives the grid view, where the column count follows the container width.

### Offline Shell and Web Push

The frontend ships a service worker (`public/sw.js`) that precaches the application shell and serves a standalone offline page when navigation fails. API calls are never cached, so the panel does not show stale server data. Web Push registration is optional: the panel detects the missing API (no service worker, insecure context, missing push manager) and reports the reason in **Notification preferences** instead of failing silently. The service worker is registered in production builds only.

### Service Status Page

`/status` is a public, session-independent page that reports panel availability and component versions, and adds storage usage, configuration checks and thresholds once an authenticated session is present. It is linked from the command palette and reachable without signing in, which makes it suitable for uptime monitoring.

### API Routes

The panel accepts both API call shapes:

| Form | Example | Notes |
|------|---------|-------|
| Path form (recommended) | `/gojs/api/login` | The supported shape. `router.php` and `.htaccess` dispatch it. |
| Query form (deprecated) | `/gojs/api?api=login` | The historical default, deprecated in 0.8.0 and removed in 1.0.0. Responses carry deprecation headers. |

Both forms end up at the same `api.php` action handler — there is only one code path. See [docs/deprecations.md](docs/deprecations.md) for the removal schedule.

---

## Documentation

| Document | What it covers |
|---|---|
| [CHANGELOG.md](CHANGELOG.md) | Every release, with the breaking changes and the migration note for each one. |
| [docs/api.md](docs/api.md) | The backend API reference: conventions, the endpoint overview table and a reference entry per endpoint. |
| [docs/deprecations.md](docs/deprecations.md) | What is deprecated, how it is announced at runtime, and the 1.0.0 removal schedule. |
| [docs/migration-0.7-to-0.8.md](docs/migration-0.7-to-0.8.md) | Upgrading from the single-admin panel to multi-user. |
| [docs/migration-0.8-to-0.9.md](docs/migration-0.8-to-0.9.md) | Upgrading to the hardened 0.9.0 release: uploads, session binding, backup verification and response headers. |
| [docs/scheduled-tasks.md](docs/scheduled-tasks.md) | Running recurring work through the system crontab or, without shell access, through `webcron.php`. |
| [docs/mfa.md](docs/mfa.md) | Per-user two-factor authentication: enrolment, the login challenge and recovery codes. |
| [docs/database-query-builder.md](docs/database-query-builder.md) | Building and running queries from the panel, and what the preview endpoint actually does. |
| [docs/waf_integration.md](docs/waf_integration.md) | The Web Application Firewall rules, modes and integration points. |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Development workflow, the language policy and the gates a pull request has to pass. |

---

## Feature Overview

### Core Features

| Feature | Description | Status |
|---------|-------------|--------|
| Auth System | Setup wizard, login/logout, change password, session timeout, brute-force lockout | OK |
| Two-factor Auth | Per-user TOTP with eight single-use recovery codes, challenged at login | OK |
| Secret Access | Token-based access URL, hides panel existence | OK |
| Dashboard | System overview, disk usage, file stats, recently modified files | OK |
| File Manager | Directory browser, file editor, upload/download, create/delete/rename, permissions, history snapshots, in-browser preview | OK |
| Zip / Tar | Compress to zip/tar.gz, extract any archive | OK |
| Database Mgmt | MySQL connections, database/table/column browser, SQL console | OK |
| SQL Import/Export | One-click full/single-table export, chunked .sql import | OK |
| PHP Error Log | Auto-detects log path, categorised filtering, live refresh | OK |
| Log Analysis | Apache / Nginx access-log parser, top pages, referrers, status breakdown, bot detection | OK |
| Health Check | One-click PHP security / performance / compatibility audit | OK |
| Disk Analysis | Per-directory size visualisation, large files list | OK |
| PHP Info | Version, extensions, ini directives, one-click copy php.ini path | OK |
| System Info | Disk, load, uptime, memory usage, process CPU, Cron | OK |
| Resource Trends | CPU / memory / disk trend charts | OK |
| Scheduled Tasks | Cron entries through `exec()` or a flat file, plus internal webcron jobs for hosts without shell access | OK |
| Website Monitor | Uptime / response-time / SSL-certificate monitoring with alerting and trend charts | OK |
| Notifications | Email / SMTP / Webhook / DingTalk / Lark / Telegram / Microsoft Teams / Slack incoming webhooks | OK |
| Operation Log | Every write log carries `request_id` / `trace_id` | OK |
| WAF Security | SQL injection / XSS / path-traversal detection, configurable rules, real-time blocking | OK |
| App Store | One-click installer for PHP apps, updates, cloning and uninstall | OK |
| Web Shell | In-browser terminal emulator with command history and colour output | OK |
| Custom Error Pages | Branded 403/404/500/503 pages, Markdown editable with live preview | OK |
| Multi-user & RBAC | Admin / operator / viewer roles, user groups, path-based ACL | OK |
| Two-person Approvals | Sensitive actions require a second admin approval within 60 minutes | OK |
| PHP Toolchain | Composer, OPcache, extensions, PHP-FPM, benchmark, upgrade dry-run | OK |
| API Tokens | Bearer tokens with scopes, per-token rate limiting, SHA-256 storage | OK |
| Settings | Theme / language switch, session settings, password change, access URL i18n | OK |

### Capability-based Degradation

Go.js Lite automatically detects your server environment and hides unavailable features:

| Feature | Dependency | When unavailable |
|---------|------------|-----------------|
| Database management | `mysqli` or `pdo_mysql` extension | Database menu hidden |
| Zip compression | `ZipArchive` class | Compress button hidden |
| Process list | `/proc` readable | Processes tab hidden |
| Cron management | `exec()` function | Cron menu hidden |
| Image thumbnails | `gd` extension | No thumbnails shown |

---

## Security

- Admin password hashed with `password_hash(PASSWORD_BCRYPT)` — one-way, irreversible.
- Database connection passwords encrypted with `AES-256-CBC`.
- Optional per-file `AES-256-GCM` encryption in the file manager.
- All file operations are anchored to a strict `$files_root` realpath — no path traversal.
- System files (`.gojs/`, `api.php`, `.htaccess`) are protected from file manager operations.
- Config directory `.gojs/` blocked from direct web access via `.htaccess`.
- CSRF token validation — cross-site request forgery protection. Server-side rate limiting enforces `X-CSRF-Token` on writes.
- Session / Cookie scope shrunk to `/gojs/` — never leaks to sibling apps in the web root.
- **[WAF]** Web Application Firewall — SQL injection, XSS, path-traversal, command-injection and config-injection detection with real-time blocking and audit logging.
- **[Access]** Secret access URL — Panel requires a token in the URL, hiding its existence.
- **[Isolation]** Subdirectory isolation — Panel owns the `/gojs/` path and nothing else.
- **[Lockout]** Brute-force lockout — IP + UA + country triple check (configurable thresholds).

---

## License

[Apache License 2.0](LICENSE)

This product also includes a `NOTICE` file as required by the Apache License, Version 2.0.

---

## Developers

**YQteam-dyq** — Crafted with care, lightweight & efficient.

---

## Acknowledgments

- [React](https://react.dev)
- [Vite](https://vitejs.dev)
- [Tailwind CSS](https://tailwindcss.com)
- [Lucide Icons](https://lucide.dev)
- [TanStack Query](https://tanstack.com/query)
- [Zustand](https://github.com/pmndrs/zustand)

---

<p align="center">
  Made with ❤️ by YQteam-dyq
</p>
