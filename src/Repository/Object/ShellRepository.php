<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Shell;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;
use iikiti\CMS\Service\DatabaseCacheManager;

/**
 * Repository for global layout shells.
 *
 * @template-extends ObjectRepository<Shell>
 */
class ShellRepository extends ObjectRepository
{
	public function __construct(
		ManagerRegistry $registry,
		SiteRegistry $siteRegistry,
		DatabaseCacheManager $cacheManager,
		string $entityClass = Shell::class,
	) {
		parent::__construct($registry, $siteRegistry, $cacheManager, $entityClass);
	}

	/**
	 * Loads all shells with their properties eager-fetched in a single cached query
	 * (preserving the same default site-scoping semantics as {@see findAll()}).
	 *
	 * @return list<Shell>
	 */
	public function findAllWithProperties(): array
	{
		$qb = $this->createQueryBuilder('o')
			->leftJoin('o.properties', 'all_props')
			->addSelect('all_props');

		/** @var list<Shell> $shells */
		$shells = $this->_executeAndCache($qb, [
			'operation' => 'findAllWithProperties',
			'dependentClasses' => [ObjectProperty::class],
		]);

		return $shells;
	}
}
