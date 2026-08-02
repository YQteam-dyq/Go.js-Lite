# Security Policy

## Supported Versions

| Version | Supported | Security fixes only | End-of-life date |
|---------|-----------|---------------------|------------------|
| 0.4.x   | ✅ Yes    | —                   | —                |
| 0.3.x   | ✔ Partial | ✔ Yes               | 2027-02-01       |
| 0.2.x   | ❌ No     | ❌ No                | 2026-09-01       |
| < 0.2   | ❌ No     | ❌ No                | —                |

Only the latest minor release in each supported major.minor line receives full support. Security fixes are backported to the 0.3.x line only until the EOL date.

## Reporting a Vulnerability

**PLEASE DO NOT file public GitHub issues for security vulnerabilities.** Public disclosure can put every Go.js Lite user at risk before a patch is ready.

Instead, send an encrypted report to:

```
Email: yqteamcs0001@163.com
PGP key ID: 0xDEADBEEFCAFEBABE1234567890ABCDEF12345678
Fingerprint: AAAA BBBB CCCC DDDD EEEE  FFFF 0000 1111 2222 3333
```

```
-----BEGIN PGP PUBLIC KEY BLOCK-----
Comment: Go.js Lite Security Team <yqteamcs0001@163.com>
Placeholder PGP block. Replace with the real team key when publishing.
mQINBG... [this is a template]
-----END PGP PUBLIC KEY BLOCK-----
```

### How to report

1. Compose an email with subject format:
   ```
   [SECURITY] Go.js-Lite <short-description-slug>
   ```
   Example: `[SECURITY] Go.js-Lite path-traversal in Files API upload parameter`

2. Include:
   - A brief description of the vulnerability (type, impact, severity estimate).
   - Full reproduction steps, including the exact HTTP request or code snippet
     that triggers it.
   - A proof-of-concept payload if possible, **anonymized** (no real user data,
     no real API keys).
   - Your GitHub username if you want to be credited in the release notes.
   - Optionally attach a PGP signature to prove the email is from you.

3. We will reply within 48 hours of receiving your email to acknowledge receipt
   and assign a tracking ticket ID.

## Disclosure Policy & Timeline

| Timeframe     | Action                                                                |
|---------------|-----------------------------------------------------------------------|
| Within 48 h   | Acknowledge report, confirm reproducibility scope, assign tracking ID |
| Within 5 days | Severity assessment + initial patch estimate + reporter preview build |
| Within 30 days | Fix released (patch version), advisory published, reporter credited  |

### Coordinated disclosure

- Reports are kept **strictly confidential** until the patch release.
- If the issue is actively being exploited in the wild, we may publish a
  mitigation notice together with the advisory earlier than the 30-day window.
- If the 30-day window cannot be met, we inform the reporter with an updated
  estimate and credit them in the final advisory regardless.
- Reporters who follow the process will be named in the "Security credits"
  section of the corresponding release notes. Requests for anonymity are
  honored.

## Scope

### In scope
- Panel code itself at `/gojs/*` and `/gojs/api.php`.
- Authentication, CSRF protection, session handling, and 2FA flows.
- File upload / download / rename / deletion logic in the Files page.
- Database export & import SQL execution paths.
- Sensitive config file handling (`.gojs/config.php`, secrets).
- SECURITY.md and the vulnerability reporting process itself.

### Out of scope — report these to the upstream vendor instead
- User-deployed PHP application code living outside `/gojs/*` under the webroot.
- Vulnerabilities in third-party PHP / Node.js dependencies that are tracked
  by the panel's dependency scanner but not fixed by us (these are surfaced as
  advisories inside Settings → Security Scan).
- Brute-force bypass reports that require disabling the existing default
  "5/15 min lockout" protection.
- Reports requiring social engineering, physical access to the panel, or
  non-standard browser extensions.
- Denial-of-service (DoS) attacks that require the attacker already to have
  valid admin credentials.
