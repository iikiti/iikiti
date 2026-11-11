# Database objects and entity conventions

## Core object model

`iikiti\CMS\Entity\DbObject` is the mapped root for records that represent CMS objects. It uses single-table inheritance: concrete subclasses share the physical `objects` table and are distinguished by the `type` discriminator. The dynamic discriminator listener registers each concrete subclass using its lowercase short class name. Doctrine maps the entity to the updatable `object_lookup` view so ID-only ORM reads can first use the global identity index and then prune the physical partitions.

A DbObject provides the shared object id, creation date, site id, creator id, and property collection. Object properties remain stored separately in `object_properties` and are managed through `PropertiedTrait`.

Use `DbObject` for new persistent CMS domain objects unless there is a specific, very good reason not to. A subclass joins the `objects` table; it cannot retain a separate table. Map subtype-only columns as nullable because the shared table stores multiple concrete object types. Give subtype fields distinct column names when another subtype uses the same PHP property name.

Entity repositories for DbObject subclasses should extend `iikiti\CMS\Repository\ObjectRepository`. It provides site filtering, property queries, and the shared query/cache behavior. Repository implementations should preserve those defaults unless their object semantics specifically require a documented override.

## Site ids and global objects

`objects.site_id` is a scalar unsigned BIGINT, not a relation to a Site row. Site id `0` means global or no site; there is no Site row with id `0`. Database identity sequences start at `1`, and no object row should be assigned id `0`.

Use `getSiteId()` and `setSiteId()` for the scalar site value. `setSite(Site)` is a convenience method for assigning an already-persisted site. `SITE_SPECIFIC = true` objects are filtered to the current site by `ObjectRepository`; `SITE_SPECIFIC = false` objects use site id `0`. A repository may disable that default filter when an endpoint intentionally searches across all site ids, and the override should be explicit.

## Adding a DbObject subtype

1. Create a concrete `#[ORM\Entity]` subclass of `DbObject` and assign it an entity-specific repository extending `ObjectRepository`.
2. Choose whether it is site-specific. Set the `SITE_SPECIFIC` class constant to `false` for global objects and persist them with `site_id = 0`.
3. Map subtype-only fields to nullable columns in `objects`; avoid column-name collisions across siblings.
4. Put global uniqueness on the object identity registry and add physical indexes to the partitioned `objects` root in a schema migration. Do not add global unique indexes to the partitioned table unless the partition columns are part of the key.
5. Add the discriminator/data migration, repository tests, and update this exception list if an entity or repository must remain standalone.

`ApiToken`, `IconSetEntity`, and `IconEntity` are DbObject subtypes. API tokens are global objects with site id `0`, while `creator_id` and `api_token_user_id` identify their owner. Icon sets and icons are global objects; icons reference their set through `icon_set_id`.

## Partitioned object storage and localization

The physical `objects` table uses top-level `LIST (site_id)` partitions and
calendar-quarter `RANGE (created_date)` subpartitions, so each site has four date
partitions per year. Site id `0` is the global/no-site partition. The
`iikiti:objects:ensure-partitions` command and the Doctrine pre-persist/update
listener create missing site/quarter partitions, including quarters needed by
historical site-transfer imports.

PostgreSQL cannot enforce a global `PRIMARY KEY (id)` or a simple inbound
foreign key on a table partitioned by both `site_id` and `created_date`.
`object_identity` therefore owns the globally unique ID, the partition key, and
the values whose uniqueness spans partitions (`token`, `slug`, asset public
token, and icon-set/name). `objects` has a composite physical key containing
`site_id`, `created_date`, and `id`; a composite cascading foreign key keeps it
paired with the registry. Existing object references target
`object_identity.id`. Deferred integrity triggers prevent identity/partition
rows from becoming orphaned or diverging.

`object_lookup` is a view over the identity registry and partitioned table with
insert/update/delete triggers. All `DbObject` Doctrine mappings use this view,
preserving the public single-ID API, ORM `find()`, lazy associations, and
generated IDs. Direct writes to `objects` or `object_identity` bypass that
contract and are unsupported; use the ORM or the partition/identity services.
The standard Doctrine schema tool cannot manage this view/partition/trigger
layout. The migrations are authoritative for these relations. The partition
cutover migration is intentionally irreversible; rollback requires restoring
the pre-migration database backup.

The cutover checks `object_properties.object_id` before adding its
identity-registry foreign key. If legacy orphan property rows exist, it preserves
them and adds the FK as `NOT VALID` so new writes are still checked. Review and
repair those rows separately, then validate the constraint with
`ALTER TABLE <schema>.object_properties VALIDATE CONSTRAINT fk_object_properties_identity`.

Localized property JSON remains in `object_properties.value`. Its indexed query
projection and ICU collation registry (`object_property_locale_index` and
`object_locale_index_collation`) are DBAL-managed infrastructure tables rather
than CMS domain entities. Rebuild projections/collations after plugin locale
declarations or supported-locale changes with
`iikiti:locale-index:rebuild`. See [`localization.md`](localization.md) for the
BCP 47 value contract and locale-resolution rules.

## Justified standalone entities and repositories

Standalone entities and their repositories must have a concrete reason to avoid the DbObject hierarchy. Keep this list current when adding exceptions.

| Entity or group | Why it remains standalone |
| --- | --- |
| `ObjectProperty` / `ObjectPropertyRepository` | The property side-table for every DbObject. Making each property a DbObject would create a circular, unbounded object/property model. |
| `AuditLogEntry` / `AuditLogEntryRepository` | Append-only audit history with user snapshots and nested events. Putting audit events in the audited object table would make the audit store recursive and couple its retention lifecycle to CMS content. |
| `Role` / `RoleRepository` | Global security configuration synchronized with the runtime authorization system, not user/site-owned CMS content. |
| `PluginRecord` / `PluginRecordRepository` | Plugin installation bookkeeping has system-level rows and custom nullable VARCHAR `site_id` semantics. |
| `DigitalAssetVariant` | A rendition/variant child row owned by a digital asset object, rather than an independently addressable CMS object. |
| `WorkflowDefinition`, `WorkflowDefinitionVersion`, `WorkflowDefinitionSiteAssignment`, `WorkflowInstance`, `WorkflowInstanceEvent`, `WorkflowTask` and their workflow repositories | A specialized workflow runtime with versioned graphs, nullable site/subject ownership, plugin/system owners, and association/history rows. Its lifecycle and optional ownership do not fit the required DbObject creator/site columns. `WorkflowDefinitionSiteAssignment` is a join table. |
| `BatchJob`, `BatchItem`, `BatchItemDependency`, `BatchEvent`, `BatchSchedule`, `BatchScheduleRun` | Operational queue, dependency, progress-event and schedule state. These high-volume records need worker claims, retries, and lifecycle independent from editable CMS content; they are not site-owned content objects. |
| `site_transfer_identity`, `site_transfer_object_map`, `site_transfer_credentials`, `batch_item_dependency_refs` | Migration-owned DBAL tables for the persistent installation UUID, source-to-destination identity mapping, encrypted short-lived transfer credentials, and bounded dependency staging. They are operational lookup/staging tables, not Doctrine entities. |
| `object_identity`, `object_property_locale_index`, `object_locale_index_collation` | Database-managed object ID/partition-key registry and locale-aware query/index projections. They enforce global identity and support collated indexed access to JSON property values; they are persistence infrastructure, not user-addressable CMS objects. |
| `SecretSiteKey` / `secret_site_keys` | Standalone ORM key metadata for wrapped per-site and global secrets master keys. It is operational cryptographic material, not user-addressable CMS content; the nullable site relation cascades key deletion with its Site. |
| `SearchIndex`, `SearchIndexField`, `SearchFilter`, `SearchAnalyzerLayer`, `SearchConfigGroup`, `Search\SiteGroup` and their search repositories | Search configuration and its field/filter/analyzer/site-assignment rows form a separate configuration graph with their own tables and lifecycle, not CMS content objects. Several are child or join rows rather than independently addressable objects. |

These exceptions do not weaken the default: a new entity/repository should use `DbObject`/`ObjectRepository` unless its design warrants an explicit exception here.
