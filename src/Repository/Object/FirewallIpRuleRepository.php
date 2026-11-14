<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\FirewallIpRule;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;
use iikiti\CMS\Service\DatabaseCacheManager;

/**
 * Repository for managed IP/CIDR firewall rules.
 *
 * @template-extends ObjectRepository<FirewallIpRule>
 *
 * @method FirewallIpRule|null find($id, $lockMode = null, $lockVersion = null, array<string,mixed> $options = [])
 * @method FirewallIpRule|null findOneBy(array<string,mixed> $criteria, ?array<string,mixed> $orderBy = null, array<string,mixed> $options = [])
 * @method FirewallIpRule[]    findAll(array<string,mixed> $options = [])
 * @method FirewallIpRule[]    findBy(array<string,mixed> $criteria, ?array<string,mixed> $orderBy = null, $limit = null, $offset = null, array<string,mixed> $options = [])
 */
class FirewallIpRuleRepository extends ObjectRepository
{
	private const GLOBAL_SITE_ID = 0;

	public function __construct(
		ManagerRegistry $registry,
		SiteRegistry $siteRegistry,
		DatabaseCacheManager $cacheManager,
	) {
		parent::__construct($registry, $siteRegistry, $cacheManager, FirewallIpRule::class);
	}

	/**
	 * Returns global rules plus rules scoped to the given site, when a site is supplied.
	 *
	 * @return list<FirewallIpRule>
	 */
	public function findForSiteAndGlobal(int|string|null $siteId): array
	{
		$rules = $this->findForScope(self::GLOBAL_SITE_ID);
		if (null === $siteId || (string) $siteId === (string) self::GLOBAL_SITE_ID) {
			return $rules;
		}

		return [...$rules, ...$this->findForScope($siteId)];
	}

	/** @return list<FirewallIpRule> */
	public function findForScope(int|string $siteId): array
	{
		// Priority is stored as an object property, not a column, so it is ordered in PHP.
		$rules = $this->findBy(['site' => $siteId], null, options: ['filterBySite' => false]);
		usort($rules, static fn (FirewallIpRule $a, FirewallIpRule $b): int => $a->getPriority() <=> $b->getPriority());

		return $rules;
	}

	protected static function _defaultOption(string $key): bool|string|int|null
	{
		if ('filterBySite' === $key) {
			return false;
		}

		return parent::_defaultOption($key);
	}
}
