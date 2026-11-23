# Core vs. Plugins Architecture

This document defines what belongs in iikiti CMS **Core**, what should ship as **built-in monorepo plugins**, and how extension contracts bridge the two without creating circular dependencies or security regressions.

---

## 1. Decision Framework (6 Principles)

Evaluate any candidate subsystem against these six criteria before placing code in `src/` vs. `plugins/{slug}/`:

| Principle | Put in **Core** (`src/`) when… | Extract to a **Plugin** (`plugins/{slug}/`) when… |
|---|---|---|
| **1. Kernel Invariant** | Bootstrapping, routing, authentication, tenant/site resolution, database connection, or container compilation fails without it. | Removing it leaves a runnable CMS serving pages, admin screens, and APIs cleanly. |
| **2. Cross-Cutting Contract** | Three or more unrelated subsystems depend on the interface or neutral value type. | The concrete policy, UI, or implementation belongs to one functional domain. |
| **3. Minimal Viable CMS** | Required for basic content authoring, publishing, media delivery, users/roles, or API access. | Adds specialized capabilities (SEO, consent banners, MFA factors, specific icon packs, rich embeds, forms, reporting). |
| **4. Replacement Test** | Swapping it requires changing core invariants across the runtime. | Different deployments may disable it, fork its presentation, or substitute another provider. |
| **5. Security Boundary** | Enforces foundational authentication, RBAC, TLS/header framework, CSP nonce stamping, or tenant isolation across all routes. | Implements an optional reporting collector, optional auth factor, or feature-specific input rule. |
| **6. Independent Lifecycle** | Has the exact same release/toggle cadence as the kernel itself. | Benefits from independent per-site or per-application enable/disable toggles and settings. |

> [!IMPORTANT]
> Core contracts (`*Interface`, abstract base classes, neutral policies such as `SiteAccessPolicy`) stay in `src/`. Feature implementations attach to them via Symfony autoconfigured tags and PHP attributes.

---

## 2. Inventory: What Belongs in Core vs. Plugins

### Core Subsystems (`src/`)

- **Runtime & Routing Kernel**: `Kernel`, `ApplicationRegistry`, `SiteRegistry`, `Initializer`, `RouteContextClassifier` (aggregator only), `ImportMapTwigExtension`.
- **Database & Entity Model**: `DbObject` single-table inheritance + `ObjectRepository`, `UnknownDbObject` proxy fallback + `DynamicDiscriminatorMapListener`, migrations infrastructure, Doctrine DBAL isolation.
- **Security & Auth Baseline**: `ApiTokenAuthenticator`, `GuestApiTokenManager`, `PermissionChecker`, `SiteAccessPolicy`, `MfaAuthenticationGate` + `MfaFactorProviderInterface` + `MfaFactorProviderRegistry` + `MfaChallengeController`, `SecurityHeaderPolicy` (base header/CSP framework), `ResponseHeaderApplicator`.
- **Template & Block Engine Core**: `TemplateResolver`, `TemplateRenderer`, core structural/content blocks (`container`, `heading`, `text`, `inline_text`, `image`, `icon`, `query`, `dynamic`), `SaveWorkflowInterface` + `DraftPublishWorkflow`, `BlockTypeRegistry`, `EditorSettingsManager`, `FrontendConfigProvider`, `PageRendering` (orchestrator), `Theme` fallback Twig templates (`base/fallback-block-editor-content.twig`, `base/fallback-error.html.twig`).
- **Media, Search, Audit, Batch & Scheduling**: `DigitalAssetManager`, `PublicAssetApiProxy`, `Search*`, `AuditLogger` + `AuditRecorder` + `AuditActionRegistry`, `Batch*`, `Scheduler*`, `SiteTransfer*`.
- **Plugin Infrastructure**: `PluginBundle`, `PluginManifest`, `PluginRegistry`, `PluginLoader`, `PluginManager`, `PluginActivationChecker`, `RequiresPluginSubscriber`, `PluginAssetController` (`/admin-plugins/{slug}/...`), `PublicPluginAssetController` (`/plugin-assets/{slug}/...`), `IconSetEntity`/`IconEntity` registries.

### Built-in First-Party Plugins (`plugins/{slug}/`)

Monorepo plugins share root `composer.json` PSR-4 autoloading and `package.json` tooling, are discovered cleanly by `PluginLoader::discoverBundled()`, and can be enabled/disabled per site or application (except `required` plugins):

| Slug | Activation Mode | Scope | Purpose |
|---|---|---|---|
| `forma-2026` | `default_on` | `site` | Default accessible Editorial theme (`@Forma2026` layouts, error page templates, theme CSS). |
| `iikiti-content-optimization` | `default_on` | `both` | SEO/OpenGraph/JSON-LD metadata, `robots.txt`/`sitemap.xml`, privacy-aware analytics connectors, admin & editor UI. |
| `consent-management` | `opt_in` | `site` | Visitor consent banner, GDPR/privacy region rules, script blocking, consent proof log. |
| `iikiti-csp-reporting` | `default_on` | `application` | CSP violation ingestion (`report-uri`), pruning, and reporting dashboard. |
| `iikiti-mfa-totp`, `iikiti-mfa-email`, `iikiti-mfa-backup-codes` | `default_on` | `both` | Individual MFA factor providers registered via `MfaFactorProviderInterface`. |
| `iikiti-icons-lucide` | `default_on` | `site` | Lucide SVG/font icon pack provider and webfont assets (core retains the generic `icon` block). |
| `iikiti-embeds` | `default_on` | `site` | `video_embed` & `social_embed` blocks, oEmbed resolver & async prefetching. |
| `iikiti-custom-css` | `default_on` | `site` | Scoped/sanitized `CustomCssCompiler` and CodeMirror `CssCodeControl` editor field. |
| `iikiti-forms` | `default_on` | `site` | Form block family (`form`, `fieldset`, `legend`, controls), hierarchy rules, multi-step workflow engine. |

*(Additional subsystems such as Asset Minification, WAF/Firewall, MCP API Server, and Search Engines follow the same pattern in dedicated roadmap phases.)*

---

## 3. Core Extension Points Matrix

Plugins extend core cleanly without modifying `src/` through the following autoconfigured contracts:

| Domain | Contract / Attribute | DI Tag | Consumer / Notes |
|---|---|---|---|
| **Entity Persistence** | `#[DbObjectDiscriminator('type')]` / `DbObjectSubtypeProviderInterface` | — | Maps plugin `DbObject` subclasses into `objects` STI; unmapped rows fall back to read-only `UnknownDbObject`. |
| **Plugin Guard** | `#[RequiresPlugin('slug')]` + `PluginActivationChecker` | Event subscriber | Blocks HTTP/API execution when the owning plugin is inactive for the resolved site/application. |
| **Routing & Consumer** | `RouteContextContributorInterface` (`AbstractRouteContextContributor`) | `iikiti.route_context_contributor` | Database-free early request rules: consumer proxy routes, guest token paths, MCP exclusions, session form prefixes. |
| **Page HTML / Head / Body** | `PageRenderContributorInterface` | `iikiti.cms.page_render_contributor` | Contributes `<head>` snippets, title override, `<body>` classes/fragments, and JSON data blocks. |
| **Editor Bootstrap** | `FrontendConfigContributorInterface` | `iikiti.cms.frontend_config_contributor` | Adds plugin keys into `window.iikiti.config` when the editor boots. |
| **Editor Settings UI** | `EditorSettingsContextContributorInterface` + `EditorSettingsHandlerInterface` | `iikiti.cms.editor_settings_contributor`, `iikiti.cms.editor_settings_handler` | Adds custom settings targets, defaults, and save/publish validation. |
| **Editor Canvas Previews** | `window.iikiti.editor.registerBlockPreview(type, cmp)` | Client ESM API | Registers custom Svelte 5 previews in `BlockContent.svelte` without editing core. |
| **Block Tree Rules** | `BlockTreeValidatorInterface` | `iikiti.cms.block_tree_validator` | Runs save-time structural validations inside `DraftPublishWorkflow`. |
| **Async Embed Prefetch** | `TemplateRenderPrefetchInterface` + `EmbedResolverInterface` | `iikiti.cms.template_render_prefetch` | Runs concurrent Fiber prefetching alongside template rendering and resolves embed blocks without core embed coupling. |
| **Icons & Icon Fonts** | `IconSet`, `FontIconSetInterface`, `IconFontStylesheetProviderInterface` | `iikiti.cms.icon_set`, `iikiti.cms.icon_font_provider` | Registers bundled icon packs (`LucideIconSet`), font families, and public `/plugin-assets/...` font stylesheets. |
| **Custom CSS Compiler** | `PluginCustomCssCompilerInterface` + `CustomCssCompilerInterface` | `iikiti.cms.custom_css_compiler` | Supplies SCSS/scoped CSS compilation (`CustomCssCompiler`), gated by `DelegatingCustomCssCompiler` with `PlainCssCompiler` fallback. |
| **Block Schemas Helper** | `CommonBlockFields` | — | Shared element and responsive style fields reused by core and plugin `BlockTypeInterface` providers. |
| **Response Output Filters** | `ResponseFilterInterface` | `iikiti.cms.response_filter` | Participates in `OutputParser`'s single-pass HTML serialization pass. |
| **CSP Directives** | `CspDirectiveContributorInterface` | `iikiti.security.csp_directive_contributor` | Contributes `report-uri`, `connect-src`, etc. to default `SecurityHeaderPolicy`. |
| **Audit Actions** | `AuditActionProviderInterface` | `iikiti.audit.action_provider` | Registers custom `^[a-z][a-z0-9_]{1,31}$` action names with `AuditActionRegistry`. |
| **Key Rotation** | `ApplicationKeyRotatorInterface` | `iikiti.crypto.key_rotator` | Re-encrypts plugin secrets inside `iikiti:security:keys:rotate`. |
| **Workflow Flows** | `FlowProviderInterface` | `iikiti.workflow.flow_provider` | Exposes interactive session flows under `/flow/{flow}/step`. |
| **Admin Menu / Screens** | `AdminExtensionInterface` + `PluginOwnedAdminExtensionInterface` | `iikiti.admin_extension` | Automatically hides menu items & screens when `getPluginSlug()` is disabled. |

---

## 4. Activation Modes & Scopes (`plugin.json`)

Every plugin declares two lifecycle fields in `plugin.json`:

- **`activation_mode`**:
  - `required`: Always active; cannot be disabled via CLI, UI, or API.
  - `default_on`: Active automatically unless explicitly listed in a site or application configuration's `inactive` array.
  - `opt_in` *(default)*: Inactive until explicitly enabled (`active` array).
- **`scope`**:
  - `site`: Toggled per `Site` (filtered by `PluginActivationChecker::isActiveForSite()`).
  - `application`: Toggled at `Application` / global configuration level.
  - `both`: Checked at `Site` level for site-bound requests and `Application` level otherwise.

---

## 5. Svelte 5 Shared ESM Import Map & Plugin Assets

To avoid duplicating Svelte 5 reactive state (`active_reaction`, `user_effect`, `svelte/store`) across independently compiled host and plugin bundles:

1. `assets/build/build-esm.mjs` compiles the shared Svelte 5 runtime subpaths (`svelte`, `svelte/internal/client`, `svelte/store`, etc.) plus `@iikiti/ui`, `admin`, and `@iikiti/editor` into `public/build/esm/` alongside `importmap.json`.
2. `templates/base/layout.twig` emits `<script type="importmap">{{ iikiti_import_map_json()|raw }}</script>` **before** `encore_entry_script_tags('iikiti')` without a manual `nonce=` attribute so `ResponseHeaderApplicator` stamps the per-request CSP nonce automatically.
3. Monorepo and third-party plugin UI bundles (`admin.js`, `editor.js`, `site_ui` scripts) compile as native ES modules externalizing `/^svelte(\/.*)?$/` and `@iikiti/ui` (mapped cleanly in `assets/build/esm-entries.json` for first-party plugins).
4. Admin/editor UI bundles load from `/admin-plugins/{slug}/{path}` (`ROLE_ADMIN`); public frontend assets load from `/plugin-assets/{slug}/{path}` (`PublicPluginAssetController`, with Consumer-node API proxy fallback).

---

## 6. Authoring Checklist for New Plugins

1. Create `plugins/{slug}/` with `plugin.json` (`activation_mode`, `scope`, `namespace`, `bundle_class`), `src/{Bundle}Bundle.php` extending `PluginBundle`, and `config/services.yaml` (`autowire: true`, `autoconfigure: true`).
2. Add PSR-4 namespace mapping in root `composer.json` (for monorepo plugins) and path entry in `phpstan.neon`.
3. Guard HTTP/API endpoints with `#[RequiresPlugin('{slug}')]` and implement `PluginOwnedAdminExtensionInterface` on admin extensions.
4. Map persistent CMS entities as `DbObject` subclasses using `#[DbObjectDiscriminator('...')]`.
5. Register UI bundles in `assets/build/esm-entries.json` and document user-facing configuration under `/docs/`.
