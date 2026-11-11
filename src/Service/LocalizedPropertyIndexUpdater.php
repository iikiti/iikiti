<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Doctrine\ObjectPartitionManager;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Plugin\ObjectPropertyDefinition;
use iikiti\CMS\Plugin\ObjectPropertyDefinitionRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Value\LocaleTag;
use iikiti\CMS\Value\LocalizedValue;

/** Maintains locale-resolved searchable/sortable text projections per write. */
final readonly class LocalizedPropertyIndexUpdater
{
	public function __construct(
		private Connection $connection,
		private DatabaseTableNameResolver $tableNames,
		private ObjectPropertyDefinitionRegistry $definitions,
		private LocaleResolver $locales,
		private LocaleCollationManager $collations,
		private PostgreSQLTextSearchConfigResolver $textSearchConfigs,
		private SiteRepository $sites,
		private ?EntityManagerInterface $entityManager = null,
	) {
	}

	public function refresh(ObjectProperty $property): void
	{
		$propertyId = (int) $property->getId();
		$index = $this->tableNames->resolve('object_property_locale_index');
		$this->connection->delete($index, ['object_property_id' => $propertyId]);
		if ($property->isSecret()) {
			return;
		}

		$object = $property->getObject();
		$name = $property->getName();
		if (null === $object || null === $name) {
			return;
		}

		$definition = $this->definition($object, $name);
		if (null === $definition || (!$definition->searchable && !$definition->sortable)) {
			return;
		}

		$localized = $property->getLocalizedValue();
		$segments = null !== $localized ? $localized->getSegments() : [];
		$text = $this->valueText($property->getValue(), $localized);
		if (null === $text || '' === trim($text)) {
			return;
		}

		$objectType = $object->getType() ?? strtolower((new \ReflectionClass($object))->getShortName());
		$objectLocale = $object->getDefaultLocale() ?? $definition->defaultLocale;
		$objectLocales = $object->getSupportedContentLocales();
		$objectSiteId = ObjectPartitionManager::normalizeSiteId($object->getSiteId());

		foreach ($this->contexts($object, $objectSiteId) as $context) {
			$site = $context['site'];
			$application = $context['application'];
			$siteLocale = $site?->getDefaultLocale();
			$applicationLocale = $application?->getDefaultLocale();
			$scopedLocales = $objectLocales ?? $site?->getSupportedContentLocales() ?? $application?->getSupportedContentLocales();
			$availableLocales = $this->availableLocales($localized, $definition, $scopedLocales);
			$targets = $this->targetLocales($localized, $definition, $scopedLocales, $objectLocale, $siteLocale, $applicationLocale, $segments);

			foreach ($targets as $targetLocale) {
				if (!$definition->supportsLocale($targetLocale->getTag()) &&
					!([] !== $segments && 'und' === $targetLocale->getTag() && $definition->allowMixedLanguage)
				) {
					continue;
				}
				if (null !== $scopedLocales &&
					!$this->locales->isLocaleAllowed($targetLocale, $scopedLocales) &&
					!([] !== $segments && 'und' === $targetLocale->getTag())
				) {
					continue;
				}
				if ([] !== $segments && 'und' !== $targetLocale->getTag()) {
					continue;
				}

				$sourceLocale = LocaleTag::fromString('und');
				$resolvedText = $text;
				if (null !== $localized && [] === $segments) {
					if ([] === $availableLocales) {
						continue;
					}
					$sourceLocale = $this->locales->resolveContentLocale(
						$availableLocales,
						$targetLocale,
						$objectLocale,
						$siteLocale,
						$applicationLocale,
					);
					$resolvedText = $localized->getTranslations()[$sourceLocale->getTag()] ?? $text;
				}

				$this->collations->ensure($targetLocale);
				$this->insertProjection(
					$propertyId,
					(int) $object->getId(),
					(int) $objectSiteId,
					(int) $context['siteId'],
					$objectType,
					$name,
					$targetLocale,
					$sourceLocale,
					$resolvedText,
					$definition,
				);
			}
		}
	}

	public function refreshObject(DbObject $object): void
	{
		foreach ($object->getProperties() as $property) {
			$this->refresh($property);
		}
	}

	public function rebuildAll(): int
	{
		return $this->rebuildProperties('1 = 1', []);
	}

	public function rebuildSite(int|string $siteId): int
	{
		return $this->rebuildProperties('object.site_id IN (0, :site_id)', ['site_id' => $siteId]);
	}

	/** @param array<string,int|string> $parameters */
	private function rebuildProperties(string $scope, array $parameters): int
	{
		if (null === $this->entityManager) {
			throw new \LogicException('An entity manager is required to rebuild localized property indexes.');
		}
		$cursor = 0;
		$rebuilt = 0;
		$properties = $this->tableNames->resolve('object_properties');
		$identities = $this->tableNames->resolve('object_identity');

		do {
			$parameters['cursor'] = $cursor;
			$ids = $this->connection->fetchFirstColumn(sprintf(
				'SELECT property.id FROM %s property INNER JOIN %s object ON object.id = property.object_id WHERE %s AND property.id > :cursor ORDER BY property.id LIMIT 250',
				$properties,
				$identities,
				$scope,
			), $parameters);
			foreach ($ids as $id) {
				$property = $this->entityManager->find(ObjectProperty::class, $id);
				if ($property instanceof ObjectProperty) {
					$this->refresh($property);
					++$rebuilt;
				}
				$cursor = (int) $id;
			}
		} while (250 === count($ids));

		return $rebuilt;
	}

	private function definition(DbObject $object, string $name): ?ObjectPropertyDefinition
	{
		$type = $object->getType() ?? strtolower((new \ReflectionClass($object))->getShortName());

		return $this->definitions->get($type, $name) ?? $this->definitions->get($object::class, $name);
	}

	/** @return list<array{siteId:string,site:?Site,application:?Application}> */
	private function contexts(DbObject $object, string $objectSiteId): array
	{
		if ('0' !== $objectSiteId) {
			$site = $this->getCurrentSite($objectSiteId) ?? $this->sites->find($objectSiteId);

			return [[
				'siteId' => $objectSiteId,
				'site' => $site,
				'application' => null === $site ? null : $this->sites->getApplicationBySite($site),
			]];
		}

		$contexts = [[
			'siteId' => '0',
			'site' => null,
			'application' => null,
		]];
		foreach ($this->sites->findAll() as $site) {
			if (null === $site->getId()) {
				continue;
			}
			$contexts[] = [
				'siteId' => ObjectPartitionManager::normalizeSiteId($site->getId()),
				'site' => $site,
				'application' => $this->sites->getApplicationBySite($site),
			];
		}

		return $contexts;
	}

	private function getCurrentSite(string $siteId): ?Site
	{
		try {
			$current = SiteRegistry::hasCurrent() ? SiteRegistry::getCurrent() : null;
		} catch (\Error) {
			return null;
		}

		return $current instanceof Site && (string) $current->getId() === $siteId ? $current : null;
	}

	/** @param list<LocaleTag>|null $scopedLocales
	 * @return list<LocaleTag>
	 */
	private function availableLocales(?LocalizedValue $localized, ObjectPropertyDefinition $definition, ?array $scopedLocales): array
	{
		if (null === $localized || [] !== $localized->getSegments()) {
			return [];
		}

		return array_values(array_filter(
			array_map(LocaleTag::fromString(...), $localized->getLocales()),
			fn (LocaleTag $locale): bool => $definition->supportsLocale($locale->getTag()) &&
				(null === $scopedLocales || $this->locales->isLocaleAllowed($locale, $scopedLocales)),
		));
	}

	/** @param list<LocaleTag>|null $scopedLocales
	 * @param list<array{text:string,locale:string}> $segments
	 *
	 * @return list<LocaleTag>
	 */
	private function targetLocales(
		?LocalizedValue $localized,
		ObjectPropertyDefinition $definition,
		?array $scopedLocales,
		?LocaleTag $objectLocale,
		?LocaleTag $siteLocale,
		?LocaleTag $applicationLocale,
		array $segments,
	): array {
		if ([] !== $segments) {
			return [LocaleTag::fromString('und')];
		}

		$targets = [];
		foreach ([
			...$definition->locales,
			...($localized?->getLocales() ?? []),
			...array_map(static fn (LocaleTag $locale): string => $locale->getTag(), $scopedLocales ?? []),
			$objectLocale?->getTag(),
			$siteLocale?->getTag(),
			$applicationLocale?->getTag(),
			'und',
		] as $tag) {
			if (is_string($tag)) {
				$targets[$tag] = LocaleTag::fromString($tag);
			}
		}

		return array_values($targets);
	}

	private function valueText(mixed $value, ?LocalizedValue $localized): ?string
	{
		if (null !== $localized) {
			if ([] !== $localized->getSegments()) {
				$text = implode('', array_column($localized->getSegments(), 'text'));
			} else {
				$text = implode(' ', $localized->getTranslations());
			}
		} elseif (is_string($value) || is_int($value) || is_float($value)) {
			$text = (string) $value;
		} elseif (is_array($value) && array_is_list($value)) {
			$parts = [];
			foreach ($value as $part) {
				if (!is_string($part) && !is_int($part) && !is_float($part)) {
					return null;
				}
				$parts[] = (string) $part;
			}
			$text = implode(' ', $parts);
		} else {
			return null;
		}

		return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	private function insertProjection(
		int $propertyId,
		int $objectId,
		int $objectSiteId,
		int $siteId,
		string $objectType,
		string $propertyName,
		LocaleTag $queryLocale,
		LocaleTag $sourceLocale,
		string $text,
		ObjectPropertyDefinition $definition,
	): void {
		$this->connection->executeStatement(sprintf(
			'INSERT INTO %s (object_property_id, object_id, object_site_id, site_id, object_type, property_name, query_locale, source_locale, value, is_searchable, is_sortable, search_vector) VALUES (:property_id, :object_id, :object_site_id, :site_id, :object_type, :property_name, :query_locale, :source_locale, :value, :searchable, :sortable, to_tsvector(CAST(:text_config AS regconfig), :search_value))',
			$this->tableNames->resolve('object_property_locale_index'),
		), [
			'property_id' => $propertyId,
			'object_id' => $objectId,
			'object_site_id' => $objectSiteId,
			'site_id' => $siteId,
			'object_type' => $objectType,
			'property_name' => $propertyName,
			'query_locale' => $queryLocale->getTag(),
			'source_locale' => $sourceLocale->getTag(),
			'value' => $text,
			'searchable' => $definition->searchable,
			'sortable' => $definition->sortable,
			'text_config' => $this->textSearchConfigs->resolve($queryLocale),
			'search_value' => $text,
		]);
	}
}
