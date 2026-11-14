# Security Headers Management

A per-site security-header policy that complements the existing request firewall. The firewall inspects inbound traffic; this feature governs outbound response headers, giving site administrators declarative control over browser security behavior.

## Overview

Security headers are emitted by a `kernel.response` listener after site resolution, so headers are site-scoped. Headers are only emitted when:

- The effective mode is `on` (see Modes below), AND
- The request is secure (HTTPS), AND
- The response is successful (2xx/3xx).

Default safe policies are applied per environment — stricter in production, relaxed in development to avoid breaking the dev toolbar.

## Headers managed

| Header | Purpose | Notes |
|--------|---------|-------|
| `Content-Security-Policy` | Restrict script/style sources; control inline execution via nonces/hashes | Nonce placeholder `__IIKITI_CSP_NONCE__` replaced per-request |
| `Content-Security-Policy-Report-Only` | Test a CSP without enforcing (violation reports only) | Same nonce substitution as CSP |
| `Strict-Transport-Security` | HSTS with `includeSubDomains` and `preload` options | Prod default: `max-age=31536000; includeSubDomains; preload` |
| `X-Frame-Options` | `DENY` / `SAMEORIGIN` / `ALLOW-FROM` | |
| `X-Content-Type-Options` | `nosniff` | |
| `Referrer-Policy` | `no-referrer`, `same-origin`, `strict-origin-when-cross-origin`, etc. | |
| `Permissions-Policy` | Camera, microphone, geolocation, payment, etc. | |
| `Cross-Origin-Opener-Policy` | `same-origin` / `same-origin-allow-popups` | |
| `Cross-Origin-Embedder-Policy` | `require-corp` / `unsafe-none` | |
| `Cross-Origin-Resource-Policy` | `same-origin` / `same-site` / `cross-origin` | |

## CSP specifics

- Nonce-based policy for inline scripts/styles: a per-request nonce is generated and injected into inline `<script nonce="...">` and `<style nonce="...">` tags that lack one, so nonce-based CSP works without template changes. The policy includes `nonce-{value}`.
- Hash-based policy for trusted inline blocks: admin-supplied hash sources are stored in the CSP value and emitted verbatim.
- `report-uri` / `report-to` points to `POST /api/internal/csp-reports` (rate-limited, stored for admin review).
- Report-only mode first, then enforce after violations are cleared.

## API (ROLE_ADMIN)

- `GET /api/admin/sites/{siteId}/security/headers` — current header policy
- `PUT /api/admin/sites/{siteId}/security/headers` — replace the policy
- `POST /api/admin/sites/{siteId}/security/headers/test` — render a sample response with applied headers for verification

The site ID in the path must match the site resolved from the request host, otherwise a 404 is returned. The caller must have `ROLE_ADMIN`.

Policy body (PUT):

```json
{
  "headers": [
    { "name": "Content-Security-Policy", "mode": "on", "value": "default-src 'self' __IIKITI_CSP_NONCE__" },
    { "name": "X-Frame-Options", "mode": "on", "value": "DENY" }
  ]
}
```

Each header needs `name` and `value`; `mode` defaults to `default` (inherits effective-mode rules). Unknown header names or `mode: "off"` / empty values are skipped.

## Storage

The policy is stored on the `Site` object as a site configuration property (`security_headers_policy`), versioned for rollback. Each PUT bumps the version; administrators can roll back by re-applying an older observed policy.

## Modes

### Global switch (environment variable)

`IIKITI_SECURITY_HEADERS`

| Value | Effect |
|-------|--------|
| blank / `default` / `true` / `1` | default (headers on when secure + successful) |
| `off` | forced off for **all** sites, overriding any administrative settings |
| anything else | treated as `default` |

The env var defaults to blank/empty (use the standard default). Setting it to `true` is the same as default. Setting it to `off` is forced off and overrides administrative settings for all sites.

### Per-site setting

Stored on the Site configuration as `security_headers_mode`:

| Value | Effect |
|-------|--------|
| `default` (unset) | headers on when secure + successful |
| `forced_off` | headers off for this site |
| `forced_on` | headers on regardless of secure/successful |

Global overrides all site settings unless the site is set to `default`. Global forced-off (`IIKITI_SECURITY_HEADERS=off`) overrides everything for all sites.

## Integration

- Pairs with existing `MCP_ALLOWED_HOSTS` DNS-rebinding concepts via CSP `frame-ancestors` and `connect-src`.
- Emitted by a `kernel.response` listener after site resolution.
- CSP violation reports POST to `POST /api/internal/csp-reports` (rate-limited, stored in the `csp_reports` table for admin review).

## Database

A new `csp_reports` table is created via `migrations/Version20261114120000.php` to store violation reports. No migration is required for the header policy itself (it reuses the existing `object_properties` table).

## Configuration example

Add to `.env.local`:

```env
IIKITI_SECURITY_HEADERS=default
```

Force off globally:

```env
IIKITI_SECURITY_HEADERS=off
```

## Verification

Run:

```bash
bun run build   # frontend, if applicable
php bin/console lint:container
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix --dry-run --diff
php bin/phpunit tests/Service/SecurityHeaderPolicyTest.php \
  tests/Service/SecurityHeaderStoreTest.php \
  tests/Controller/Api/Admin/SecurityHeadersControllerTest.php \
  tests/Integration/SecurityHeadersWiringTest.php
```

## Changelog

Recorded in `CHANGELOG.md` under `## [Unreleased] > Added`.
