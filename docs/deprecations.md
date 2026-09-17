# Deprecations and the 1.0 removal schedule

This file is the single place where Go.js-Lite records what is deprecated, how the
deprecation is announced at runtime, and when the deprecated surface is removed.

Current version: the value in [`version.json`](../version.json) (`version` field).

---

## Policy

1. A deprecated surface keeps working until the removal release named below.
2. Every deprecated surface is announced at runtime, not only in the changelog:
   responses carry `Deprecation`, `Sunset`, `Link ... rel="deprecation"` and
   `X-Gojs-Deprecations` headers.
3. `GET /api/bootstrap` returns the full registry in its `deprecations` field, so a
   client can warn its operator without reading the source.
4. A surface is only removed in a `major` release. The next removal window is
   **1.0.0**, no earlier than the sunset date below.

---

## Registry

| Id | Deprecated surface | Replacement | Deprecated in | Removed in | Sunset |
| --- | --- | --- | --- | --- | --- |
| [`query_api`](#query_api) | `?api=<action>` query form | `/api/<action>` path form | 0.8.0 | 1.0.0 | 2027-06-30 |
| [`legacy_access_token`](#legacy_access_token) | `?token=<access_token>`, `X-Access-Token`, `POST /api/regenerate-access-token`, the `access_token` config key | A scoped API token (`X-API-Token` or `Authorization: Bearer`) or an authenticated session | 0.8.0 | 1.0.0 | 2027-06-30 |

The registry lives in `backend/deprecations.php` (`gojs_deprecation_registry()`), which
is the machine-readable source for the table above.

### `query_api`

The panel exposes two spellings of the same endpoint. The path form is the supported
one because it keeps the action out of the query string, out of access logs and out of
`Referer` headers.

```http
GET /gojs/api/files?path=/            # supported
GET /gojs/api.php?api=files&path=/    # deprecated
```

`/api` and `/api/` are also recognised as the path form. Under the built-in dev server
`router.php` rewrites `/api/<action>` internally, which is still the path form: the
deprecation is only raised when the client itself sends `?api=`.

Removal behaviour in 1.0.0: `?api=<action>` is no longer resolved, the action stays
empty and the request answers `404 not_found`.

### `legacy_access_token`

The shared `access_token` in `.gojs/config.php` predates scoped API tokens. It is a
single secret, it is not scoped, it is not revocable per client, and the URL form leaks
it into logs and browser history.

```http
GET /gojs/api/bootstrap?token=<access_token>   # deprecated
GET /gojs/api/bootstrap
X-API-Token: <scoped token>                    # supported
```

Removal behaviour in 1.0.0:

- `?token=` no longer authenticates; the request answers `401 token_removed`.
- The `X-Access-Token` header is ignored.
- `POST /api/regenerate-access-token` is removed (`404 not_found`).
- The `access_token` config key is no longer read by the authentication chain.

---

## Runtime signals

| Signal | Where | Example |
| --- | --- | --- |
| `Deprecation` | any request that used a deprecated surface | `Deprecation: Thu, 17 Sep 2026 00:00:00 GMT` |
| `Sunset` | same | `Sunset: Wed, 30 Jun 2027 00:00:00 GMT` |
| `Link` | same | `Link: <docs/deprecations.md#query_api>; rel="deprecation"; type="text/markdown"` |
| `X-Gojs-Deprecations` | same, lists every id hit by the request | `X-Gojs-Deprecations: query_api` |
| `deprecations` | `GET /api/bootstrap` response body | object keyed by id, one notice per entry |
| `deprecated`, `removeIn`, `replacement` | `POST /api/regenerate-access-token` response body | `true`, `1.0.0`, `tokens` |

---

## Timeline

| Milestone | Release | Date | What changes |
| --- | --- | --- | --- |
| Announced | 0.8.0 | 2026-09-17 | Deprecation headers and the `deprecations` bootstrap payload go live, this file and `docs/api.md` are updated. Nothing breaks. |
| Warn | 0.9.0 | 2026-Q4 | The notice is repeated in the operator-facing login/settings surfaces and in the upgrade report. Still nothing breaks. |
| Frozen | 0.9.9 | 2027-Q1 | Last release that accepts the deprecated surfaces. New code must not use them. |
| Removed | 1.0.0 | 2027-06-30 or later | The surfaces listed above stop working. `docs/migration-0.9-to-1.0.md` ships the exact replacement steps. |

> Status note: the notice is currently emitted on the wire only - the response headers listed under
> "Runtime signals" and the `deprecations` field of `GET /api/bootstrap`. The rest of the Warn
> milestone, repeating the notice in the login and settings surfaces and in the upgrade report, is
> still outstanding. Nothing breaks either way; a caller can already detect the deprecated surface
> from the headers alone.

The sunset date is the earliest removal date, not a promise: 1.0.0 ships when the
timeline above is complete, which may be later than 2027-06-30.

---

## How to check whether you are affected

```bash
curl -sS -D- -o /dev/null 'https://panel.example/gojs/api/bootstrap?token=REDACTED' \
  | grep -iE '^(deprecation|sunset|x-gojs-deprecations):'
```

A non-empty result means the caller used a deprecated surface and has until 1.0.0 to
migrate.

---

## Removal checklist for 1.0.0

1. Delete `gojs_check_access_token()` and its call sites in `backend/common.php`,
   `backend/auth.php`, `backend/core.php` and `router.php`.
2. Delete `gojs_api_regenerate_access_token()` and its route in `backend/core.php`.
3. Drop the `access_token` config key and the `accessToken` fields in
   `backend/auth.php` and `backend/exports.php`.
4. Stop resolving `$_GET['api']` in `gojs_dispatch()`.
5. Remove the `query_api` and `legacy_access_token` entries from
   `gojs_deprecation_registry()` once they are gone, and keep this file as the record of
   what was removed and when.
