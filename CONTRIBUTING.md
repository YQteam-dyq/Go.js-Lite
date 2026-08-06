# Contributing Guide

Thank you for considering contributing to Go.js Lite! This guide helps you set up a local environment, develop, test, and submit changes. Please read it fully before submitting, and make sure your changes follow the project's conventions.

---

## About the Project

Go.js Lite is a lightweight server management panel built for PHP shared hosting. It **does not claim the web root**, is mobile-friendly, and covers file management, database management, SSL/ACME, backups, Cron, FTP, and notification monitoring.

Architecture overview:

- **Backend**: PHP 7.4+, with domain-split module files under `backend/` (`core.php`, `common.php`, `files.php`, `auth.php`, `database.php`, `system.php`, `ssl.php`, `backup.php`, `cron.php`, `notifications.php`, `ftp.php`, `htaccess.php`, `monitor.php`, `secscan.php`, `destinations.php`, `upgrade.php`). `api.php` is the single entry point, dispatching requests to module handlers by `/api/<action>` via `router.php` and `backend/Router.php`.
- **Frontend**: React 18 + TypeScript + Vite + Tailwind CSS, with source code under `src/`.

---

## Environment Requirements

| Dependency | Version   | Notes |
| --- | --- | --- |
| PHP | `>=7.4` | Hard constraint for shared hosting; **PHP 8-only syntax is not allowed** |
| Composer | any recent version | For installing backend test dependencies |
| Node.js | 18+ | Frontend build toolchain |
| npm | 9+ | Installed with Node.js |

> ⚠️ **PHP 7.4 compatibility is a hard constraint**: do not use PHP 8-only syntax such as `enum`, `readonly`, constructor property promotion, or named arguments, so that the panel can run on shared hosting.

---

## Local Setup

### 1. Clone the repository

```bash
git clone https://github.com/YQteam-dyq/Go.js-Lite.git
cd Go.js-Lite
```

### 2. Install frontend dependencies

```bash
npm install
```

### 3. Start the backend

The backend only needs PHP's built-in server for development and supports the `/gojs/` prefix:

```bash
php -S 127.0.0.1:8080 router.php
```

`api.php` is the single backend entry point, dispatching requests to module handlers by `/api/<action>` via `router.php` and `backend/Router.php`.

### 4. Start the frontend dev server

```bash
npm run dev
```

The frontend dev server runs at `http://localhost:5173` and automatically proxies `/gojs/api`. Visit `http://localhost:5173/gojs/` to develop.

---

## Build and Checks

```bash
# Local development (hot reload)
npm run dev

# Type checking (passes when there is no output)
npm run typecheck

# Lint (--max-warnings 0, zero tolerance)
npm run lint

# Production build (runs tsc -b, then vite build)
npm run build

# Preview the build locally
npm run preview
```

---

## Running Tests

The backend uses PHPUnit (9.x, compatible with PHP 7.4). Install dependencies before the first run:

```bash
# Install backend dependencies (including phpunit)
composer install

# Run all backend unit tests
vendor/bin/phpunit

# Run a specific test file
vendor/bin/phpunit tests/AuthTest.php
```

The frontend does not yet include a unit testing framework; please use `npm run typecheck` and `npm run lint` to ensure frontend code quality.

---

## Code Style Conventions

### Backend (PHP)

- **Target version**: PHP 7.4. No PHP 8-only syntax is allowed (`enum`, `readonly`, constructor property promotion, named arguments, `match`, etc.).
- **Function naming**: public module functions use the `gojs_` prefix, e.g. `gojs_safe_path`, `gojs_json_response`.
- **Comments**: core handler functions use English PHPDoc comments (`@param` / `@return` / behavior description), formatted as:

  ```php
  /**
   * Describes the function behavior.
   *
   * @param string $path The path to validate
   * @return bool Returns true if validation passes, otherwise false
   */
  ```

- **Routing**: to add an endpoint, register it in `gojs_build_router()` in `backend/core.php`; do not modify the business dispatch logic directly.
- **Compatibility**: do not change the HTTP status codes or the JSON structure (`ok` / `code` / `message` / `data`) of existing endpoints.

### Frontend (TypeScript / React)

- Use TypeScript strict mode; avoid overusing `any`.
- Components follow the existing directory structure (`src/components`, `src/routes`, `src/api`, `src/hooks`).
- Styling uses Tailwind CSS consistently; do not introduce additional UI libraries.
- Keep interactions concise and mobile-first.

---

## Commit Conventions (Conventional Commits)

Please use the Conventional Commits convention to make generating CHANGELOGs and locating changes easier:

```
<type>(<scope>): <subject>
```

Common types:

- `feat`: new feature
- `fix`: bug fix
- `docs`: documentation-only changes
- `refactor`: refactoring (no behavior change)
- `test`: adding/modifying tests
- `chore`: build or tooling tasks
- `style`: code formatting (does not affect logic)
- `perf`: performance improvements
- `security`: security fixes

Examples:

```text
feat(auth): add TOTP two-factor recovery code endpoint
fix(files): fix memory overflow when uploading very large files
docs: add PHPDoc comments for backend core functions
```

Recommended practices:

- Make each commit focus on a single logical change; avoid mixing unrelated modifications.
- Keep change descriptions concise and clear; add background and impact in the body when necessary.
- Before committing, run `npm run typecheck`, `npm run lint`, and `vendor/bin/phpunit`.

---

## How to Open an Issue / PR

### Opening an Issue

- Use the repository's issue templates (a security vulnerability template is available under `.github/ISSUE_TEMPLATE/`).
- Describe clearly: reproduction steps, expected behavior, actual behavior, and the runtime environment (PHP / Node versions, hosting type).
- For security vulnerabilities, follow the private disclosure process in `SECURITY.md`; **do not submit them publicly**.

### Opening a PR

1. Create a separate branch from `main`, named to describe the change, e.g. `feat/auth-totp`, `fix/files-upload`.
2. Complete your changes following the code style and commit conventions above.
3. Pass `php -l`, `npm run typecheck`, `npm run lint`, and `vendor/bin/phpunit` locally.
4. Submit the PR, describing the motivation, scope, and test coverage.
5. Maintainers may suggest changes after review; please stay in communication.

Thank you for your contribution!