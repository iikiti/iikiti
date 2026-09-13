# Query Builder

The iikiti query builder is a safety-first abstraction over Doctrine DBAL's
query builder. It provides typed SQL elements, automatic parameter binding and
database-neutral operators, while remaining close enough to DBAL that every
existing capability is available.

The goals are:

- make correct, parameterised SQL the path of least resistance;
- reject inline values in expressions by default, so user input can never be
  interpolated into SQL;
- keep database-specific syntax behind a strategy so additional databases can
  be supported without touching application code;
- encourage developers to extend the builder rather than write raw SQL.

> **Prefer the query builder.** Raw SQL strings are still possible through the
> documented escape hatches, but their use is discouraged and will be flagged
> in code review.

## Architecture

```
QueryBuilder / PostgreSQLQueryBuilder   (SELECT, UPDATE, DELETE, INSERT)
        │
        ├── ExpressionBuilder                 typed, parameter-binding helpers
        │       └── Expression\*              Comparison, FunctionExpression, …
        │
        ├── Identifier\*                      Column, Table, Alias (validated)
        ├── Parameter / ParameterBag          named bound parameters
        ├── Cte / CteSet                      common table expressions
        └── UnionQueryPart                    union parts
        │
        ▼
DatabasePlatformStrategyInterface
        └── PostgreSQLPlatformStrategy        operators, functions, casting
```

## Quick start

Inject the factory and obtain a builder bound to the default connection:

```php
use iikiti\CMS\Query\QueryBuilderFactory;

final class SiteFinder
{
    public function __construct(private readonly QueryBuilderFactory $queryBuilderFactory)
    {
    }

    public function findActiveSites(string $domain): array
    {
        $qb = $this->queryBuilderFactory->create();

        $qb->selectColumn($qb->expr()->column('id', 's'), $qb->expr()->alias('site_id'))
            ->addSelect('s.name')
            ->from($qb->expr()->table('sites'), $qb->expr()->alias('s'))
            ->where($qb->expr()->eq('s.domain', $domain))
            ->andWhere($qb->expr()->eq('s.active', true))
            ->orderBy('s.name', 'ASC')
            ->setMaxResults(50);

        return $qb->executeQuery()->fetchAllAssociative();
    }
}
```

For update and delete statements use `executeStatement()`:

```php
$qb = $this->queryBuilderFactory->create();
$qb->update('sites')
    ->set('active', $qb->parameter(false))
    ->where($qb->expr()->lt('last_seen_at', $cutoff));

$affected = $qb->executeStatement();
```

## Configuration

| Variable | Default | Description |
| --- | --- | --- |
| `QUERY_DEFAULT_PLATFORM` | `postgresql` | Strategy used when platform detection does not match a registered strategy. |
| `QUERY_SAFETY_ENABLED` | `true` | Reject inline literal values in expression strings. |

Both values are exposed as container parameters (`iikiti_query.*`) and are read
by `QueryBuilderFactory`. They are defined in `config/packages/iikiti_query.yaml`
and the corresponding environment variables live in `.env`.

## The safety model

When a string is passed to `where()`, `andWhere()`, `orWhere()`, `having()`,
`andHaving()`, `orHaving()`, `set()` or `select()`, the builder scans it for
inline literals:

- single-quoted strings (`'…'`);
- double-quoted strings (`"…"`);
- bare numeric literals (`42`, `3.14`).

If a literal is found, an `InlineValueException` is thrown before the SQL is
rendered:

```php
// Throws InlineValueException.
$qb->where('id = 42');
$qb->where("name = 'John'");
```

The correct approaches are the expression builder (which binds values) or an
explicit raw expression:

```php
// Parameterised: renders `id = :qp1` and binds 42.
$qb->where($qb->expr()->eq('id', 42));

// Explicitly trusted SQL. Use sparingly.
$qb->where($qb->expr()->raw('id = 42'));
```

Parameter placeholders such as `:qp12` are recognised, so the digits inside a
placeholder name are never mistaken for a literal. The scan also rejects
dollar-quoted string literals (`$$…$$`), hexadecimal/octal/binary,
digit-separated and scientific-notation numerics, and boolean/`NULL` keyword
literals, while still allowing the `IS [NOT] NULL` operator forms.

Identifier inputs are validated too. When a table, alias or column is passed as
a plain string (rather than as a `Table`/`Alias`/`Column` object), it is checked
against the same identifier pattern, so a dynamic name can never become SQL
syntax. Safety can be disabled for a builder with `disableSafety()` (and
re-enabled with `enableSafety()`), but `expr()->raw()` is preferred because it
is explicit at the call site.

## Identifiers

Identifiers are validated value objects. They may contain letters, digits and
underscores, and may be qualified with one or two dots
(`column`, `alias.column`, `schema.table.column`). Anything else raises an
`IdentifierValidationException`, which means an identifier can never break out
of its position.

```php
use iikiti\CMS\Query\Identifier\Alias;
use iikiti\CMS\Query\Identifier\Column;
use iikiti\CMS\Query\Identifier\Table;

new Column('id');          // id
new Column('id', 'u');     // u.id
new Column('u.id');        // u.id
new Table('objects');      // objects
new Table('objects', 'public'); // public.objects
new Alias('u');            // u
```

`Column` doubles as an expression, so it can be passed anywhere an expression is
expected.

## The expression builder

`$qb->expr()` returns an `ExpressionBuilder`. Scalar values passed to the
helpers are automatically bound as parameters and the returned SQL contains a
placeholder.

### Comparison

```php
$qb->expr()->eq('u.id', $id);      // u.id = :qp1
$qb->expr()->neq('u.id', $id);     // u.id <> :qp2
$qb->expr()->lt('u.score', 10);    // u.score < :qp3
$qb->expr()->lte('u.score', 10);   // u.score <= :qp4
$qb->expr()->gt('u.score', 10);    // u.score > :qp5
$qb->expr()->gte('u.score', 10);   // u.score >= :qp6
```

To compare two columns, wrap the right hand side in a `Column`. All other
scalar values are treated as bound values:

```php
$qb->expr()->eq('u.id', $qb->expr()->column('u.parent_id')); // u.id = u.parent_id
```

### Null checks

```php
$qb->expr()->isNull('u.deleted_at');     // u.deleted_at IS NULL
$qb->expr()->isNotNull('u.published_at'); // u.published_at IS NOT NULL
```

### Pattern matching

```php
$qb->expr()->like('u.name', 'A%');           // u.name LIKE :qp1
$qb->expr()->notLike('u.name', 'A%');        // u.name NOT LIKE :qp2
$qb->expr()->like('u.name', 'A\\%', '\\');   // … LIKE :qp3 ESCAPE :qp4
```

The `ESCAPE` character is bound as a parameter, never inlined.

### Regular expressions

Mapped to the platform operator (`~`, `!~`, `~*`, `!~*` on PostgreSQL):

```php
$qb->expr()->regex('u.slug', '^news-');     // u.slug ~ :qp1
$qb->expr()->notRegex('u.slug', '^news-');  // u.slug !~ :qp2
$qb->expr()->iregex('u.slug', '^NEWS-');    // u.slug ~* :qp3
$qb->expr()->notIregex('u.slug', '^NEWS-'); // u.slug !~* :qp4
```

### JSON

```php
$qb->expr()->jsonExtract('p.data', 'title');   // p.data -> :qp1
$qb->expr()->jsonGetText('p.data', 'title');   // p.data ->> :qp2
$qb->expr()->jsonContains('p.data', ['type' => 'page']); // p.data @> (:qp3)::jsonb
```

### Full-text search

```php
$qb->expr()->ftsMatch('p.content', 'hello world');
// p.content @@ PLAINTO_TSQUERY(:qp1)

$qb->expr()->ftsMatch('p.content', 'hello & world', 'toTsquery');
// p.content @@ TO_TSQUERY(:qp1)
```

### Arrays

```php
$qb->expr()->arrayContains('p.tags', ['news', 'tech']); // p.tags @> :qp1
$qb->expr()->arrayOverlaps('p.tags', ['news', 'tech']); // p.tags && :qp2
```

### Casting

```php
$qb->expr()->cast('p.created_at', 'date'); // (p.created_at)::date

// Compare a cast value.
$qb->expr()->condition(
    $qb->expr()->castExpression('p.created_at', 'date'),
    Operator::EQ,
    '2026-01-01'
);
```

### Functions

`func()` builds a generic function call. The function name is an abstract
catalog key resolved by the platform strategy, and scalar arguments are bound:

```php
$qb->expr()->func('coalesce', $qb->expr()->column('u.name'), 'anonymous');
// COALESCE(u.name, :qp1)

$qb->expr()->func('dateTrunc', 'day', $qb->expr()->column('p.created_at'));
// DATE_TRUNC(:qp1, p.created_at)
```

### Combining expressions

```php
$qb->where($qb->expr()->conjunction(
    $qb->expr()->eq('a', 1),
    $qb->expr()->eq('b', 2),
)); // (a = :qp1) AND (b = :qp2)

$qb->where($qb->expr()->disjunction(/* … */)); // … OR …
```

## Parameters

Parameters are bound through the builder or the expression builder. The builder
assigns each parameter a process-unique name and binds only the parameters that
appear in the rendered SQL, so expressions that are built but never attached do
not leak unused parameters.

```php
$placeholder = $qb->parameter(42, ParameterType::INTEGER); // :qp1
$qb->where('id = '.$placeholder);

// Or add a prepared Parameter object.
use iikiti\CMS\Query\Parameter;
$qb->addParameter(new Parameter('active', ParameterType::STRING));
```

Use `Doctrine\DBAL\ArrayParameterType` for array parameters and `ParameterType`
for scalars.

## Common table expressions

```php
use iikiti\CMS\Query\Cte;
use iikiti\CMS\Query\CteSet;

$active = $this->queryBuilderFactory->create();
$active->select('s.id')->from('sites', 's')->where($active->expr()->eq('s.active', true));

$qb = $this->queryBuilderFactory->create();
$qb->withCte(new Cte('active_sites', $active, ['id']));
$qb->select('a.id')->from('active_sites', 'a');

// WITH active_sites (id) AS (SELECT … ) SELECT a.id FROM active_sites a
```

Multiple CTEs can be validated and added as a set:

```php
$set = new CteSet([
    new Cte('first', 'SELECT id FROM a'),
    new Cte('second', 'SELECT id FROM first'),
]);
$set->validate(); // throws on duplicate names or forward references
$qb->withCteSet($set);
```

Recursive CTEs use `withRecursiveCte()` (PostgreSQL), which renders
`WITH RECURSIVE`:

```php
$qb->withRecursiveCte(new Cte('tree', $recursiveSelect));
```

## Unions

DBAL builds a union query entirely from its union parts, so at least two parts
are required:

```php
use Doctrine\DBAL\Query\UnionType;
use iikiti\CMS\Query\UnionQueryPart;

$qb = $this->queryBuilderFactory->create();
$qb->unionPart(new UnionQueryPart('SELECT id FROM sites'));
$qb->addUnionPart(new UnionQueryPart('SELECT id FROM pages', UnionType::ALL));

// (SELECT id FROM sites) UNION ALL (SELECT id FROM pages)
```

## Database platform strategies

All vendor syntax lives in a `DatabasePlatformStrategyInterface`. A strategy
provides:

- the operator catalog (`eq`, `regex`, `jsonContains`, `arrayOverlaps`, …);
- the function catalog (`coalesce`, `dateTrunc`, `toTsquery`, …);
- identifier quoting and value casting;
- feature flags for CTEs, unions, `RETURNING` and upserts.

`PostgreSQLPlatformStrategy` is the default. To support another database,
implement the interface (extending `AbstractPlatformStrategy` is usually
enough) and register it.

### Compile-time registration

Tag the service with `iikiti.query.database_platform`:

```yaml
services:
    App\Query\MySQLPlatformStrategy:
        tags: ['iikiti.query.database_platform']
```

### Runtime registration

```php
use iikiti\CMS\Query\DatabasePlatform\DatabasePlatformRegistry;

$registry->register('mysql', new MySQLPlatformStrategy());
```

`QueryBuilderFactory::create()` detects the platform from the connection's
Doctrine platform and selects the matching strategy, falling back to
`iikiti_query.default_platform`.

## Extending the builder

The builder is designed to be extended. Subclass `QueryBuilder` (or
`PostgreSQLQueryBuilder`) to add domain-specific methods, and add platform
strategies for new databases.

```php
final class SiteQueryBuilder extends PostgreSQLQueryBuilder
{
    public function activeOnly(): self
    {
        return $this->andWhere($this->expr()->eq('active', true));
    }
}
```

`PostgreSQLQueryBuilder` adds `withRecursiveCte()` and `returning()` as
examples of vendor-specific conveniences.

## Custom queries are discouraged

The only supported way to write free-form SQL inside the builder is
`expr()->raw()` (or a `RawExpression`). It bypasses the inline-value scan and is
therefore a security-sensitive call:

```php
$qb->where($qb->expr()->raw('to_tsvector(content) @@ to_tsquery(:query)'));
```

Prefer the typed helpers. When a construct genuinely cannot be expressed through
the builder, keep the raw fragment as small as possible and continue to bind
values through the expression builder.

## Migration from raw SQL

| Raw SQL | Query builder |
| --- | --- |
| `sprintf('o.%s IN (:%s)', $field, $p)` | `$qb->expr()->in($qb->expr()->column($field, 'o'), $values)` |
| `"u.id = " . $id` | `$qb->expr()->eq('u.id', $id)` |
| `"name = '{$name}'"` | `$qb->expr()->eq('name', $name)` |
| `'JSONB_CONTAINS(p.value, :value) = true'` | `$qb->expr()->jsonContains('p.value', $value)` |
| `'col ~ :pattern'` | `$qb->expr()->regex('col', $pattern)` |

## Reference

| Class | Purpose |
| --- | --- |
| `QueryBuilder` | Base builder: safety, parameters, CTEs, unions, typed statements. |
| `PostgreSQLQueryBuilder` | PostgreSQL conveniences (`WITH RECURSIVE`, `RETURNING`). |
| `QueryBuilderFactory` | Creates builders bound to a connection and platform. |
| `ExpressionBuilder` | Typed, parameter-binding expression helpers. |
| `DatabasePlatformStrategyInterface` | Vendor-specific SQL contract. |
| `DatabasePlatformRegistry` | Strategy registration and resolution. |
| `Identifier\Column` / `Table` / `Alias` | Validated identifiers. |
| `Cte` / `CteSet` | Common table expressions and validation. |
| `UnionQueryPart` | A single union part. |
| `Parameter` / `ParameterBag` | Bound query parameters. |
| `Expression\*` | Expression value objects. |
| `Exception\InlineValueException` | Raised when an inline literal is detected. |
