# Database objects and entity conventions

## Core object model

`iikiti\CMS\Entity\DbObject` is the mapped root for records that represent CMS objects. It uses single-table inheritance: concrete subclasses share the `objects` table and are distinguished by the `type` discriminator. The dynamic discriminator listener registers each concrete subclass using its lowercase short class name.

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
4. Add uniqueness constraints/indexes to the `DbObject` root table mapping and a schema migration. The ORM schema tool builds the shared table from the root mapping, so subtype constraints belong there rather than only on a child class.
5. Add the discriminator/data migration, repository tests, and update this exception list if an entity or repository must remain standalone.

`ApiToken`, `IconSetEntity`, and `IconEntity` are DbObject subtypes. API tokens are global objects with site id `0`, while `creator_id` and `api_token_user_id` identify their owner. Icon sets and icons are global objects; icons reference their set through `icon_set_id`.

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
| `SearchIndex`, `SearchIndexField`, `SearchFilter`, `SearchAnalyzerLayer`, `SearchConfigGroup`, `Search\SiteGroup` and their search repositories | Search configuration and its field/filter/analyzer/site-assignment rows form a separate configuration graph with their own tables and lifecycle, not CMS content objects. Several are child or join rows rather than independently addressable objects. |

These exceptions do not weaken the default: a new entity/repository should use `DbObject`/`ObjectRepository` unless its design warrants an explicit exception here.
