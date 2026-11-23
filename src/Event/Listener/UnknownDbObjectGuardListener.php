<?php

declare(strict_types=1);

namespace iikiti\CMS\Event\Listener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use iikiti\CMS\Entity\UnknownDbObject;

/**
 * Prevents creating or mutating {@see UnknownDbObject} fallback entities whose
 * owning plugin subtype is unloaded.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class UnknownDbObjectGuardListener
{
	public function prePersist(PrePersistEventArgs $args): void
	{
		$this->assertNotUnknown($args->getObject(), 'persist');
	}

	public function preUpdate(PreUpdateEventArgs $args): void
	{
		$this->assertNotUnknown($args->getObject(), 'update');
	}

	private function assertNotUnknown(object $entity, string $operation): void
	{
		if (!$entity instanceof UnknownDbObject) {
			return;
		}

		throw new \LogicException(sprintf(
			'Cannot %s UnknownDbObject of original type "%s" because its owning plugin subtype is not loaded.',
			$operation,
			$entity->getOriginalType(),
		));
	}
}
