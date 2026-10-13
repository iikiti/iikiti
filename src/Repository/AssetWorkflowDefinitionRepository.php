<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\AssetWorkflowDefinition;

/** @extends ServiceEntityRepository<AssetWorkflowDefinition> */
class AssetWorkflowDefinitionRepository extends ServiceEntityRepository
{
	public function __construct(ManagerRegistry $registry)
	{
		parent::__construct($registry, AssetWorkflowDefinition::class);
	}

	public function findByKey(string $key): ?AssetWorkflowDefinition
	{
		$result = $this->findOneBy(['key' => $key]);

		return $result instanceof AssetWorkflowDefinition ? $result : null;
	}
}
