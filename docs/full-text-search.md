# Full-Text Search

The iikiti CMS provides a configurable, multi-engine full-text search system.
PostgreSQL `tsvector` columns with GIN indexes are the default engine; the
architecture is abstracted behind a `SearchEngineInterface` so that
Elasticsearch or other engines can be added later without changing application
code.

## Architecture

```
SearchService → SearchEngineRegistry → SearchEngineInterface
                    ↑                    └── PostgreSQLSearchEngine (default)
                    │                    └── ElasticsearchSearchEngine (future)
                    └── iikiti.search_engine (DI tag)

SearchIndex (entity) ─┬─ SearchIndexField(s)     — columns, properties, virtuals, aliases
                       ├─ SearchAnalyzerLayer(s)   — ngram, stemmer, stop, tokenizer, etc.
                       └─ SearchFilter(s)           — query-time / index-time, visible filters

Lifecycle hooks: SearchIndexListener (onFlush/postFlush) keeps index tables
in sync when DbObject entities are persisted, updated or removed.
```

## Configuration

Search configurations are stored as entities in the `search_indexes` table
(schema-qualified by `DB_SCHEMA`), managed through the admin UI and CLI
commands.

### Environment variables

| Variable | Default | Description |
|---|---|---|
| `FTS_DEFAULT_ENGINE` | `postgresql` | Default engine when an index does not specify one. |
| `FTS_ENABLED` | `true` | Master switch. |
| `FTS_INDEX_PREFIX` | `fts_` | Table name prefix for index tables. |
| `FTS_AUTO_INDEX` | `true` | Whether Doctrine lifecycle events automatically update indexes. |
| `FTS_DEFAULT_LANGUAGE` | `english` | Default PostgreSQL text search config. |

## Available search engines

### PostgreSQL (default)

Uses PostgreSQL's native full-text search:
- `tsvector` columns with `TO_TSVECTOR` for indexing.
- `@@` operator for matching against `plainto_tsquery` / `websearch_to_tsquery`.
- `ts_rank()` for relevance ordering.
- `ts_headline()` for match highlighting.
- `pg_trgm` extension for trigram-based autocomplete.
- Per-field weights A–D via `setweight()`.
- Custom text search configurations mapped from analyzer layer chains.

```yaml
doctrine:
    dbal:
        # Ensure the pg_trgm extension is available
        # CREATE EXTENSION IF NOT EXISTS pg_trgm;
```

### Elasticsearch (deferred)

The `SearchEngineInterface` contract is designed so a future
`ElasticsearchSearchEngine` can be plugged in. Key mapping points:
- Layers map to Elasticsearch analyzer chain components.
- The `options` JSON column on `SearchIndex` can carry ES-specific settings
  (index shards, replicas, similarity, etc.).

## Analyzer layers

Each layer is an ordered step in the text-analysis pipeline, inspired by
the Apache Lucene / Elasticsearch analyzer model. Layers are stored in
`search_analyzer_layers` and grouped by name.

| Layer type | Description | PostgreSQL mapping |
|---|---|---|
| `tokenizer` | Splits text into tokens | Text search parser |
| `charfilter` | Pre-tokenization character filtering | (custom function) |
| `tokenfilter` | Post-tokenization token filtering | (custom function) |
| `lowercase` / `uppercase` | Case normalisation | Automatic in TS config |
| `stop` | Stop word removal | Stop dictionary |
| `stemmer` | Word stemming | Snowball dictionary |
| `synonym` | Synonym expansion | Synonym dictionary |
| `ngram` | N-gram tokenization | `pg_trgm` |
| `edgengram` | Edge N-gram (prefix autocomplete) | `pg_trgm` + prefix |
| `normalizer` | Pre-tokenization normalization | Lower/trim |

### Creating a custom analyzer

```php
use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Entity\SearchAnalyzerLayer;
use iikiti\CMS\Search\Enum\SearchLayerType;

$index = new SearchIndex('my_index', 'My Custom Index');

$layer1 = new SearchAnalyzerLayer('my_custom', SearchLayerType::CharFilter);
$layer1->setOptions(['pattern' => '\\W+', 'replacement' => ' ']);
$layer1->setPosition(1);

$layer2 = new SearchAnalyzerLayer('my_custom', SearchLayerType::Tokenizer);
$layer2->setPosition(2);

$layer3 = new SearchAnalyzerLayer('my_custom', SearchLayerType::Stemmer);
$layer3->setOptions(['algorithm' => 'english']);
$layer3->setPosition(3);

$index->addLayer($layer1);
// ... etc.
```

## Index fields

Fields can be sourced from:

| Source type | Description |
|---|---|
| `column` | Direct column on the `objects` table. |
| `property` | A key-value property from the `ObjectProperty` store. |
| `virtual` | A user-defined SQL expression (e.g. `o.data::text`). |
| `alias` | An alternative name for another field in the same index. |

Each field has a `weight` (1–4 → A–D in PostgreSQL) that controls ranking
priority, and an optional `analyzer_name` to override the index default.

## Using search programmatically

```php
use iikiti\CMS\Search\Service\SearchService;
use iikiti\CMS\Search\Service\SearchParams;

/** @var SearchService $searchService */
$result = $searchService->searchFrontend('hello world', [
    'limit' => 10,
    'highlight' => true,
    'filters' => ['category' => 'news'],
]);

foreach ($result->getHits() as $hit) {
    echo $hit->id . ' (' . $hit->type . ') rank=' . $hit->rank . PHP_EOL;
}
```

### Repository search

Any repository extending `ObjectRepository` (and implementing
`SearchableRepositoryInterface`) delegates to the front-end index by default:

```php
/** @var \iikiti\CMS\Repository\Object\PageRepository $repo */
$result = $repo->search('published pages');
```

### Autocomplete

```php
$suggestions = $searchService->autocomplete('sear', 5);
// Returns: [['id' => 1, 'type' => 'Page', 'label' => 'Search Results']]
```

## Custom search filters

Filters can modify search queries (query-time) or indexed data (index-time).
They are stored as `SearchFilter` entities with visibility controls.

### Registering a filter implementation via DI

```yaml
# config/services.yaml
services:
    App\Search\DateRangeFilter:
        tags:
            - { name: iikiti.search_filter }
```

```php
use iikiti\CMS\Search\Strategy\SearchFilterInterface;
use iikiti\CMS\Search\Entity\SearchIndex;
use iikiti\CMS\Search\Service\SearchParams;

class DateRangeFilter implements SearchFilterInterface
{
    public function getName(): string { return 'date_range'; }

    public function getLabel(): string { return 'Date Range'; }

    public function supports(SearchIndex $index): bool
    {
        return true;
    }

    public function apply(SearchIndex $index, SearchParams $params, mixed $value): void
    {
        // $value is ['from' => '2024-01-01', 'to' => '2024-12-31']
        $params->extraCriteria['created_date'] = $value;
    }

    public function process(SearchIndex $index, mixed $data): mixed
    {
        return $data;
    }
}
```

### Filter visibility

| Visibility | Description |
|---|---|
| `public_frontend` | Available on the public front-end search. |
| `admin_only` | Only accessible through the admin search. |
| `role_restricted` | Accessible to users with one of the specified `required_roles`. |

## Extending the search engine

To add a new engine:

```php
use iikiti\CMS\Search\Strategy\SearchEngineInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('iikiti.search_engine')]
class MySearchEngine implements SearchEngineInterface
{
    public function getName(): string { return 'my_engine'; }
    public function getLabel(): string { return 'My Search Engine'; }
    // ... implement all interface methods
}
```

Or register at runtime:

```php
$registry->register('my_engine', $myEngine);
```

## Plugin integration

Plugins can declare search capabilities in their `plugin.json` manifest:

```json
{
    "capabilities": {
        "search_indexes": ["my_index"],
        "search_filters": ["my_custom_filter"]
    }
}
```

## CLI commands

| Command | Description |
|---|---|
| `iikiti:search:config:init` | Seed default frontend and admin configurations. |
| `iikiti:search:config:list` | List all search index configurations. |
| `iikiti:search:index:create {slug}` | Create the index table for a configuration. |
| `iikiti:search:index:drop {slug}` | Drop the index table. |
| `iikiti:search:index:rebuild {slug}` | Rebuild the index from source data. |
| `iikiti:search:rebuild-all` | Rebuild all enabled indexes. |
| `iikiti:search:engines` | List available search engine adapters. |

## Events

| Event | Description |
|---|---|
| `iikiti.search.search` | Fired before query execution; allows short-circuiting. |
| `iikiti.search.autocomplete` | Fired during autocomplete. |
| `iikiti.search.filter_resolve` | Fired after filter resolution; plugins can add/remove filters. |
| `iikiti.search.filter_apply` | Fired when a filter is about to be applied. |
| `iikiti.search.index_created` | Fired after an index table is created. |
| `iikiti.search.index_dropped` | Fired after an index table is dropped. |
| `iikiti.search.index_rebuilt` | Fired after an index is rebuilt. |

## Database schema

All FTS tables are created in the configured `DB_SCHEMA` (default: the
PostgreSQL `search_path`). Tables:

| Table | Purpose |
|---|---|
| `search_indexes` | Index configurations (engine, type, language, options). |
| `search_index_fields` | Field definitions (source type, weight, analyzer). |
| `search_analyzer_layers` | Analyzer chain layers (type, position, options JSON). |
| `search_filters` | Filter definitions (visibility, roles, hook). |
| `search_config_groups` | Groups of configurations for site assignment. |
| `search_site_groups` | Groups of sites for bulk config assignment. |
