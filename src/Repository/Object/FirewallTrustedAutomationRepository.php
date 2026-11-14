<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository\Object;

use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\FirewallTrustedAutomation;
use iikiti\CMS\Registry\SiteRegistry;
use iikiti\CMS\Repository\ObjectRepository;
use iikiti\CMS\Service\DatabaseCacheManager;

/**
 * Repository for operator-defined trusted automations (global and site-scoped).
 *
 * @template-extends ObjectRepository<FirewallTrustedAutomation>
 *
 * @method FirewallTrustedAutomation|null find($id, $lockMode = null, $lockVersion = null, array<string,mixed> $options = [])
 * @method FirewallTrustedAutomation|null findOneBy(array<string,mixed> $criteria, ?array<string,mixed> $orderBy = null, array<string,mixed> $options = [])
 * @method FirewallTrustedAutomation[]    findAll(array<string,mixed> $options = [])
 * @method FirewallTrustedAutomation[]    findBy(array<string,mixed> $criteria, ?array<string,mixed> $orderBy = null, $limit = null, $offset = null, array<string,mixed> $options = [])
 */
class FirewallTrustedAutomationRepository extends ObjectRepository
{
	private const GLOBAL_SITE_ID = 0;

	public function __construct(
		ManagerRegistry $registry,
		SiteRegistry $siteRegistry,
		DatabaseCacheManager $cacheManager,
	) {
		parent::__construct($registry, $siteRegistry, $cacheManager, FirewallTrustedAutomation::class);
	}

	/**
	 * Global automations plus those scoped to the given site, ordered by priority.
	 *
	 * @return list<FirewallTrustedAutomation>
	 */
	public function findForSiteAndGlobal(int|string|null $siteId): array
	{
		$automations = $this->findForScope(self::GLOBAL_SITE_ID);
		if (null === $siteId || (string) $siteId === (string) self::GLOBAL_SITE_ID) {
			return $automations;
		}

		return $this->sortByPriority([...$automations, ...$this->findForScope($siteId)]);
	}

	/** @return list<FirewallTrustedAutomation> */
	public function findForScope(int|string $siteId): array
	{
		return $this->sortByPriority($this->findBy(['site' => $siteId], null, options: ['filterBySite' => false]));
	}

	public function findOneByHandle(string $handle, int|string $siteId = self::GLOBAL_SITE_ID): ?FirewallTrustedAutomation
	{
		foreach ($this->findForScope($siteId) as $automation) {
			if ($automation->getHandle() === $handle) {
				return $automation;
			}
		}

		return null;
	}

	/**
	 * @param list<FirewallTrustedAutomation> $automations
	 *
	 * @return list<FirewallTrustedAutomation>
	 */
	private function sortByPriority(array $automations): array
	{
		usort($automations, static fn (FirewallTrustedAutomation $a, FirewallTrustedAutomation $b): int => $a->getPriority() <=> $b->getPriority());

		return $automations;
	}

	protected static function _defaultOption(string $key): bool|string|int|null
	{
		if ('filterBySite' === $key) {
			return false;
		}

		return parent::_defaultOption($key);
	}
}
