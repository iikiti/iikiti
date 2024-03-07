<?php

namespace iikiti\CMS\Service;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use iikiti\CMS\Entity\DbObject;

/**
 * Full-text search service.
 */
#[AsEntityListener(
	event: Events::postPersist,
	method: 'postPersist',
	entity: DbObject::class
)]
class FullTextSearch
{
	public function __construct()
	{
	}

	public function postPersist(LifecycleEventArgs $args): void
	{
		$entity = $args->getObject();

		if (!($entity instanceof DbObject)) {
			return;
		}

		$entityManager = $args->getObjectManager();
	}
}
