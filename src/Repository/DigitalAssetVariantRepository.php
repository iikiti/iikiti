<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\AbstractDigitalAsset;
use iikiti\CMS\Entity\DigitalAssetVariant;

/** @extends ServiceEntityRepository<DigitalAssetVariant> */
class DigitalAssetVariantRepository extends ServiceEntityRepository
{
	public function __construct(ManagerRegistry $registry)
	{
		parent::__construct($registry, DigitalAssetVariant::class);
	}

	/** @return list<DigitalAssetVariant> */
	public function findByAsset(AbstractDigitalAsset $asset): array
	{
		return $this->findBy(['asset' => $asset]);
	}

	public function findByAssetAndKey(AbstractDigitalAsset $asset, string $key): ?DigitalAssetVariant
	{
		$result = $this->findOneBy(['asset' => $asset, 'key' => $key]);

		return $result instanceof DigitalAssetVariant ? $result : null;
	}

	/**
	 * Batch-loads all variants for the provided asset IDs in a single query.
	 *
	 * @param list<int|string> $assetIds
	 *
	 * @return list<DigitalAssetVariant>
	 */
	public function findByAssetIds(array $assetIds): array
	{
		if ([] === $assetIds) {
			return [];
		}

		/** @var list<DigitalAssetVariant> $results */
		$results = $this->createQueryBuilder('variant')->
			andWhere('IDENTITY(variant.asset) IN (:assetIds)')->
			setParameter('assetIds', array_values(array_unique($assetIds)))->
			orderBy('variant.id', 'ASC')->
			getQuery()->
			getResult();

		return $results;
	}
}
