<?php

declare(strict_types=1);

namespace iikiti\CMS\Web\BlockEditor\Query;

use Doctrine\ORM\EntityManagerInterface;
use iikiti\CMS\Entity\DbObject;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Default query source: objects in the current site, filtered by whitelisted
 * columns and object type. All conditions are parameterized — users cannot inject SQL.
 */
final class ObjectsQuerySource implements QuerySourceInterface
{
	public function __construct(
		private readonly EntityManagerInterface $em,
		#[Autowire(service: 'cache.database')]
		private readonly CacheItemPoolInterface $cache,
	) {
	}

	public function getName(): string
	{
		return 'objects';
	}

	#[\Override]
	public function execute(QueryDefinition $definition, ?int $siteId): array
	{
		$cacheKey = 'iikiti_query:'.md5(serialize([
			$definition->getSource(),
			$definition->getObjectType(),
			$definition->getFilters(),
			$definition->getOrderBy(),
			$definition->getLimit(),
			$siteId,
		]));

		$item = $this->cache->getItem($cacheKey);
		if ($item->isHit()) {
			$cached = $item->get();

			return is_array($cached) ? array_values($cached) : [];
		}

		$results = $this->runQuery($definition, $siteId);
		$item->set($results);
		$item->expiresAfter(300);
		$this->cache->save($item);

		return $results;
	}

	/**
	 * @return list<DbObject>
	 */
	private function runQuery(QueryDefinition $definition, ?int $siteId): array
	{
		$qb = $this->em->createQueryBuilder()->
			select('o')->
			from(DbObject::class, 'o');

		// Property columns require joins on object_properties (whitelisted only).
		$propertyJoins = [];
		$joined = [];
		foreach ($definition->getFilters() as $filter) {
			if (isset(QueryDefinition::COLUMN_MAP[$filter['field']]) && str_starts_with(
				QueryDefinition::COLUMN_MAP[$filter['field']],
				'prop'
			)) {
				$alias = $this->aliasFor($filter['field']);
				if (!isset($joined[$alias])) {
					$joined[$alias] = true;
					$qb->leftJoin('o.properties', $alias, null, $alias.'.name = :name'.$alias)->
						setParameter('name'.$alias, $filter['field']);
					$propertyJoins[$alias] = $filter['field'];
				}
			}
		}

		// Object type filter (discriminator = FQCN).
		if ('' !== $definition->getObjectType()) {
			$qb->andWhere('o.type = :objectType')->
				setParameter('objectType', $definition->getObjectType());
		}

		// Site scoping (always applied).
		if (null !== $siteId) {
			$qb->andWhere('o.site = :site')->
				setParameter('site', $siteId);
		}

		// Whitelisted column filters.
		$param = 0;
		foreach ($definition->getFilters() as $filter) {
			$dql = QueryDefinition::COLUMN_MAP[$filter['field']] ?? null;
			if (null === $dql) {
				continue; // ignore unknown columns
			}
			$placeholder = 'param'.$param++;
			$qb->andWhere($this->applyOperator($dql, $filter['op'], $placeholder))->
				setParameter($placeholder, $this->castValue($filter['op'], $filter['value']));
		}

		foreach ($definition->getOrderBy() as $order) {
			$dql = QueryDefinition::COLUMN_MAP[$order['field']] ?? null;
			if (null === $dql) {
				continue;
			}
			$qb->addOrderBy($dql, $order['dir']);
		}

		$qb->setMaxResults($definition->getLimit());

		return $qb->getQuery()->getResult();
	}

	private function aliasFor(string $field): string
	{
		return preg_replace('/[^a-z0-9]/', '', $field).'Prop';
	}

	private function applyOperator(string $dql, string $op, string $placeholder): string
	{
		return match ($op) {
			'eq' => $dql.' = :'.$placeholder,
			'ne' => $dql.' != :'.$placeholder,
			'gt' => $dql.' > :'.$placeholder,
			'lt' => $dql.' < :'.$placeholder,
			'gte' => $dql.' >= :'.$placeholder,
			'lte' => $dql.' <= :'.$placeholder,
			'like' => $dql.' LIKE :'.$placeholder,
			default => $dql.' = :'.$placeholder,
		};
	}

	private function castValue(string $op, mixed $value): mixed
	{
		return match ($op) {
			'gt', 'lt', 'gte', 'lte' => is_numeric($value) ? (float) $value : $value,
			'like' => '%'.$this->escapeLike((string) $value).'%',
			default => $value,
		};
	}

	private function escapeLike(string $value): string
	{
		return addcslashes($value, '%\\_');
	}
}
