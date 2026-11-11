<?php

declare(strict_types=1);

namespace iikiti\CMS\Event\Listener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Service\ObjectPropertyValueValidator;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final readonly class ObjectPropertyLocaleValidationListener
{
	public function __construct(
		private ObjectPropertyValueValidator $validator,
	) {
	}

	public function prePersist(PrePersistEventArgs $event): void
	{
		$this->validate($event->getObject());
	}

	public function preUpdate(PreUpdateEventArgs $event): void
	{
		$this->validate($event->getObject());
	}

	private function validate(object $entity): void
	{
		if (!$entity instanceof ObjectProperty || null === $entity->getLocalizedValue()) {
			return;
		}

		$object = $entity->getObject();
		if (null === $object) {
			throw new \InvalidArgumentException('A localized object property must belong to a DbObject.');
		}

		$this->validator->validate($object, $entity);
	}
}
