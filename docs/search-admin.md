# Search Administration

This guide describes how to manage full-text search configurations through
the iikiti CMS administration interface.

## Overview

The search administration UI provides full CRUD (create, read, update, delete)
on search configurations. Two default configurations are seeded automatically:

1. **Front-end Search** (`frontend` slug) — the default public-facing search.
   This index is deletable.
2. **Administration Search** (`admin` slug) — a system-level, non-deletable
   index for searching content within the administration. While the index
   itself cannot be deleted, you can add, modify, and remove its fields.

## Accessing the UI

Navigate to **Admin → Search → Indexes** to manage search configurations.

## Managing search indexes

### Creating a custom index

1. Click **New Index**.
2. Fill in the form:
   - **Slug**: Machine name (used in API calls and CLI). E.g. `products`.
   - **Name**: Human-readable name.
   - **Type**: Select "Custom Index".
   - **Engine**: `postgresql` (the only engine currently supported).
   - **Language**: PostgreSQL text search configuration (e.g. `english`,
     `french`, `german`).
   - **Description**: Optional description.
3. Click **Save**.

### Editing an index

Click the index name to open the edit form. You can:
- Change the name, description, language, and engine.
- Enable/disable the index.
- Assign it to a site or configuration group.

**Note:** The Administration Search index is system-locked. While you can
edit its fields and most settings, its `engine` cannot be changed.

### Deleting an index

Click the **Delete** button. System-locked indexes (like the Administration
Search) cannot be deleted. When deleting a non-locked index:
- The configuration row is removed.
- The engine index table is dropped automatically.

### Index operations

Each index has three operation buttons:

| Button | Effect |
|---|---|
| **Create** | Creates the underlying database index table (tsvector column + GIN index). |
| **Rebuild** | Drops all indexed data and re-reads from source objects. Use after changing fields. |
| **Drop** | Drops the index table and removes all indexed data. The configuration remains. |

### Rebuild All

Use the **Rebuild All** button to rebuild every enabled index at once. This
is useful after installing a new plugin or changing global search settings.

## Managing index fields

Each index has a set of **fields** that determine what data is searchable.
Fields are weighted A (highest) to D (lowest) for relevance ranking.

### Adding a field

1. In the index edit form, scroll to the **Fields** section.
2. Click **Add Field**.
3. Configure:
   - **Name**: Field name as it appears in search results and `data` output.
   - **Source Type**: How to obtain the data:
     - **Database Column**: A column on the `objects` table (e.g. `type`,
       `created_date`, `id`).
     - **Object Property**: A key-value property from the object's property
       store (e.g. `title`, `content`, `keywords`).
     - **Virtual Column**: A custom SQL expression (e.g.
       `p.value::text`).
     - **Alias**: An alternative name for another field in the index.
   - **Source**: For column/property: the column or property name. For virtual:
     the SQL expression. For alias: the target field name.
   - **Weight**: 1–4 (A=most important, D=least).
   - **Data Type**: The field's data type for display.
   - **Language**: Optional per-field language override.
   - **Facetable**: Enable faceted search on this field.
   - **Sortable**: Allow sorting by this field.
4. Click **Save**.

### Modifying a field

Click the edit icon next to a field. Changes to source type, source, weight,
or visibility take effect immediately in searches that use the field.

### Removing a field

Click the **Delete** icon next to a field. After removing fields, click
**Rebuild** on the index to apply changes to existing indexed data.

## Managing analyzer layers

Analyzer layers define the text-analysis pipeline: how text is tokenized,
normalized, filtered, and ultimately indexed.

### Viewing layers

Navigate to **Admin → Search → Analyzers** to see all configured layer chains.

### Adding a layer

1. Click **New Layer**.
2. Configure:
   - **Name**: The analyzer chain name. Layers with the same name form a chain.
   - **Type**: Choose from:
     - `tokenizer` — splits text into tokens.
     - `charfilter` — character-level filter (before tokenization).
     - `normalizer` — lowercasing, trimming, etc.
     - `lowercase` / `uppercase` — case conversion.
     - `stop` — stop word removal.
     - `stemmer` — word stemming (e.g. English snowball).
     - `synonym` — synonym expansion.
     - `ngram` — n-gram tokenization (for partial matching).
     - `edgengram` — edge n-gram (for prefix autocomplete).
     - `tokenfilter` — generic token-level filter.
   - **Position**: Order within the chain (lower = earlier).
   - **Options**: JSON object with layer-specific parameters.

   Example ngram options: `{"min_gram": 2, "max_gram": 10}`

### Modifying a layer

Edit the type, position, or options. Changes take effect on the next
index rebuild.

### Removing a layer

Delete the layer. The chain will skip it on the next rebuild.

## Managing search filters

Filters constrain or modify search results. They can be public (visible on
the front-end), admin-only, or restricted to specific user roles.

### Creating a filter

1. Navigate to **Admin → Search → Filters**.
2. Click **New Filter**.
3. Configure:
   - **Index**: Select the search index this filter belongs to.
   - **Name**: Machine name (matches a `SearchFilterInterface` service).
   - **Label**: Human-readable label shown in the UI.
   - **Mode**: `Query-time` (applied during search) or `Index-time`
     (applied during indexing).
   - **Visibility**:
     - **Public (front-end)**: Available to anonymous users on the storefront.
     - **Admin only**: Only used in the administration search.
     - **Role-restricted**: Requires one or more roles.
   - **Required Roles**: (Only for role-restricted visibility) The Symfony
     roles required to use this filter. E.g. `["ROLE_ADMIN", "ROLE_EDITOR"]`.
   - **Hook**: The service ID of the `SearchFilterInterface` implementation
     that provides the filter logic.
   - **Options**: JSON object with filter-specific configuration.
   - **Enabled**: Toggle the filter on/off.

### Example: Category filter

| Field | Value |
|---|---|
| Index | frontend |
| Name | category |
| Label | Category |
| Mode | Query-time |
| Visibility | Public (front-end) |
| Hook | `iikiti.cms.search.filter:category` |
| Options | `{"property": "category"}` |

### Modifying a filter

Edit any field except the hook (changing the hook may break existing
configurations). After modifying a filter's visibility or required roles,
clear the application cache for changes to take effect.

## Configuration groups

Configuration groups allow you to organise search indexes and assign them to
sites or site groups as a unit.

### Viewing groups

Navigate to **Admin → Search → Config Groups**.

### Creating a group

1. Click **New Group**.
2. Configure:
   - **Name**: Machine name.
   - **Label**: Display name.
   - **Description**: Optional.
   - **Sites**: Select sites that should use this group's indexes.
   - **Site groups**: Or select site groups.
   - **Search indexes**: Select indexes to include in this group.
3. Click **Save**.

### System groups

The default `default` group is system-locked and contains both the frontend
and admin search indexes. It cannot be deleted, but you can add/remove indexes
from it.

## Site groups

Site groups allow you to assign configurations to multiple sites at once.

### Creating a site group

1. Navigate to **Admin → Search → Site Groups**.
2. Click **New Site Group**.
3. Configure:
   - **Name**: Machine name.
   - **Label**: Display name.
   - **Sites**: Select the sites that belong to this group.
4. Click **Save**.

## API endpoints

All search admin endpoints require `ROLE_ADMIN`.

| Endpoint | Method | Description |
|---|---|---|
| `/api/admin/search/indexes` | GET | List all index configurations. |
| `/api/admin/search/indexes/{id}` | GET | Get a specific index. |
| `/api/admin/search/indexes` | POST | Create a new index (custom type only). |
| `/api/admin/search/indexes/{id}` | PUT | Update an index. |
| `/api/admin/search/indexes/{id}` | DELETE | Delete an index (system-locked = rejected). |
| `/api/admin/search/indexes/{id}/create` | POST | Create the engine index table. |
| `/api/admin/search/indexes/{id}/drop` | POST | Drop the engine index table. |
| `/api/admin/search/indexes/{id}/rebuild` | POST | Rebuild the index from source data. |
| `/api/admin/search/rebuild-all` | POST | Rebuild all enabled indexes. |
| `/api/admin/search/engines` | GET | List available search engines. |
| `/api/admin/search/object-types` | GET | List registered DbObject types. |
| `/api/admin/search/public-filters/{slug}` | GET | List public filters for an index. |

## CLI commands

All commands are prefixed with `iikiti:search:`.

```bash
# Seed default configurations
php bin/console iikiti:search:config:init

# List all indexes
php bin/console iikiti:search:config:list

# Create an index table
php bin/console iikiti:search:index:create frontend

# Drop an index table
php bin/console iikiti:search:index:drop frontend --force

# Rebuild an index
php bin/console iikiti:search:index:rebuild frontend

# Rebuild all indexes
php bin/console iikiti:search:rebuild-all
```

## Troubleshooting

### Index table doesn't exist

Run `iikiti:search:index:create {slug}` or click **Create** in the UI.
The table is not created automatically until you explicitly build it.

### Search returns no results after adding fields

After modifying fields, click **Rebuild** to repopulate the index table
with the updated field definitions.

### pg_trgm extension not available

The PostgreSQL engine requires the `pg_trgm` extension for autocomplete
support. Enable it with:

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
```

The `iikiti:search:index:create` command handles this automatically.

### System-locked index

The Administration Search index is system-locked. You cannot delete it or
change its engine, but you can still add, modify, and remove its fields.
To reset it to defaults, run:

```bash
php bin/console iikiti:search:config:init --force
```
