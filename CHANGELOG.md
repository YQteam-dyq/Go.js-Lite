# Changelog

> Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
> Project language policy: this file is **English only** starting from v0.3.1; Chinese is no longer maintained here.

## [0.9.0] - Unreleased

Hardening for uploads, sessions, response headers and backup archives, alongside frontend performance, offline and accessibility work. No surface is removed in this release: the two deprecated surfaces below keep working and are scheduled for removal in 1.0.0.

### Added
- Frontend: every route component is loaded through a Vite dynamic `import()` wrapped in `React.lazy` with a shared `Suspense` fallback, and `manualChunks` isolates the heavy route-only dependencies, so the first paint downloads only the module that belongs to the opened route instead of the whole application bundle.
- Frontend: directory listings render through a windowed list that mounts only the visible rows plus an overscan band, and falls back to the plain DOM below the windowing threshold so find-in-page, text selection and tab order are unaffected for ordinary directories. The window follows the scroll offset through `requestAnimationFrame` and the viewport through `ResizeObserver`, and the grid derives its column count from the measured width. Delete, rename and chmod patch the query cache instead of refetching the whole directory.
- Frontend: offline shell - a service worker, a web manifest and an offline fallback page keep the panel shell loading without a network. Push support is detected before it is used and every failure path returns a typed reason (`unsupported`, `insecure_context`, `no_service_worker`, `no_push_manager`, `permission_denied`, `subscribe_failed`, `no_application_server_key`), so the notification settings explain the state instead of rendering a broken control. The application server key is read from the environment and the service worker is only registered in production builds.
- Frontend: a public `/status` page that keeps working without a session and degrades to the data it can read, with the overall verdict derived from the health summary and the disk usage, plus an accessible storage meter (`role="progressbar"` with `aria-valuetext`) that the disk analysis page reuses.
- Frontend: a Cmd+K command palette driven by the same navigation model as the sidebar, a focus trap and an accessible name for the modal, and the navigation items moved into one data source.
- Tests: the Vitest + jsdom foundation with a v8 coverage threshold of 70% statements, branches, functions and lines over `src/api/client.ts`, `src/hooks/useI18n.ts`, `src/i18n`, `src/lib`, `src/stores/authStore.ts` and `shared/version.ts`.
- CI: the unit test, English-only and review gates are aggregated behind a single `Merge Gate` required status check, and a new `PHP 7.4 Compat Guard` job lints the production sources with PHP 7.4 itself and greps them for PHP 8-only syntax.
- Docs: operator guides for [scheduled tasks](docs/scheduled-tasks.md), [two-factor authentication](docs/mfa.md) and the [database query builder](docs/database-query-builder.md), and the API reference now carries reference entries for most of the endpoints that shipped in 0.8.0 without one.

### Security
- Upload guard: `backend/upload_guard.php` centralises upload validation and runs it from both `upload` and `upload-chunk` — bypass-resistant extension checks (`photo.php.jpg`, `payload.php.`, `payload.php `, `payload.ph%70`), reserved server configuration names (`.htaccess`, `.user.ini`, `php.ini`, `.env`, `web.config`), file name length limits, optional allow list mode, content sniffing against the declared type (`finfo` with a magic-byte fallback) and active content detection (SVG scripts, event handlers, frames, external entities, meta refresh, polyglot images). `GET|POST /api/upload-guard` returns the active policy and can inspect a single path.
- Security: `backend/security_headers.php` centralises the response header policy and emits it on every response - `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, `Cross-Origin-Resource-Policy`, `X-Permitted-Cross-Domain-Policies`, `Strict-Transport-Security` over HTTPS, plus a context-aware `Content-Security-Policy` (a locked-down policy for API and download responses, and a self-only policy for rendered pages). Overridable per deployment through `config.php`. Inspect it with `GET /api/security/headers`.
- Backup integrity: `backup/create` writes a `manifest.json` entry into the archive that records the size and SHA-256 of every other entry plus a digest over that list, so the manifest itself is tamper evident, and writes the SHA-256 of the finished archive to `<filename>.sha256` next to it (also returned as `sha256`). `backup/verify` recomputes every entry hash, compares the entry list with the manifest and reports `mismatched`, `missing` and `extra` entries; `backup/precheck` then checks the restore target (the `backup.json` metadata entry, parent directory segments in entry names, files root availability and writability, and free space against the uncompressed footprint). Archives written before this release are reported with a `legacy` flag.
- Session fingerprint: every authenticated request derives a fingerprint from the client signals and a random per-session salt and compares it with the binding stored in the session. The binding is issued on the first authenticated request and the salt is re-issued after `rotate_seconds` (900 by default), so a fingerprint derived from an old salt stops being useful. A mismatched request is rejected with `401` and `error.code = "session_fingerprint_mismatch"` and the session is destroyed; the mismatch counter and timestamp stay for the audit trail. `GET|POST /api/session-fingerprint` reports the binding state or rotates it on demand.

### Deprecated
- Deprecated: the `?api=<action>` query form now answers with `Deprecation`, `Sunset`, `Link ... rel="deprecation"` and `X-Gojs-Deprecations` headers and is scheduled for removal in 1.0.0.
- Deprecated: the legacy access token (`?token=`, `X-Access-Token`, `POST /api/regenerate-access-token`) now answers with the same deprecation headers and `deprecated` / `removeIn` / `replacement` fields, and is scheduled for removal in 1.0.0.
- This release is the **warn** milestone of the schedule in [docs/deprecations.md](docs/deprecations.md): the deprecated surfaces keep working, are frozen in 0.9.9 and are removed in 1.0.0. Whether a caller is affected can be checked from the response headers alone; see the section "How to check whether you are affected" in that document.

### Changed
- Version: `version.json` is now the single source of truth. `api.php` and `tests/bootstrap.php` derive `VERSION` / `APP_VERSION` from it through `gojs_version()`, `shared/version.ts` imports it, and `package.json` / `package-lock.json` are kept in sync by `npm run version:bump` and verified by `npm run version:check`.
- `GET /api/bootstrap` returns a `deprecations` object with the full registry.
- The removal schedule lives in `docs/deprecations.md`.
- Dependencies: xterm is unified on the `@xterm/xterm@6` generation that the Web Shell actually imports, and the unused legacy v5 packages, `socket.io-client` and `react-hook-form` were removed.

### Breaking
- Uploads are validated on the server before the file is written. Names that rely on a trailing dot, a trailing space, a semicolon or a zero-width character to smuggle an executable extension, and content that does not match its declared type, are now rejected instead of stored. Deployments that intentionally upload such names need an explicit `allowed_extensions` policy or the guard relaxed in `config.php`.
- An authenticated session is bound to the client signals it was created with and the binding rotates while the session is live. A request from a different network or browser is rejected with `401 session_fingerprint_mismatch` and the session is destroyed, so long-lived sessions that legitimately move between networks have to sign in again.
- Restoring a backup runs verification first. Archives written before 0.9.0 are flagged `legacy` and a mismatch is reported instead of being extracted over the files root.
- Every response now carries the header policy in `backend/security_headers.php`, including a `Content-Security-Policy`. Rendered pages get a self-only policy; a page that relies on inline script or on remote origins must be adjusted or the policy overridden through `security_headers.csp` in `config.php`.

### Migration (0.8 → 0.9)
See [docs/migration-0.8-to-0.9.md](docs/migration-0.8-to-0.9.md).

## [0.8.1] - 2026-09-14

Patch release that stabilises the 0.8.0 feature drop. These entries were listed under 0.8.0 until this release; the 0.8.1 tag is the release that shipped them.

### Fixed
- WebShell: API requests now use the `/gojs/api/webshell` base path, restoring command execution, history, clear-history and autocomplete.
- API client: `buildApiError` no longer crashes when the backend returns an empty error body.
- i18n: added the missing `nav.webshell`, `nav.websiteMonitor` and `nav.customErrorPages` keys to the Chinese and English locales.
- Versioning: the backend `VERSION` / `APP_VERSION` (and `tests/bootstrap.php`) now match the frontend `0.8.0`.

### UX
- System info and WebShell failures now show readable, localized messages instead of raw exception text.
- WebShell history shows an explicit failure state with a retry action instead of a silent empty list.
- File deletion confirmation now states that files are moved to the trash and can be restored.
- Backup deletion now requires typing the backup filename to confirm, and states that the file is permanently removed.
- Settings shows a warning when the frontend and backend versions differ.

## [0.8.0] - 2026-09-13

Multi-user collaboration on a single panel instance: RBAC, path ACL, audit, approvals and a PHP toolchain. Multi-user is a **collaboration tool, not multi-tenancy** — deploy one instance per customer.

### Added
- Users: `users.json` store auto-seeded from the legacy admin in `config.php` on first run; username + password login; lockout, password expiry (`password_changed_at` / `password_expires_at`), per-user preferences and avatar colors.
- ACL: role model `admin / operator / viewer`; route-level pre-check (`gojs_acl_route_precheck`) defaulting unlisted actions to admin-only; `path_allowlist` for viewers, unioned with user-group allowlists (`groups.json`); `permissions_boost[]` as a role-orthogonal whitelist for specific actions.
- Users API: `/api/users` CRUD, `/api/sessions` list + kick (`POST /sessions/{sid}/kick`, self-kick returns `409 cannot_kick_self`), `/api/profile`, `/api/groups`, `/api/tokens` (Bearer), `/api/invitations`, `/api/devices`, `/api/profile/export`, `/api/notification-preferences`, `/api/approvals`.
- Public API tokens: SHA-256-at-rest, plaintext shown once, scope model (`admin`, `readonly`, `user-self`, `<resource>.read|.write`), per-token rate limiting, `Authorization: Bearer` support.
- Two-person approvals: sensitive actions (`db/import`, `trash/purge` all, `logout-all`, `appstore/uninstall`) return `202 approval_pending`; single-admin deployments get `409 single_admin_no_second_factor`; 60-minute TTL; decisions enforce requester ≠ approver; approved requests are executed server-side and the original response is returned.
- Invitations: 24h one-time activation links at `/gojs/invite/<token>`, public preview/accept endpoints, `410 invite_expired`.
- Trusted devices: 14-day trust stored per user, fingerprinted by IP + UA.
- GDPR-style export: `POST /api/profile/export` returns `202` + a signed (1h TTL) download URL with `audit.<uid>.json/.csv`, `preferences.json`, `sessions.json`, `tokens.json`; 7-day cleanup.
- Per-user notification preferences: `preferences.notifications.{email,inapp}.severity_min` with role-based defaults (admin on, operator/viewer off).
- User activity: `GET /api/audit/aggregate?since=24h&by=user_id|action|hour` and `/api/user_activity/recent|online|{user_id}`.
- Quotas: per-role request throttling with `429` + `X-RateLimit-*` headers (`backend/quota.php`).
- PHP toolchain: `/api/composer/*` (status/install/require/update/json, `501 composer_unavailable` with install guide), `/api/php/opcache/*`, `/api/php/extensions`, `/api/php/errors`, `/api/php/fpm/*` (`501 fpm_not_applicable` off-FPM), `/api/php/bench/*` (8 micro-benchmarks), `/api/php/ini-diff`, `/api/php/jit`, `/api/php/include-path`, `/api/php/processes*`, `/api/php/upgrade-check`, `/api/php/autoload-audit` (all admin-only).
- Frontend pages: Users, Sessions, User Activity, Profile, Groups, API Tokens, Invitations, Devices, Notification Preferences, Approvals, Composer, OPcache, PHP Extensions, PHP Errors, PHP-FPM, PHP Benchmark, PHP Config/JIT, PHP Processes, PHP Upgrade.
- Composer integration: `backend/autoload.php` transparently requires `vendor/autoload.php` when present.
- Tests: 314 PHPUnit tests (from 106) covering users, ACL, sessions, quotas, groups, tokens, invitations, devices, exports, notification preferences, approvals, permissions boost, the PHP toolchain and audit aggregation.

### Changed
- Audit log rows carry `user_id` (`gojs_log_operation`); the legacy access-token URL logs `token_login` and binds the session to the real admin user id.
- TOTP secrets and recovery codes live in the per-user record (`users.json`) instead of `config.php`, with a one-time migration from the legacy global config.
- Trash purge-all and other gated actions require a second admin when more than one admin exists; single-item trash purge stays un-gated.
- `.user.ini` writes (JIT, include_path) are managed through `backend/php_ini.php` helpers.
- `gojs_relative_path()` normalises directory separators, fixing Windows backslash leakage in file paths.

### Breaking
- Login is now username + password against `users.json`; the legacy admin-password-only login flow is replaced (the access-token URL keeps working for admins during the 0.8 compatibility window and is scheduled for removal in 1.0).
- Unauthenticated `settings` writes no longer exist: preferences are per user via `/api/profile`; the global settings write endpoint was removed.
- Default notification delivery changes: only admins receive notifications unless a user opts in.
- Every unlisted API action is admin-only by default; operators/viewers need explicit ACL entries or `permissions_boost`.

### Migration (0.7 → 0.8)
See [docs/migration-0.7-to-0.8.md](docs/migration-0.7-to-0.8.md).

## [0.7.0] - 2026-09-08

### Changed
- Versioning: unified `0.7.0` across `package.json`, `shared/version.ts`, `api.php` (`VERSION` / `APP_VERSION`) and `tests/bootstrap.php`.
- Backend philosophy: stay as a single-file PHP entry (`api.php` + `router.php`) with modular `backend/` and an internal `webcron.php`. No new external services are introduced for 0.7.
- API contract: keep the **query form** `/gojs/api?api=<action>` as the historical default. The **path form** `/gojs/api/<action>` is also supported as an alias — `router.php` recognises both shapes and dispatches them to the same handler, so existing query-form callers and any path-form callers (e.g. third-party scripts) both keep working.

### Added
- File manager: history snapshots per save at `.gojs/file-history/<hash>.json`, UI rollback and diff entry.
- File manager: in-browser preview for images / video / audio / Markdown (`react-markdown`) / PDF (`pdf.js`) / CSV.
- File manager: bulk operations (multi-select delete / copy / move / pack / chmod).
- File manager: optional per-file AES-256-GCM encryption, key derived from the admin password via HKDF.
- Database manager: persistent connections, slow-query log at `.gojs/slow_queries.log`, schema diff snapshots under `.gojs/db_snapshots/`, sensitive-column masking on SQL export.
- Monitoring: CPU / memory / disk trend charts with 5m / 1h / 24h windows over `monitor_history.json`; per-API call count / latency / error rate written to `.gojs/api_metrics.json` (rolling 7 days).
- Notifications: Microsoft Teams and Slack incoming-webhook adapters in addition to the existing Email / SMTP / Webhook channels.
- Security: IP + UA + country triple on brute-force lockout, per-user / per-endpoint rate limiting with `X-Rate-Limit-*` headers (default 60 req/min, file ops 20 req/min), 429 response with `retry_after`.
- Operations: every write log carries a `request_id` / `trace_id`; 30-day inactive session cleanup with `auth.log` archival.
- Diagnostics: `/.gojs/diagnostics/export` bundles a redacted runtime snapshot for bug reports.

### Removed (explicit non-goals for the lightweight profile)
- Microservices, service discovery, circuit breaker, load balancer.
- GraphQL / gRPC / WebSocket server APIs (REST-only).
- OAuth2 / JWT providers (single-admin session stays the only auth model).
- DDoS protection, intrusion detection, firewall manager (brute-force lockout remains).
- Container / Kubernetes / HPA / auto-scaling, CI/CD pipeline orchestration, environment promotion.
- SEO / image optimization, plugin marketplace, plugin hot-reload, GraphQL/grpc extension points.
- Database replication, point-in-time recovery, master-slave failover.

### Breaking
- Rate limiting is now enforced server-side; clients without `X-CSRF-Token` on writes receive 403.
- Operation log entries add `request_id` / `trace_id` fields; older logs are still readable.

### Migration from 0.6.0
- No data migration: `.gojs/config.php`, backup archives, notification channels, FTP accounts and API tokens remain compatible.
- No frontend migration: the path-form API contract was already the only one shipped from 0.6.0; older query-form callers (none exist in the bundled frontend) would simply fail the contract test.

## [0.6.0] - 2026-09-08

### Changed
- Routing contract: API calls are dispatched through `router.php` to `api.php` via the historical query form `/gojs/api?api=<action>`. The bundled frontend's `apiFetch` was rewritten on top of this contract, with optional `params` query support and a normalized `ApiError(code, message, status?, payload?)` shape that auto-derives the error code from HTTP status when called with a number. The path form `/gojs/api/<action>` is also accepted as an alias by `router.php` and `.htaccess` for third-party callers.
- Frontend cleanup: removed the unused fake integration layer (`src/api/route-manager.ts`, `integration.ts`, `data-flow-manager.ts`, `index.ts`) and the dead demo routes (`ShareLinks`, `AppStore`, `DirProtect`). The frontend now talks to PHP through a single `apiFetch` only.
- Removed the unused demo components (`PerformanceMonitor`, `SystemDiagnostics`, `DataFlowManager`, `RealTimeMonitor`, `PerformanceChart`) and the stale `src/core/`, `src/cache/`, `src/database/`, `src/deployment/`, `src/monitoring/`, `src/filemanager/`, `src/performance/` directories.
- Removed all TypeScript / JSDoc comments from `src/**` and `tests/**` to keep the source noise-free. Behaviour is unchanged.
- Removed comments from `api.php`, `router.php`, `vite.config.ts`, `scripts/smoke-test.ps1`, `.github/workflows/ci.yml`, `backend/system.php`.

### Fixed
- Frontend: missing exports `apiFetch` / `setCsrfToken` from `src/api/client.ts` when the file was previously truncated mid-rewrite. The file is now a single coherent real-fetch layer.
- Browser: `SyntaxError: Invalid or unexpected token` introduced by an extension / injected code that does not appear in our bundle (verified by `node_modules/.vite/deps/` and dist bundle contents).
- PHP environment: `mb_detect_encoding` crashed the editor for every file because the host PHP had no `mbstring` extension. Documented required PHP extensions and the dev bootstrap now copies a working `php.ini`.
- Cron capabilities: `crontab -l` returning exit code 1 was misread as "crontab available". Capabilities now check stderr / stdout for "not recognized" / "no such file" / "command not found" before reporting `crontab_available: true`.
- Cron UI: when `crontab` is missing, the panel now shows a "partially available" warning instead of falsely claiming "Cron Available".
- Notification i18n: category key building used `c.charAt(0).toUpperCase() + c.slice(1)`, which produced `categoryLogin_anomaly` for the `login_anomaly` category. Replaced with a real snake-case to camelCase converter; added the missing `categoryMonitor` i18n key in both `zh.ts` and `en.ts`.
- Router proxy: Vite dev proxy used to rewrite `/gojs/api/<action>` into `/api.php?api=<action>`, which never matched `router.php`. Proxy now passes the path through unchanged.

## [0.5.2] - 2026-08-07

### Changed
- Architecture: split the monolithic `api.php` into modular `backend/` files and replace the global `switch` dispatch with a lightweight `GoJS_Router`, keeping `router.php`/`webcron.php` entry contracts unchanged.
- Dependency injection: introduce `GoJS_Context` to centralize `config` / `files_root` global state, improving testability without breaking legacy entry points.
- Versioning: unify the version to a single source (`shared/version.ts`, `api.php` `VERSION`/`APP_VERSION`, `package.json`) at 0.5.2.
- Tooling: prune redundant Vite configs (keep `vite.config.ts` only).

### Added
- PHPUnit test suite covering auth, file operations / safe-path validation, database config, and the router (green).
- GitHub Actions CI: PHP lint, PHPUnit, and frontend typecheck + build.
- API documentation (`docs/api.md`) and a contributor guide (`CONTRIBUTING.md`).
- Frontend error-code to i18n key mapping so errors render in the active language while staying backward-compatible with the backend JSON.
- README performance notes for OPcache and on-demand module loading.

## [0.4.0] - 2026-08-02

### Added
- FTP account management: create / edit / delete FTP accounts with POSIX home-directory, quota, bandwidth and IP allow/deny restrictions; test login, sync from system users, and JSON export.
- Notification center: email / SMTP / webhook channels with a one-click test sender, an in-panel inbox (read / unread / delete / clear), and a live summary badge in the top bar.
- Alert rules: watch site file changes, SSL expiry, disk usage, backup success/failure and more; deliver alerts to notification channels.
- Security scan: heuristic vulnerability scan of the panel and site files, with capability-based availability and bilingual explanations.
- Backup destinations: remote storage for backups via S3, FTP and SFTP (access keys / passwords / private keys stored AES-encrypted).
- Backup schedules: recurring automated backups with retention, run now, and per-run history (list / detail).
- Two-factor authentication (TOTP): enroll / confirm / disable 2FA with recovery codes, integrated into the Settings page.
- ACME SSL: issue, renew, auto-renew and delete Let's Encrypt certificates with PEM download (uses webcron for unattended renewal).
- Internal web cron: `webcron.php` token-guarded endpoint that drives scheduled backups, ACME renewal and notification delivery without OS crontab.
- `SECURITY.md` and a GitHub vulnerability-report issue template.

### Fixed
- TypeScript strict-mode errors across the new modules (`secscan`, `notifications`, `Ftp`, `Backup`, `OperationLog`, `SecurityScan`, `shared/types`) — cumulative 40+ fixes, `tsc --noEmit` clean.
- i18n: removed duplicate top-level namespaces and `remoteBackup.tabDestinations` duplicate keys in `zh.ts` / `en.ts`; `useI18n` now exposes `language` instead of the non-existent `locale`.

### Breaking Changes
- None. `.gojs/` config stays compatible; upgrade by overwriting the `gojs/` folder.

## [0.3.1] - 2026-07-31

### Fixed
- Session cookie path is now auto-inferred from `SCRIPT_NAME` (works for `/`, `/gojs/`, `/panel/`, or any sub-path).
- Cron capability detection now decouples `exec()` availability from the presence of the `crontab` CLI; a warning banner is shown when only `crontab` is missing instead of locking the entire UI.
- Settings "Developer" row no longer duplicates the team name; the `developerTeam` i18n key is now a proper label.
- Bare-name file rename in React is 100% stable: the dialog opens with the text auto-selected, and submit synchronises the DOM `input.value` back into React state before calling the API.
- Database `export`/`import` and all other `db/*` endpoints return HTTP 400 with the standard `{ ok: false, error: { code, message, message_key } }` shape on failure; the error is surfaced in the UI via a toast.
- SSL Status visual states: Checking / Failed / Pending / OK now render with distinct icon badges (Spinner, XCircle, Clock, CheckCircle), a `warning` Badge variant was added, and failed rows show a retry-style Check button label.
- Pre-existing TypeScript `TS6133: 'hasKey' is declared but never read` warning in `SSL.tsx` eliminated.

### UX / Polish
- Dashboard memory usage tooltip shows "Used / Total" plus the percentage on two lines.
- PhpInfo top card "Loaded Extensions" count renders on its own line with a larger font and `min-w-0`, so it no longer horizontally overflows at 375px.
- Install wizard success page now shows a prominent large "Go to Login" CTA button.
- Error Log empty state now mentions the default log path `.gojs/php_errors.log`.
- Activity Log list row uses `grid-cols-[1fr_auto_auto] gap-4` so the action / time / IP columns are clearly separated.

### Breaking Changes
- None. `.gojs/` config stays compatible; upgrade by overwriting the `gojs/` folder.

## [0.3.0] - 2026-07-31

### Added
- Environment check page: a PHP capability matrix is shown the moment you enter the panel, each item marked ✅ / ❌.
- Operation log system: every write action is auto-recorded with IP + timestamp, with filtering and pagination.
- Login brute-force lockout: 5 consecutive failures per IP ban the IP for 15 minutes, with a countdown shown on the login page.
- Cron job management: add / edit / delete crontab entries, with graceful degradation when `exec` is disabled.
- One-click backup and restore: packs site files + database SQL, for one-click download / restore.
- SSL certificate status monitor: detects SSL expiry dates for added domains.
- Disk usage visualisation: ring progress chart + directory size bar chart.
- Version management and migration: auto-detects legacy configs on first boot and migrates them forward.

### Fixed
- File management edge bugs (special-character filenames, empty-directory deletion, deeply nested paths).
- Database management edge bugs (empty SQL import, chunked upload of very large files, special-character table names).
- 1970 date display: `formatDate` was treating PHP second-level timestamps as milliseconds; a new `toMs()` helper now normalises both units.
- Settings page front-end version stuck at `0.1.0`: `authStore.setBootstrap` now accepts `frontendVersion` from the bootstrap API.
- SSL domain regex too strict: localhost / IPs / internal hostnames were rejected. Frontend and backend regexes are now unified and accept optional TLDs.
- EnvCheck related-feature / reason / suggestion fields contained mixed Chinese. Backend now returns i18n keys, frontend uses `hasKey` + `t()` to translate.
- Cron / SSL error messages hard-coded in Chinese. Backend now returns `message_key` / `error_key` with params; frontend translates uniformly.

### Breaking Changes
- None. The `.gojs/` config directory structure remains backward-compatible. Existing users may upgrade by overwriting the `gojs/` folder.

## [0.2.1] - 2026-07-29

### Added
- Sub-path architecture refactor: the panel is served from the `/gojs/` subdirectory and does not occupy the web root.
- System Info: added memory usage card, dual `/proc` sampling for per-process CPU.
- Settings page "Private Access" section is fully i18n-ified.
- PHP Info page: added a "Copy php.ini path" button.

### Fixed
- `router.php` / `api.php` dispatch leading-slash bug that caused API 404s.
- `useAuth.logout()` hard-coded `/login` redirect path.
- Added `ImportMetaEnv.BASE_URL` TypeScript declaration.

## [0.2.0] - 2026-07-28

### Added
- File compress / extract (Zip / Tar).
- Database SQL import / export.
- PHP error log viewer.
- Config health check.
- Disk analysis.
- Security hardening (path traversal protection, IP forgery protection, file upload safety, etc.).
