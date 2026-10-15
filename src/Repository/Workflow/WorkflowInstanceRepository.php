<?php

declare(strict_types=1);

namespace iikiti\CMS\Repository\Workflow;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use iikiti\CMS\Entity\Workflow\WorkflowInstance;

/** @extends ServiceEntityRepository<WorkflowInstance> */
final class WorkflowInstanceRepository extends ServiceEntityRepository
{
	public function __construct(ManagerRegistry $registry)
	{
		parent::__construct($registry, WorkflowInstance::class);
	}

	public function findByIdempotencyKey(string $idempotencyKey): ?WorkflowInstance
	{
		$instance = $this->findOneBy(['idempotencyKey' => $idempotencyKey]);

		return $instance instanceof WorkflowInstance ? $instance : null;
	}

	/** @return list<WorkflowInstance> */
	public function findBySubject(string $subjectType, int|string $subjectId): array
	{
		return $this->findBy(['subjectType' => $subjectType, 'subjectId' => (string) $subjectId], ['id' => 'ASC']);
	}
}
