<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Query;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Doctrine\DatabaseTableNameResolver;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\User;
use iikiti\CMS\Plugin\ObjectPropertyDefinition;
use iikiti\CMS\Plugin\ObjectPropertyDefinitionRegistry;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\Object\SiteRepository;
use iikiti\CMS\Service\LocaleCollationManager;
use iikiti\CMS\Service\LocaleResolver;
use iikiti\CMS\Service\PostgreSQLTextSearchConfigResolver;
use iikiti\CMS\Value\LocaleTag;
use Symfony\Bundle\SecurityBundle\Security;

/** Queries plugin-declared textual fields through their locale-specific indexes. */
final readonly class LocalizedPropertyIndexQuery
{
	public function __construct(
		private EntityManagerInterface $entityManager,
		private DatabaseTableNameResolver $tableNames,
		private ObjectPropertyDefinitionRegistry $definitions,
		private LocaleResolver $locales,
		private LocaleCollationManager $collations,
		private PostgreSQLTextSearchConfigResolver $textSearchConfigs,
		private SiteRepository $sites,
		private Security $security,
	) {
	}

	/** @return list<DbObject>|null Null means the query has no property-index fields. */
	public function execute(QueryDefinition $definition, ?int $siteId): ?array
	{
		$ids = $this->queryIds($definition, $siteId);
		if (null === $ids || [] === $ids) {
			return $ids;
		}

		$objects = $this->entityManager->createQueryBuilder()->
			select('object')->
			from(DbObject::class, 'object')->
			where('object.id IN (:ids)')->
			setParameter('ids', $ids)->
			getQuery()->
			getResult();
		$byId = [];
		foreach ($objects as $object) {
			$byId[(string) $object->getId()] = $object;
		}

		$ordered = [];
		foreach ($ids as $id) {
			if (isset($byId[(string) $id])) {
				$ordered[] = $byId[(string) $id];
			}
		}

		return $ordered;
	}

	/** @return list<string>|null Null means the query has no property-index fields. */
	public function queryIds(QueryDefinition $definition, ?int $siteId): ?array
	{
		$propertyFields = [];
		foreach ($definition->getFilters() as $filter) {
			if (null !== $property = $this->propertyName($filter['field'])) {
				$propertyFields[$property]['filter'] = true;
			}
		}
		foreach ($definition->getOrderBy() as $order) {
			if (null !== $property = $this->propertyName($order['field'])) {
				$propertyFields[$property]['sort'] = true;
			}
		}
		if ([] === $propertyFields) {
			return null;
		}

		$siteId ??= $this->currentSiteId();
		$siteId ??= 0;
		$site = $this->sites->find($siteId);
		$application = $site instanceof \iikiti\CMS\Entity\Object\Site ? $this->sites->getApplicationBySite($site) : null;
		$user = $this->security->getUser();
		$preferredLocale = $user instanceof User ? $user->getPreferredLocale() : null;
		$requestedLocale = $this->locales->resolveUiLocale(
			$preferredLocale,
			$site instanceof \iikiti\CMS\Entity\Object\Site ? $site->getDefaultLocale() : null,
			$application instanceof \iikiti\CMS\Entity\Object\Application ? $application->getDefaultLocale() : null,
		);
		$objectType = $this->objectType($definition->getObjectType());
		$indexTable = $this->tableNames->resolve('object_property_locale_index');
		$aliases = [];
		$joins = [];
		$params = ['site_id' => $siteId];

		foreach ($propertyFields as $propertyName => $requirements) {
			$propertyDefinition = $this->definitions->get($objectType, $propertyName);
			if (null === $propertyDefinition ||
				(isset($requirements['filter']) && !$propertyDefinition->searchable) ||
				(isset($requirements['sort']) && !$propertyDefinition->sortable)
			) {
				throw new \InvalidArgumentException(sprintf('Property "%s" is not declared searchable/sortable for object type "%s".', $propertyName, $objectType));
			}

			$alias = 'localized_'.count($aliases);
			$locale = $this->queryLocale($siteId, $objectType, $propertyName, $propertyDefinition, $requestedLocale, $site, $application);
			$collation = $this->collations->get($locale);
			$propertyParameter = $alias.'_property';
			$localeParameter = $alias.'_locale';
			$siteParameter = $alias.'_site';
			$params[$propertyParameter] = $propertyName;
			$params[$localeParameter] = $locale->getTag();
			$params[$siteParameter] = $siteId;
			$condition = [];
			if (isset($requirements['filter'])) {
				$condition[] = $alias.'.is_searchable = TRUE';
			}
			if (isset($requirements['sort'])) {
				$condition[] = $alias.'.is_sortable = TRUE';
			}
			$joins[] = sprintf(
				' INNER JOIN %s %s ON %s.object_id = object.id AND %s.site_id = :%s AND %s.object_type = object.type AND %s.property_name = :%s AND %s.query_locale = :%s AND %s',
				$indexTable,
				$alias,
				$alias,
				$alias,
				$siteParameter,
				$alias,
				$alias,
				$propertyParameter,
				$alias,
				$localeParameter,
				implode(' AND ', $condition),
			);
			$aliases[$propertyName] = ['alias' => $alias, 'collation' => $collation, 'locale' => $locale];
		}

		$sql = 'SELECT object.id FROM '.$this->tableNames->resolve('object_lookup').' object'.implode('', $joins).' WHERE object.site_id = :site_id';
		if ('' !== $objectType) {
			$sql .= ' AND object.type = :object_type';
			$params['object_type'] = $objectType;
		}

		$parameterNumber = 0;
		foreach ($definition->getFilters() as $filter) {
			$field = $filter['field'];
			$propertyName = $this->propertyName($field);
			if (null !== $propertyName && isset($aliases[$propertyName])) {
				$alias = $aliases[$propertyName]['alias'];
				$placeholder = 'value_'.$parameterNumber++;
				$valueExpression = $alias.'.value COLLATE '.$aliases[$propertyName]['collation'];
				if ('fts' === $filter['op']) {
					$configParameter = 'text_config_'.$parameterNumber;
					$sql .= ' AND '.$alias.'.search_vector @@ websearch_to_tsquery(CAST(:'.$configParameter.' AS regconfig), :'.$placeholder.')';
					$params[$configParameter] = $this->textSearchConfigs->resolve($aliases[$propertyName]['locale']);
					$params[$placeholder] = (string) $filter['value'];
				} elseif ('like' === $filter['op']) {
					$sql .= ' AND '.$alias.'.value LIKE :'.$placeholder;
					$params[$placeholder] = '%'.$this->escapeLike((string) $filter['value']).'%';
				} else {
					$sql .= ' AND '.$valueExpression.' '.$this->operator($filter['op']).' :'.$placeholder;
					$params[$placeholder] = $filter['value'];
				}
				continue;
			}

			$sqlColumn = match ($field) {
				'id' => 'object.id',
				'created_date' => 'object.created_date',
				default => null,
			};
			if (null === $sqlColumn) {
				continue;
			}
			$placeholder = 'value_'.$parameterNumber++;
			$sql .= ' AND '.$sqlColumn.' '.$this->operator($filter['op']).' :'.$placeholder;
			$params[$placeholder] = $filter['value'];
		}

		$orderBy = [];
		foreach ($definition->getOrderBy() as $order) {
			$field = $order['field'];
			$propertyName = $this->propertyName($field);
			if (null !== $propertyName && isset($aliases[$propertyName])) {
				$orderBy[] = $aliases[$propertyName]['alias'].'.value COLLATE '.$aliases[$propertyName]['collation'].' '.strtoupper($order['dir']);
			} elseif ('id' === $field || 'created_date' === $field) {
				$orderBy[] = 'object.'.$field.' '.strtoupper($order['dir']);
			}
		}
		$sql .= ' ORDER BY '.([] === $orderBy ? 'object.id ASC' : implode(', ', $orderBy));
		$sql .= ' LIMIT :query_limit';
		$params['query_limit'] = $definition->getLimit();
		$ids = $this->entityManager->getConnection()->fetchFirstColumn($sql, $params);
		if ([] === $ids) {
			return [];
		}

		return $ids;
	}

	public function cacheKey(): string
	{
		$user = $this->security->getUser();

		return $user instanceof User ? ($user->getPreferredLocale()?->getTag() ?? '') : '';
	}

	private function queryLocale(
		int $siteId,
		string $objectType,
		string $propertyName,
		ObjectPropertyDefinition $definition,
		LocaleTag $requested,
		mixed $site,
		mixed $application,
	): LocaleTag {
		$available = $this->entityManager->getConnection()->fetchFirstColumn(
			sprintf(
				'SELECT DISTINCT query_locale FROM %s WHERE site_id = :site_id AND object_type = :object_type AND property_name = :property_name ORDER BY query_locale',
				$this->tableNames->resolve('object_property_locale_index'),
			),
			['site_id' => $siteId, 'object_type' => $objectType, 'property_name' => $propertyName],
		);
		$locales = [];
		foreach ($available as $tag) {
			$locale = LocaleTag::fromString((string) $tag);
			$locales[$locale->getTag()] = $locale;
		}
		if ([] === $locales) {
			foreach ($definition->locales as $tag) {
				$locale = LocaleTag::fromString($tag);
				$locales[$locale->getTag()] = $locale;
			}
			$locales['und'] = LocaleTag::fromString('und');
		}

		return $this->locales->matchAvailableLocale($requested, $locales) ??
			$this->locales->matchAvailableLocale($site instanceof \iikiti\CMS\Entity\Object\Site ? $site->getDefaultLocale() : null, $locales) ??
			$this->locales->matchAvailableLocale($application instanceof \iikiti\CMS\Entity\Object\Application ? $application->getDefaultLocale() : null, $locales) ??
			$locales[array_key_first($locales)];
	}

	private function currentSiteId(): ?int
	{
		try {
			$id = SiteRegistry::hasCurrent() ? SiteRegistry::getCurrent()->getId() : null;
		} catch (\Error) {
			return null;
		}

		return null === $id ? null : (int) $id;
	}

	private function objectType(string $type): string
	{
		if ('' === $type) {
			return '';
		}
		if (class_exists($type)) {
			return strtolower((new \ReflectionClass($type))->getShortName());
		}
		$parts = explode('\\', $type);

		return strtolower((string) end($parts));
	}

	private function propertyName(string $field): ?string
	{
		foreach (['property.', 'properties.'] as $prefix) {
			if (str_starts_with($field, $prefix)) {
				$name = substr($field, strlen($prefix));

				return '' === $name ? null : $name;
			}
		}

		$expression = QueryDefinition::COLUMN_MAP[$field] ?? null;

		return is_string($expression) && str_ends_with($expression, 'Prop.value') ? $field : null;
	}

	private function operator(string $operator): string
	{
		return match ($operator) {
			'eq' => '=',
			'ne' => '<>',
			'gt' => '>',
			'lt' => '<',
			'gte' => '>=',
			'lte' => '<=',
			default => '=',
		};
	}

	private function escapeLike(string $value): string
	{
		return addcslashes($value, '%\\_');
	}
}
