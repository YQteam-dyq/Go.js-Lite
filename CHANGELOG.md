# Changelog

> Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
> Project language policy: this file is **English only** starting from v0.3.1; Chinese is no longer maintained here.

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
