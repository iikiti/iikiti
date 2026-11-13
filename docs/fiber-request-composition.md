# Fiber Request Composition

This feature uses PHP's built-in `Fiber` class and the Symfony HttpClient already
required by the application. It runs inside the ordinary Symfony request under
PHP-FPM, Apache, or another conventional PHP handler. It adds no Composer package,
worker server, proxy, daemon, or application-server configuration.

## Concurrency Boundaries

`iikiti\CMS\Async\FiberTaskRunner` starts a bounded set of operations in native
Fibers and multiplexes their suspended Symfony HTTP responses with
`HttpClientInterface::stream()`. It accepts at most 32 operations and defaults
to a 5-second group deadline. Results are returned in input order, not network
completion order. A failed operation is reported as `FiberTaskException` with
the task key.

Task callbacks must perform remote work through the supplied
`FiberHttpClient::request()` so the Fiber can suspend and the runner can stream
several responses together. Synchronous code runs to completion and does not
yield. In particular, Doctrine/PDO queries, ordinary filesystem calls, Twig
rendering, and CPU-bound work remain blocking. There is no native-Fiber-only way
to make a synchronous PDO query nonblocking without an async database driver.

## Business API Consumers

`iikiti\CMS\Api\BusinessApiClient` is the server-side HTTP transport for
resource and business-action API calls. It only accepts `/api/...` paths, disables
redirects, uses bounded timeouts, and supports both ordinary requests and
`FiberHttpClient` requests. Independent calls should be placed in one
`FiberTaskRunner` group; wrapping a single sequential API call in a Fiber does not
make the API's own database work nonblocking.

Configure `INTERNAL_API_BASE_URL` as the API service's HTTP(S) origin (without a
path). Production requires HTTPS. The API runtime owns `DATABASE_URL` and uses
`APPLICATION_ROLE=api`. A database-free public renderer can use
`APPLICATION_ROLE=consumer`; this consumer slice supports `/`, `/{slug}`,
`/admin` SPA shells, `/_iikiti/fragments/{provider}`, and public asset delivery, and returns 503 for routes that
have not crossed the API boundary yet. It obtains page-render manifests and
server-rendered fragments from `/api/public/render/*` and public asset delivery through
`/api/public/sites/{siteHost}/assets/*`, with the original site host and scheme
preserved. Rendered asset URLs point directly to the API's streaming asset route
rather than buffering file bodies through the consumer. The asset API firewall
shares the authenticated session context for private assets; cross-origin
deployments must share the session store/cookie domain and allow credentials
through CORS. The admin SPA obtains its user-bound token from the API session
endpoint and sends subsequent API calls, including shell management, to the
configured API origin with the original site context. Set the same `APP_SECRET`
on both runtimes so short-lived guest credentials can be verified by the API.
In isolated PHP runtimes, `INTERNAL_API_RESOLVE` can provide a JSON hostname-to-IP
map for the transport socket while preserving the configured API hostname for the
HTTP `Host` header and vhost routing.
The database-owning `api` role can render pages locally as a synchronous fallback
to avoid self-HTTP through a single PHP worker pool. Browser-facing deployments
that need concurrent server-rendered page fragments use the database-free
`consumer` role and a separate API worker pool.

For concurrent full-page server rendering, serve the browser-facing host from a
separate `APPLICATION_ROLE=consumer` runtime and point its
`INTERNAL_API_BASE_URL` at the API runtime. The consumer and API must use separate
PHP worker pools; the consumer starts independent fragment API requests through
Fibers while the API workers render each fragment's Twig/Doctrine/PDO work. Do not
use the API process itself as both consumer and API behind a single PHP worker
pool; nested HTTP calls can exhaust that pool. This role setting is deployment
configuration only: plugins call `window.iikiti.fragments` through a same-origin
route and need no environment variable, API URL, or browser credential.

For a single-runtime development profile, `PUBLIC_PAGE_RENDER_VIA_API=1` opts
the API-role homepage and page routes into the manifest/fragment proxy while
leaving API endpoints in the database-owning role. This is still an HTTP
self-request: PHP-FPM must be able to reach `INTERNAL_API_BASE_URL`, and the
worker pool must have capacity for the nested API requests. If FPM cannot reach
the origin, the proxy returns 502 rather than rendering database-backed content
locally.
Login/MFA flows and command-driven plugin/search maintenance remain on the
database-owning API runtime; do not serve those routes from the consumer role.
The login form posts to the stateful `/api/auth/login` form-login firewall with
CSRF enabled; the MFA challenge runs at `/api/auth/mfa/challenge` in the same
session context. The existing MFA proof workflow remains the only path to a
fully authenticated session. The login DTO is write-only for passwords and no
longer exposes default CRUD resource operations.
Interactive multi-step workflows now use stateful `/api/forms/*` routes; rendered
form actions target the API origin with a site-context parameter. Cross-site
deployments must use a session cookie policy that permits authenticated form
POSTs to the API origin (for example, `SameSite=None; Secure`) and share the
session store.

When no `X-AUTH-TOKEN` is supplied, the page proxy issues a five-minute,
site-host-bound guest credential. The API accepts that credential only for
read-only GET/HEAD public page-render operations. An explicitly supplied
user-bound API token is forwarded and remains subject to API-side site and role
checks. If the request has a Symfony session cookie, the proxy forwards only
that cookie to `POST /api/auth/session-token`; the stateful API firewall resolves
the authenticated user and returns that user's short-lived API token. The cookie
is not forwarded to the page-render operation. API and consumer runtimes must
share the session store and `APP_SECRET` for this exchange to work.

### Public Page Fragment API

The consumer resolves any user-session token first, then starts the page's
`/manifest` request and the API-selected main-region fragment request in the
same Fiber task group. The API resolves the site, page, template, shells,
authorization, and editor mode; responses carry a render revision which the
consumer compares before composing. The manifest returns a rendered document
skeleton, CSS, region/shell descriptors, independently rendered widget
descriptors, and server-mode named plugin provider descriptors declared through
`iikiti_fragment()`. Region/shell/provider slots are server-issued tokens.
Widget IDs are site/revision-bound HMACs of structural tree paths (not block
IDs), and their slot tokens are stable for that render revision. Consumers do
not send block trees, query definitions, selectors, or arbitrary render data.

The main region is fetched concurrently with the manifest; the consumer then
requests all other listed regions, shells, widgets, and server-mode plugin
providers in bounded `FiberTaskRunner` groups of at most 32 requests (up to 128
descriptors per page). Independent descriptors start together. Named provider
placements can declare `dependsOn` sibling keys; the consumer waits for those
requests before starting dependents and rejects cycles or unknown keys. Widget
responses are composed by nesting depth so each parent slot exists before its
child slot is filled. The consumer waits for all required server responses,
then fills the API-issued slots in the same main DOM document before returning
one complete HTML document. This is concurrent rendering, not progressive HTTP
streaming. Every page-fragment request repeats API-side user/guest, MFA, site,
and page checks and verifies the page render revision. Provider requests are
limited to registered, site-enabled public providers and validated params. If a
page-fragment request fails, the consumer returns an error rather than partially
composed server HTML. When the 128-fragment budget is exhausted, additional
eligible widgets render inline instead of producing an unfillable slot. Existing
full-page API routes remain available for legacy callers.

Each direct block node in a region or shell tree with a render template is a
separate fragment by default. Deeper descendants render inline by default and
can opt in per saved widget instance with `renderMode: "always"`; `"never"`
keeps a widget inline even at the region/shell boundary. Missing/invalid values
use `"default"`. Query blocks remain atomic: the query and its per-result child
templates render in one fragment so row bindings are not transported between
requests. Direct API-role page rendering remains local and synchronous as a
compatibility/development fallback; concurrent full-page server rendering uses
the consumer proxy.

The same named provider can be rendered by Twig into the initial page (default)
or invoked through `/api/public/render/providers/{provider}`. An explicit Twig
`delivery: "browser"` placement emits a browser descriptor; the browser fetches
and inserts it progressively after the shell has been returned. Pages can mix
server-composed and browser-delivered placements. The `window.iikiti.fragments`
facade lazy-loads its implementation only on first use or when an explicit
browser descriptor is present. `/_iikiti/fragments/{provider}` is same-origin and
dispatches locally in API role or relays through the site-scoped guest token in
consumer role; plugins do not configure the app role or API origin.

The revision check covers the resolved template, region trees, shell definitions,
page/site properties, and widget render/cache settings. Query blocks still
execute on the API for each fragment request; their database reads remain
synchronous and may observe updates made while a multi-fragment page is
assembled. Fragment fan-out increases API request and database load, so keep widget counts bounded and opt
only worthwhile descendants into separate requests. These independent HTTP
operations, not Twig/DOM CPU work or a synchronous PDO query within one API
request, are what Fibers overlap. In the development profiler, nested manifest/fragment
and server-rendered provider requests are excluded as standalone profiles to
avoid profiling the same render twice. When the browser page request is profiled,
the Doctrine Queries tab and metrics include SQL and parameters from its
authenticated internal session-token, manifest, fragment, and provider calls;
each remote statement's SQL comment identifies the page and selected Twig
layout, with region names/roles, shell names/roles, widget types/tree locations,
or the provider name where applicable. The comment also includes the API path.
The diagnostic handoff is signed with the shared application secret, limited to
development requests with the profiler enabled, and bounded; ordinary API and
browser responses do not expose it. The parent page's Asynchronous and Subrequest
sections continue to record API request timings.

For a browser on a different origin from the API, configure `CORS_ALLOW_ORIGIN`
for the exact consumer origin, allow credentials, and use a session cookie
policy/storage shared by the two runtimes.

`/api/auth/session-token` requires a fully authenticated session and explicitly
rejects the pending MFA proxy token, even though that token exposes the
underlying `User`. User API credentials use the `v2_` prefix; legacy unversioned
tokens are rejected and replaced on the next fully authenticated token request.
After this rollout, clients holding an older token must authenticate again
(including completing MFA) to obtain a replacement.

Headless schedule polling, background queue ticks, batch-item processing, and
digital-asset processing use separate `SCHEDULE_TRIGGER_TOKEN`,
`BACKGROUND_QUEUE_TRIGGER_TOKEN`, `BATCH_PROCESS_TRIGGER_TOKEN`, and
`ASSET_PROCESS_TRIGGER_TOKEN` credentials, each accepted only by its
corresponding internal action route. The development `.env` defaults derive
these from `APP_SECRET`; production deployments should configure independent
random values and provide each only to the API runtime and matching
scheduler/worker client. No IP allowlist is used.

The public page-render operations own page/template/shell resolution and the
existing bounded block-query feature. Consumers request API-issued fragments,
not SQL, DQL, block trees, or query-definition payloads; no generic query
endpoint is exposed. These reads execute in the API-owning request process, but
Doctrine/PDO remains synchronous inside each API request.

`iikiti\CMS\Async\Promise` exposes the JavaScript-style constructor,
`then(onFulfilled, onRejected)`, `catch()`, `finally()`, and static `all()`,
`allSettled()`, `allKeyed()`, `allSettledKeyed()`, `any()`, `race()`,
`resolve()`, `reject()`, `try()`, and `withResolvers()`. The constructor executor
runs immediately and resolution adopts Promises and thenables. `Promise::try()`
also invokes its callback immediately. `Promise::async()` is a PHP extension that
creates a lazy Fiber-backed operation; it receives the same `FiberHttpClient` and
starts when awaited or passed to `$runner->runPromises([...])`. Settled values
and reasons are cached.

The scheduling semantics are necessarily PHP-specific: chained callbacks run
when the Promise is driven by `FiberTaskRunner`, not on a JavaScript microtask
queue. The combinators use the Fiber scheduler; unfinished HTTP tasks are
cancelled once `race()`/`any()` wins or `all()` rejects rather than detached to
run after the request. A `withResolvers()` Promise can be resolved before
awaiting or by another task in the same Fiber group. An unresolved external
callback needs an event source integrated with the runner. This Promise
abstraction is not an additional event loop: custom work only overlaps when it
suspends through a supported cooperative adapter. `race([])` remains pending;
awaiting a Promise without an executor or resolver source fails rather than
blocking a shared-hosting request indefinitely.

The application uses a `postgresql://` `DATABASE_URL`. Doctrine DBAL 4.5 has a
native ext-pgsql driver (`pgsql`) as well as the PDO driver (`pdo_pgsql`), but
DoctrineBundle maps PostgreSQL URL schemes to `pdo_pgsql` by default. The mapping
can be changed to select the native driver:

```yaml
doctrine:
    dbal:
        driver_schemes:
            postgresql: pgsql
            postgres: pgsql
```

This requires ext-pgsql. More importantly, DBAL's native pgsql driver still calls
`pg_get_result()` immediately after `pg_send_query()`, so switching drivers alone
does not make queries nonblocking. The Promise abstraction does not change that:
it only yields when its operation uses an adapter supported by the Fiber runner.
A true async integration would need a custom DBAL connection/driver that returns
Promises and exposes PostgreSQL socket readiness to the scheduler, plus
separate-connection and transaction/entity-manager rules for concurrent queries.
That integration is not implemented here, and ext-pgsql is not available on every
shared host. For portable DB performance, prefer query batching, avoiding N+1
access, and existing cache paths.

The API renderer starts requests for static social oEmbed URLs found in each
region, dynamic-block, shell, or widget fragment before rendering that fragment.
In consumer mode, independent region/shell/widget API requests are additionally
dispatched through Fiber groups and composed into the main DOM after all results succeed.
The plugin update command also checks independent plugin metadata requests as
one task group; it still downloads, verifies, and installs packages sequentially. Only
the existing oEmbed provider endpoints are requested by embed prefetch; redirects
are disabled, HTTP timeouts and body size are bounded, returned markup must
reference the requested provider host, and failed requests use the existing
link-card fallback. URLs only known during later dynamic rendering use the
single-URL fallback and cannot join the already-started batch.

## Response Composition

For direct API-role rendering, `PageRendering` creates the base
`Dom\HTMLDocument` and `TemplateRenderer` inserts local region and shell
fragments as before. In consumer mode, the API produces the skeleton and
server-authored slot manifest; `PublicPageApiProxy` fetches each region, shell,
and widget fragment through `FiberHttpClient`, inserts the returned trusted HTML
and CSS into the same DOM document, then returns a `DomHtmlResponse`. The HTML output
filter can minify that retained document without reparsing the completed page
string. Legacy full-page API and template/string responses remain supported.
Fragments are trusted rendered Twig HTML, not sanitized input; untrusted values
must remain escaped by Twig.

Ordinary Symfony controllers can opt into `ControllerResponseComposer` by
returning its response. Each operation returns a typed `ResponseFragment`; the
plan chooses an HTML document skeleton, JSON object, JSON list, or buffered text
response. HTML plans can receive an existing `Dom\HTMLDocument` or a skeleton
string. HTML fragments may be trusted strings or `Dom\DocumentFragment`
instances imported into that document. For example:

```php
return $responseComposer->compose(ControllerResponsePlan::html($skeleton, [
    'profile' => Promise::async(static fn (FiberHttpClient $http) => ResponseFragment::html(
        '#profile',
        $http->request('GET', $profileUrl)->getContent(),
        order: 20,
    )),
    'navigation' => Promise::async(static fn (FiberHttpClient $http) => ResponseFragment::html(
        '#navigation',
        $http->request('GET', $navigationUrl)->getContent(),
        order: 10,
    )),
]));
```

This is explicit and opt-in; existing controllers returning `Response` or
`JsonResponse` remain unchanged. Task completion order does not determine output
order. JSON fragments compose keyed or list values, while text and unknown
content types are written to a `php://temp` buffer and returned with the chosen
content type.

`JsonObjectComposer` combines ordered keyed pieces and throws on duplicate
top-level keys. `JsonArrayComposer` concatenates ordered list pieces. They are
used for the editor-room API payload and front-end editor configuration, then
the existing Symfony/Twig response paths serialize the final values.

## Rendered Widget Cache

Rendered-output caching is independent from the database query-result cache and
is opt-in per independently rendered widget. The block editor's **Rendering**
section exposes `None` (default), `All visitors (this site)`, and a selected
`UserGroup`. `UserGroup` membership defines only a cache-sharing cohort: current
members may share its entry; nonmembers bypass that entry and still render the
widget normally. It is not an access-control rule. Site ID is always part of the
cache key, so neither global nor group-scoped entries cross sites. Inline
descendants have no separate rendered-output cache; set them to `Always async`
to make them an independent piece. Query-row children are not independently
cached in this increment.

Entries live in the API runtime's `cache.rendered_fragments` pool. The final page
and fragment HTTP responses remain `private, no-store`; browser/CDN caching is
not enabled. The fragment key includes site, render revision/request context,
structural widget identity, and the selected sharing scope. Editor/draft output
and renders for users with edit capability bypass this shared cache. Selecting a
shared scope asserts that the widget output does not vary by user beyond the
selected cohort; leave user-dependent or uncertain widgets at `None`.

`CACHE_RENDERED_FRAGMENT_TTL` configures one system-wide lifetime in seconds
(default `300`). Known CMS object/property writes invalidate the rendered-widget
pool immediately; template/page/shell edits also change the render-revision key.
The object-query result cache includes object/property generations so a cache
miss cannot refill the rendered cache from stale query results. Remote or
otherwise untracked dependencies are bounded by the TTL. Plugin templates can
register an explicit dependency tag with `{{ render_cache.add('catalog_posts') }}`
and invalidate it through `RenderedFragmentCache::invalidateTags(['catalog_posts'])`;
invalid or unavailable cache entries fall back to normal rendering. The profiler
reports rendered-fragment cache hits and misses separately from Fiber HTTP
requests.

In the development Web Profiler, these diagnostics appear as a **Response
composition** section inside **Request / Response → Response**, not as a separate
panel or toolbar item. It reports Fiber group task counts, local rendering time,
HTTP wait time, task failures, HTTP requests through the Fiber adapter, and the
number/format of composed fragments. Legacy responses remain distinguishable by
their response mode and lack of Fiber groups.

## Validation

Run the focused checks with:

```sh
vendor/bin/phpunit tests/Async
vendor/bin/phpunit tests/Response/Composition
vendor/bin/phpunit tests/Web/BlockEditor/Embed/EmbedResolverTest.php
vendor/bin/phpunit tests/Web/Template/TemplateRendererTest.php
vendor/bin/phpunit tests/Plugin/PluginDownloaderTest.php
```

The shared-hosting request path remains unchanged. Fibers are cooperatively
scheduled only while the explicit task runner is active; they do not wrap or
replace the Symfony kernel lifecycle.
