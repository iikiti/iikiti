<?php

declare(strict_types=1);

namespace iikiti\CMS\Service;

use iikiti\CMS\Doctrine\ObjectPartitionManager;
use iikiti\CMS\Repository\Object\SiteRepository;

/** Pre-creates the current and next three quarters for every known site. */
final readonly class ObjectPartitionMaintenance
{
	public function __construct(
		private ObjectPartitionManager $partitions,
		private SiteRepository $sites,
	) {
	}

	public function ensureUpcoming(): int
	{
		$siteIds = ['0' => '0'];
		foreach ($this->sites->findAll() as $site) {
			if (null !== $site->getId()) {
				$siteId = ObjectPartitionManager::normalizeSiteId($site->getId());
				$siteIds[$siteId] = $siteId;
			}
		}

		$quarter = self::quarterStart(new \DateTimeImmutable());
		foreach ($siteIds as $siteId) {
			for ($offset = 0; $offset < 4; ++$offset) {
				$this->partitions->ensurePartition($siteId, $quarter->modify(sprintf('+%d months', 3 * $offset)));
			}
		}

		return count($siteIds) * 4;
	}

	private static function quarterStart(\DateTimeImmutable $date): \DateTimeImmutable
	{
		$month = intdiv((int) $date->format('n') - 1, 3) * 3 + 1;

		return $date->setDate((int) $date->format('Y'), $month, 1)->setTime(0, 0);
	}
}
