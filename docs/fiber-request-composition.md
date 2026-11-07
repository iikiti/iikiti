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

The page renderer starts requests for static social oEmbed URLs found in region,
dynamic-block, and shell trees before rendering their fragments. Region and shell
fragments are inserted into the main DOM while the HTTP responses are pending;
the embed template placeholders are filled after the responses arrive. The plugin
update command also checks independent plugin metadata requests as one task
group; it still downloads, verifies, and installs packages sequentially. Only
the existing oEmbed provider endpoints are requested by embed prefetch; redirects
are disabled, HTTP timeouts and body size are bounded, returned markup must
reference the requested provider host, and failed requests use the existing
link-card fallback. URLs only known during later dynamic rendering use the
single-URL fallback and cannot join the already-started batch.

## Response Composition

`PageRendering` creates the base `Dom\HTMLDocument` first. `TemplateRenderer`
inserts the template layout skeleton, then region and shell fragments are parsed
individually and inserted into their DOM slots. Social oEmbed requests start
before local region rendering, so the main region and non-header shells can be
rendered while those HTTP responses are pending. Deferred embed markers are
replaced with their DOM nodes before the response is sent. `DomHtmlResponse`
retains that document so the HTML output filter can minify it without reparsing
the completed page string. Legacy template/string responses still use the
existing filter path. Fragments are trusted rendered Twig HTML, not sanitized
input; untrusted values must remain escaped by Twig.

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
