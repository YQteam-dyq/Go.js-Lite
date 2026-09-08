# Go.js Lite — Lightweight PHP Shared Hosting Control Panel

> A lightweight server management panel built specifically for PHP shared hosting. **Does not occupy your web root**. Mobile-friendly.

**English** (primary) · [Full Chinese Translation (全文中文)](README.zh-CN.md)

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-777bb4.svg)](https://php.net)
[![React](https://img.shields.io/badge/React-18-61dafb.svg)](https://react.dev)
[![TypeScript](https://img.shields.io/badge/TypeScript-5-3178c6.svg)](https://www.typescriptlang.org)
[![Version](https://img.shields.io/badge/version-0.7.0-blue.svg)](CHANGELOG.md)

---

## What's new in 0.7.0

- **REST contract** — keeps the historical query form `/gojs/api?api=<action>` as the default, and adds the path form `/gojs/api/<action>` as an alias. Both forms are recognised by `router.php` and `.htaccess` and dispatched to the same handler.
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
- **[Security]** Secure & reliable — BCrypt password hashing, CSRF protection, path traversal prevention, system file protection.
- **[Files]** File management — Browse / edit / upload / download files online, permission changes supported.
- **[Archives]** Zip / Tar archives — Compress & extract zip / tar.gz archives online.
- **[Database]** Database management — MySQL connections, SQL console, table structure browser, **.sql import & export**.
- **[Logs]** PHP error log viewer — Auto-detects log paths, categorised filtering, live refresh.
- **[Health]** Health check — One-click PHP security / performance / compatibility audit.
- **[Disk]** Disk analysis — Visualises per-directory usage and identifies large files.
- **[System]** System info — PHP info, server environment, disk usage, **memory monitor**, process CPU.
- **[Trends]** Resource trends — CPU / memory / disk trend charts in the dashboard.
- **[Lockout]** Brute-force lockout — IP + UA + country triple check.
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
public_html/              <- Your user site (panel never touches it)
├── index.html / index.php <- Keep your original content as-is
└── gojs/                  <- Panel lives here, access through this path
    ├── api.php            # Backend API (single file)
    ├── .htaccess          # Apache rewrite rules (RewriteBase /gojs/)
    └── dist/              # Frontend build
        ├── index.html
        └── assets/
```

Config files are automatically created in the panel's parent directory:

```
public_html/
└── .gojs/
    ├── config.php         # Main config (PHP array, web access blocked)
    └── auth.log           # Login log (brute-force protection)
```

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

### API Routes

The panel accepts both API call shapes; pick whichever suits your client:

| Form | Example | Notes |
|------|---------|-------|
| Query form (default) | `/gojs/api?api=login` | The historical default used by the bundled frontend. |
| Path form (alias) | `/gojs/api/login` | Recognised by `router.php` and `.htaccess`, dispatched to the same handler. |

Both forms end up at the same `api.php` action handler — there is only one code path.

---

## Feature Overview

### Core Features

| Feature | Description | Status |
|---------|-------------|--------|
| Auth System | Setup wizard, login/logout, change password, session timeout, brute-force lockout | OK |
| Secret Access | Token-based access URL, hides panel existence | OK |
| Dashboard | System overview, disk usage, file stats, recently modified files | OK |
| File Manager | Directory browser, file editor, upload/download, create/delete/rename, permissions, history snapshots, in-browser preview | OK |
| Zip / Tar | Compress to zip/tar.gz, extract any archive | OK |
| Database Mgmt | MySQL connections, database/table/column browser, SQL console | OK |
| SQL Import/Export | One-click full/single-table export, chunked .sql import | OK |
| PHP Error Log | Auto-detects log path, categorised filtering, live refresh | OK |
| Health Check | One-click PHP security / performance / compatibility audit | OK |
| Disk Analysis | Per-directory size visualisation, large files list | OK |
| PHP Info | Version, extensions, ini directives, one-click copy php.ini path | OK |
| System Info | Disk, load, uptime, memory usage, process CPU, Cron | OK |
| Resource Trends | CPU / memory / disk trend charts | OK |
| Notifications | Email / SMTP / Webhook / DingTalk / Lark / Telegram / Microsoft Teams / Slack incoming webhooks | OK |
| Operation Log | Every write log carries `request_id` / `trace_id` | OK |
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
- **[Access]** Secret access URL — Panel requires a token in the URL, hiding its existence.
- **[Isolation]** Subdirectory isolation — Panel owns the `/gojs/` path and nothing else.
- **[Lockout]** Brute-force lockout — IP + UA + country triple check (configurable thresholds).

---

## License

[MIT License](LICENSE)

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
