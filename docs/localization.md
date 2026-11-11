# Localization

## Locale identifiers and scope

Use canonical BCP 47 locale tags, including an optional script and region when
they affect spelling, grammar, collation, or formatting: `en-CA`, `en-GB`,
`fr-CA`, `zh-Hant-TW`. The region is part of the locale; a separate country code
is not needed for language or presentation differences. Store country separately
only for independent business/geographic facts such as legal country or tax
jurisdiction.

The locale settings are available at four scopes:

- `Application::setLocaleSettings($default, $uiLocales, $contentLocales)` stores
  application-wide defaults and available UI/content locales in application
  configuration.
- `Site::setLocaleSettings(...)` stores site overrides. An unset value inherits
  the application setting.
- Every `DbObject` can set an object-level content locale and available content
  locales with the same method. On ordinary objects these settings are stored
  in the `locale_settings` object property. Object defaults override the site
  default; plugin property defaults are used when the object has no default.
- `User::setPreferredLocale($locale)` stores a full regional locale in user
  preferences. `POST /api/user/locale` accepts `{"locale":"fr-CA"}`; send
  `null` to clear the preference.

The UI locale resolves as user, site, application, then the current `en`
Symfony fallback catalog. Object locales do not change UI labels. Content
selection tries the user locale when the property has a matching translation,
including regional/base-language fallback, then the object, site, and
application defaults, and finally a deterministic available translation.
Regional presentation formatting keeps the user's region even when text falls
back to another variant. Without a user preference, formatting falls back to
the content locale, site, then application locale. Time conversion uses the
existing user timezone preference; locale and timezone are separate.

For example, an object may contain only `en-GB` text while the user prefers
`en-CA`. The text retains British spelling and its source language, while dates
and numbers use Canadian conventions and times use the user's timezone.

## Property values

`ObjectProperty.value` remains JSON. Existing strings and other JSON values are
left unchanged. New multilingual text uses a versioned localized value with
either separate translations:

```php
use iikiti\CMS\Value\LocalizedValue;

$page->setProperty('title', LocalizedValue::fromTranslations([
    'en-CA' => 'The colour of the sky',
    'fr-CA' => 'La couleur du ciel',
]));
```

The stored JSON shape is:

```json
{
  "$type": "localized",
  "version": 1,
  "translations": {
    "en-CA": "The colour of the sky",
    "fr-CA": "La couleur du ciel"
  }
}
```

Or one ordered value can contain inline language changes:

```php
$page->setProperty('title', LocalizedValue::fromSegments([
    ['text' => 'Bonjour, ', 'locale' => 'fr-CA'],
    ['text' => 'world!', 'locale' => 'en-CA'],
]));
```

The serializer returns the selected value in `value`, its source tag in
`locale`, and the full stored form in `localizedValue` for editing. Mixed
segments remain ordered and retain each segment's language. Twig's
`iikiti_localized_text` filter emits escaped text inside language-tagged spans;
use `iikiti_localized_plain_text` for attribute values. The
`iikiti_localized_property(object, name)` Twig function resolves an object
property for the active request.

Malformed locale tags and values are rejected before persistence. Secret
properties cannot be localized or indexed. Existing untagged strings are not
assumed to be English; they use the `und` fallback.

## Plugin declarations and indexed queries

Plugins declare content locales and query capabilities using the autoconfigured
`ObjectPropertyDefinitionProviderInterface`. The `objectType` is the lowercase
Doctrine discriminator (or the object's PHP class name). A base tag such as
`en` permits regional variants; a regional declaration such as `en-CA` remains
specific to that region and its base fallback.

```php
use iikiti\CMS\Plugin\ObjectPropertyDefinition;
use iikiti\CMS\Plugin\ObjectPropertyDefinitionProviderInterface;
use iikiti\CMS\Value\LocaleTag;

final class ArticleProperties implements ObjectPropertyDefinitionProviderInterface
{
    public function getObjectPropertyDefinitions(): iterable
    {
        return [
            new ObjectPropertyDefinition(
                'article',
                'title',
                ['en-CA', 'en-GB', 'fr-CA'],
                LocaleTag::fromString('en-CA'),
                searchable: true,
                sortable: true,
            ),
            new ObjectPropertyDefinition(
                'article',
                'body',
                ['en-CA', 'fr-CA'],
                allowMixedLanguage: true,
                searchable: true,
            ),
        ];
    }
}
```

Definitions also support `allowMixedLanguage`, `searchable`, and `sortable`.
The default core definitions cover the common `title`, `slug`, `tags`, and
`content` fields; a plugin can provide an object-specific definition to narrow
or replace those capabilities.

Queryable text is projected to `object_property_locale_index`. Writes update
the projection transactionally. Equality/range/order queries use a B-tree index
with that locale's ICU collation; substring matching uses `pg_trgm`, and
full-text queries use a GIN `tsvector` index with a PostgreSQL text-search
configuration selected separately from the ICU collation. Mixed-language
segments use `und` for sorting/search projection while retaining per-segment
language tags for rendering.

Only declared searchable/sortable fields have an indexed-query guarantee.
Queries using undeclared property fields are rejected rather than falling back
to a JSON full scan. After activating a plugin with new property locales or
changing supported locale configuration, run:

```sh
php bin/console iikiti:locale-index:rebuild
```

The command rebuilds projections and creates missing ICU collations/indexes. It
also refreshes indexes whose ICU collation version changed. UTF-8 is a database
encoding and cannot be changed by a table migration. The application uses
explicit ICU collations at query/index level; it does not change the database's
global collation. `und` is the deterministic fallback collation.

Twig date/number filters are `iikiti_format_date`, `iikiti_format_time`,
`iikiti_format_datetime`, and `iikiti_format_number`. An optional locale argument
supplies the content locale when the user has no preferred locale. Browser code
can use `setSavedLocale()`, `formatDateTime()`, and `formatNumber()` from
`assets/js/iikiti/time/temporal.js`.

## Partition operations

Physical `objects` storage is partitioned by `LIST (site_id)`, then by calendar
quarter over `created_date` (four date partitions per year). Site id `0` has a
global partition. Object writes and historical imports ensure the required
site/quarter partition before insert; the scheduler and
`iikiti:objects:ensure-partitions` command create the current and next three
quarters for every site.

The migration is a one-way cutover and requires a database backup and a rehearsed
maintenance window. Rollback is by restoring that backup; the migration's
Doctrine `down()` intentionally refuses a lossy reverse operation. See
[`database-objects.md`](database-objects.md) for the identity registry, ORM view,
foreign-key, and schema-tool details.
