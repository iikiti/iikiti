# iikiti Content Optimization

The first-party `iikiti-content-optimization` plugin provides SEO, GEO, and AIO-related metadata, indexing controls, structured data, sitemaps, robots policy, and analytics connector settings. It supplies standards-based metadata and crawl directives; it does not guarantee search rankings or placement in generative/AI answers.

## Activation and administration

Content Optimization is enabled by default on new and existing Sites. Site administrators can enable or disable it on the **Content Optimization** screen. Disabling it stops the plugin's page metadata, editor section, analytics configuration, and crawl output for that Site; settings remain available in the admin screen so the plugin can be re-enabled without losing configuration.

The screen has two settings scopes:

- **Global (Application):** global administrators manage defaults for Sites in the selected Application.
- **Site:** Site Managers may override any inherited setting for the current/selected Site. Reset an override to inherit the Application value. Changing a child value never silently copies or modifies the parent value.

All settings are validated by the API. The application-level settings are stored on the Application; Site overrides are stored separately on the Site. Analytics measurement/project IDs are public identifiers, not credentials. Do not enter tokens, API keys, passwords, or other secrets.

## Page and homepage SEO settings

Open a Page with `?edit` and choose the **SEO** settings section. Enter a title, description, canonical URL, indexing directives, Open Graph values/image URL, and X/Twitter card values. Blank values inherit from the Site/Application defaults. Canonical and social image URLs must be absolute `https://` URLs or root-relative paths (`/path`). The SERP and social previews display the draft values without loading remote preview images. Title/description guidance is advisory; values are not silently truncated.

SEO values use the editor's existing settings draft/publish lifecycle. A bad or stale draft cannot change public metadata until successfully published. The server normalizes and validates namespaced Content Optimization keys while preserving settings owned by other plugins.

On the homepage, **Homepage SEO Settings** is a Site-scoped target distinct from the shared Template. Use the settings-target selector to switch between homepage SEO and shared Template settings; homepage values never mutate a Template used by other Pages. Homepage SEO save/publish requires Site-management permission and the relevant editor publish permission.

Public output includes a title, description where present, canonical URL, robots directives, Open Graph tags (including the active render locale), Twitter card tags, and WebSite/Organization/Breadcrumb JSON-LD when the necessary Site/Page data exists. Localized Page/Site titles and the document `lang` use the resolved render locale. Canonical hosts come from the configured Site domain; query parameters are not included. Editor/preview requests are marked noindex/nofollow and do not load analytics.

## Indexing, robots, and sitemap

Application/Site defaults provide index/follow directives, an XML sitemap toggle, and AI-crawler access policy. Page-level `noindex`/`nofollow` overrides are honored. Sitemap entries are based on existing publicly routable Page slugs and exclude entries whose effective SEO or response-header policy is `noindex`/`none`, or whose custom `canonical_url` points to a different URL than the page itself. No new publication-state flag is introduced.

`/robots.txt` and `/sitemap.xml` support `GET` and `HEAD`, emit `Cache-Control: public, max-age=300`, and cache generated documents server-side in `cache.rendered_fragments` (invalidated automatically when CMS content or configuration changes).

Application/Site `X-Robots-Tag` rules remain managed through the shared **Response Headers** policy, not a parallel Content Optimization store. The indexing screen shows configured header rules and warns when a stored `noindex` rule may conflict with an `index` default; conditional rules and Application/Site inheritance determine the effective result. Content Optimization never edits CSP or response-header policy automatically.

The `robots.txt` AI-crawler setting applies only to declared crawler user agents; it does not provide access control. A `Disallow` rule is a crawl preference, not a security boundary. The sitemap advertises only configured canonical URLs.

## Analytics integrations and consent

Version 1 configures Google Analytics 4 and Microsoft Clarity tags and links administrators to their provider dashboards. It does not import provider metrics into iikiti. Enable a provider and enter its GA4 measurement ID (`G-...`) or Clarity project ID; invalid identifiers are rejected by the API.

Analytics requires the **Consent Management** plugin to be enabled for the Site and its `consent-management.banner` fragment provider to be available. If the consent runtime is missing, initializing, stale, or has not explicitly granted the `analytics` category, no provider script is loaded. Consent Management's banner appears in the page body, not in `<head>`. The public page contains only an inert JSON descriptor; the same-origin loader is dynamically imported only on pages that emit a descriptor and creates provider scripts after consent (using GA4 Consent Mode v2 and Clarity Consent API v2). It does not emit provider `preconnect`, `dns-prefetch`, or `preload` hints.

The API and Site admin interfaces do not change CSP automatically. Configure the site's existing security-header policy with only the required sources, and verify the policy in report-only mode first. Built-in requirements are:

- **GA4:** `script-src https://www.googletagmanager.com/gtag/js`; `connect-src https://www.google-analytics.com https://*.google-analytics.com https://*.analytics.google.com`; `img-src https://*.google-analytics.com`.
- **Clarity:** `script-src https://www.clarity.ms/tag/ https://scripts.clarity.ms/`; `connect-src https://*.clarity.ms`; `img-src https://c.clarity.ms https://c.bing.com`.

Review `script-src 'strict-dynamic'` carefully: it can allow a trusted loader's dynamically created scripts transitively, while `connect-src` and `img-src` continue to constrain beacons/pixels. Optional GA4 Signals/Ads domains are not enabled by the plugin.

## Third-party analytics connectors

Plugins may register an `AnalyticsConnectorInterface` service tagged `iikiti.analytics_connector`. Connector keys must be namespaced as `plugin-slug.connector-id` and match the owning plugin's declared bundle namespace; consent is fixed to `analytics`. Each connector declares a typed setting schema, server-side validation, a code-owned HTTPS script URL/adapter identifier, and structured `scriptSrc`/`connectSrc`/`imgSrc` requirements. Stored configuration cannot supply URLs, JavaScript, HTML, or credentials. Malformed, namespace-mismatched, or key-colliding third-party connectors are skipped and logged at boot so a broken third-party plugin cannot take down site rendering. Custom connector schemas and CSP requirements appear in the Content Optimization admin screen only while the owning plugin is active on the Site.

Plugins that need a browser adapter register trusted code with `window.iikiti.analytics.registerAdapter(adapterId, { load, setConsent })`. Both methods are required. `load` runs only after current explicit analytics consent; `setConsent(false, state)` must stop further collection when consent is revoked. Do not use inline scripts, mutable database-supplied URLs, generic script loaders, or nonce propagation. The core loader validates the server descriptor and CSP path again before dispatching to an adapter; unregistered adapters fail closed.

## Consumer/API split

In a Consumer/API split, page metadata, SEO settings, and sitemap/robots data are resolved on the database-owning API runtime and composed into the consumer's full-document response. Configure Symfony's `TRUSTED_PROXIES` on the API tier when terminating TLS at the Consumer or reverse proxy so the forwarded `X-Forwarded-Proto` header preserves `https://` in canonical and sitemap URLs (untrusted `X-SITE-SCHEME` headers are never used for public URL generation). Analytics scripts are loaded from the consumer's same-origin bundled loader after browser consent. Consent state is local to the browser when the Consumer cannot reach the existing consent persistence endpoint; providers remain blocked until the local Consent Management state is explicitly current and allowed.
