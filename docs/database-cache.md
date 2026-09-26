# Database Query Result Caching

This document describes the database query result caching layer added to the
project. Query results are cached to reduce database load for the read-heavy
paths (site resolution, user lookup, API Platform endpoints) that run on every
request.

The cache is implemented with **Doctrine ORM result cache**
(`Query::enableResultCache()`) backed by a **Symfony Cache pool**
(`cache.database`). Caching raw database rows means entities are re-hydrated on
every cache hit, so Doctrine lifecycle events (e.g. `postLoad`, used by
`UserSiteContextSubscriber`) fire exactly as they do for uncached queries.

## Overview

```
CacheState (per-request enable/disable)
    |
    v
DatabaseCacheManager -----------------------> CachingStrategyRegistry
    |                                              |
    v                                              v
cache.database pool (Symfony Cache)      CachingStrategyInterface
                                              + DoctrineResultCacheStrategy (default)
                                              + NoCacheStrategy
                                              + (plugin/bundle strategies)
```

| Component | Purpose |
| --- | --- |
| `CacheState` | Per-request enable/disable switch + runtime strategy override. |
| `DatabaseCacheManager` | Coordinates repositories, API Platform extensions, cache keys (with per-entity-class generations) and TTL. |
| `CachingStrategyInterface` | Contract for pluggable cache strategies. |
| `DoctrineResultCacheStrategy` | Default strategy using Doctrine ORM result cache. |
| `NoCacheStrategy` | Disables caching (used by batch requests). |
| `CachingStrategyRegistry` | Enumeration + selection of registered strategies. |
| `CacheInvalidationSubscriber` | Doctrine `onFlush`/`postFlush` — bumps per-entity-class generations on writes. |
| `BatchCacheDisablerSubscriber` | Disables caching for batch HTTP contexts. |
| `DatabaseConfigStrategyResolver` | Applies a strategy name read from the current site's configuration. |
| `ApiPlatformCacheCollectionExtension` / `ApiPlatformCacheItemExtension` | Apply caching to API Platform queries. |

## Configuration

All settings live under environment variables consumed by
`config/packages/iikiti_cache.yaml`.

| Variable | Default | Description |
| --- | --- | --- |
| `CACHE_STRATEGY` | `doctrine_result_cache` | The active caching strategy name. |
| `CACHE_DATABASE_TTL` | `300` | Default cache lifetime in seconds. |
| `CACHE_DATABASE_ENABLED` | `true` | Whether caching is enabled by default per request. |
| `CACHE_DATABASE_BATCH_PATTERN` | `batch_` | Route-name prefix that disables caching. |

The cache pool `cache.database` is defined in
`config/packages/cache.yaml` and uses the `cache.app` adapter (filesystem by
default, Redis when `CACHE_DSN` is configured).

```dotenv
# .env
CACHE_STRATEGY=doctrine_result_cache
CACHE_DATABASE_TTL=300
CACHE_DATABASE_ENABLED=true
CACHE_DATABASE_BATCH_PATTERN=batch_
```

To use Redis as the backing store:

```dotenv
CACHE_DSN=redis://localhost:6379
```

## Cache keys and invalidation

Cache keys follow the shape
`db_cache:v{generation}:{entityClass}:{operation}:{hash}:{siteId}`.

- **generation** — a per-entity-class counter. The `CacheInvalidationSubscriber`
  increments it after any insert/update/delete in `postFlush`, so previously
  cached query results for that entity type become unreachable immediately.
- **siteId** — the current site id (multi-tenancy). Queries performed without a
  site context use `0`.
- **hash** — a stable hash of the operation context (criteria, filters,
  order-by, parameters).

The default TTL (300s) is a safety net: if a generation is ever not bumped,
entries still expire.

## Disabling the cache

### Per request (batch processing)

Any main request can opt out of caching by setting the `_disable_db_cache`
attribute. In YAML routes:

```yaml
batch_import:
    path: /api/batch/import
    options: { _disable_db_cache: true }
```

Or programmatically:

```php
$request->attributes->set('_disable_db_cache', true);
```

Routes whose name starts with the configured batch pattern (default `batch_`)
also disable caching automatically.

Console commands performing batch writes can disable caching:

```php
use iikiti\CMS\Trait\DisablesDatabaseCache;

class ImportCommand extends Command
{
    use DisablesDatabaseCache;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->disableDatabaseCache($this->cacheState);
        // ... batch processing ...
    }
}
```

### Per query

Repository methods accept an `$options` array. Set `cache => false` to bypass
the cache for a single call, and `cacheTTL` to override the lifetime:

```php
$sites = $this->siteRepository->findByDomain('example.com', ['cache' => false]);
$apps  = $this->applicationRepository->findBy([], null, 10, 0, ['cacheTTL' => 60]);
```

The same is available on `find`, `findBy`, `findOneBy`, `findAll`,
`findByProperty` and `findOneByProperty`.

### Per operation (API Platform)

Operations can carry an `iikiti_cache` extra property:

```php
#[Get(path: '/api/sensitive/{id}', extraProperties: ['iikiti_cache' => ['cache' => false]])]
class SensitiveResource
{
}
```

## Strategies

### Built-in strategies

| Name | Label | Capabilities |
| --- | --- | --- |
| `doctrine_result_cache` | Doctrine ORM Result Cache | `query_level`, `ttl`, `invalidation` |
| `none` | Disabled | — |

### Selecting a strategy

Strategies can be selected through:

1. **Configuration file** — `config/packages/iikiti_cache.yaml`:
   ```yaml
   parameters:
       iikiti_cache.strategy: doctrine_result_cache
   ```
2. **Environment variable** — `CACHE_STRATEGY=none`.
3. **Dynamically from the database** — store a `cache_strategy` value in the
   current site's configuration. `DatabaseConfigStrategyResolver` reads it per
   request and applies it as a runtime override:
   ```php
   $site->setProperty('configuration', ['cache_strategy' => 'none']);
   ```
4. **Programmatically** — via `CacheState`:
   ```php
   $cacheState->setOverrideStrategy('none');
   ```

Resolution order (highest priority first):

1. `CacheState::setOverrideStrategy()` (runtime/DB override)
2. `CacheState::disable()` → `none`
3. `iikiti_cache.strategy` configuration parameter
4. `CACHE_STRATEGY` environment variable
5. `doctrine_result_cache`

### Enumerating strategies (admin UI)

The registry exposes all registered strategies so an administrative UI can
present them:

```php
/** @var CachingStrategyRegistry $registry */
foreach ($registry->getAvailableStrategies() as $metadata) {
    $name         = $metadata->name;          // 'doctrine_result_cache'
    $label        = $metadata->label;         // 'Doctrine ORM Result Cache'
    $description  = $metadata->description;
    $capabilities = $metadata->capabilities;  // ['query_level', ...]
}
```

## Registering a new strategy

### Compile time (DI tag)

Implement `CachingStrategyInterface`, then register the service. The
`#[AutoconfigureTag('iikiti.cache_strategy')]` attribute on the interface
auto-registers any implementation when `autoconfigure` is enabled.

```php
use Doctrine\ORM\Query;
use iikiti\CMS\Cache\CachingStrategyInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;

class RedisCacheStrategy implements CachingStrategyInterface
{
    public function __construct(private RedisAdapter $redis) {}

    public function getName(): string          { return 'redis_cache'; }
    public function getLabel(): string         { return 'Redis Cache'; }
    public function getDescription(): string   { return 'Stores cache entries in Redis.'; }
    public function getCapabilities(): array   { return ['method_level', 'ttl', 'tags']; }

    public function isEnabled(array $options): bool      { return false !== ($options['cache'] ?? true); }

    public function decorateQuery(Query $query, string $cacheKey, ?int $ttl): void
    {
        // Query-level strategies set the result cache here (or do nothing).
    }

    public function cacheResult(string $cacheKey, callable $callback, ?int $ttl, array $tags): mixed
    {
        // Method-level strategy: read/write cache + invoke callback on miss.
        return $callback();
    }

    public function invalidate(string $entityClass): void  { /* clear tags / keys */ }
    public function clear(): void                          { $this->redis->clear(); }
}
```

```yaml
services:
    App\Cache\RedisCacheStrategy:
        arguments:
            $redis: '@Redis'
        tags: ['iikiti.cache_strategy']
```

### Runtime (plugins and bundles)

A plugin or bundle can register a strategy programmatically without touching
configuration files:

```php
use iikiti\CMS\Cache\CachingStrategyRegistry;

class MyBundle extends Bundle
{
    public function boot(): void
    {
        $registry = $this->container->get(CachingStrategyRegistry::class);
        $registry->register('my_redis', new RedisCacheStrategy($redis));
    }
}
```

The `DatabaseCacheManager` resolves the active strategy through the registry,
falling back to `doctrine_result_cache` (or `none` when caching is disabled for
the request).

## Database-backed strategy example

A key/value cache stored in a database table implements `cacheResult()` with
DBAL queries against a `cache_entries` table, and `decorateQuery()` as a no-op
(aside from applying the cache at the method level). Invalidation issues SQL
`DELETE` statements keyed on the entity class tag. Register the strategy the
same way as any other (DI tag or `register()`).

## Debugging / Symfony Profiler

The iikiti database query-cache diagnostics are surfaced inside Symfony's
built-in **Cache** profiler panel (dev/test only). `DatabaseCacheDataCollector` is
registered as a *tabless* data collector — tagged `data_collector` with no
`template`, so it does not get its own profiler tab — and is reached from the Cache
panel via `profile.getCollector('iikiti.database_cache')`, the same cross-collector
access pattern the WebProfiler layout itself uses. The shared Cache panel template
is overridden at `templates/bundles/WebProfilerBundle/Collector/cache.html.twig`
(it extends `@!WebProfiler/Collector/cache.html.twig`; the `!` prefix avoids the
recursion error when extending an overridden template) and appends an
**iikiti database cache** subsection below the inherited pool stats. That
subsection reports:

- the active **strategy** (name, label, enabled state for the current request),
- **per-entity-class generation counters** (read at request end),
- the **registered strategies** available for selection.

Pool-level hit/miss/time statistics and the **backend adapter** class (e.g.
`FilesystemAdapter`, `RedisAdapter`) are *not* duplicated here — they come from the
standard cache-pool tracing shown in the Cache panel's **Pools** section,
populated per-request by Symfony's `TraceableAdapter`. Do not decorate
`data_collector.cache` to achieve this: Symfony's `CacheCollectorPass` gates on
`hasDefinition('data_collector.cache')`, which is false for a decorated (aliased)
service, so decorating it silently disables pool tracing. The per-entity generation
values change the cache key version, so a write to a tracked entity class makes
previously cached results unreachable until they expire by TTL.

## Clearing the cache

Built-in Symfony commands work on the backing pool:

```bash
php bin/console cache:pool:clear cache.database
```

The `DatabaseCacheManager` also exposes `clear()` and
`getAvailableStrategies()` for programmatic use and admin UIs.

## API Platform notes

The API Platform extensions apply the active strategy to Doctrine queries for
API Platform resources extending `DbObject`:

- **Items** — always cached through the strategy.
- **Collections** — cached when pagination is not active. Paginated collections
  are left to the built-in pagination extension (its `Paginator` result is not
  interchangeable with a cached array).

Query parameters (`filters`, pagination state, SQL and bound parameters) are
part of the cache key, so different filter/page combinations never collide.