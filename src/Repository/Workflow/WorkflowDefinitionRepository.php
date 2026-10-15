<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository\Workflow;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\Workflow\WorkflowDefinition;

/** @extends ServiceEntityRepository<WorkflowDefinition> */
class WorkflowDefinitionRepository extends ServiceEntityRepository
{
	public function __construct(ManagerRegistry $registry)
	{
		parent::__construct($registry, WorkflowDefinition::class);
	}

	/** @return list<WorkflowDefinition> */
	public function findEnabled(): array
	{
		return $this->createQueryBuilder('definition')->
			andWhere('definition.enabled = :enabled')->
			andWhere('definition.archived = :archived')->
			setParameter('enabled', true)->
			setParameter('archived', false)->
			orderBy('definition.id', 'ASC')->
			getQuery()->
			getResult();
	}

	/** @return list<WorkflowDefinition> */
	public function findAssignedToSite(Site $site, bool $enabledOnly = false): array
	{
		$builder = $this->createQueryBuilder('definition')->
			innerJoin('definition.siteAssignments', 'assignment')->
			andWhere('assignment.site = :site')->
			setParameter('site', $site)->
			orderBy('definition.name', 'ASC');
		if ($enabledOnly) {
			$builder->andWhere('definition.enabled = :enabled')->andWhere('definition.archived = :archived')->
				setParameter('enabled', true)->
				setParameter('archived', false);
		}

		return $builder->getQuery()->getResult();
	}
}
