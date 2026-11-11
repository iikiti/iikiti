<?php

declare(strict_types=1);

namespace iikiti\CMS\Event\Listener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use iikiti\CMS\Entity\DbObject;
use iikiti\CMS\Entity\Object\Application;
use iikiti\CMS\Entity\Object\Site;
use iikiti\CMS\Entity\ObjectProperty;
use iikiti\CMS\Service\LocalizedPropertyIndexUpdater;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final readonly class LocalizedPropertyIndexListener
{
	public function __construct(
		private LocalizedPropertyIndexUpdater $updater,
	) {
	}

	public function postPersist(PostPersistEventArgs $event): void
	{
		$object = $event->getObject();
		if ($object instanceof ObjectProperty) {
			$this->propertyChanged($object);
		} elseif ($object instanceof Site && null !== $object->getId()) {
			$this->updater->rebuildSite($object->getId());
		} elseif ($object instanceof Application) {
			$this->updater->rebuildAll();
		}
	}

	public function postUpdate(PostUpdateEventArgs $event): void
	{
		$object = $event->getObject();
		if ($object instanceof ObjectProperty) {
			$this->propertyChanged($object);

			return;
		}

		if (!$object instanceof DbObject) {
			return;
		}

		$changes = $event->getObjectManager()->getUnitOfWork()->getEntityChangeSet($object);
		if (isset($changes['site']) || isset($changes['created_date'])) {
			$this->updater->refreshObject($object);
		}
	}

	private function propertyChanged(ObjectProperty $property): void
	{
		$owner = $property->getObject();
		$name = $property->getName();
		if ('configuration' === $name && $owner instanceof Site && null !== $owner->getId()) {
			$this->updater->rebuildSite($owner->getId());
		} elseif ('configuration' === $name && $owner instanceof Application) {
			$this->updater->rebuildAll();
		} elseif ('locale_settings' === $name && $owner instanceof DbObject) {
			$this->updater->refreshObject($owner);
		} else {
			$this->updater->refresh($property);
		}
	}
}
