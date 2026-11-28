<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\DigitalAssetVersion;

/** @extends ServiceEntityRepository<DigitalAssetVersion> */
class DigitalAssetVersionRepository extends ServiceEntityRepository
{
	public function __construct(ManagerRegistry $registry)
	{
		parent::__construct($registry, DigitalAssetVersion::class);
	}

	/**
	 * @return list<DigitalAssetVersion>
	 */
	public function findByAsset(AbstractDigitalAsset $asset): array
	{
		/** @var list<DigitalAssetVersion> $results */
		$results = $this->createQueryBuilder('version')->
			andWhere('version.asset = :asset')->
			setParameter('asset', $asset)->
			orderBy('version.versionNumber', 'DESC')->
			getQuery()->
			getResult();

		return $results;
	}

	public function findByAssetAndVersion(AbstractDigitalAsset $asset, int $versionNumber): ?DigitalAssetVersion
	{
		$result = $this->findOneBy([
			'asset' => $asset,
			'versionNumber' => $versionNumber,
		]);

		return $result instanceof DigitalAssetVersion ? $result : null;
	}

	public function findMaxVersionNumber(AbstractDigitalAsset $asset): int
	{
		$max = $this->createQueryBuilder('version')->
			select('MAX(version.versionNumber)')->
			andWhere('version.asset = :asset')->
			setParameter('asset', $asset)->
			getQuery()->
			getSingleScalarResult();

		return is_numeric($max) ? (int) $max : 0;
	}

	/**
	 * Counts version rows still pointing to the given storage key so shared or
	 * rolled-back blobs are never prematurely deleted from storage.
	 */
	public function countStorageKeyReferences(string $storageKey, int|string|null $excludeAssetId = null): int
	{
		$qb = $this->createQueryBuilder('version')->
			select('COUNT(version.id)')->
			andWhere('version.storageKey = :storageKey')->
			setParameter('storageKey', $storageKey);

		if (null !== $excludeAssetId) {
			$qb->andWhere('IDENTITY(version.asset) != :excludeAssetId')->
				setParameter('excludeAssetId', $excludeAssetId);
		}

		return (int) $qb->getQuery()->getSingleScalarResult();
	}
}
