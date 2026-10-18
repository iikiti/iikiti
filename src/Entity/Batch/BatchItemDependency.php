<?php

declare(strict_types=1);

namespace iikiti\CMS\Entity\Batch;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'batch_item_dependencies')]
#[ORM\Index(name: 'idx_batch_dependency_target', columns: ['dependency_item_id'])]
class BatchItemDependency
{
	#[ORM\Id]
	#[ORM\ManyToOne(targetEntity: BatchItem::class)]
	#[ORM\JoinColumn(name: 'dependent_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
	private BatchItem $dependent;

	#[ORM\Id]
	#[ORM\ManyToOne(targetEntity: BatchItem::class)]
	#[ORM\JoinColumn(name: 'dependency_item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
	private BatchItem $dependency;

	public function __construct(BatchItem $dependent, BatchItem $dependency)
	{
		if ($dependent === $dependency || $dependent->getJob() !== $dependency->getJob()) {
			throw new \InvalidArgumentException('Batch dependencies must be distinct items in the same job.');
		}
		$this->dependent = $dependent;
		$this->dependency = $dependency;
	}
}
