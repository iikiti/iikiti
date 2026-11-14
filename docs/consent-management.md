# Consent Management Platform

The Consent Management plugin (`plugins/consent-management`) provides a self-contained cookie consent and privacy management system: a Svelte consent banner, per-user/session consent records, frontend script blocking until consent, a consent API, audit logging and proof of consent, geo-targeted legal frameworks, and optional integration with Google Consent Mode / Consent Mode v2.

## Features

- Consent banner with category toggles: `necessary` (always on), `analytics`, `marketing`.
- Consent records stored per site + user (logged in) or session (anonymous) in `object_properties` (no new database tables).
- Frontend script blocking: scripts tagged `data-consent-category="analytics|marketing"` are neutralised until consent is granted, then released.
- Consent management API: `POST /api/privacy/consent` and `GET /api/privacy/consent`.
- Admin screens: consent log and per-site banner configuration (`ROLE_ADMIN`).
- Audit logging via `AuditRecorder` for every consent change, with proof-of-consent capture (proof token, timestamp, categories snapshot, framework, geo, signals, IP, UA).
- Auto-selection of `analytics`/`marketing` = denied when a GPC (`Sec-GPC` / `navigator.globalPrivacyControl`) or Do Not Track signal is present and the visitor has not previously consented.
- Geo-targeted legal framework selection (GDPR / CCPA / LGPD / PIPEDA / none) from the `X-Geo-Country` header or `CONSENT_DEFAULT_COUNTRY` env var, overridable per site.
- Google Consent Mode / Consent Mode v2 surface: consent changes dispatch `window.iikiti.consent` events mapping categories to `ad_storage`, `analytics_storage`, `ad_user_data`, `ad_personalization`.

## Installation

The plugin is core-bundled under `plugins/consent-management/` and is discovered automatically. Enable it on a site:

```
php bin/console iikiti:plugin:enable consent-management
```

Banner defaults (consent version, default categories, framework override) live in the site's plugin configuration under `consent-management` and can be edited at Admin › Privacy & Consent › Banner Configuration, or via `iikiti:plugin:configure`.

## Front-end delivery

Two webpack entries are built into `public/build/`:

- `consent-manager` — vanilla consent manager loaded early (sync) on public pages; governs script blocking and consent state. Exposes `window.iikiti.consent`.
- `consent-banner` — Svelte banner/preferences components, lazy-mounted by the consent manager when the banner needs to show.

The `consent-management.banner` fragment renders the banner container, the `iikiti-consent-config` block, and both script tags. Themes should render `{{ iikiti_fragment('consent-management.banner') }}` in the page `<head>` so script blocking is active as early as possible.

## JavaScript API (`window.iikiti.consent`)

| Method | Description |
|--------|-------------|
| `getState()` | Current consent state (`hasConsented`, `categories`, `version`, `legalFramework`, `proofToken`, …). |
| `isAllowed(category)` | `true` if `analytics` or `marketing` is allowed. |
| `allow(category)` / `deny(category)` | Set a category and persist consent. |
| `onChange(fn)` | Subscribe to consent changes; returns an unsubscribe function. |
| `showBanner()` / `hideBanner()` / `loadBanner()` | Show / hide / lazy-load the Svelte banner. |

## Consent Mode v2 integration

On every consent change the manager dispatches a `CustomEvent` named `iikiti:consent-mode-update` on `window` with detail:

```js
{
  ad_storage: 'granted'|'denied',
  analytics_storage: 'granted'|'denied',
  ad_user_data: 'granted'|'denied',
  ad_personalization: 'granted'|'denied',
  consent_state: { …full state… },
}
```

A Google Tag Manager / gtag consumer can listen for this event and call the corresponding Consent Mode v2 commands. The event is a frontend surface only; server-side Consent Mode tagging is the consumer's responsibility.

## API

`POST /api/privacy/consent` (public)
- Body: `{ categories: { necessary, analytics, marketing }, session_id?, version? }`
- Records/updates consent for the authenticated user or the supplied session id. Returns the proof token and resolved categories/framework/version.
- `400` when anonymous without a `session_id`.

`GET /api/privacy/consent` (public)
- Returns the current consent state for the authenticated user or session, including `stale` (true when the configured consent version changed since the visitor last consented).

`GET /api/admin/privacy/consent-log` (`ROLE_ADMIN`)
- Paginated consent-log records for the current site.

`GET`/`PUT /api/admin/privacy/config` (`ROLE_ADMIN`)
- Read/update the site's consent-management plugin configuration (version, default categories, framework override).

## Proof of consent

Every consent change is written to a `ConsentRecord` (`object_properties` namespaced `privacy.consent.*`) including:

- `proof_token` (random 16-byte hex)
- `consented_at`
- `categories` snapshot
- `legal_framework`, `geo_country`, `signals`
- `ip_address`, `user_agent`

An `AuditRecorder` event (`consent.granted` / `consent.updated`) is emitted around each change with before/after categories and the proof context.

## Legal frameworks

Default country → framework mapping:

| Region | Countries | Framework |
|--------|-----------|-----------|
| EU/EEA | DE, FR, ES, IT, NL, … | `gdpr` |
| US | US | `ccpa` |
| Brazil | BR | `lgpd` |
| Canada | CA | `pipeda` |
| other | — | `none` |

Override per site via `framework_override` in banner configuration, or leave empty to auto-detect from geo.

## Signals

- `Sec-GPC` request header → `signals.gpc`
- `navigator.globalPrivacyControl === true` → `signals.gpc`
- `DNT: 1` / `navigator.doNotTrack === '1'` → `signals.dnt`

When any signal is present and the visitor has not previously consented, `analytics` and `marketing` default to denied.
