<?php

declare(strict_types=1);

namespace iikiti\CMS\Event\Listener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use iikiti\CMS\Doctrine\ObjectPartitionManager;
use iikiti\CMS\Entity\DbObject;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final readonly class EnsureObjectPartitionListener
{
	public function __construct(
		private ObjectPartitionManager $partitions,
	) {
	}

	public function prePersist(PrePersistEventArgs $event): void
	{
		$this->ensureFor($event->getObject());
	}

	public function preUpdate(PreUpdateEventArgs $event): void
	{
		if (!$event->hasChangedField('site') && !$event->hasChangedField('created_date')) {
			return;
		}
		$this->ensureFor($event->getObject());
	}

	private function ensureFor(object $entity): void
	{
		if (!$entity instanceof DbObject) {
			return;
		}

		$createdDate = $entity->getCreatedDate();
		if (null === $createdDate) {
			throw new \LogicException(sprintf('%s requires a creation date before it can be persisted.', $entity::class));
		}

		$this->partitions->ensurePartition($entity->getSiteId(), $createdDate);
	}
}
